# Plan 016: Tema oscuro OPT-IN real (conmutable, basado en tokens)

> **Executor instructions**: paso a paso, verifica, respeta STOP conditions, actualiza `plans/README.md`.
>
> **Drift check**: `git diff --stat c2d7cff..HEAD -- css/custom-style.css css/stic-base.css sinergiacrm-private-area.php js/stic-ui.js`

## Status

- **Priority**: P2
- **Effort**: L
- **Risk**: MED
- **Depends on**: 018 (consolidación CSS) recomendado, no bloqueante
- **Category**: ui / dark-mode
- **Planned at**: commit `c2d7cff`, 2026-07-19

## Why this matters

El área fuerza HOY el tema claro a propósito (design system §1.5): el `@media (prefers-color-scheme: dark)`
del SO se pisa en `custom-style.css` §20.d y desde `c2d7cff` también con `color-scheme: light` en
`.stic-container`/`.stic-auth-shell`. Esa decisión sigue vigente **por defecto**. Este plan añade un
modo oscuro **opt-in** (interruptor del usuario, no el del SO) sin romper el claro-por-defecto.

La base ya está preparada: los colores de marca, sombras, glow y colores de estado
(`--success/--danger/--warning-*`) viven como tokens en el `:root` de `css/custom-style.css` §1, y
`--grad-brand-soft`/`--shadow-glow` se derivan con `color-mix`. Cambiar tema = redefinir tokens bajo
un atributo, NO reescribir reglas.

## Current state

- `css/custom-style.css` §1 (`:root`): tokens de marca, superficies (`--surface`, `--surface-2`,
  `--surface-glass`), grises `--gray-50…900` (definidos en `css/stic-base.css` y re-forzados en el
  bloque §20.d), sombras, radios, estados.
- §17 es un stub vacío: `/* 17. MODO OSCURO (Desactivado a petición) */`.
- §20.d re-fuerza tokens claros dentro de `@media (prefers-color-scheme: dark)` — NO tocar: es el
  comportamiento por defecto deseado.
- `.stic-container` y `.stic-auth-shell` llevan `color-scheme: light` (§2 y §3).
- El menú de usuario se genera en `menu.php::menu()`; los SVG usan `stroke='currentColor'` (se
  adaptan solos).

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint PHP | `php -l sinergiacrm-private-area.php && php -l menu.php` | sin errores |
| Lint JS | `node --check js/stic-ui.js` | sin errores |
| Buscar hex fuera de tokens | `awk 'NR>120' css/custom-style.css \| grep -c '#[0-9a-fA-F]\{3,8\}'` | ver STOP 2 |

## Scope

**In scope**: `css/custom-style.css` (nueva sección al final), `js/stic-ui.js` (toggle + persistencia),
`menu.php` (botón del conmutador), `sinergiacrm-private-area.php` (clase inicial en el contenedor para
evitar FOUC).
**Out of scope**: `css/stic-base.css` (sus reglas quedan pisadas por los tokens), las librerías
vendorizadas (FullCalendar/Selectize/DataTables: ver Step 5), el modo oscuro AUTOMÁTICO por SO
(decisión de producto: NO se activa solo).

## Design decisions (ya tomadas — no re-abrir)

1. **Activación por atributo**, no por media query: `.stic-container[data-stic-theme="dark"]` y
   `.stic-auth-shell[data-stic-theme="dark"]`. El default sin atributo sigue claro.
2. **Persistencia en `localStorage`** (`sticpa-theme`: `'dark'` | `'light'`), igual que el estado de
   las secciones colapsables (`js/stic-ui.js::bindCollapsibleSections` es el ejemplar a imitar).
3. **Los tokens son la única superficie de theming.** Si una regla necesita un color distinto en
   oscuro y no existe token, se crea el token en §1 y se usa en ambos temas.

## Steps

### Step 1: Bloque de tokens oscuros (nueva sección numerada al final de custom-style.css)

```css
/* ============================================================
   43. TEMA OSCURO OPT-IN — [data-stic-theme="dark"]
   ============================================================ */
.stic-container[data-stic-theme="dark"],
.stic-auth-shell[data-stic-theme="dark"] {
    color-scheme: dark;
    --surface: #111827;
    --surface-2: #1f2937;
    --surface-glass: rgba(17, 24, 39, 0.74);
    --glass-border: rgba(255, 255, 255, 0.14);
    --primary-50:  color-mix(in srgb, var(--primary-color) 18%, #111827);
    --secondary-50: color-mix(in srgb, var(--secondary-color) 18%, #111827);
    /* Escala de grises INVERTIDA: el texto claro sale de los tokens, no de reglas nuevas */
    --gray-50:  #1f2937;  --gray-100: #273449;  --gray-200: #374151;
    --gray-300: #4b5563;  --gray-400: #6b7280;  --gray-500: #9ca3af;
    --gray-600: #cbd5e1;  --gray-700: #d1d5db;  --gray-800: #e5e7eb;  --gray-900: #f9fafb;
    /* Estados: variantes soft oscurecidas */
    --success-soft: color-mix(in srgb, var(--success-color) 16%, #111827);
    --success-50:   color-mix(in srgb, var(--success-color) 20%, #111827);
    --success-100:  color-mix(in srgb, var(--success-color) 26%, #111827);
    --danger-soft:  color-mix(in srgb, var(--danger-color) 16%, #111827);
    --danger-100:   color-mix(in srgb, var(--danger-color) 24%, #111827);
    --warning-soft: color-mix(in srgb, var(--warning-color) 14%, #111827);
    --warning-100:  color-mix(in srgb, var(--warning-color) 20%, #111827);
    --warning-200:  color-mix(in srgb, var(--warning-color) 28%, #111827);
    --warning-dark: #fbbf24;   /* texto ámbar legible sobre oscuro */
    --warning-darker: #fcd34d;
    --warning-text: #f59e0b;
    --success-dark: #34d399;
    scrollbar-color: var(--gray-300) transparent;
}
```

**Verify**: con el atributo puesto a mano en DevTools, la home, un formulario y un listado se ven
oscuros y legibles SIN tocar ninguna otra regla.

### Step 2: Neutralizar el re-forzado claro de §20.d cuando el tema oscuro está activo

El bloque `@media (prefers-color-scheme: dark)` de §20.d re-impone tokens claros. Como §43 va
DESPUÉS en el archivo y tiene mayor especificidad (atributo), gana — pero verifica las reglas de
§20.d que usan `!important` sobre inputs: añade en §43 la contraparte:

```css
.stic-container[data-stic-theme="dark"] :is(input, textarea, select, .input-text),
.stic-auth-shell[data-stic-theme="dark"] :is(input, textarea, select, .input-text),
[data-stic-theme="dark"] .stic-field input {
    background-color: var(--surface-2) !important;
    color: var(--gray-800) !important;
    border-color: var(--gray-200) !important;
}
```

**Verify**: en OS-dark + tema oscuro activo, los inputs se ven oscuros; en OS-dark + default, claros.

### Step 3: Conmutador en la barra (menu.php) + persistencia (stic-ui.js)

- `menu.php`: añade junto al botón "Salir" (bloque `$actions`) un
  `<button type='button' class='stic-iconbtn stic-theme-toggle' aria-pressed='false' aria-label='Activar modo oscuro'>`
  con un SVG luna/sol inline (mismo estilo `sticpa_icon`: stroke currentColor, 24×24, stroke-width 2).
- `js/stic-ui.js`: nueva función `bindThemeToggle()` registrada en `ready()`:
  - lee `localStorage.getItem('sticpa-theme')` (try/catch como en `bindCollapsibleSections`);
  - aplica/quita `data-stic-theme="dark"` en TODOS los `.stic-container` y `.stic-auth-shell`;
  - actualiza `aria-pressed` y el icono;
  - guarda la elección al pulsar.
- Anti-FOUC: en `sinergiacrm-private-area.php`, junto al enqueue de `stic-ui`, añade un
  `wp_add_inline_script(..., 'position: before')` de 3 líneas que lea localStorage y ponga el
  atributo en `document.currentScript`-independiente (usa un selector diferido o marca
  `document.documentElement` con una clase que §43 también acepte).

**Verify**: `node --check js/stic-ui.js`; alternar el botón cambia el tema al instante y sobrevive a
recargar la página.

### Step 4: Pasada visual de contraste

Con el tema oscuro activo, revisa estas zonas y ajusta SOLO vía tokens o reglas dentro de §43:
tarjetas del dashboard, nav (ya es degradado de marca: apenas cambia), formularios (tarjetas §22.a),
listados-tarjeta (§22.b, la banda `td.stic-cell-title` usa rgba de marca sobre blanco → añade
contraparte), tooltips (§29: fondo `--gray-900` que ahora es claro → fija
`background: #0b1220` explícito en §43), modales, overlay de carga (fondo
`rgba(244,247,252,0.82)` → contraparte oscura), badges de estado.

**Verify**: texto normal ≥ 4.5:1 y texto grande/iconos ≥ 3:1 en las zonas listadas (usa el picker de
contraste de DevTools).

### Step 5: Librerías vendorizadas — mínimo viable

NO tematices FullCalendar/DataTables/Selectize a fondo. Solo:
```css
[data-stic-theme="dark"] #calendar { filter: none; } /* placeholder: revisa variables --fc-* */
```
FullCalendar 5 expone variables CSS (`--fc-page-bg-color`, `--fc-neutral-bg-color`,
`--fc-border-color`, `--fc-list-event-hover-bg-color`): redefínelas dentro de §43. Selectize y
DataTables heredan casi todo de inputs/tabla ya tematizados; corrige solo lo que se vea roto.

**Verify**: calendario y un multiselect legibles en oscuro.

## Test plan

Manual/staging (no hay suite; ver plan 013): matriz de 4 estados — {SO claro, SO oscuro} ×
{default, oscuro activado} — sobre: login, home, "Mis datos", un listado con datos, calendario,
modal de borrado y cropper. En `?app=1` (WebView) el toggle también debe funcionar.

## Done criteria

- [ ] `node --check js/stic-ui.js` y `php -l` de los PHP tocados exit 0
- [ ] Sin atributo, TODO se ve exactamente igual que antes (diff visual nulo en claro)
- [ ] El toggle persiste tras recarga y no produce flash de tema incorrecto
- [ ] Las 4 combinaciones de la matriz son legibles (contraste AA en texto)
- [ ] Ningún hex nuevo fuera de §1/§43
- [ ] Fila 016 actualizada en `plans/README.md`

## STOP conditions

1. Si al aplicar §43 hay >15 zonas visualmente rotas (no legibles), PARA y reporta: significa que
   quedan demasiadas reglas con colores literales y conviene ejecutar antes el plan 018.
2. Si el conteo de hex fuera de tokens en `custom-style.css` supera ~120 tras tu trabajo, PARA:
   estás añadiendo literales en vez de tokens.
3. Si el tema de WordPress (fuera del área) cambia de aspecto con el toggle activo, PARA: el
   atributo se está aplicando fuera de `.stic-container`/`.stic-auth-shell`.

## Maintenance notes

- Componentes nuevos deben usar tokens; si lo hacen, heredan el tema oscuro gratis.
- Si el CRM añade páginas nuevas con colores propios, exigir tokens en la review.
- Futuro: si producto decide seguir el SO, basta añadir
  `@media (prefers-color-scheme: dark) { .stic-container:not([data-stic-theme="light"]) { … } }`
  reutilizando el mismo bloque de tokens.
