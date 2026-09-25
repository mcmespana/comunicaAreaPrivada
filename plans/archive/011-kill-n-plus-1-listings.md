# Plan 011: Los listados, el calendario y el selector dejan de hacer N+1 al CRM

> **Executor instructions**: paso a paso, verifica, respeta STOP conditions, actualiza `plans/README.md`.
>
> **Drift check**: `git diff --stat 337ec6a..HEAD -- pages/ inc/stic-class-6.php inc/stic-calendar.php`

## Status

- **Priority**: P1
- **Effort**: L
- **Risk**: MED
- **Depends on**: plans/013-verification-baseline.md (recomendado antes de refactorizar rutas de dinero/datos)
- **Category**: perf
- **Planned at**: commit `bc3c436`, 2026-07-19 — **referencias re-verificadas y corregidas contra `337ec6a` el 2026-08-09** (el N+1 del calendario se movió de la página a `inc/stic-calendar.php`)

## Why this matters

Cada carga de página hace 3-6 llamadas SÍNCRONAS al CRM (~0,5-2s cada una); el cuello de botella
es siempre la API. Varios listados amplifican esto con N+1: una llamada por fila para enriquecer
datos. El peor caso es triple-anidado (`1 + N + N×M`) en Sesiones y Calendario: 10 inscripciones ×
3 eventos = 40+ round-trips bloqueantes para una sola página.

## Current state

- **Triple-anidado** — `pages/list_stic_sessions.php:74-110`: `getRelatedElementsForLoggedUser`
  (inscripciones, :74) → `foreach` con una llamada por inscripción (eventos, :93) → `foreach`
  anidado con una llamada por evento (sesiones, :110). Hay dedupe que debe preservarse.
- **Triple-anidado del CALENDARIO (referencia corregida 2026-08-09)** — ya NO está en
  `pages/single_stic_activities_calendar.php` (esa página tiene una sola llamada): vive en
  `inc/stic-calendar.php::sticpa_gather_calendar_data` (`:233-399`): inscripciones (:256) →
  una llamada por inscripción (eventos, :274) → una llamada por evento (sesiones, :300) →
  otra llamada por inscripción (asistencias, :335) → todos los eventos de la ventana (:370).
  Coste en frío `2 + 2N + N×M`. Está MITIGADO por un transient de 300 s
  (`:239-244`, `:395-397`, clave `sticpa_calendar_cache_key()`), que
  `inc/stic-action.php:460-462` borra entero en cada inscripción — así que el pico se paga
  justo después de inscribirse, y también lo paga la HOME (`pages/single_stic_home.php:116`
  consume el mismo helper). Al colapsar los niveles, preserva: el dedupe por `$sessions[$sid]`,
  el fallback de asistencia por nombre único (`:324-327`, `:352-357`) y el filtrado de
  inscripciones `cancelled` (`:269`). Considera además subir el TTL (p. ej. 30 min vía filtro
  `sticpa_calendar_cache_ttl`): la invalidación explícita ya cubre los cambios del propio
  usuario. OJO: el plan 029 (step 1) hace que el guard de inscripciones LEA de este helper —
  si cambias la forma de `registered_events`, revisa `prefix_user_active_event_ids`.
- **N+1 por fila** — `pages/list_stic_payments.php:89-104` (una llamada por pago para el nombre del
  compromiso; rama else en :120-141 anida pagos dentro de compromisos),
  `pages/list_stic_attendances.php:74-96` (una por inscripción), y
  `pages/single_stic_profile_selection.php:60-80` (una por contacto relacionado para su nombre).
- **Mecanismo disponible**: `getRecordsModule` (`inc/stic-class-6.php:411-437`) ya usa
  `related_module_link_name_to_fields_array` para traer campos de un módulo relacionado EN la misma
  llamada. Es la herramienta para colapsar el N+1: pedir el campo relacionado (nombre del
  compromiso, etc.) en la primera consulta en vez de una llamada por fila.
- **Transporte** — `inc/stic-class-6.php:48,69` abre/cierra cURL por llamada y usa HTTP/1.0 (`:52`):
  sin keep-alive, cada round-trip paga handshake TLS. (El plan 008 sube a HTTP/1.1.)

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint | `for f in pages/list_stic_sessions.php pages/list_stic_payments.php pages/list_stic_attendances.php pages/single_stic_activities_calendar.php pages/single_stic_profile_selection.php inc/stic-class-6.php; do php -l "$f"; done` | todas sin errores |

## Scope

**In scope**: las 5 páginas citadas; opcionalmente `inc/stic-class-6.php` (keep-alive del handler cURL).
**Out of scope**: cache de lectura por página (PERF-08, aparte); cambiar el formato de salida de los listados.

## Steps

### Step 1: Colapsar el N+1 por fila con `related_module_link_name_to_fields_array`

Para Pagos (`list_stic_payments.php:89-104`), Asistencias (`list_stic_attendances.php:74-96`) y
selección de participante (`single_stic_profile_selection.php:60-80`): en la PRIMERA consulta,
solicita el campo del módulo relacionado (nombre del compromiso / del contacto) usando
`related_module_link_name_to_fields_array`, siguiendo la forma de `getRecordsModule`
(`inc/stic-class-6.php:411-437`). Elimina la llamada por fila. Preserva la alineación por índice de
fila (p. ej. `list_stic_payments.php:103`).

**Verify**: `grep -n "getRelatedElementsForLoggedUser" pages/list_stic_payments.php` — ya no debe
aparecer dentro de un `foreach` de filas.

### Step 2: Colapsar el triple-anidado (Sesiones y Calendario) a 3 consultas por nivel

En `list_stic_sessions.php:74-110` y en `inc/stic-calendar.php::sticpa_gather_calendar_data`
(`:233-399` — NO en la página del calendario, ver "Current state"): en vez de una llamada por
inscripción y por evento, recoge primero todos los ids de inscripción, luego una consulta
batch para todos los eventos de esos ids, luego una consulta batch para todas las sesiones de
esos eventos (3 llamadas por nivel en total). En el gather del calendario, preserva: dedupe
por `$sessions[$sid]`, fallback de asistencia por nombre único, filtrado de `cancelled`, y la
FORMA del array devuelto (`sessions` / `attendance_by_session` / `registered_events` /
`available_events`) — el plan 029 hace que el guard de inscripciones lea `registered_events`.
Opcional en el mismo paso: subir el TTL del transient (filtro `sticpa_calendar_cache_ttl`) a
30 min, apoyándose en que `inc/stic-action.php:460-462` ya invalida al inscribirse.

**Verify**: leyendo, no quedan llamadas al CRM dentro de bucles anidados en
`list_stic_sessions.php` ni en `sticpa_gather_calendar_data`.
**Verify** (si hay vendor/): `composer test` → verde.

### Step 3 (opcional, si el plan 008 ya subió a HTTP/1.1): keep-alive del handler cURL

En `inc/stic-class-6.php`, guarda el handle de cURL en el singleton y usa `curl_reset()` por llamada
en vez de `curl_init`/`curl_close` (`:48,69`), reutilizando la conexión TLS dentro de una misma
petición. Cuida el parseo de cabeceras (`explode("\r\n\r\n", …)`, :71).

**Verify**: `php -l inc/stic-class-6.php` → sin errores; los flujos siguen funcionando en staging.

## Test plan

Requiere el plan 013 (tests de caracterización de estas páginas) idealmente ANTES. Manual/staging:
- Contar las llamadas al CRM (logs) al abrir Sesiones/Calendario con varias inscripciones: debe caer
  de `1+N+N×M` a un número constante pequeño.
- Pagos/Asistencias/selección: el contenido mostrado (nombres relacionados) es idéntico al anterior.
- Ningún listado pierde filas ni desalinea columnas.

## Done criteria

- [ ] Todas las páginas del scope pasan `php -l`
- [ ] No hay llamadas al CRM dentro de bucles por fila/anidados en las 5 páginas
- [ ] El contenido renderizado es equivalente al de antes (mismos nombres/valores/orden)
- [ ] Fila 011 actualizada en `plans/README.md`

## STOP conditions

- `related_module_link_name_to_fields_array` no soporta el campo relacionado que necesitas → para y
  reporta; puede requerir cambio en `SugarRestApiCall`.
- Cambiar la agregación altera los datos mostrados (faltan/ sobran filas) → revierte esa página y reporta.
- No hay tests (plan 013 sin hacer) y el riesgo en rutas de dinero (pagos) es alto → escribe al menos
  una comprobación manual documentada antes de tocar `list_stic_payments.php`.

## Maintenance notes

- Al añadir columnas relacionadas nuevas a un listado, pídelas en la consulta batch, no con una
  llamada por fila.
- Reviewer: verificar el conteo de llamadas al CRM antes/después en staging; es el criterio real de éxito.
- La cache de lectura por página (PERF-08) es el siguiente salto y compone con esto.
