# Plan 019: Integrar (o retirar) el chrome de DataTables en los listados-tarjeta

> **Executor instructions**: paso a paso, verifica, respeta STOP conditions, actualiza `plans/README.md`.
>
> **Drift check**: `git diff --stat c2d7cff..HEAD -- inc/stic-listController.php css/custom-style.css pages/`

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW-MED
- **Depends on**: 010 (vendorizar el CSS de DataTables) — ejecutar 010 primero o a la vez
- **Category**: ui / ux
- **Planned at**: commit `c2d7cff`, 2026-07-19

## Why this matters

Los listados se pintan como TARJETAS en todos los tamaños de pantalla (custom-style §22.b), pero
DataTables sigue inicializándose con su chrome por defecto: caja "Search", info "Showing X of Y" y
paginación con el look del CSS del CDN, flotando sin integración sobre tarjetas premium. Desde
`c2d7cff` la ordenación por cabecera ya está desactivada por defecto (las cabeceras están fuera de
pantalla y atrapaban el foco), así que lo que queda de DataTables en la mayoría de listados es:
buscador + contador. Hay que decidir por listado si eso aporta, y dejar bonito lo que se quede.

## Current state

- `inc/stic-listController.php:113-150`: si `datatables.value == true`, emite un `<script>` inline
  con `jQuery('#this-list').DataTable(jsonSettings)`; desde `c2d7cff` fuerza
  `ordering: false` salvo que la página lo pida explícitamente.
- Todas las páginas de listado usan `'paging' => false` y casi todas `'searching' => true`
  (`grep "'datatables'" pages/`).
- El CSS del chrome apenas está tematizado: solo `.dataTables_filter input:focus` y
  `.paginate_button.current` (custom-style §12, ~línea 1195-1215).
- El CSS base de DataTables llega del CDN dentro del body (`stic-listController.php:12`) — lo
  vendoriza el plan 010.
- Idioma: el objeto `language` se construye por render en PHP (`:120-150`).

## Scope

**In scope**: `inc/stic-listController.php` (markup/init), custom-style.css (nueva sección numerada
para el chrome), decisión por página en `pages/list_*.php`.
**Out of scope**: sustituir DataTables por otra librería (anti-patrón §9: nada de librerías nuevas),
la carga condicional de main.js de DataTables (plan 010), N+1 de datos (plan 011).

## Steps

### Step 1: Decidir por listado si el buscador aporta

Regla acordada: listados que típicamente tienen < 8 elementos por usuario (inscripciones, pagos,
documentos, contactos del participante) NO necesitan buscador → `datatables.value = false` y se
ahorra la init entera. Listados potencialmente largos (eventos, ofertas de empleo, sesiones,
asistencias) lo mantienen. Si dudas de los volúmenes reales, pregunta al mantenedor ANTES de tocar
(STOP suave) o déjalo activado.

### Step 2: Tematizar el chrome restante

Nueva sección numerada al final de `custom-style.css` (después de la 42): estilos con tokens para
`.dataTables_wrapper`, `.dataTables_filter` (input como `.stic-field`: 44px, radio `--radius-md`,
borde `--gray-200`, focus `--shadow-glow`, label oculto visualmente con clase sr-only propia),
`.dataTables_info` (texto `--gray-500`, 0.85rem) y `.dataTables_paginate` si algún listado activa
paginación (botones como `.stic-soft-btn`). Sin `!important` salvo para ganar al CSS vendorizado de
DataTables — coméntalo.

### Step 3: Placeholder útil en el buscador

En la init (`stic-listController.php`), tras `DataTable(...)`, añade
`jQuery('.dataTables_filter input').attr('placeholder', <texto localizado 'Buscar en la lista…'>)`
pasado por el array `language` ya existente (añade clave propia) y oculta el label redundante.

### Step 4: Estado "sin resultados" de búsqueda con marca

DataTables muestra `zeroRecords` como una fila de tabla; con las tarjetas queda raro. Usa la opción
`language.zeroRecords` ya localizada + estiliza `td.dataTables_empty` en la nueva sección para que
se vea como `.stic-empty-state` compacto (icono no necesario).

## Test plan

Manual/staging en un listado largo (eventos): buscar filtra tarjetas en vivo; borrar la búsqueda las
restaura; "sin resultados" se ve integrado; el contador refleja el filtro. En un listado corto
desactivado: no hay caja de búsqueda ni scripts de DataTables. Teclado: el input de búsqueda tiene
foco visible y ningún elemento fuera de pantalla recibe foco (Tab de punta a punta).

## Done criteria

- [ ] `php -l inc/stic-listController.php` exit 0
- [ ] Chrome de DataTables usa tokens (cero hex nuevos)
- [ ] Listados cortos sin init de DataTables
- [ ] Tab de punta a punta sin focos invisibles en un listado
- [ ] Fila 019 actualizada en `plans/README.md`

## STOP conditions

- Si algún listado en producción depende de la ordenación por cabecera (usuarios reales la echan de
  menos), NO la reactives ocultas: repórtalo — la solución correcta es un control de orden visible
  para tarjetas (select "Ordenar por"), que es un mini-diseño aparte.
- Si el plan 010 no se ha ejecutado (CSS aún del CDN), tus estilos pelearán con esa hoja según
  latencia de red; ejecuta 010 primero.

## Maintenance notes

- Si se añade un listado nuevo, decide `datatables.value` con la misma regla de volumen.
- El objeto `language` se genera por render; si se centraliza (plan futuro con PERF-12/plan 020),
  mover también el placeholder.
