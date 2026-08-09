# Plan 027: El cliente del CRM reutiliza la conexión, tiene timeouts y acepta gzip

> **Executor instructions**: Sigue este plan paso a paso. Ejecuta cada comando de
> verificación y confirma el resultado esperado antes de pasar al siguiente paso.
> Si ocurre algo de la sección "STOP conditions", para y reporta — no improvises.
> Al terminar, actualiza la fila de este plan en `plans/README.md`.
>
> **Drift check (ejecutar primero)**: `git diff --stat 337ec6a..HEAD -- inc/stic-class-6.php`
> Si el archivo cambió desde que se escribió este plan, compara los extractos de
> "Current state" con el código vivo antes de seguir; si no coinciden, es STOP.

## Status

- **Priority**: P0 (es el multiplicador de TODO lo demás)
- **Effort**: S-M
- **Risk**: MED (cambia el transporte de todas las llamadas al CRM)
- **Depends on**: none (pero coordina con `plans/008-harden-crm-transport-and-queries.md`, ver "Solapes")
- **Category**: perf
- **Planned at**: commit `337ec6a`, 2026-08-09

## Why this matters

TODOS los datos del área privada vienen de un SuiteCRM remoto vía REST, y **cada página hace
entre 2 y 40+ llamadas secuenciales** a ese CRM. Hoy cada llamada abre un socket nuevo
(DNS + TCP + handshake TLS completo, típicamente 50-150 ms) porque se crea y destruye un handle
cURL por llamada y se fuerza HTTP/1.0 (sin keep-alive). Además no hay **ningún timeout**: si el
CRM se cuelga, el usuario ve la WebView en blanco hasta el `max_execution_time` de PHP. Y no se
pide compresión, así que las respuestas grandes (listados sin límite) viajan sin gzip.

Arreglar el transporte multiplica: cada round-trip de cada página del área se abarata a la vez.
Es el cambio con mejor relación beneficio/esfuerzo de toda la auditoría de rendimiento.

## Current state

Un único archivo contiene todo el diálogo con el CRM:

- `inc/stic-class-6.php` — clase `SugarRestApiCall`. Singleton vía `getObjSCP()` (`:35-43`).
  El `session_id` del CRM se cachea en `$_SESSION['api_session_id']` (`:22-27`), así que el
  login NO se repite por llamada; el problema es solo el transporte.

El método `call()` tal y como existe hoy (`inc/stic-class-6.php:45-82`):

```php
public function call($method, $parameters, $url, $retry = false)
{
    ob_start();
    $curl_request = curl_init();

    curl_setopt($curl_request, CURLOPT_URL, $url);
    curl_setopt($curl_request, CURLOPT_POST, 1);
    curl_setopt($curl_request, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
    curl_setopt($curl_request, CURLOPT_HEADER, 1);
    curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl_request, CURLOPT_FOLLOWLOCATION, 0);

    $jsonEncodedData = json_encode($parameters);

    $post = array(
        "method" => $method,
        "input_type" => "JSON",
        "response_type" => "JSON",
        "rest_data" => $jsonEncodedData,
    );

    curl_setopt($curl_request, CURLOPT_POSTFIELDS, $post);
    $result = curl_exec($curl_request);
    curl_close($curl_request);

    $result = explode("\r\n\r\n", $result, 2);
    $response = json_decode($result[1]);
    ob_end_flush();
    // ...
    if (isset($response->number) && $response->number == 11 && !$retry) {
        $this->session_id = $this->login();
        $_SESSION['api_session_id'] = $this->session_id;
        return $this->call($method, $parameters, $url, true);
    }
    return $response;
}
```

Hechos relevantes:

- `curl_init()`/`curl_close()` por llamada (`:48`, `:69`) → sin reutilización de conexión.
- `CURL_HTTP_VERSION_1_0` (`:52`) → el servidor cierra la conexión tras cada respuesta
  aunque se reutilizara el handle.
- `CURLOPT_HEADER, 1` (`:53`) + `explode("\r\n\r\n", $result, 2)` (`:71`) para separar
  cabeceras de cuerpo — frágil ante `100 Continue` o `Transfer-Encoding: chunked` (por eso
  probablemente se fijó HTTP/1.0 en su día).
- **Cero** `CURLOPT_TIMEOUT` / `CURLOPT_CONNECTTIMEOUT` en todo el repo (verificado por grep).
- **Cero** `CURLOPT_ENCODING` → sin gzip.
- `ob_start()`/`ob_end_flush()` (`:47`, `:73`) envuelven la llamada sin capturar nada útil
  (con `RETURNTRANSFER` no hay salida); es ruido heredado.
- El retry de sesión caducada (`number == 11`, `:76-80`) debe seguir funcionando igual.
- Los consumidores toleran `null`: `inc/stic-class-6.php:379` (`?? null`), `:437` (`?? null`).
  Otros acceden directo a propiedades (p. ej. `$set_entry_result->id`, `:227`) — en PHP 8
  eso emite Warning y devuelve null, no un fatal. Aceptable como degradación.

Convenciones del repo: PHP procedural, compatible **PHP 7.4+** (`composer.json` declara
`"php": ">=7.4"`; el CI corre 8.3). Sin autoloader de Composer en runtime (el deploy por FTP
no sube `vendor/`). Los ajustes con "tuneable sin tocar código" usan `apply_filters`
(ejemplo existente: `sticpa_session_ttl()` en `sinergiacrm-private-area.php:1062-1065`).

### Solapes con otros planes (leer antes de tocar)

- `plans/008-harden-crm-transport-and-queries.md` (TODO) toca la MISMA línea `:52` para
  subir a HTTP/1.1 y activar `SSL_VERIFYPEER`. **Este plan hace el cambio de versión HTTP;
  el 008 hace el de TLS.** Si el 008 ya se ejecutó, no dupliques: verifica y sigue.
  **No cambies `CURLOPT_SSL_VERIFYPEER` en este plan** (es del 008, con su propia
  verificación contra el certificado real del CRM).

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint de un archivo | `php -l inc/stic-class-6.php` | `No syntax errors detected` |
| Lint de todo | `find . -name "*.php" -not -path "./vendor/*" -not -path "./.agents/*" -not -path "./.claude/*" -print0 \| xargs -0 -n1 -P4 php -l >/dev/null` | exit 0 |
| Tests (si hay `vendor/`) | `composer test` | todos verdes (requiere `composer install` previo, con red) |

## Scope

**In scope** (los únicos archivos a modificar):
- `inc/stic-class-6.php`

**Out of scope** (NO tocar aunque parezca relacionado):
- `CURLOPT_SSL_VERIFYPEER` — es del plan 008.
- Las queries concatenadas de `PortalLogin` etc. — plan 008 (SEC-02).
- Cualquier página de `pages/` o handler de `inc/stic-action.php`.
- El número de llamadas que se hacen (eso es de los planes 011/029): aquí solo se abarata
  cada llamada.

## Git workflow

- Rama: la que indique el operador; si no, `advisor/027-crm-transport`.
- Mensajes de commit en el estilo del repo (ver `git log`): tipo(área) en español, p. ej.
  `perf(crm): keep-alive, timeouts y gzip en el cliente REST`.
- NO hagas push ni PR salvo que el operador lo pida.

## Steps

### Step 1: Handle cURL persistente por instancia

En `SugarRestApiCall`, añade una propiedad privada para el handle y un helper:

```php
private $curlHandle = null;

private function getCurlHandle()
{
    if ($this->curlHandle === null) {
        $this->curlHandle = curl_init();
    } else {
        curl_reset($this->curlHandle);
    }
    return $this->curlHandle;
}
```

En `call()`, sustituye `curl_init()` por `$curl_request = $this->getCurlHandle();` y
**elimina** el `curl_close($curl_request);` de después de `curl_exec`. (El handle muere con
la petición PHP; no hace falta cerrarlo a mano. Si prefieres ser explícito, añade un
`__destruct()` que haga `curl_close` si el handle existe.)

`curl_reset()` existe desde PHP 5.5 — no hay problema de compatibilidad.

**Verify**: `php -l inc/stic-class-6.php` → `No syntax errors detected`.
**Verify**: `grep -c "curl_init" inc/stic-class-6.php` → `1` (solo en el helper).

### Step 2: HTTP/1.1 y separación de cabeceras robusta

Dos cambios que van juntos (el segundo protege del riesgo del primero):

1. `CURLOPT_HTTP_VERSION`: cambia `CURL_HTTP_VERSION_1_0` por `CURL_HTTP_VERSION_1_1`.
2. Cambia `CURLOPT_HEADER` de `1` a `0` y elimina la línea
   `$result = explode("\r\n\r\n", $result, 2);`. Con `CURLOPT_HEADER, 0` cURL ya NO incluye
   cabeceras en `$result`, así que el cuerpo es directamente el JSON:

```php
$result = curl_exec($curl_request);
$response = json_decode($result);
```

Con esto, `chunked` y `100 Continue` dejan de ser un problema: cURL los gestiona por debajo.

**Verify**: `php -l inc/stic-class-6.php` → sin errores.
**Verify**: `grep -n "explode(\"\\\\r\\\\n\\\\r\\\\n\"" inc/stic-class-6.php` → sin resultados.
**Verify**: `grep -n "HTTP_VERSION" inc/stic-class-6.php` → una línea, con `CURL_HTTP_VERSION_1_1`.

### Step 3: Timeouts con filtros

Añade justo después de las demás `curl_setopt`:

```php
curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, (int) apply_filters('sticpa_crm_connect_timeout', 5));
curl_setopt($curl_request, CURLOPT_TIMEOUT, (int) apply_filters('sticpa_crm_timeout', 20));
```

Y tras `curl_exec`, gestiona el fallo ANTES de decodificar:

```php
if ($result === false) {
    error_log('[sticpa] CRM call failed (' . $method . '): ' . curl_error($curl_request));
    return null;
}
```

Nota: `apply_filters` existe siempre en runtime WordPress. En los tests (que stubean WP en
`tests/bootstrap.php`) puede no existir; protege con
`function_exists('apply_filters') ? apply_filters(...) : <default>` si algún test carga esta
clase (hoy ninguno lo hace, pero es barato).

**Verify**: `php -l inc/stic-class-6.php` → sin errores.
**Verify**: `grep -c "CURLOPT_TIMEOUT\|CURLOPT_CONNECTTIMEOUT" inc/stic-class-6.php` → `2`.

### Step 4: Compresión

Añade:

```php
curl_setopt($curl_request, CURLOPT_ENCODING, '');
```

(Cadena vacía = "acepta todo lo que soportes y descomprime automáticamente".)

**Verify**: `grep -n "CURLOPT_ENCODING" inc/stic-class-6.php` → 1 línea.

### Step 5: Retirar el par ob_start/ob_end_flush

Elimina el `ob_start();` (línea 47 original) y el `ob_end_flush();` (línea 73 original) de
`call()`. No capturan nada (con `RETURNTRANSFER=1` no hay salida) y anidan buffers dentro del
`ob_start()` que ya hace el render del shortcode (`sinergiacrm-private-area.php:969`).

**Verify**: `grep -n "ob_start\|ob_end_flush" inc/stic-class-6.php` → sin resultados.
**Verify**: `php -l inc/stic-class-6.php` → sin errores.

### Step 6 (opcional, hacer solo si los pasos 1-5 verificaron): renovación proactiva de la sesión CRM

La sesión PHP del área vive 1 año, pero la del CRM caduca mucho antes. Hoy la recuperación es
reactiva: llamada fallida (`number == 11`) → `login()` → reintento = 3 round-trips que caen
siempre en **el primer tap** al volver a la app. Guarda un timestamp al lado del id:

- Al hacer login (constructor `:26-27` y retry `:77-78`): `$_SESSION['api_session_time'] = time();`
- En el constructor (`:22-24`), reutiliza `api_session_id` **solo si**
  `time() - ($_SESSION['api_session_time'] ?? 0)` es menor que
  `(int) apply_filters('sticpa_crm_session_max_age', 20 * 60)`; si es más viejo, haz login.

El retry reactivo se queda como red de seguridad.

**Verify**: `php -l inc/stic-class-6.php` → sin errores.
**Verify**: `grep -c "api_session_time" inc/stic-class-6.php` → ≥ 3.

## Test plan

No hay tests unitarios de esta clase (hace cURL real; `tests/bootstrap.php` no la carga) y NO
es objetivo de este plan añadirlos. Verificación:

1. `php -l` en cada paso (arriba).
2. La suite existente sigue verde si tienes `vendor/`: `composer test`.
3. **En staging/producción** (lo hace el operador tras el deploy): entrar al área, abrir
   Eventos, Inscripciones, Calendario y guardar el perfil. Todo debe funcionar igual pero más
   rápido. Si el hosting tiene logs, no debe aparecer `[sticpa] CRM call failed`.

## Done criteria

Machine-checkable — TODOS deben cumplirse:

- [ ] `php -l inc/stic-class-6.php` → exit 0
- [ ] `grep -c "curl_init" inc/stic-class-6.php` → 1
- [ ] `grep -n "CURL_HTTP_VERSION_1_0" inc/stic-class-6.php` → sin resultados
- [ ] `grep -c "CURLOPT_TIMEOUT" inc/stic-class-6.php` → 1
- [ ] `grep -n "ob_start" inc/stic-class-6.php` → sin resultados
- [ ] `git status --porcelain` solo muestra `inc/stic-class-6.php` (y `plans/README.md`)
- [ ] Fila 027 actualizada en `plans/README.md`

## STOP conditions

Para y reporta (no improvises) si:

- El código de `call()` no coincide con el extracto de "Current state" (deriva — quizá el
  plan 008 ya lo tocó; compara y reconcilia antes de nada).
- Tras el cambio, alguna respuesta del CRM llega vacía o truncada en pruebas (indicio de que
  el endpoint SuiteCRM no tolera HTTP/1.1 con este flujo — muy improbable, pero si pasa,
  revierte SOLO el paso 2.1 dejando `CURLOPT_HEADER, 0` + json_decode directo, y reporta).
- Te ves tentado de tocar `CURLOPT_SSL_VERIFYPEER` o las queries: eso es del plan 008.

## Maintenance notes

- Si en el futuro se paraleliza el render (varias llamadas a la vez), el handle único por
  instancia deja de valer: haría falta `curl_multi`. Este plan optimiza el caso secuencial,
  que es el 100 % del código actual.
- Reviewer: comprobar que el retry de `number == 11` sigue intacto y que `login()` (que
  también pasa por `call()`) no entra en bucle (el flag `$retry` lo impide — verificar que
  no se ha tocado).
- El timeout de 20 s es deliberadamente holgado para no cortar subidas de certificados
  (base64 de hasta 6 MB en `inc/stic-action.php:1200`). Si se ejecuta el plan 029 (que
  agrupa esas subidas), se puede bajar con el filtro sin tocar código.
