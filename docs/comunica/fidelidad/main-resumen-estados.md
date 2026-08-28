# Fidelidad — `Main`, `Resumen` y `Estados`

Artboards en [`design/pasar-lista/`](../../../design/pasar-lista/) · CSS en
`css/pasar-lista.css`. Revisado el **28/08/2026**, con captura en los dos temas.

**La spec es el `style="…"` en línea de cada artboard, no su captura.** Los
colores van traducidos a tokens de `css/custom-style.css` §1.

---

## `Main` → portada (`single_stic_pasar_lista`)

Estaba **casi entera**: la cabecera con su barra de degradado (4×26), la tarjeta
del sábado, las filas de sección con su punto de 10 px y su divisoria que
arranca a 40 px, y el botón de «Resumen de grupos» ya cuadraban.

| Componente | Spec | Corregido el 28/08 |
|---|---|---|
| Tarjeta del sábado | radio 1.4 rem · `22px` · sombra `--shadow-lg` | — |
| ↳ pastilla del cuándo | `.16rem .6rem` · blanco al 22 % · 0.7 rem · 800 | — |
| ↳ título | **1.6 rem** · 800 · `-0.02em` | era 1.55 rem |
| ↳ línea de datos | **0.9 rem · peso 500** · blanco al **88 %** | era 0.83 rem, peso normal, 85 % |
| ↳ cápsula de fecha | 58 px · radio `--radius-lg` · día 1.4 rem · mes 0.62 rem | — |
| ↳ botón | alto 54 px · radio 1.1 rem · 1 rem · 800 · azul de marca | — |
| Listas pendientes | `--warning-soft` · borde `--warning-200` | — |
| ↳ fila | **`13px 16px`** | era `0.8rem 1rem` |
| ↳ grupo | 0.78 rem · **`--warning-text`** | era `--warning-dark` |
| Filas de sección | `15px 16px` · hueco 14 px · punto 10 px · nombre 1 rem/700 | — |
| ↳ pastilla del número | `--gray-100`/`--gray-600` · 0.75 rem · 800 | — |
| ↳ divisoria | desde `left: 40px`, no de lado a lado | — |
| «Resumen de grupos» | alto 48 px · radio 1.1 rem · borde `--gray-200` · 0.92 rem/700 | — |

**El número de la pastilla** es participantes cuando el recuento nocturno está
fresco y grupos cuando no, con la unidad dicha («12 gr.»). Es una decisión ya
tomada y escrita en la propia pantalla: un número que no se puede calcular no se
inventa, se cambia por el que sí y se dice cuál es.

## `Resumen` → resumen de coordinación

También estaba casi entera.

| Componente | Spec | Corregido |
|---|---|---|
| Tarjetas de etapa | **`15px 12px`** · hueco **6 px** · `--shadow-xs` | era `0.9rem 0.75rem` · 5.6 px |
| ↳ cabecera | punto 8 px · 0.72 rem · 800 · `--gray-500` · mayúsculas | — |
| ↳ número | 1.7 rem · 800 · `-0.03em` | — |
| ↳ pie | 0.72 rem · peso 500 · `--gray-400` | — |
| Tarjeta de la última sesión | radio 1.1 rem · `17px 18px` · hueco 16 px · `--shadow-md` · círculo 56 px | — |

## `Estados` → la hoja de asistencia

Aquí sí había **dos fallos de verdad**, no medidas.

| Componente | Spec | Estaba |
|---|---|---|
| **El motivo** | pastilla de **48 px** con el lápiz y el texto en UNA línea | una caja de ~130 px con el lápiz ENCIMA del texto, y un recuadro con borde dentro de la pastilla |
| Asidero | **44×5** sobre `--gray-300` | 40×4 sobre `--gray-200`: casi no se veía, y es la única pista de que la hoja se arrastra |
| Zona del asidero | margen `-4px 0 4px` | `-0.4rem 0 0.5rem` |
| Opción elegida | nombre en **800** y en el color del estado | peso 700, color heredado |
| Avatar de la hoja | **46 px** · 0.9 rem | 40 px · 0.82 rem (el de la fila) |
| Hueco de la opción · de la persona | 13 px | 12.8 px |
| Relleno del motivo | `0 16px` | `0 0.9rem` |

### Los dos fallos, y por qué nadie los vio

Los dos son **el tema de WordPress ganando por especificidad**, la misma familia
que la trampa §3.5 del parte de estado (los `<button>`), pero con otros dos
selectores:

1. `stic-base.css` estila `:is(.stic-tab-content, …) label { display: block }`.
   Eso le gana a una clase suelta, así que **los tres componentes de Pasar Lista
   que son `<label>`** —el motivo, las casillas de los avisos y los campos del
   alta de seguimientos— perdían su `display: flex` y apilaban sus hijos.
2. `custom-style.css` §10 estila todos los `input[type=text|search]` del área con
   `border-width: 1.5px !important` y `border-radius: … !important`. Con razón,
   para los formularios de verdad; pero el motivo y el buscador del árbol son un
   icono y un texto dentro de una pastilla, y eso dibujaba **una caja dentro de
   la caja**.

Los dos se neutralizan en `pasar-lista.css` §0.c y §0.d, con la misma receta que
§0.b usa para los botones. **Regla para el futuro: cualquier componente nuevo de
Pasar Lista que sea un `<label>` o lleve un `<input>` dentro pasa por §0.c/§0.d
antes de darse por bueno.**

## Divergencias DELIBERADAS

- **El texto escrito de los campos no baja de 1 rem** (16 px reales). Por debajo,
  iOS hace zoom al enfocar y descoloca la webview entera. El *placeholder* sí
  sigue al artboard.
- **La pastilla «Recuperar» mide 38 px de alto**, no los ~34 del artboard: es un
  objetivo táctil.
- **El velo de la hoja sube a 0.62 de opacidad** con
  `prefers-reduced-transparency`, en vez del 0.44 del artboard. Lo pide el
  sistema operativo, no el diseño.
