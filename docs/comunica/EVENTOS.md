# Eventos — qué campos usa el área privada y qué falta en SinergiaCRM

Cómo se pintan los eventos (listado, ficha de detalle e inscripción) y **qué
campos habría que crear en SinergiaCRM** para que la experiencia sea completa.

Todo el formato vive en [`inc/stic-events.php`](../../inc/stic-events.php).

---

## 1. Qué se usa HOY (campos que ya existen en `stic_Events`)

| Campo CRM | Dónde se ve | Notas |
|-----------|-------------|-------|
| `name` | Título de la tarjeta y de la ficha | Obligatorio: sin nombre el evento no se pinta |
| `start_date` | Cápsula de fecha + "del 1 al 10 de julio de 2026" | Sin fecha, la cápsula muestra un icono genérico |
| `end_date` | Rango de fechas y cálculo de duración | Si falta, se asume evento de un día |
| `status` | Chip de estado (etiqueta traducida del desplegable) | El valor crudo (`Planned`…) no se muestra nunca |
| `description` | Bloque "Sobre esta actividad" de la ficha | Texto plano; los saltos de línea se respetan |
| `type` | Se recupera, aún sin uso visual | Reservado para agrupar/filtrar por tipo |

**"Ya celebrado"** no es un campo: se calcula con `end_date` (o `start_date` si
no hay fin). Los eventos pasados se muestran apagados, al final de la lista y
sin botón de inscripción.

---

## 2. Campos OPCIONALES que la interfaz ya sabe pintar

Estos **no hace falta programarlos**: si los creas en SinergiaCRM con ese
nombre exacto, aparecen solos en la ficha (y el lugar, además, en la tarjeta
del listado). Están declarados en `sticpa_event_optional_fields()`.

| Campo CRM | Tipo sugerido | Dónde saldría |
|-----------|---------------|---------------|
| `location` | Texto | Tarjeta del listado + dato clave "Lugar" |
| `city` | Texto | Alternativa a `location` si no existe |
| `address` | Texto | Dato clave "Dirección" |
| `start_time` | Texto/hora | Dato clave "Hora" |
| `capacity` | Entero | Dato clave "Plazas" |
| `price` | Decimal | Dato clave "Precio" (formateado en euros) |
| `registration_end` | Fecha | Dato clave "Inscripción hasta" |

> El plugin **pregunta primero al CRM qué campos existen**
> (`sticpa_event_fields_to_request()`), así que declarar aquí un campo que aún
> no está creado no rompe nada: simplemente no se pide ni se pinta.

Para añadir uno nuevo que no esté en la lista, basta con una línea en
`sticpa_event_optional_fields()` (etiqueta + icono + formato).

---

## 3. Lo que RECOMIENDO crear en SinergiaCRM

Por orden de impacto en la experiencia de quien se inscribe:

1. **`location` (Texto) — el más importante.** Hoy nadie puede saber *dónde* es
   una actividad sin preguntar. Es el dato que más se echa en falta.
2. **`registration_end` (Fecha).** Permite decir "te quedan X días para
   apuntarte" y cerrar la inscripción sola cuando pase la fecha, en vez de
   depender de que alguien cambie el estado a mano.
3. **`price` (Decimal) + `capacity` (Entero).** Precio y plazas son las dos
   preguntas que siempre llegan por WhatsApp. Con `capacity` además se podría
   mostrar "quedan N plazas" (haría falta contar inscripciones).
4. **`start_time` / `end_time` (Hora).** Ahora mismo solo hay fechas: una
   actividad de una tarde se ve igual que una de todo el día.
5. **Imagen de cabecera** (campo de tipo imagen o URL, p. ej. `image_url`).
   Es lo que más cambiaría el aspecto de la ficha, pero requiere decidir dónde
   se alojan las imágenes y añadir el hueco en la plantilla — no está hecho.

### Qué NO hace falta
- No hace falta un campo "abierto a inscripción": se deduce de `status` +
  fechas. Si más adelante se quiere control fino, `registration_end` es la
  forma limpia de hacerlo.
- No hace falta duplicar el lugar en la descripción: en cuanto exista
  `location` sale como dato propio, mejor formateado.

---

## 4. Pantallas

| Pantalla | Archivo | Qué muestra |
|----------|---------|-------------|
| Listado | `pages/list_stic_events.php` | Tarjetas con fecha, nombre, lugar y estado. Próximos primero; los ya inscritos se ocultan (están en "Inscripciones") |
| Detalle | `pages/single_stic_events.php` | Ficha completa + botón de inscripción |
| Inscripción | `pages/single_stic_registrations.php` | Formulario con la tarjeta del evento arriba |

El listado **ya no usa DataTables**: eran tres filas de "ETIQUETA: valor" por
evento y el buscador sobra con pocos eventos. Si algún día la lista crece
mucho, el sitio natural para un filtro (por tipo o por fecha) es
`sticpa_events_list_html()`.
