# 025 — Eventos: tarjetas propias, ficha de detalle y campos del CRM

**Estado:** DONE (verificado en render offline con Chromium a 390/1200 px).
**Prioridad:** P2 · **Esfuerzo:** M · **Depende de:** —

## Problema

1. **Las tarjetas de "Eventos" eran feas** y decían poco. Se pintaban con el
   renderizador genérico de listados (`makeList`), que produce filas
   "ETIQUETA: valor". Un evento acababa siendo tres filas de jerga
   administrativa — *Estado / Fecha inicio / Fecha fin* — para expresar una
   sola idea ("es del 1 al 10 de julio"). Dos eventos llenaban la pantalla de
   un móvil.
2. **No había forma de ver el detalle de un evento.** La única acción era
   "Inscribirse": te apuntabas sin poder leer de qué iba. Existía
   `pages/single_stic_events.php`, pero era el formulario genérico con todos
   los campos `disabled` (cajas grises intocables) y no se enlazaba desde
   ningún sitio.

## Qué se ha hecho

### `inc/stic-events.php` (nuevo)
Formato propio de evento, compartido por listado y ficha:
- `sticpa_event_view_model()` — normaliza el registro del CRM (fechas, estado,
  duración, "ya pasado") para que ambas pantallas digan lo mismo.
- `sticpa_event_date_line()` — fechas en lenguaje humano: *"del 1 al 10 de
  julio de 2026"*, *"5 de mayo de 2026"* si es de un día, sin repetir el mes
  cuando coincide.
- `sticpa_events_list_html()` — tarjetas: cápsula de fecha, nombre, cuándo,
  lugar (si existe) y chip de estado. Dos acciones separadas: **Ver detalle**
  (secundaria) e **Inscribirme** (primaria).
- `sticpa_event_detail_html()` — ficha: cabecera de marca, datos clave,
  descripción y CTA.
- `sticpa_event_fields_to_request()` — pregunta al CRM qué campos existen
  (`get_module_fields`, cacheado 6 h) y solo pide esos. Así, declarar un campo
  opcional que aún no está creado en SinergiaCRM **no rompe nada**.

### Comportamiento
- **Próximos primero**, ordenados por fecha; los ya celebrados van al final,
  apagados, sin botón de inscripción y con el más reciente arriba.
- El detalle detecta si ya hay inscripción activa y, en ese caso, lo dice en
  vez de ofrecer el botón (mismo criterio que el guard del formulario).

### CSS — nueva §49
Tarjetas, chips, cápsula de fecha, ficha de detalle y variante móvil.

### `docs/comunica/EVENTOS.md` (nuevo)
Qué campos usa hoy, cuáles se pintarían solos si se crean en SinergiaCRM
(`location`, `price`, `capacity`, `registration_end`…) y **qué se recomienda
crear**, por orden de impacto. `location` es el que más falta hace: hoy no hay
forma de saber dónde es una actividad.

## Decisiones que conviene conocer
- **El listado ya no usa DataTables.** Su único aporte era un buscador sobre
  una lista de pocos elementos, y era incompatible con el formato de tarjeta
  (el marcado tiene que ser una `<table>`). Si la lista crece, el sitio para un
  filtro es `sticpa_events_list_html()`.
- **"Ya celebrado" se calcula, no se lee de un campo** (`end_date`, o
  `start_date` si no hay fin). El listado no filtra los pasados: se ven, pero
  ordenados y atenuados.
- Los campos opcionales se declaran en un único sitio
  (`sticpa_event_optional_fields()`), con etiqueta, icono y formato.
