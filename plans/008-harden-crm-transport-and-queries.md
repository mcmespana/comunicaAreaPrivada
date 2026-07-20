# Plan 008: El transporte al CRM verifica TLS y las queries de login van escapadas

> **Executor instructions**: paso a paso, verifica, respeta STOP conditions, actualiza `plans/README.md`.
>
> **Drift check**: `git diff --stat bc3c436..HEAD -- inc/stic-class-6.php`

## Status

- **Priority**: P0
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `bc3c436`, 2026-07-19
- **Nota**: corresponde a los ítems ya trackeados en `TODO.md` como **SEC-04** (TLS) y **SEC-02**
  (escapar queries). No existían planes; este es el plan de ambos.

## Why this matters

El cliente cURL desactiva la verificación TLS (`CURLOPT_SSL_VERIFYPEER = 0` y `VERIFYHOST` sin
fijar): todo el tráfico con el CRM — que incluye contraseñas del área privada en claro — es
susceptible de man-in-the-middle. Además, el login concatena `username`/`password` sin escapar en la
query del CRM (inyección SuiteQL): un valor con comilla altera la consulta y permite bypass/enumeración.

## Current state

- **TLS** — `inc/stic-class-6.php:52,54` dentro de `call()`:
  ```php
  curl_setopt($curl_request, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
  curl_setopt($curl_request, CURLOPT_HEADER, 1);
  curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, 0);   // ← verificación desactivada
  // (no hay CURLOPT_SSL_VERIFYHOST → por defecto también queda sin verificar host)
  ```
- **Query de login sin escapar** — `PortalLogin` (`inc/stic-class-6.php:128`):
  ```php
  'query' => "stic_pa_username_c = '{$username}' AND  stic_pa_password_c = '{$password}'",
  ```
  Mismo patrón sin escapar en `getUserExists` (~:300) y `getUserInformationByUsername` (~:322).
  Otros métodos (`getContactByUsername`, `searchContacts`, `getContactByEmail`, `PortalLoginByToken`)
  ya hacen un saneo parcial quitando comillas/backslash — a imitar/unificar.

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint | `php -l inc/stic-class-6.php` | `No syntax errors detected` |

## Scope

**In scope**: `inc/stic-class-6.php`.
**Out of scope**: eliminar el login por contraseña o hashear contraseñas (SEC-03, cambio de calado
aparte); el flujo de login del front (`sinergiacrm-private-area.php`).

## Steps

### Step 1: Activar la verificación TLS

`inc/stic-class-6.php:54` y añadir VERIFYHOST:
```php
curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, 1);
curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, 2);
```
Sube también, si es viable, a HTTP/1.1 (`CURL_HTTP_VERSION_1_1`) — necesario para el keep-alive del
plan 011, y sin coste aquí.

**Verify**: `php -l inc/stic-class-6.php` → sin errores.
**Verify**: en un entorno con el CRM accesible, un login/consulta sigue funcionando (el certificado
del CRM debe ser válido; si es autofirmado, ver STOP conditions).

### Step 2: Helper de escape para valores dentro de las queries

Añade un método privado que escape un valor para incrustarlo en la query del CRM (mínimo: escapar la
comilla simple y el backslash; opcionalmente rechazar `%`/`_` de wildcard si el campo es de
igualdad):
```php
private function escapeQueryValue($v) {
    return str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $v);
}
```
Unifica aquí lo que hoy hacen a mano los métodos que ya sanean parcialmente.

**Verify**: `php -l inc/stic-class-6.php` → sin errores.

### Step 3: Aplicar el escape en PortalLogin / getUserExists / getUserInformationByUsername

`PortalLogin` (`:128`):
```php
$u = $this->escapeQueryValue($username);
$p = $this->escapeQueryValue($password);
'query' => "stic_pa_username_c = '{$u}' AND stic_pa_password_c = '{$p}'",
```
Haz lo equivalente en `getUserExists` (~:300) y `getUserInformationByUsername` (~:322), y en los que
ya sanean parcialmente sustituye su saneo ad-hoc por `escapeQueryValue` para dejar un único patrón.

**Verify**: `grep -n "escapeQueryValue" inc/stic-class-6.php` → ≥3 usos en los métodos de login.
**Verify**: un username con comilla (`o'brien`) no rompe la consulta ni permite alterar su lógica.

## Test plan

Manual / staging (sin suite; ver 013):
- Login correcto e incorrecto siguen comportándose igual con usuarios normales.
- Un intento con `username = ' OR '1'='1` NO devuelve un registro (la comilla queda escapada).
- Con el certificado válido del CRM, las llamadas funcionan con `VERIFYPEER=1`.

## Done criteria

- [ ] `php -l inc/stic-class-6.php` exit 0
- [ ] `CURLOPT_SSL_VERIFYPEER = 1` y `CURLOPT_SSL_VERIFYHOST = 2`
- [ ] `PortalLogin`, `getUserExists`, `getUserInformationByUsername` escapan sus valores
- [ ] `TODO.md` SEC-02 y SEC-04 marcados como hechos (referenciando este plan)
- [ ] Fila 008 actualizada en `plans/README.md`

## STOP conditions

- El CRM usa un certificado autofirmado o una cadena que falla la verificación → NO vuelvas a poner
  `VERIFYPEER=0`; para y reporta (la solución es instalar el CA correcto / bundle, no desactivar TLS).
- Escapar rompe algún login legítimo con caracteres especiales → revisa el escape y reporta.
- Cambiar a HTTP/1.1 altera el parseo de cabeceras (`explode("\r\n\r\n", …)`, `:71`) → si aparece,
  déjalo en 1.0 y trata el keep-alive en el plan 011.

## Maintenance notes

- La query del CRM es SuiteQL/estilo SQL: el escape aquí es defensivo. Lo ideal a futuro es un
  binding parametrizado si la API lo soporta; documenta que hoy es escape manual.
- Reviewer: confirmar que ningún método construye query con datos externos sin `escapeQueryValue`.
