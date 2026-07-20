# Plan 014: Se retiran los assets muertos de producción y se corrigen las docs desfasadas

> **Executor instructions**: paso a paso, verifica, respeta STOP conditions, actualiza `plans/README.md`.
>
> **Drift check**: `git diff --stat bc3c436..HEAD -- prueba.html sinergiacrm-private-area.php PLAN.md .github/workflows/deploy-produccion.yml`

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: tech-debt / docs
- **Planned at**: commit `bc3c436`, 2026-07-19
- **Nota**: cierra `MNT-01` y `DOCS-01` de `TODO.md`.

## Why this matters

Se despliegan a producción cosas muertas: `prueba.html` (una página "Prueba de deploy"), un
`js/custom-utils.js` vacío (33 bytes) encolado en TODAS las páginas (una petición HTTP inútil por
carga), y dos helpers de debug (`debug()` imprime `print_r` en la página; `my_log_file()` escribe en
`./wordpress.log` en la raíz web) que quedan enviados aunque hoy no se llamen. Además `PLAN.md`
enlaza a un CSS borrado por UI-15, que despistará a quien lo siga.

## Current state

- `prueba.html` (raíz): página de test de deploy. El workflow excluye `**/*.md` y `**/*.backup`
  (`.github/workflows/deploy-produccion.yml:52-53`) pero NO `*.html` → `prueba.html` se sube a prod.
- `js/custom-utils.js`: 33 bytes (solo un comentario). Se registra y encola en
  `sinergiacrm-private-area.php:104-105`.
- `my_log_file()` (`sinergiacrm-private-area.php:49`) — definido, nunca llamado.
  `debug()` (`:64`) — referenciado solo en líneas comentadas (`inc/stic-formController.php:2`,
  `pages/list_stic_member_organizations.php:59`, `pages/list_stic_job_offers.php:63`,
  `pages/single_stic_attendances.php:107`, `pages/list_stic_attendances.php:90`,
  `pages/single_stic_events.php:59`).
- `PLAN.md:123` enlaza `css/stic-modern-style.css` como archivo vivo (borrado por UI-15; ahora
  `css/` solo tiene `custom-style.css`, `selectize.css`, `stic-base.css`). `PLAN.md:191` ya lo
  describe como consolidado — solo la 123 miente. README y design-system ya son correctos.

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint | `php -l sinergiacrm-private-area.php` | sin errores |
| Confirmar borrado | `test ! -e prueba.html && echo OK` | `OK` |

## Scope

**In scope**: borrar `prueba.html`; quitar el enqueue de `custom-utils` (y opcionalmente el archivo);
eliminar `my_log_file()`/`debug()` y las líneas comentadas que las referencian; corregir `PLAN.md:123`;
opcionalmente añadir `*.html` al `exclude` del deploy.
**Out of scope**: los `$_REQUEST` sin `isset` (MNT-02, ruido aparte); cualquier cambio funcional.

## Steps

### Step 1: Borrar `prueba.html` y blindar el deploy

`git rm prueba.html`. Añade `**/*.html` (o `prueba.html`) a la lista `exclude:` del workflow
(`.github/workflows/deploy-produccion.yml:52-53`) por si en el futuro reaparece un HTML de prueba.

**Verify**: `test ! -e prueba.html && echo OK` → `OK`.

### Step 2: Quitar el script vacío `custom-utils`

En `sinergiacrm-private-area.php:104-105`, elimina el `wp_register_script('custom-utils'…)` y su
`wp_enqueue_script('custom-utils')`. Borra `js/custom-utils.js` (está vacío) salvo que se quiera
conservar como punto de extensión; si se conserva, al menos NO lo encoles.

**Verify**: `grep -n "custom-utils" sinergiacrm-private-area.php` → 0 (o solo un comentario).

### Step 3: Eliminar los helpers de debug y sus referencias comentadas

Borra `my_log_file()` (`:49`) y `debug()` (`:64`) de `sinergiacrm-private-area.php`, y las líneas
comentadas que las mencionan en: `inc/stic-formController.php:2`,
`pages/list_stic_member_organizations.php:59`, `pages/list_stic_job_offers.php:63`,
`pages/single_stic_attendances.php:107`, `pages/list_stic_attendances.php:90`,
`pages/single_stic_events.php:59`.

**Verify**: `grep -rn "my_log_file\|function debug\|[^a-z]debug(" sinergiacrm-private-area.php inc/ pages/`
→ 0 resultados (ni definición ni llamadas). `php -l sinergiacrm-private-area.php` → sin errores.

### Step 4: Corregir el enlace muerto en PLAN.md

`PLAN.md:123` → apunta a `css/stic-base.css` (la capa base consolidada) y menciona UI-15, en línea
con `README.md:308` y `docs/design-system.md:35`.

**Verify**: `grep -n "stic-modern-style" PLAN.md` → solo la mención histórica de UI-15 (:191), no
como archivo vivo enlazado en :123.

## Test plan

Sin runtime que probar más allá de que el plugin siga cargando:
- `php -l` de los archivos tocados sin errores.
- En una página del área, el HTML de salida ya no incluye `custom-utils.js`.
- Búsquedas del Step 3/4 vacías.

## Done criteria

- [ ] `prueba.html` borrado y `*.html` excluido del deploy
- [ ] `custom-utils` ya no se encola
- [ ] `my_log_file`/`debug` eliminados y sin referencias (grep vacío)
- [ ] `PLAN.md:123` apunta a `css/stic-base.css`
- [ ] `php -l sinergiacrm-private-area.php` exit 0
- [ ] `TODO.md` MNT-01 marcado hecho; fila 014 actualizada en `plans/README.md`

## STOP conditions

- Alguna línea NO comentada llama a `debug()`/`my_log_file()` (contradice el análisis) → NO borres el
  helper; para y reporta esa llamada viva.
- `js/custom-utils.js` resulta NO estar vacío (tiene código real) → no lo borres; solo revisa el enqueue.

## Maintenance notes

- Para depurar en el futuro, usar `error_log()` bajo `WP_DEBUG`, no imprimir en la página.
- Reviewer: confirmar que el deploy FTPS no sube ya `prueba.html` (revisar el `exclude`).
