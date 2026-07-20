# Plan 010: El CSS de DataTables se sirve local y las libs pesadas solo se cargan donde se usan

> **Executor instructions**: paso a paso, verifica, respeta STOP conditions, actualiza `plans/README.md`.
>
> **Drift check**: `git diff --stat bc3c436..HEAD -- inc/stic-listController.php sinergiacrm-private-area.php`

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `bc3c436`, 2026-07-19

## Why this matters

Dos problemas: (1) `makeList` inyecta un `<link>` al CSS de DataTables desde `cdn.datatables.net`
en mitad del body de cada listado — dependencia externa (disponibilidad/privacidad) y render-blocking,
cuando el JS de DataTables ya está vendorizado local; (2) `dcms_insertar_js()` encola FullCalendar,
DataTables, Selectize, Cropper e iban.js en TODAS las páginas del área, aunque una vista de perfil
no use ninguna. Cargar cada lib solo donde hace falta reduce peso y tiempo de carga en móvil.

## Current state

- CSS de DataTables por CDN — `inc/stic-listController.php:13`:
  ```php
  $html = "<link href='https://cdn.datatables.net/1.12.1/css/jquery.dataTables.min.css' rel='stylesheet'>";
  ```
  El JS ya es local: `js/jquery.dataTables.min.js`.
- Encolado incondicional — `sinergiacrm-private-area.php`, `dcms_insertar_js()` (encola
  FullCalendar, Selectize, DataTables, Cropper, iban.js) llamado una vez desde el shortcode; los
  estilos (`sugar_crm_portal_style_and_script`) también encolan Selectize CSS y FullCalendar CSS
  siempre. El versionado de assets propios usa `filemtime()` (cache-busting automático) — imítalo.
- La página actual se resuelve por `?internalpage` (whitelist `[a-z0-9_]+`, ver
  `sticpa_resolve_page_file` en `sinergiacrm-private-area.php`). Úsalo para decidir qué encolar.
- Mapa de dependencias real:
  - FullCalendar → solo `single_stic_activities_calendar`.
  - DataTables → páginas `list_*` (las que llaman a `makeList`).
  - Selectize → páginas con multiselect (campos `multienum`/`selectMultiple`; p. ej. formularios de
    perfil/monitor). Ante la duda, encólalo en los `single_*` de formularios.
  - Cropper → páginas con input de imagen (perfil/monitor).

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint | `php -l inc/stic-listController.php && php -l sinergiacrm-private-area.php` | sin errores |
| Buscar CDN | `grep -rn "cdn.datatables.net" inc/ pages/` | 0 tras el cambio |

## Scope

**In scope**: `inc/stic-listController.php` (quitar el `<link>` CDN), `sinergiacrm-private-area.php`
(descargar el CSS de DataTables local y hacer condicional el enqueue). Añadir el archivo
`css/datatables.min.css` (vendorizado).
**Out of scope**: actualizar versiones de las libs (DEPS-01, aparte); reescribir DataTables.

## Steps

### Step 1: Vendorizar el CSS de DataTables

Descarga el CSS que hoy viene del CDN (DataTables 1.12.1, la MISMA versión que el JS local) a
`css/datatables.min.css`. Regístralo/encólalo con `wp_enqueue_style` usando `filemtime()` como
versión (patrón del repo para assets propios).

**Verify**: el archivo `css/datatables.min.css` existe y no está vacío.

### Step 2: Quitar el `<link>` CDN de `makeList`

`inc/stic-listController.php:13` → elimina esa línea (inicializa `$html = '';` en su lugar; las
líneas siguientes ya concatenan). El CSS ahora lo aporta el enqueue del Step 1/3.

**Verify**: `grep -rn "cdn.datatables.net" inc/ pages/` → 0 resultados.

### Step 3: Encolar cada lib solo en su página

En `sugar_crm_portal_style_and_script()` / `dcms_insertar_js()`, resuelve la página actual
(`$_GET['internalpage']` saneado, o la función que ya usa `sticpa_resolve_page_file`) y encola
condicionalmente:
- DataTables (JS + el nuevo CSS): solo si la página empieza por `list_` (o si es una vista que llama
  a `makeList`).
- FullCalendar (JS + CSS): solo `single_stic_activities_calendar`.
- Selectize (JS + CSS) y Cropper: solo en las páginas de formulario que los usan.
Deja siempre encolados jQuery y los propios `stic-*` (los usa todo el área).

**Verify**: `php -l sinergiacrm-private-area.php` → sin errores. En una vista de perfil, el HTML de
salida no debe incluir `main.js` de FullCalendar ni `dataTables`.

## Test plan

Manual / staging (sin suite; ver 013):
- Página de perfil: no carga FullCalendar ni DataTables (mirar el `<head>`/footer).
- Calendario: FullCalendar carga y funciona.
- Un listado: DataTables funciona con el CSS local (orden/paginación con estilo correcto).
- Un formulario con multiselect: Selectize funciona.

## Done criteria

- [ ] `css/datatables.min.css` vendorizado y encolado con `filemtime()`
- [ ] `grep -rn "cdn.datatables.net" inc/ pages/` → 0
- [ ] Enqueue condicional por `internalpage` para FullCalendar / DataTables / Selectize / Cropper
- [ ] Las páginas que usan cada lib siguen funcionando
- [ ] Fila 010 actualizada en `plans/README.md`

## STOP conditions

- No logras determinar con seguridad qué páginas usan Selectize/Cropper (riesgo de romper un
  multiselect) → sé conservador: encólalos en TODOS los `single_*` de formulario y reporta para
  afinar luego; nunca dejes una página con multiselect sin Selectize.
- El deploy FTPS excluye `css/` — comprueba `deploy-produccion.yml`; el nuevo CSS debe subir a producción.

## Maintenance notes

- Al añadir una página que use una lib pesada, hay que añadirla al mapa condicional; documenta el
  mapa junto a `dcms_insertar_js()`.
- Reviewer: confirmar que el CSS local es de la misma versión que el JS (1.12.1) para evitar desfases visuales.
