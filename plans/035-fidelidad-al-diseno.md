# 035 — Fidelidad al diseño de Pasar Lista

**Prioridad: P2** (P1 percibida: es lo que el propietario ve cada día).
Esfuerzo: M. Depende de: nada (pero no pisar 033/034 en los mismos ficheros).

> ## Estado al 27/08/2026 (noche)
>
> - **Marcar: verificada y fiel.** Comparada propiedad a propiedad con el CSS en
>   línea del artboard (título 1,15rem/800, nombre 0,99rem/700, avatar y círculo
>   40 px, fila `11px 6px 11px 14px`, leyenda 0,74rem/600). La corrigió el PR
>   #60 y sigue cuadrando.
> - **Ficha → bloque de teléfonos: corregido.** Era el que más desviado estaba,
>   y justo el que ahora se usa (la familia ya se encuentra):
>   nombre 0,88 → **0,95rem**, número 0,8 → **0,79rem**, relleno de fila
>   `0,7/0,85rem` → **`13px 16px`**, hueco interno 0,1 → **0,19rem**, y el verde
>   del botón de WhatsApp pasa de `--success-50` (#e6fcf5, el de «han venido
>   todos») a **`--success-soft`** (#f4fbf7), que es el del artboard.
>   Y la estructura, que era el desvío de fondo: el artboard pone **nombre +
>   pastilla** arriba y **«Parentesco · número»** debajo; estaba todo amontonado
>   en una línea.
> - **Divergencia DELIBERADA, y se queda:** los botones de llamar y WhatsApp son
>   de **44 px**, no de 40 como el artboard. Es el mínimo de un objetivo táctil,
>   y con prisa un botón de 40 se falla. La fidelidad no manda sobre la
>   accesibilidad; queda escrito para que nadie lo «arregle» de vuelta.
> - **Pendientes**: `Grupos`, `Resumen`, `Main` y `Estados`. Y `monitores`,
>   `monitor` y `reuniones`, que no tienen artboard y siguen la spec de su
>   pantalla hermana (monitores = marcar; reuniones = árbol).
>
> ## Estado al 28/08/2026
>
> - **`Grupos`: spec extraída y catorce desvíos corregidos.** La tabla completa
>   —y las cuatro divergencias deliberadas— está en
>   [`../docs/comunica/fidelidad/grupos.md`](../docs/comunica/fidelidad/grupos.md),
>   que es el formato que pedía el «Método» de abajo y que ahora existe para
>   que la próxima pantalla se compare contra algo en vez de re-medirla.
>   El desvío de fondo era estructural: **el estado de cada fila se pintaba como
>   un disco de color de 26 px**, y el artboard reserva el disco para la tarjeta
>   de «tu grupo». En una columna de veintiocho filas, veintiocho discos pesan
>   más que los nombres.
> - **Herramienta nueva y muy barata**: se puede renderizar cualquier pantalla
>   contra el doble de test, envolverla igual que `menu.php`
>   (`.stic-container > .stic-tab-content`) y hacerle una captura con Chromium
>   en los DOS temas. Es lo que ha permitido comparar de verdad en vez de a ojo,
>   y de paso encontró un desborde horizontal real (ver abajo). No vive en el
>   repo: son treinta líneas y se reescriben en un minuto.
> - **Desborde horizontal, encontrado y tapado.** Varios bloques sangran a los
>   lados con márgenes negativos contando con el relleno del tema de WordPress;
>   `.stic-tab-content` lo pone a **cero** en horizontal, así que en `marcar` y
>   `monitores` eso eran 32 px de barra de desplazamiento lateral. Se recorta
>   con `overflow-x: clip` en `.stic-container` (`clip`, no `hidden`: `hidden`
>   crearía un contenedor de scroll y rompería el `sticky` de la barra de
>   guardar). Comprobado que la hoja de estados sigue apareciendo igual.

## El problema

Los artboards de `design/pasar-lista/` (`Main`, `Grupos`, `Marcar`, `Ficha`,
`Resumen`, `Estados` — `.dc.html`) son **la especificación visual**, y las
pantallas «se parecen» pero no son fieles. Ya se sabe por qué pasó
(parte de estado §3.3): quien implementó leyó el TEXTO de los artboards y no
su **CSS en línea**, que es donde está la letra pequeña (tamaños, paddings,
radios, colores). El PR #60 corrigió media docena de desvíos; quedan los
demás, y sobre todo falta un método para que no vuelva a divergir.

## Reglas del juego (no opcionales)

1. **El CSS en línea de cada artboard ES la spec.** No se estima «a ojo» desde
   la captura: se lee `style="…"` y se copian los valores.
2. Los valores de color se traducen a **tokens existentes** de
   `css/custom-style.css` §1 (leer antes `docs/design-system.md`). Las tres
   trampas del parte §3.4 mandan: nada de valores de reserva con color en
   `var()`; `--white` NO es blanco; el atributo de tema es `data-stic-scheme`.
   `tests/TokensCssTest.php` es el guardián — ampliarlo si se añaden tokens.
3. Los `<button>` heredan estilos del tema de WordPress (§3.5): cualquier
   botón nuevo pasa por la neutralización de `css/pasar-lista.css` §0.b.
4. Cambios SOLO en `css/pasar-lista.css` y en los builders de HTML
   (`inc/stic-pasar-lista-ui.php`, `pages/single_stic_pasar_lista*.php`). No
   tocar `custom-style.css` salvo para añadir un token que falte de verdad.

## Método, pantalla a pantalla

Para cada pareja artboard ↔ pantalla (`Main`→portada, `Grupos`→árbol,
`Marcar`→marcar, `Ficha`→ficha, `Resumen`→resumen, `Estados`→hoja de estados):

1. **Extraer la spec**: del `.dc.html`, tabla de propiedades medibles por
   componente — familia/tamaño/peso de letra, line-height, paddings, gaps,
   radios, sombras, colores (ya mapeados a token), tamaños de icono, altura de
   fila. Guardar la tabla en `docs/comunica/fidelidad/<pantalla>.md` (nueva
   carpeta): es el checklist permanente y lo que evita re-auditar desde cero.
2. **Comparar** contra el CSS real (`css/pasar-lista.css`) y el HTML que
   generan los builders. Anotar cada desvío: `qué | spec | actual | dónde`.
3. **Corregir** los desvíos en lotes pequeños (una pantalla por PR), en los
   DOS temas: cada cambio se mira con `data-stic-scheme="dark"` además del
   claro. Los artboards están dibujados en claro; en oscuro mandan los tokens,
   no una inversión inventada.
4. **Verificar**: `tests/PasarListaRenderTest.php` cubre estructura — añadir
   aserciones de clase/atributo cuando el arreglo sea estructural. Para lo
   puramente visual, captura antes/después (la skill `run` o el harness de
   `tests/manual/`) pegada en el PR.

Empezar por **Marcar** (la más usada) y **Grupos** (la más visible), luego
Resumen, Ficha, Main, Estados.

## Desvíos ya conocidos para arrancar la lista

- Árbol de grupos: el diseño dice `Mercedes · 1º ESO · 10 participantes` por
  fila; hoy sale `MIC · 2025-2026`. OJO: el dato de recuento es el melón de
  `PASAR-LISTA-RECUENTOS.md` (campos en `ajmcm_GRUPOS`) — la PARTE VISUAL
  (composición de la fila, tipografía) se puede dejar lista con los datos que
  ya hay, y el recuento entra cuando exista el campo (plan 036).
- Resumen: las tarjetas ponen de número grande cuántos GRUPOS hay; el diseño
  pide cuántos chavales (mismo melón de recuentos; misma separación
  visual-ahora / dato-después).
- El PR #60 dejó dichos (y corregidos) estos patrones: título 1,45rem (no
  1,15), filas `padding: 15px 16px` (no altura fija 52px), sin tarjetas
  verdes inventadas. Revisar que no se hayan colado de nuevo en pantallas no
  tocadas por ese PR (ficha, resumen, monitores, reuniones).
- Las pantallas SIN artboard (monitores, reuniones, seguimientos) siguen la
  spec de la pantalla hermana más cercana (monitores = marcar; reuniones =
  árbol) — dejarlo escrito en su checklist para que nadie les invente estilo.

## STOP conditions

- STOP si un valor del artboard contradice `docs/design-system.md` (p. ej. un
  color que no existe como token): se pregunta al propietario en el PR, no se
  crea un token nuevo por cuenta propia.
- STOP si la fidelidad pide cambiar HTML de forma que rompa
  `PasarListaRenderTest` de manera no trivial: parar y mirar si el test
  protege un contrato (accesibilidad, offline) antes de «arreglarlo».
- No usar NUNCA un valor de reserva con color en `var()` (es la trampa §3.4).

## Estado

| Pantalla | Spec extraída | Desvíos anotados | Corregida | Verificada (2 temas) |
|---|---|---|---|---|
| Marcar | ✅ | ninguno vivo | ✅ (PR #60) | ✅ 28/08 (captura) |
| Ficha | ✅ teléfonos | ✅ 5 + la estructura | ✅ teléfonos | ✅ 28/08 (captura) |
| Grupos | ✅ [`fidelidad/grupos.md`](../docs/comunica/fidelidad/grupos.md) | ✅ 14 | ✅ 28/08 | ✅ 28/08 (captura) |
| Resumen | ✅ [`fidelidad/main-resumen-estados.md`](../docs/comunica/fidelidad/main-resumen-estados.md) | ✅ 2 (medidas) | ✅ 28/08 | ✅ 28/08 (captura) |
| Main | ✅ ídem | ✅ 4 (medidas) | ✅ 28/08 | ✅ 28/08 (captura) |
| Estados | ✅ ídem | ✅ 7, **dos de ellos de verdad** | ✅ 28/08 | ✅ 28/08 (captura) |

**Las seis pantallas con artboard están hechas.** Lo que queda del plan es el
método, no la lista: que cualquier componente nuevo pase por §0.b/§0.c/§0.d de
`pasar-lista.css` antes de darse por bueno, porque los tres fallos de fondo que
ha habido eran el tema de WordPress ganando por especificidad y ninguno se veía
leyendo el CSS del plugin.
