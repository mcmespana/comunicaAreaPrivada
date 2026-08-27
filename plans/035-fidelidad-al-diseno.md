# 035 — Fidelidad al diseño de Pasar Lista

**Prioridad: P2** (P1 percibida: es lo que el propietario ve cada día).
Esfuerzo: M. Depende de: nada (pero no pisar 033/034 en los mismos ficheros).

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
| Marcar | | | | |
| Grupos | | | | |
| Resumen | | | | |
| Ficha | | | | |
| Main | | | | |
| Estados | | | | |
