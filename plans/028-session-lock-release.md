# Plan 028: Soltar el candado de la sesión PHP para que las peticiones del mismo usuario no se pongan en cola

> **Executor instructions**: Sigue este plan paso a paso. Ejecuta cada comando de
> verificación y confirma el resultado esperado antes de pasar al siguiente paso.
> Si ocurre algo de la sección "STOP conditions", para y reporta — no improvises.
> Al terminar, actualiza la fila de este plan en `plans/README.md`.
>
> **Drift check (ejecutar primero)**:
> `git diff --stat 337ec6a..HEAD -- sinergiacrm-private-area.php inc/stic-action.php`
> Si algún archivo in-scope cambió desde que se escribió este plan, compara los
> extractos de "Current state" con el código vivo; si no coinciden, STOP.

## Status

- **Priority**: P1
- **Effort**: S-M
- **Risk**: MED (cerrar la sesión demasiado pronto pierde escrituras EN SILENCIO)
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `337ec6a`, 2026-08-09
- **Estado**: **PARCIAL** — steps 1-3 en `122289f` (2026-08-12). Falta el cierre de
  sesión DURANTE el render, que estaba fuera de alcance a propósito (ver
  "Decisión deliberada" y "Maintenance notes"): sigue siendo el siguiente paso.

## Why this matters

PHP guarda las sesiones en ficheros y mantiene un **lock exclusivo** sobre el fichero de
sesión desde `session_start()` hasta que el script termina (o hasta `session_write_close()`).
Este plugin arranca la sesión en `init` prioridad 1 en TODAS las peticiones y **nunca** llama
a `session_write_close()` (0 ocurrencias en el repo, verificado). Consecuencia: todas las
peticiones del mismo usuario se **serializan**. La más dolorosa: la foto de perfil se sirve
por un endpoint PHP con sesión (`admin-post.php?action=stic_profile_photo`), así que la
imagen **espera a que el HTML termine** (y el HTML puede estar 5-30 s haciendo llamadas al
CRM) en vez de cargar en paralelo. Lo mismo con un segundo tap mientras la página anterior
aún carga: no espera "su" latencia, espera la suma de las dos.

Este plan libera el lock donde es seguro: en los endpoints de solo-lectura inmediatamente, y
en el render de páginas justo antes de empezar a llamar al CRM (tras consolidar las
escrituras). También deja de reenviar la cookie de sesión en cada respuesta.

## Current state

- `sinergiacrm-private-area.php:1067-1121` — `sugar_crm_portal_start_session()`: cuelga de
  `add_action('init', ..., 1)`, hace `session_start()` (`:1099`) y **reenvía la cookie de
  sesión en cada petición** (`:1104-1120`) para implementar la caducidad deslizante de 1 año.
- `session_write_close` → **0 ocurrencias** en el repo.
- `sinergiacrm-private-area.php:946-983` — `sugar_crm_portal_index()`: pinta el menú y hace
  `include` de la página de `pages/`. Es DONDE ocurren las llamadas al CRM del render.
- Endpoint de foto — `inc/stic-action.php:949-1016` (`prefix_admin_stic_profile_photo`):

```php
function prefix_admin_stic_profile_photo()
{
    if (empty($_SESSION['scp_user_id'])) {
        status_header(403);
        exit;
    }
    $userId = $_SESSION['scp_user_id'];
    $cachePath = sticpa_profile_photo_cache_path($userId);
    // ... (puede llamar al CRM y redimensionar; luego readfile + exit)
```

- Endpoint de descarga de documentos — `inc/stic-action.php`, función `download_document()`
  (busca `function download_document` para la línea exacta) — sirve el binario y sale.

### Escrituras en `$_SESSION` DURANTE el render (censo verificado por grep)

Estas son las únicas escrituras que ocurren dentro del render de páginas (no en login ni en
handlers POST, que son peticiones aparte):

1. `pages/single_stic_profile_selection.php:108-109`:
   ```php
   $_SESSION['scp_available_profiles'] = $availableContacts;
   $_SESSION['scp_is_familia'] = count($availableContacts) > 0;
   ```
2. `inc/stic-comunica-roles.php:84-85` (dentro de `sticpa_store_comunica_role`), alcanzable
   durante el render vía `sticpa_get_comunica_role()` (`:92-100`, detección perezosa que
   escribe `scp_role` si no estaba) — y `menu()` la llama en cada página (`menu.php:15`).
3. `inc/stic-class-6.php:27` y `:78` — `$_SESSION['api_session_id']` se escribe cuando el
   cliente CRM hace login (primera petición o sesión CRM caducada). Esto puede pasar EN MEDIO
   del render.

La n.º 3 es la razón por la que NO se puede cerrar la sesión incondicionalmente antes del
render sin perder el `api_session_id` renovado (se re-loguearía en cada petición — más lento,
no menos). La estrategia del Step 2 lo tiene en cuenta.

Convención del repo: helpers con prefijo `sticpa_`, comentarios en español explicando el
porqué (ver `sugar_crm_portal_start_session` como ejemplar).

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint | `php -l sinergiacrm-private-area.php && php -l inc/stic-action.php` | sin errores |
| Lint global | `find . -name "*.php" -not -path "./vendor/*" -not -path "./.agents/*" -not -path "./.claude/*" -print0 \| xargs -0 -n1 -P4 php -l >/dev/null` | exit 0 |
| Tests (con vendor/) | `composer test` | verdes (SessionTest no toca nada de esto) |

## Scope

**In scope**:
- `inc/stic-action.php` (solo `prefix_admin_stic_profile_photo` y `download_document`)
- `sinergiacrm-private-area.php` (solo `sugar_crm_portal_start_session`)

**Out of scope** (NO tocar):
- `sugar_crm_portal_index()` y el render de páginas — ver "Decisión deliberada" abajo.
- Los handlers `admin_post_*` que escriben sesión (selector de participante, login…).
- `inc/stic-magic-login.php` (escribe sesión al establecerla; es su trabajo).
- Cambiar el TTL de 1 año o el mecanismo de ventana deslizante.

### Decisión deliberada: NO cerrar la sesión en el render (en esta iteración)

Cerrar la sesión al principio de `sugar_crm_portal_index()` liberaría el lock durante las
llamadas al CRM del render, pero perdería la escritura de `api_session_id` si el CRM renueva
sesión a mitad de página (censo n.º 3): el área haría un login al CRM **en cada petición**, que
es peor que el lock. Resolverlo bien exige reabrir/cerrar sesión alrededor de esa escritura en
`inc/stic-class-6.php`, y eso conviene hacerlo DESPUÉS del plan 027 (que ya toca ese archivo)
y con medición. Queda anotado en "Maintenance notes" como siguiente paso. Este plan captura
la mayor parte del beneficio (la foto y las descargas dejan de serializar) con riesgo mínimo.

## Git workflow

- Rama: la que indique el operador; si no, `advisor/028-session-lock`.
- Commits estilo repo, p. ej. `perf(sesión): la foto y las descargas ya no bloquean al resto de peticiones`.
- NO push/PR salvo instrucción del operador.

## Steps

### Step 1: Soltar el lock en el endpoint de la foto

En `inc/stic-action.php`, función `prefix_admin_stic_profile_photo` (`:949`), justo después
de capturar el id (línea `$userId = $_SESSION['scp_user_id'];`), añade:

```php
    // Solo-lectura: soltamos el lock de sesión YA, para que esta petición no
    // bloquee (ni sea bloqueada por) el HTML que se está renderizando en paralelo.
    session_write_close();
```

Este handler no vuelve a escribir en `$_SESSION` (verifícalo leyendo la función entera antes
de tocar). OJO: si el CRM renueva sesión dentro de `get_image` (`inc/stic-class-6.php:78`
escribe `$_SESSION['api_session_id']`), esa escritura se perderá — es aceptable: la siguiente
petición HTML re-logueará una vez. Es el mismo comportamiento que hoy tiene una sesión CRM
caducada.

**Verify**: `php -l inc/stic-action.php` → sin errores.
**Verify**: `grep -n "session_write_close" inc/stic-action.php` → 1 resultado dentro de
`prefix_admin_stic_profile_photo`.

### Step 2: Soltar el lock en la descarga de documentos

En `inc/stic-action.php`, localiza `function download_document` (sirve el binario del
documento). Igual que el paso 1: tras la última LECTURA de `$_SESSION` de la función y antes
de la llamada al CRM que trae el documento, añade `session_write_close();` con el mismo
comentario. Si el plan 003 (seguridad de documentos) ya añadió comprobaciones de sesión,
colócalo DESPUÉS de todas ellas.

**Verify**: `php -l inc/stic-action.php` → sin errores.
**Verify**: `grep -c "session_write_close" inc/stic-action.php` → `2`.

### Step 3: Reenviar la cookie deslizante como mucho una vez al día

En `sinergiacrm-private-area.php`, `sugar_crm_portal_start_session()` (`:1101-1120`): el
bloque que reenvía la cookie en cada petición. Envuélvelo para que solo se ejecute si hace
más de un día del último reenvío:

```php
    // Ventana DESLIZANTE: reenviar la cookie en cada petición era ruido (rompe
    // cachés y añade cabeceras). Con reenviarla una vez al día la ventana de un
    // año sigue siendo efectivamente deslizante.
    $lastRefresh = isset($_SESSION['sticpa_cookie_refreshed']) ? (int) $_SESSION['sticpa_cookie_refreshed'] : 0;
    if (!headers_sent() && (time() - $lastRefresh) > DAY_IN_SECONDS) {
        $_SESSION['sticpa_cookie_refreshed'] = time();
        // ... (el setcookie existente, tal cual está)
    }
```

Mantén el `setcookie` interior EXACTAMENTE como está (parámetros y rama PHP < 7.3 incluida).

**Verify**: `php -l sinergiacrm-private-area.php` → sin errores.
**Verify**: `grep -n "sticpa_cookie_refreshed" sinergiacrm-private-area.php` → 2 resultados.

## Test plan

- `composer test` (si hay `vendor/`): la suite existente (`SessionTest`, `ThemeTest`,
  `MagicLinkTest`, `OtpTest`) debe seguir verde — ninguna toca estos caminos.
- No se piden tests nuevos: el efecto (paralelismo entre peticiones) no es observable en
  unit tests con los stubs actuales. La verificación real es en staging:
  1. Con sesión iniciada y la caché de foto borrada (`wp-content/uploads/stic-uploads/`),
     abrir una página con foto: la imagen debe llegar ANTES de que termine una página lenta
     abierta en otra pestaña (antes llegaban estrictamente en serie).
  2. Login clásico y por enlace mágico siguen funcionando (la cookie se emite en el primer
     acceso: `$_SESSION['sticpa_cookie_refreshed']` no existe → se reenvía).

## Done criteria

- [ ] `php -l` exit 0 en los dos archivos
- [ ] `grep -c "session_write_close" inc/stic-action.php` → 2
- [ ] `grep -rn "session_write_close" pages/ inc/stic-class-6.php` → sin resultados (no se
      ha cerrado sesión en ningún sitio con escrituras posteriores)
- [ ] `grep -c "sticpa_cookie_refreshed" sinergiacrm-private-area.php` → 2
- [ ] `git status --porcelain` solo muestra los 2 archivos in-scope (y `plans/README.md`)
- [ ] Fila 028 actualizada en `plans/README.md`

## STOP conditions

- `prefix_admin_stic_profile_photo` o `download_document` escriben en `$_SESSION` en alguna
  rama que no esté en el censo de "Current state" → STOP y reporta (el cierre perdería esa
  escritura en silencio).
- El bloque de la cookie en `sugar_crm_portal_start_session` no coincide con el extracto
  (deriva) → STOP.
- Te ves añadiendo `session_write_close()` en `sugar_crm_portal_index()` o en páginas de
  `pages/` → STOP: está explícitamente fuera de alcance (ver "Decisión deliberada").

## Maintenance notes

- **Siguiente paso natural** (plan futuro, tras el 027): cerrar la sesión al inicio de
  `sugar_crm_portal_index()` para liberar el lock durante el render. Requiere: (1) forzar
  antes la detección perezosa del rol (`sticpa_get_comunica_role()`), (2) excluir
  `single_stic_profile_selection` (escribe perfiles en sesión), y (3) que
  `inc/stic-class-6.php` reabra la sesión (`session_start()` + escribir + `session_write_close()`)
  cuando renueve `api_session_id`. Con esas tres piezas es seguro; sin ellas, no.
- Reviewer: buscar cualquier `$_SESSION[...] =` que se ejecute después de un
  `session_write_close()` en el mismo flujo — es la única forma de romper esto y es silenciosa.
- Si el hosting usara un handler de sesiones sin lock (Redis/Memcached), este plan sería
  inocuo pero innecesario; con ficheros (lo normal en hosting compartido) es donde gana.
