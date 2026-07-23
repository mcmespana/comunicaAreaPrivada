# Plan 022: Rediseño fino del calendario MES en móvil (dots + tap-tooltip)

> **Executor instructions**: paso a paso, verifica con el arnés de render, respeta STOP conditions,
> actualiza `plans/README.md`. **Coordina con el mantenedor**: el calendario (`inc/stic-calendar.php`,
> `pages/single_stic_activities_calendar.php`, la init de FullCalendar en `js/stic-init.js`) está en
> evolución activa; alinéate antes de tocar su lógica de datos/colores.

## Status
- **Priority**: P2 · **Effort**: M · **Risk**: MED (toca render de FullCalendar) · **Depends on**: —
- **Category**: ui / mobile · **Planned at**: commit tras la pasada móvil 2026-07-23

## Contexto (qué YA se hizo en esta pasada)
La rejilla de Mes en móvil "es un drama". Se resolvió lo de mayor impacto y bajo riesgo:
- **En móvil el calendario abre en AGENDA (`listMonth`)** por defecto (`js/stic-init.js`, `matchMedia`);
  la lista es clara y no se corta. Escritorio sigue en Mes.
- Título limpio **"Octubre 2025"** (sin "De"): `datesSet` en `stic-init.js` + quitar `text-transform:
  capitalize`.
- Toolbar estable (título en su fila, botones que no saltan), menos bordes (`.fc` a sangre en móvil),
  Agenda sin cortes (tabla fluida, textos que envuelven) — todo en `custom-style.css §45`.

Lo que queda (este plan): que la **vista Mes en móvil** también sea buena, con la idea del mantenedor:
**no mostrar el título del evento en la celda; mostrar solo un punto/barrita de color; al tocar, un
tooltip con el nombre + botón "Ir al evento".**

## Por qué NO se hizo ya con CSS
Se intentó y se midió: ocultar títulos y convertir los eventos en barras/puntos **a pelo con CSS**
pelea con los internals de FullCalendar (el "dot" es `width:0;height:0;border:<color>`; los eventos
de rango son `block-event` con otra estructura; `contentHeight:auto` deja celdas altas). El resultado
era frágil e inconsistente (las sesiones con hora desaparecían). La forma correcta es **JS
(`eventContent`)**, controlando el markup nosotros.

## Enfoque recomendado (eventContent)
En `js/stic-init.js`, dentro del bloque FullCalendar, añadir `fcSettings.eventContent`:

```js
fcSettings.eventContent = function (arg) {
    // Solo la rejilla de Mes: en Agenda/otros dejar el render por defecto.
    if (arg.view.type.indexOf('dayGrid') !== 0) { return undefined; }
    var color = arg.event.backgroundColor || arg.event.borderColor || '';
    var wrap = document.createElement('div');
    wrap.className = 'stic-fc-chip';
    var bar = document.createElement('span');
    bar.className = 'stic-fc-chip-bar';
    if (color) { bar.style.background = color; }
    var title = document.createElement('span');
    title.className = 'stic-fc-chip-title';
    title.textContent = arg.event.title;
    wrap.appendChild(bar); wrap.appendChild(title);
    return { domNodes: [wrap] };
};
```

CSS (nueva sub-sección en `§45`):
- **Escritorio**: `.stic-fc-chip{display:flex;align-items:center;gap:5px}` · `.stic-fc-chip-bar{width:7px;height:7px;border-radius:50%;flex:none}` · `.stic-fc-chip-title{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}` (punto + título, como ahora).
- **Móvil (≤767)**: `.stic-fc-chip-title{display:none}` · `.stic-fc-chip-bar{flex:1;height:6px;border-radius:999px;margin:1px 3px}` → **barra horizontal de color, sin texto**.

### Tooltip al tocar + "Ir al evento"
- Añadir `fcSettings.eventClick` (ya existe): en móvil, en vez de navegar directo, abrir un popover
  anclado al evento con el **nombre + estado** (ya viene en `extendedProps.tooltip`) y un botón
  **"Ir al evento"** que hace el `window.location.assign(props.href || …)` actual. En escritorio,
  mantener el hover con `title` (ya está en `eventDidMount`).
- Reutilizar el patrón de posicionamiento de `js/stic-ui.js::positionInfoTip` (fixed + clamp al
  viewport) para que el popover no se corte. Cerrar con tap fuera / Escape / scroll (como los otros).
- Accesibilidad: el popover debe recibir foco y el botón "Ir al evento" ser alcanzable por teclado.

## Verificación (arnés ya montado)
Renderizado offline con FullCalendar real (no hace falta el sitio; el proxy bloquea Chromium contra
la web autenticada):
1. HTML de prueba que cargue `js/fullcalendar/lib/main.min.js` + `locales/es.js` + `custom-style.css`
   + `stic-base.css`, con un `<div id="calendar" data-fc-settings='{…eventos de ejemplo…}'>` y
   `js/stic-init.js`. (Hay un generador en el scratchpad de la sesión anterior; recréalo.)
2. Render con Playwright (`playwright-core`, Chromium de `/opt/pw-browsers`, `--allow-file-access-from-files`)
   a **390px** (móvil) y **1000px** (escritorio). Capturar Mes y Agenda.
3. Revisar: en móvil, eventos = barras de color sin texto; tap abre tooltip con nombre + "Ir";
   sesiones (con hora) y eventos de rango TODOS visibles; celdas no gigantes. En escritorio, punto +
   título como ahora.

## Done criteria
- [ ] En móvil, Mes muestra barras de color (todos los tipos de evento visibles), sin títulos.
- [ ] Tap → tooltip con nombre + botón "Ir al evento" (no navega directo); teclado y cierre OK.
- [ ] Escritorio intacto (punto + título).
- [ ] `node --check js/stic-init.js` y llaves de CSS OK; render 390/1000 revisado.
- [ ] Fila 022 actualizada en `plans/README.md`.

## STOP conditions
- Si `eventContent` choca con la lógica de colores/clases del mantenedor
  (`inc/stic-calendar.php::sticpa_calendar_fc_events`), PARA y coordina: quizá el color deba salir de
  una clase/extendedProp concreta, no de `backgroundColor`.
- Si el popover requiere reescribir el `eventClick` del mantenedor, acuérdalo antes (no lo pises).

## Maintenance notes
- Alternativa/меnor esfuerzo ya aplicada: en móvil se abre Agenda por defecto, que evita el problema
  del todo para quien no cambie de vista. Este plan es para pulir la vista Mes cuando se use en móvil.
