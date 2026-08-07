# 026 — El lenguaje de tarjetas en todos los listados, login rediseñado y home sin doble cabecera

**Estado:** DONE (verificado en render offline con Chromium: 375/390/700/1100/1280 px).
**Prioridad:** P2 · **Esfuerzo:** M · **Depende de:** 025 (de ahí sale el lenguaje visual)

Cierra además la parte A del plan **024** (login compacto en móvil).

---

## A) Listados: el formato de Eventos, para todas las secciones

El plan 025 le dio a Eventos un formato propio y funcionó, así que se sube al
renderizador **genérico** (`makeList`) para que lo hereden Inscripciones,
Documentos, Pagos, Sesiones, Asistencias y las que vengan:

- **Cápsula de fecha** en la cabecera de la tarjeta + el nombre con la fecha
  completa debajo. La página declara cuál es su columna de fecha con
  `$listSettings['cardDate'] = '<columna>'`; **sin eso la tarjeta se pinta como
  antes**, así que ningún listado se rompe por no declararlo.
  Ya lo declaran: `list_stic_registrations` (`registration_date`),
  `list_stic_sessions` y `list_stic_attendances` (`start_date`),
  `list_stic_documents` (`active_date`).
- **Estado como chip**: cualquier columna cuyo nombre contenga `status` se
  envuelve en `<span class="stic-chip">` y se pinta como píldora.
- **Barra de acciones** al pie de la tarjeta: la **primera** acción es la
  principal (botón de marca) y el resto secundarias (gris). Antes eran enlaces
  idénticos y no se sabía cuál pulsar.

> `list_stic_payments` **no** tiene columna de fecha, así que se queda sin
> cápsula. Si el CRM tiene un campo de fecha de pago, añadirlo a `$columnsList`
> y declararlo en `cardDate` es todo lo que hace falta.

## B) Login

**Móvil:** no cabía de un vistazo porque tres elementos decían lo mismo antes
de llegar al campo (kicker "ÁREA PRIVADA" + "Hola de nuevo" + "Accede a tu área
privada de X"). Ahora hay un **saludo según la hora** (`sticpa_greeting()`) y
UNA línea de contexto, con la marca en horizontal. Entra entero en un iPhone SE
(375×667) con margen.

**Escritorio (≥860px):** la tarjeta se parte en dos — panel de marca que cuenta
qué hay dentro + formulario al lado.

> **Cuidado con la §24 del CSS.** A partir de 561px es el *shell* el que hace de
> tarjeta (borde, sombra, `overflow:hidden`) y la tarjeta interior queda
> transparente y sin padding, todo con `!important`. La §50 **no pelea** contra
> eso: ensancha el shell y convierte la tarjeta interior en la rejilla, así el
> panel de marca llega a sangre hasta el borde redondeado.
> El layout de dos columnas va acotado con `:has(.stic-auth-aside)` para no
> afectar a la pantalla de recuperar contraseña, que comparte shell.

**Movimiento:** entrada escalonada, halo que late tras el logo y el degradado
del panel moviéndose despacio. Todo `transform`/`opacity` — el plan 020 midió
lo cara que sale esta pantalla, así que nada de `blur` nuevo. Hay bloque de
`prefers-reduced-motion` (los perks nacen con `opacity:0`: sin él, quietos =
invisibles).

## C) Home: se veían dos cabeceras moradas

En móvil, la barra de identidad y el hero eran dos tarjetas con el mismo
degradado y el mismo nombre, una encima de otra: ~340 px de pantalla para
presentarse. En móvil el hero **deja de ser tarjeta** (fondo transparente,
texto oscuro) y el saludo se queda como texto sobre el fondo de la página. En
escritorio se mantiene como estaba. Textos acortados.

## Pendiente relacionado
- Plan **024 parte B**: el encaje visual con WordPress (los grises). Sigue
  necesitando ver el sitio real; el mantenedor pasará captura.
