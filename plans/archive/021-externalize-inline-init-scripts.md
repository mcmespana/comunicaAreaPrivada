# Plan 021: Sacar los `<script>` inline de DataTables/FullCalendar a un archivo diferido

> **Executor instructions**: paso a paso, verifica, respeta STOP conditions, actualiza `plans/README.md`.
>
> **Drift check**: `git diff --stat c2d7cff..HEAD -- inc/stic-listController.php pages/single_stic_activities_calendar.php js/`

## Status

- **Priority**: P3
- **Effort**: S-M
- **Risk**: LOW
- **Depends on**: coordinar con 019 (toca la misma init de DataTables) — ejecutar juntos idealmente
- **Category**: perf / tech-debt
- **Planned at**: commit `c2d7cff`, 2026-07-19

## Why this matters

Cada listado emite un `<script>` inline en mitad del body con la init de DataTables y su objeto
`language` completo repetido; el calendario emite otro con toda su configuración y los eventos
embebidos. Ese JS no se cachea, no se puede diferir y se re-parsea en cada página. Moverlo a un
archivo estático dirigido por `data-*` lo hace cacheable y deja el HTML más ligero.

## Current state

- `inc/stic-listController.php` (~:120-155): construye `language` (28 claves localizadas) y emite
  `<script>document.addEventListener('DOMContentLoaded', … DataTable(json) …)</script>` POR LISTADO.
- `pages/single_stic_activities_calendar.php` (~:116-155): `<script>` inline con
  `new FullCalendar.Calendar(...)`, `events: $availableSessionsJson` (JSON embebido) y handlers.
- Ya existe el mecanismo para pasar datos a JS: `wp_localize_script('sugarcrm-own', 'stic_script_vars',
  getSticScriptVars())` en `sinergiacrm-private-area.php::dcms_insertar_js` y el archivo
  `inc/stic-script-vars.php`.
- Los scripts propios se encolan con versión `filemtime` (patrón `$jsver`, mismo archivo).

## Scope

**In scope**: `inc/stic-listController.php`, `pages/single_stic_activities_calendar.php`, un archivo
nuevo `js/stic-init.js`, `inc/stic-script-vars.php` (añadir el objeto language una sola vez),
`sinergiacrm-private-area.php` (enqueue del nuevo archivo).
**Out of scope**: qué opciones tiene DataTables (plan 019), los datos del calendario (plan 011).

## Steps

### Step 1: `js/stic-init.js`

Archivo nuevo (sin dependencias más allá de jQuery/FullCalendar ya cargados) que en
`DOMContentLoaded`:
- para cada `table#this-list[data-dt-settings]`: `JSON.parse` del atributo, mezcla el objeto
  `stic_script_vars.dtLanguage` y llama a `jQuery(el).DataTable(settings)`;
- para `#calendar[data-fc-settings]`: `JSON.parse`, añade el `eventClick` (redirección con
  `internalpage` + `id`, copiar la actual) y `new FullCalendar.Calendar(el, settings).render()`.

### Step 2: Emitir atributos en vez de scripts

- `stic-listController.php`: sustituye el bloque `<script>` por
  `data-dt-settings='<?= esc_attr(json_encode($jsonSettings)) ?>'` en el `<table>`; mueve el array
  `language` a `getSticScriptVars()` como `dtLanguage` (se localiza una única vez).
- `single_stic_activities_calendar.php`: `data-fc-settings` con el array de opciones (incluidos los
  eventos), sin el `<script>`.

### Step 3: Enqueue

En `dcms_insertar_js`: `wp_register_script('stic-init', …'js/stic-init.js', array('multiselect',
'datatables', 'fullcalendar'), $jsver('js/stic-init.js'), true)`.

**Verify**: `php -l` de los PHP tocados; `node --check js/stic-init.js`; un listado y el calendario
funcionan igual (buscar, click en evento navega al detalle).

## Test plan

Manual: listado con buscador filtra; calendario pinta sesiones (rojas) y eventos (azules), el click
navega al detalle correcto; segunda visita sirve `stic-init.js` desde caché (pestaña Network, 304 o
memory cache); no queda ningún `<script>` inline de init (`grep -rn "DataTable(" pages/ inc/` → 0
fuera de js/).

## Done criteria

- [ ] Cero `<script>` inline de init en `inc/` y `pages/` (greps a 0)
- [ ] Objeto language de DataTables definido UNA vez (en script-vars)
- [ ] `php -l` y `node --check` exit 0
- [ ] Fila 021 actualizada en `plans/README.md`

## STOP conditions

- Si alguna página pasa callbacks JS dentro de `jsonSettings` (funciones no serializables a JSON),
  PARA y lista cuáles: necesitan un registro de callbacks por nombre en stic-init.js, decisión de
  diseño aparte.

## Maintenance notes

- Nuevas páginas con widgets JS deben seguir este patrón `data-*` + stic-init.js, no `<script>` inline.
