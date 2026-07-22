# Plan 018: Consolidar las dos capas CSS — un solo `:root`, menos duplicados, menos `!important`

> **Executor instructions**: paso a paso, verifica, respeta STOP conditions, actualiza `plans/README.md`.
>
> **Drift check**: `git diff --stat c2d7cff..HEAD -- css/custom-style.css css/stic-base.css`

## Status

- **Priority**: P2
- **Effort**: L
- **Risk**: MED (regresiones visuales por orden de cascada)
- **Depends on**: none (hacer ANTES que 016 idealmente)
- **Category**: perf / tech-debt / ui
- **Planned at**: commit `c2d7cff`, 2026-07-19

## Estado de ejecución (actualizado 2026-07-22)

- **Fase 1: HECHA y en producción.** Un solo `:root` (en `custom-style.css §1`); `stic-base.css` ya
  no define tokens. Verificado sin `var()` huérfanos y llaves balanceadas.
- **Fase 2 y 3: NO HECHAS — a propósito.** Se intentaron y se **midió a nivel de píxel** que NO son
  un batch seguro:
  - Quitar los 23 `!important` de §22.b (tarjetas de listado) **cambia el render** del listado
    (~16 000 px distintos, banda de tarjetas y=163–477). Son *portantes*: vencen a la capa base.
  - El intento **acoplado** (borrar además el bloque de tabla de `stic-base.css` 1090–1143 **y**
    quitar el `!important`) **sigue cambiando** el render (~20 000 px). Base compite desde MÁS
    sitios: `stic-base.css` líneas ~179, 1090, 1291 (dataTables), 1815, 1879.
  - Conclusión: F2/F3 están entrelazadas; hay que neutralizar TODOS los competidores de base por
    componente y verificar por diff de píxeles **y en todos los estados** (hover, modal, selectize
    —que no renderiza offline—, `?app=1`). Es QA visual iterativo, no automatizable a ciegas.

### Runbook de verificación (ya montado — úsalo para retomar)

Método barato para iterar F2/F3 con seguridad, sin navegador contra el sitio (el proxy bloquea
Chromium contra la web autenticada):

1. Login por `curl` con cookie-jar contra `…/aptest/` (usuario/contraseña de pruebas) y descargar el
   HTML autenticado de un formulario (`?internalpage=single_stic_comunica_perfil`) y un listado
   (`?internalpage=list_stic_events`).
2. Extraer el bloque `.stic-container` y renderizarlo **offline** (`file://`) con Playwright
   (`playwright-core` + Chromium de `/opt/pw-browsers`), inline de `stic-base.css` + `custom-style.css`.
3. Diff con **Pillow** (`ImageChops.difference().getbbox()`): objetivo = bbox `None` (0 px) para cada
   página y estado tras cada cambio. Si difiere, revertir esa regla.
4. Repetir por sección (§25 selectize y §12/§22 tablas primero); cada `!important` que se quite debe
   ir acompañado del borrado de la regla base que combatía, y quedar 0 px de diff.

Ojo: `!important` que solo actúan en `:hover`/modales/selectize/app-mode NO se ven en un render
estático — hay que forzar esos estados o revisarlos a mano. **No** dar por seguro un `!important`
solo porque el render en reposo no cambie.

## Why this matters

Cada página del área carga ~175 KB de CSS sin comprimir en dos hojas que compiten entre sí:
`css/stic-base.css` (1985 líneas, capa base heredada) define un sistema de tokens PARALELO que
`css/custom-style.css` (~3600 líneas, capa premium, carga la última) contradice y pisa. Resultado:
valores en conflicto que "funcionan" solo por orden de carga, reglas muertas que se parsean igual, y
567 `!important` en la capa premium que hacen cada cambio más caro. Es además el principal bloqueo
estructural para el tema oscuro (plan 016).

## Current state (conflictos verificados)

| Token/regla | stic-base.css | custom-style.css (gana) |
|---|---|---|
| `--font-family` | system stack (`:743`) | Inter (`§1`) |
| `--shadow-sm` | `:714` | `§1` (tintada de marca) |
| `--radius-lg` | `:727` | `§1` (1.1rem) |
| `.stic-profile-picture` | `:539` (max-w 180px) y `:1704` (150px) | `§14` |
| Estados `--success/--danger/--warning` | `:690-697` (sin usar por la premium) | `§1` (desde c2d7cff, en uso) |

- Orden de carga (en `sinergiacrm-private-area.php::sugar_crm_portal_style_and_script`):
  `stic-base` → `selectize` → `fullcalendar` → `custom-style` (última, gana).
- `!important`: 567 en custom-style, 87 en base (`grep -c '!important' css/*.css`).
- Hotspots de `!important` en la premium: §12 tablas/DataTables, §22.b tarjetas-listado,
  §23 botones, §25 selectize.

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Conteo !important | `grep -c '!important' css/custom-style.css css/stic-base.css` | baja tras cada fase |
| Tokens duplicados | `grep -n '^\s*--' css/stic-base.css` | lista a reconciliar |
| Referencias a un token | `grep -n 'var(--shadow-sm)' css/*.css` | antes de borrar nada |

## Scope

**In scope**: `css/stic-base.css`, `css/custom-style.css`.
**Out of scope**: `css/selectize.css` y CSS de FullCalendar (vendorizados), cualquier PHP/JS, y el
comportamiento visual (el objetivo es CERO cambio visual — esto es una refactorización).

## Method (fases pequeñas y verificables — commit por fase)

### Fase 1: Un solo sistema de tokens

1. Inventaria los `--tokens` de `stic-base.css` (`grep '^\s*--'`).
2. Para cada uno: si custom-style define el mismo nombre, borra la definición del base (la premium
   ya gana, esto solo elimina la mentira); si solo existe en base y SE USA (grep), muévelo al `:root`
   §1 de custom-style con comentario; si no se usa, bórralo.
3. Resultado: `stic-base.css` sin bloque `:root` propio (o mínimo), custom-style §1 como única
   fuente (lo que promete `docs/design-system.md` §3).

**Verify**: `grep -c ':root' css/stic-base.css` → 0 (o solo el mínimo justificado); captura visual
de home/login/formulario/listado idéntica a antes (misma ventana, mismo zoom).

### Fase 2: Reglas del base totalmente pisadas

Para cada selector duplicado en ambas hojas (empieza por `.stic-profile-picture`, `.stic-button`,
`.stic-form li`, tablas): comprueba con DevTools que la regla del base aparece TACHADA en todos los
estados (reposo/hover/focus/móvil/escritorio) y bórrala del base. Si solo se pisa parcialmente,
NO la toques en esta fase; anótala.

**Verify**: mismo pantallazo idéntico; el tamaño de `stic-base.css` baja (objetivo orientativo:
−30% o más).

### Fase 3: De-escalar `!important` en la premium (sección a sección)

Orden recomendado: §25 selectize → §12/§22.b tablas → §23 botones. En cada sección: quita el
`!important`, recarga, y si la regla pierde contra el base, es que la Fase 2 dejó viva una regla
del base que debía morir — bórrala allí en vez de devolver el `!important`. Los `!important` que
ganan a estilos DEL TEMA de WordPress (no del base) se quedan y se comentan (regla del design
system §9).

**Verify por sección**: pantallazo idéntico + `grep -c '!important' css/custom-style.css`
decreciente. Objetivo global orientativo: < 300.

## Test plan

Sin suite automatizada: matriz visual manual a 390px y 1280px sobre login, home, "Mis datos"
(formulario largo con secciones), un listado con datos, calendario, modal de borrado, cropper y
selector de participante. Compara contra capturas tomadas ANTES de empezar (haz las capturas como
paso 0 y guárdalas fuera del repo).

## Done criteria

- [ ] Un solo bloque `:root` de tokens (custom-style §1)
- [ ] Cero cambio visual en la matriz de capturas
- [ ] `!important` total reducido ≥ 40% respecto a 567+87
- [ ] `php -l` no aplica; no se tocó PHP (git diff limpio fuera de css/)
- [ ] Fila 018 actualizada en `plans/README.md`

## STOP conditions

- Si una regla del base "muerta" resulta estar viva en un estado no probado (p. ej. solo en
  `?app=1` o en el tema WP concreto de producción), restáurala y anótala; si pasa más de 5 veces,
  PARA y reporta: hace falta una pasada de CSS-coverage real (Chrome DevTools > Coverage) antes de
  seguir.
- NO borres reglas de `.stic-auth-*` sin probar el login con `?mode=password` y el flujo de enlace
  mágico (`?internalpage=stic_forgot_password`).

## Maintenance notes

- Tras este plan, la regla de oro para PRs: ningún `--token` nuevo fuera de §1, ningún `!important`
  nuevo sin comentario que diga a qué estilo del TEMA gana.
- El plan 016 (tema oscuro) se apoya en este: cuanto menos `!important`, menos contrapartes
  necesita el bloque oscuro.
