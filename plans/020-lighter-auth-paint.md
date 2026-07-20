# Plan 020: Aligerar el coste de pintura de la pantalla de login (blur/glass)

> **Executor instructions**: paso a paso, verifica, respeta STOP conditions, actualiza `plans/README.md`.
>
> **Drift check**: `git diff --stat c2d7cff..HEAD -- css/custom-style.css`

## Status

- **Priority**: P2
- **Effort**: S-M
- **Risk**: MED (es un cambio estético deliberado; requiere ojo)
- **Depends on**: none
- **Category**: perf / ui
- **Planned at**: commit `c2d7cff`, 2026-07-19

## Why this matters

El login es la PRIMERA pantalla que ve una familia al entrar desde el email, normalmente en un móvil
modesto. Hoy acumula las operaciones de pintura más caras que existen en CSS: `backdrop-filter:
blur(18px) saturate(1.4)` en la tarjeta de cristal, un halo `::before` con `filter: blur(64px)` a
todo el ancho, 5 gradientes radiales/lineales apilados de fondo y una trama de puntos con máscara
radial. En GPUs móviles baratas eso se nota en el primer render y al hacer scroll con el teclado
abierto. El objetivo NO es quitar el estilo "premium", sino producir el mismo look con la mitad de
coste.

## Current state (todo en `css/custom-style.css`)

- §3 `.stic-auth-shell::before` (~línea 128): 4 radial-gradients + 1 linear (estático, sin animación).
- §3 `.stic-auth-shell::after`: trama de puntos con `mask-image` radial.
- §4 `.stic-login-form/.stic-forgotpas-form` (~línea 165): `backdrop-filter: blur(var(--glass-blur))
  saturate(1.4)` con `--glass-blur: 18px` (token en §1).
- §4 `::before` halo (~línea 178): `background: var(--grad-brand); filter: blur(64px); opacity: .34`.
- Otros `backdrop-filter` menores ya tienen su twin `-webkit-` (overlay de carga, modal, barra sticky).

## Steps

### Step 1: Sustituir el halo blur(64px) por un gradiente pre-difuminado

Un `filter: blur(64px)` sobre un elemento grande obliga a la GPU a difuminar en cada composición.
El mismo efecto visual se consigue SIN filter con un radial-gradient suave:

```css
.stic-login-form::before,
.stic-forgotpas-form::before {
    background:
        radial-gradient(60% 80% at 30% 20%, color-mix(in srgb, var(--primary-color) 32%, transparent), transparent 70%),
        radial-gradient(60% 80% at 75% 30%, color-mix(in srgb, var(--secondary-color) 28%, transparent), transparent 70%);
    filter: none;
    opacity: 0.5;   /* ajusta a ojo hasta igualar el look actual */
}
```
Haz captura antes/después y ajusta paradas/opacidad hasta que el cambio sea imperceptible.

### Step 2: Rebajar el cristal en pantallas pequeñas

Mantén el glass completo en escritorio; en móvil usa un cristal más barato:

```css
@media (max-width: 600px) {
    :root { --glass-blur: 8px; }
    .stic-login-form, .stic-forgotpas-form { background: rgba(255, 255, 255, 0.88); }
}
```
(un fondo más opaco permite un blur menor sin perder legibilidad).

### Step 3: Fallback sin backdrop-filter

Envuelve el glass en `@supports (backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))`
y define fuera un fondo sólido `rgba(255,255,255,0.92)` — hoy un navegador sin soporte ve el texto
sobre un fondo demasiado transparente.

### Step 4: Medir

Chrome DevTools > Performance con CPU 4x + un móvil real si hay: graba la carga del login antes y
después. Anota en el commit los ms de "Paint/Composite" de ambas mediciones.

## Test plan

Visual manual: login (vista mágica y `?mode=password`) y "olvidé mi contraseña" a 390px y 1280px,
claro, con capturas antes/después lado a lado; el look debe leerse como "igual". Interacción: abrir
teclado móvil y hacer scroll — sin tirones.

## Done criteria

- [ ] Sin `filter: blur()` ≥ 32px en ningún elemento del auth
- [ ] Look validado contra capturas (diferencia solo apreciable en A/B directo)
- [ ] Fallback `@supports` presente
- [ ] Medición antes/después anotada en el mensaje de commit
- [ ] Fila 020 actualizada en `plans/README.md`

## STOP conditions

- Si tras el Step 1 el halo se ve claramente distinto y no consigues igualarlo en ~3 iteraciones,
  PARA y deja el halo original: el resto de pasos siguen valiendo por sí solos.
- No toques las animaciones de entrada (stic-pop/stic-fade-up): son one-shot y baratas.

## Maintenance notes

- Regla para el futuro: `filter: blur()` grande solo en elementos pequeños; para "glows" grandes,
  radial-gradient.
- Si se añade un tema oscuro (plan 016), revisar las opacidades de este glass en oscuro.
