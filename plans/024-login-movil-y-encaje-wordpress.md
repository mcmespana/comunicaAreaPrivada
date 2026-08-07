# 024 — Login compacto en móvil + encaje visual con WordPress

**Estado:** TODO · **Prioridad:** P2 · **Esfuerzo:** M · **Depende de:** —

Dos encargos anotados el 2026-08-07 mientras se trabajaba en la home móvil, el
selector de participante y los eventos (planes 023 y siguientes). Van juntos
porque los dos son "el área privada no acaba de encajar en la pantalla / en la
página que la contiene".

---

## A) La pantalla de LOGIN no cabe de un vistazo en móvil

**Síntoma (palabras del mantenedor):** "al menos en móvil tendría que ser mucho
más pequeña porque no cabe en un vistazo".

**Dónde vive**
- Marcado: `sugar_crm_portal_login_form()` en `sinergiacrm-private-area.php`
  (cabecera de marca `.stic-auth-brand`, formulario `.stic-login-form`, pie con
  "¿Todavía no tienes cuenta?").
- Estilos: `.stic-auth-shell`, `.stic-login-form`, `.stic-auth-logo`,
  `.stic-auth-brand` en `css/custom-style.css` (§ del bloque de autenticación) y
  el ajuste móvil de §16 (`@media (max-width: 560px)`), que hoy solo baja el
  padding a `1.75rem 1.4rem`.

**Qué mirar (por orden de sospecha)**
1. `min-height: 100dvh` + el logo grande + kicker + título + subtítulo: entre
   la marca y el aire, el campo de email queda por debajo del pliegue en
   pantallas de ~650px de alto.
2. El bloque de marca (logo escudo + "ÁREA PRIVADA" + "Hola de nuevo" +
   subtítulo) son 4 elementos para decir una cosa. En móvil probablemente
   sobren el kicker y parte del subtítulo.
3. El pie de "¿Todavía no tienes cuenta?" empuja desde abajo.
4. Revisar también el modo `password` (no solo el `magic`): son dos variantes
   de la misma pantalla y hay que comprobar las dos.

**Criterio de aceptación:** en un iPhone SE (375×667) se ven, sin hacer scroll,
el título, el campo de email y el botón principal.

**Ojo:** el login ya pasó por el plan 020 (coste de pintura del cristal/blur).
No deshacer aquello: aquí se trata de TAMAÑO y JERARQUÍA, no de efectos.

---

## B) Encaje con el WordPress que la contiene

**Síntoma:** "hay unos grises de fondo y cosas que no acaban de encajarse".

**Contexto:** el área privada se inserta con un shortcode dentro de una página
de WordPress (maquetada con Elementor, según los comentarios del CSS). Hay dos
capas pintando fondo: la página del tema y `.stic-container`, que trae su propio
degradado suave de marca. Donde no coinciden, se ve una banda gris.

**Qué mirar**
1. `.stic-container` tiene `padding: 0 !important` y un fondo radial propio; si
   el contenedor de Elementor añade su propio padding/fondo, aparecen franjas
   a los lados o arriba.
2. `@media (max-width: 767px) .stic-container { margin-top: 1.15rem; }` (y el
   `0.85rem` de ≤560px que se añadió en el plan 023) existen para separar del
   título que pinta el tema encima. Si ese título se quita o cambia, ese margen
   sobra y queda un hueco gris.
3. El modo oscuro (§44) fuerza superficies del área, pero **el fondo de la
   página sigue siendo del tema**: si el tema no acompaña, el área oscura queda
   flotando sobre un fondo claro. Es el candidato número uno a "no acaba de
   encajar".
4. Comprobar el ancho: si la plantilla de la página no es a ancho completo, el
   área hereda un `max-width` del tema que la estrecha en escritorio.

**Cómo abordarlo:** esto **no se puede resolver a ciegas desde el repo** — hace
falta ver la página real. Lo primero es una captura del sitio en móvil y en
escritorio, en claro y en oscuro, señalando qué gris molesta. Con eso, la
solución es casi siempre una de estas tres:
- dar al `.stic-container` el fondo que le falta (o quitárselo y dejar el del
  tema), en vez de que ambos pinten;
- ajustar la plantilla/ancho de la página de WordPress que lo contiene;
- publicar unas pocas reglas de "reset" para el contenedor del shortcode.

**No mezclar** esto con el rediseño interno del área: aquí se toca la costura,
no el contenido.
