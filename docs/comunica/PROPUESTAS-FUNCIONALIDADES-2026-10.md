# Propuestas de funcionalidades nuevas — octubre 2026

Esto es el frente 5 del [plan 042](../../plans/042-pulido-integral/README.md): funcionalidades nuevas para el área privada, ordenadas de más a menos recomendable. **No se implementa ninguna sin tu GO.**
Cómo usarlo: en cada ficha marca `[x] GO` o `[x] NO GO` y, si quieres, deja una nota detrás («sí, pero solo para coordinación», «después de Navidad»…).
Lo que ya estaba previsto en `TODO.md` o en otros planes va aparte, al final, y no cuenta como nuevo. Escrito el 09/10/2026.

Esfuerzo como en `TODO.md`: **S** (< medio día) · **M** (1-3 días) · **L** (semanas).

| ID | Título | Para quién | Esfuerzo | Recomendación |
|---|---|---|---|---|
| F1 | Añadir al calendario del móvil (y suscribirse) | Familias, participantes y monitores | M (el botón suelto, S) | **GO** |
| F2 | «Inscritos» de mi actividad, para quien la organiza | Coordinación y delegaciones | M | **GO** |
| F3 | Lo importante del grupo, de un vistazo (se va solo, fotos, alergias) | Monitores | S | **GO** |
| F4 | Papeles del equipo: quién no está en regla | Coordinación | S | **GO** |
| F5 | Compartir un evento con el texto ya escrito | Coordinación, monitores y familias | S | **GO** |
| F6 | «¿A quién apuntas?» en la ficha del evento (FAM-a14) | Familias con varios hijos | L | **GO con matices** |
| F7 | Salud y autorizaciones al día al apuntarse | Familias (y quien organiza) | M | **GO con matices** |
| F8 | La hoja de la salida, también sin cobertura | Monitores que van a una convivencia o excursión | M | **GO con matices** |
| F9 | Avisar de un toque, con el mensaje preparado | Monitores y coordinación | S | **GO con matices** |
| F10 | Avisos en el móvil desde MCM App (notificaciones) | Todos | L | **Más adelante** |
| F11 | Certificado de horas de voluntariado | Monitores | M | **Más adelante** |
| F12 | Tablón o chat de la delegación dentro del área | Todos | L | **NO GO** |

> Un apunte de calendario: lo que toca Pasar Lista o Mis grupos (F3, F4, F8, F9), mejor **después del arranque del curso del sábado 24/10**, cuando el semanal haya rodado un par de sábados.

---

## F1 — Añadir al calendario del móvil (y suscribirse)

**Para quién:** familias, participantes y monitores.

**Problema:** las fechas viven en el área, pero la gente mira su calendario. Por eso llegan los «¿a qué hora es el sábado?» y los «¿cuándo era la convivencia?» por WhatsApp, y por eso se olvidan los plazos.

**Propuesta:** dos pasos.
1. **Botón «Añadir al calendario»** en la ficha de cada evento y de cada sesión: descarga un `.ics` con el título, las fechas, el horario, el lugar y el enlace a la ficha.
2. **«Suscribirme a mi calendario MCM»**, una vez: un enlace personal (`webcal://`) que el calendario del móvil consulta solo cada pocas horas y que trae lo mismo que ya pinta el calendario del área (inscripciones y sesiones semanales). En una familia, los de todos los hijos en un solo calendario, con el nombre delante («Lucía · Convivencia COM»). Lo que cambie en el CRM llega solo, sin volver a avisar.

Dentro de MCM App la WebView no abre un `.ics` por sí sola: hace falta un mensaje nuevo del puente (p. ej. `sticpa:open-external`, con el prefijo `sticpa:` como pide `CONTRATO-APP-WEBVIEW.md` §4.a) para que la app lo pase al sistema con `Linking.openURL`. En iPhone, el `webcal://` abre directamente la suscripción. En Android se suscribe por Google Calendar (`calendar.google.com/calendar/r?cid=…`), que tarda horas en refrescar.

**Qué necesita del CRM:** nada nuevo. `stic_Events`: `name`, `start_date`, `end_date`, `timetable`, `ajmcm_lugar_c`, `ajmcm_direccion_c`, `ajmcm_mapa_c`; `stic_Sessions`: `start_date` y `end_date`, que ya lee el calendario (`inc/stic-calendar.php`). La firma del enlace personal vive en WordPress (como `sticpa_form_is_genuine()`), no en el CRM.

**Esfuerzo:** M. El botón suelto es S; la suscripción, M; el mensaje del puente, S en el repo de la app.

**Riesgo:** medio.
- El enlace de suscripción funciona **sin sesión**, porque el calendario no inicia sesión. Es un endpoint de solo lectura con firma, y hay que poder revocarlo (regenerar) como el token. Lleva lo mínimo: ni salud ni nombres de otros.
- Cada calendario suscrito pregunta varias veces al día y el CRM es lento. Hay que servirlo desde caché (unas horas por persona) o lo pagará el CRM.

**Recomendación:** **GO.** Es lo que más mensajes ahorra a más gente, los datos ya están y el calendario ya los junta. Empezaría por el botón (S) y, si se usa, la suscripción.

**Decisión del propietario:** [ ] GO  [ ] NO GO — nota:

---

## F2 — «Inscritos» de mi actividad, para quien la organiza

**Para quién:** coordinación y la delegación que organiza un evento (en lo nacional, ECE).

**Problema:** para saber «¿cuántos llevamos?», «¿quién falta por pagar?» o «¿cuántas camisetas de cada talla?», alguien tiene que entrar al CRM (y muchos monitores no tienen cuenta) o preguntar por WhatsApp a quien sí la tiene.

**Propuesta:** en la ficha del evento, solo para coordinación y solo si el evento es de su delegación, un bloque «Inscritos: 34 · 28 pagados · 6 pendientes» que abre la lista. En ella va el nombre, el curso, la respuesta a las preguntas simples, la talla y el estado del pago. Arriba, los recuentos (por curso, por talla y por respuesta) y «Copiar la lista», que sí funciona dentro de la app (descargar un CSV en la WebView, no).

**Qué necesita del CRM:** nada nuevo.
- `stic_Registrations` del evento: `status`, `ajmcm_respuesta_1_c`, `ajmcm_respuesta_2_c`, `ajmcm_curso_escolar_c`, `ajmcm_clase_c`, `ajmcm_registration_amount_c`.
- `Contacts`: `ajmcm_tallas_c` y `ajmcm_etapa_c`.
- El estado del pago, con la misma lógica que ya usa «Pagos» (`inc/stic-payments.php` y `sticpa_payment_commitment_id()`).
- El dueño, `assigned_user_id` del evento.

**Esfuerzo:** M.

**Riesgo:** medio. Son datos de menores en una pantalla nueva y **los grupos de seguridad del CRM no filtran aquí** (`CLAUDE.md`), así que la comparación con la delegación (`sticpa_pl_delegation()`) es obligatoria y va con su test. Con más de 100 inscritos hay que leerlos en una tanda, no persona a persona.

**Recomendación:** **GO.** Ahorra trabajo repetido cada vez que hay una convivencia y saca al CRM de la ecuación para quien no lo usa. A decidir: ¿lo ven solo coordinación o también los monitores que van a la actividad?

**Decisión del propietario:** [ ] GO  [ ] NO GO — nota:

---

## F3 — Lo importante del grupo, de un vistazo

**Para quién:** monitores.

**Problema:** a las 18:00 la pregunta es «¿quién se puede ir solo?», y antes de subir fotos a Instagram, «¿quién no puede salir?». Hoy eso está ficha a ficha (`pages/single_stic_pasar_lista_ficha.php`), y nadie abre doce fichas un sábado.

**Propuesta:** en la lista del grupo (Mis grupos y la pantalla de marcar), una marca discreta junto al nombre, con tres como máximo: «No se va solo/a», «Sin fotos» y «Alergia o tratamiento». Más un filtro en Mis grupos: «¿Quién no puede salir en fotos?». El detalle sigue en la ficha, y la marca de salud no dice cuál es.

**Qué necesita del CRM:** nada nuevo. `ajmcm_soloacasa_c`, `ajmcm_cesionimagenes_interne_c`, `ajmcm_descripcion_allergies__c` (con dos guiones bajos, de verdad), `ajmcm_descripcion_intoler_c` y `ajmcm_descripcion_tratam_c`. Se añaden a la consulta de la lista que ya se hace, sin llamadas nuevas.

**Esfuerzo:** S.

**Riesgo:** bajo-medio. Esas pantallas se guardan para usarlas sin conexión (por usuario, y se borran al salir), así que la marca de salud no lleva texto. Hay que decidir qué hacer si `ajmcm_datossalud_c` es «No» (propuesta: no pintar la marca de salud). Y cuidado con la ley de diseño: una marca sobria, nada de una fila de emojis.

**Recomendación:** **GO.** Coste mínimo, dato que ya está y valor de seguridad real.

**Decisión del propietario:** [ ] GO  [ ] NO GO — nota:

---

## F4 — Papeles del equipo: quién no está en regla

**Para quién:** coordinación.

**Problema:** la ficha de cada monitor ya dice «En regla» (`PASAR-LISTA-COORDINACION.md` §4), pero para saber **a quién de los treinta** le falta el certificado de delitos sexuales o el acuerdo de voluntariado hay que abrir treinta fichas. Es una obligación legal y se revisa a principio de curso.

**Propuesta:** en el directorio de Monitores, un filtro «Papeles pendientes (4)» con lo que le falta a cada uno: certificado o autorización verificada de delitos sexuales, compromiso, acuerdo de voluntariado y formación de protección del menor. Desde cada fila, «Recordárselo» (F9). Va en la misma línea que COO-8 («a quién hay que mirar»).

**Qué necesita del CRM:** nada nuevo: `ajmcm_aut_del_sex_c`, `ajmcm_aut_del_sex_file_c`, `ajmcm_cert_del_sex_c`, `ajmcm_compromiso_c`, `ajmcm_vol_acuerdo_c` y `ajmcm_form_intera_proteccion_c`. Para saber si un certificado está **caducado** no hay fecha; habría que crear un campo de fecha del certificado en Personas (nombre a decidir en Studio). Sin él, el filtro dice «falta», no «caducado».

**Esfuerzo:** S. Son campos de la misma consulta del directorio, más el filtro.

**Riesgo:** bajo. Coordinación ya ve esto ficha a ficha.

**Recomendación:** **GO.** Convierte una revisión de una tarde en un vistazo.

**Decisión del propietario:** [ ] GO  [ ] NO GO — nota:

---

## F5 — Compartir un evento con el texto ya escrito

**Para quién:** coordinación y monitores, que lo mandan a los grupos de WhatsApp, y familias, que se lo pasan a otras.

**Problema:** la ficha de un evento ya es «la del enlace de WhatsApp» (auditoría VEL-5), pero el mensaje se escribe a mano cada vez, copiando fechas, sitio y plazo, y a veces con el enlace equivocado (área o web pública).

**Propuesta:** un botón «Compartir» en la ficha que prepara «Convivencia COM · sáb 15 y dom 16 de noviembre · Benicàssim · Apúntate antes del 5/11: ‹enlace›». El enlace es el de la página pública si el evento la tiene y, si no, el de la ficha en el área (el destino ya sobrevive al login, EV-8). En el navegador abre la hoja de compartir del móvil (`navigator.share`). Dentro de la app, la WebView de Android no la tiene: hace falta un mensaje `sticpa:share` que la app atienda con su `Share` nativo. Hasta entonces, el botón da «Enviar por WhatsApp» (`wa.me` con el texto) y «Copiar».

**Qué necesita del CRM:** nada nuevo: `name`, `start_date`, `end_date`, `ajmcm_lugar_c`, `ajmcm_end_inscripcion_c`, `web_publicar_c` y `web_slug_c` (página pública `/actividades?e=…`).

**Esfuerzo:** S, más S en la app para `sticpa:share`.

**Riesgo:** bajo.

**Recomendación:** **GO.** Poco código y se usa cada semana.

**Decisión del propietario:** [ ] GO  [ ] NO GO — nota:

---

## F6 — «¿A quién apuntas?» en la ficha del evento (FAM-a14)

**Para quién:** familias con dos o más hijos en el MCM.

**Problema:** la ficha solo dice si está apuntado el hijo activo. Apuntar a dos hermanos exige cambiar de participante entre una inscripción y otra, y ninguna pantalla contesta «¿a cuáles de mis hijos he apuntado ya?». El detalle está en `plans/042-pulido-integral/auditoria-familias.md`, FAM-a14.

**Propuesta:** en la ficha, con 2+ hijos, una fila por hijo: «Apuntada ✓ · Ver», el botón «Apuntar» o «No es para su curso». El botón cambia de participante y lleva directo al formulario de ese evento.

**Qué necesita del CRM:** nada nuevo: las inscripciones de cada hijo (`stic_Registrations`) y su audiencia (`ajmcm_dirigido_a_c`, `ajmcm_filtro_edades_c`, `ajmcm_curso_escolar_c` de la relación).

**Esfuerzo:** L.

**Riesgo:** medio. Es una consulta por hijo, en una tanda paralela, en la pantalla a la que más se llega desde fuera, y hay que subir el tope de `tests/CosteLlamadasAreaTest.php`. Además toca el selector de familia, que ya ha dado sustos (FAM-a1).

**Recomendación:** **GO con matices.** Vale la pena, pero **después** de FAM-a1 y FAM-a8 (volver a la misma página al cambiar de hijo), que ya bajan de 7 a 5 toques lo de apuntar a dos hermanos. Si con eso las familias no se quejan, puede esperar.

**Decisión del propietario:** [ ] GO  [ ] NO GO — nota:

---

## F7 — Salud y autorizaciones al día al apuntarse

**Para quién:** familias, y quien organiza (que se ahorra perseguir datos).

**Problema:** antes de cada convivencia alguien pregunta por WhatsApp «¿sigue teniendo la misma alergia?», o se descubre en la casa de colonias que falta la autorización de actividades fuera del centro. Los datos están en el CRM, pero nadie le pide a la familia que los mire.

**Propuesta:**
- Al apuntar a un hijo (en el alta del área; los eventos con formulario web avanzado van por otro lado), un bloque corto: «Alergias: frutos secos · Se va sola a casa: Sí · Fotos: No. ¿Sigue todo igual? [Sí] [Cambiar]». «Cambiar» lleva a su perfil, que ya edita estos campos (`pages/single_stic_comunica_perfil.php`).
- En la portada, una línea solo si falta algo: «A Lucía le falta la autorización para participar».

**Qué necesita del CRM:** `ajmcm_aut_participar_c`, `ajmcm_actividadesout_c`, `ajmcm_soloacasa_c`, `ajmcm_cesionimagenes_interne_c`, `ajmcm_acepta_lopd_c`, `ajmcm_datossalud_c`, los cinco `ajmcm_descripcion_*` de salud y `ajmcm_tallas_c`. Si se quiere saber **cuándo** confirmó la familia, hay que crear un campo de fecha en Personas (nombre a decidir en Studio). Sin él funciona igual, pero no se puede pedir la confirmación «una vez por curso».

**Esfuerzo:** M.

**Riesgo:** bajo. «No» es una respuesta válida: solo cuenta como pendiente lo **vacío**.

**Recomendación:** **GO con matices.** Hay que decidir qué autorizaciones son obligatorias para apuntarse y si se crea el campo de fecha. Y que no se convierta en un muro: se avisa, no se bloquea.

**Decisión del propietario:** [ ] GO  [ ] NO GO — nota:

---

## F8 — La hoja de la salida, también sin cobertura

**Para quién:** los monitores que van a una convivencia o una excursión.

**Problema:** en una convivencia hace falta tener a mano, y sin cobertura, quién va, el teléfono de sus padres, sus alergias y si puede irse solo. Hoy se hace con un Excel sacado del CRM o con capturas, que acaban en móviles sin control y no se borran nunca.

**Propuesta:** una pantalla de solo lectura por evento, con los inscritos y lo importante de cada uno: teléfonos de los tutores, salud, autorizaciones y talla. Se guarda para usarla sin conexión con el mismo service worker de Pasar Lista: caché por usuario, que se borra al cerrar sesión. Solo está disponible desde unos días antes hasta unos días después del evento. Es la base del «pasar lista del bus», que ya está previsto (ver abajo): primero se lee, luego se marca.

**Qué necesita del CRM:** nada nuevo.
- `stic_Registrations` del evento, con `ajmcm_tutor1_phone_c` y `ajmcm_tutor2_phone_c`.
- `Contacts`: los `ajmcm_descripcion_*` de salud, `ajmcm_soloacasa_c`, `ajmcm_actividadesout_c`, `ajmcm_cesionimagenes_interne_c` y `ajmcm_tallas_c`.
- El dueño, `assigned_user_id` del evento.

**Esfuerzo:** M (L si se hace junto con pasar lista del evento).

**Riesgo:** **alto.** Son datos de salud de menores guardados en el móvil. Hay que decidir bien **quién** la ve: todos los monitores de la delegación o solo los inscritos como monitores en ese evento.

**Recomendación:** **GO con matices.** Es probablemente lo más útil para una salida, pero va después de F3 y con la regla de acceso decidida por escrito. A cambio, sustituye a los Excel sueltos, que son peores.

**Decisión del propietario:** [ ] GO  [ ] NO GO — nota:

---

## F9 — Avisar de un toque, con el mensaje preparado

**Para quién:** monitores y coordinación.

**Problema:** el aviso de faltas de la ficha ya tiene la casilla «notificado a la familia» y el botón de WhatsApp, pero el mensaje se escribe desde cero cada vez. Pasa lo mismo al recordar a un monitor que no ha pasado lista (COO-7) o que le falta un papel (F4).

**Propuesta:** botones «Avisar a la familia», «Recordárselo» y similares que abren WhatsApp (`wa.me`) con un texto ya escrito y editable: «Hola, somos los monitores de Lucía. Hemos visto que lleva tres sábados sin venir; ¿va todo bien?». Al volver, la pantalla pregunta «¿Lo has enviado?» y, si dices que sí, marca el aviso como notificado. Pulsar no es enviar, así que no se marca solo.

**Qué necesita del CRM:** `AVI_avisos.ajmcm_notificado_familia_c` (existe) y los teléfonos que ya se pintan. ⚠️ **Contradicción encontrada:** `CAMPOS.md` lista `ajmcm_notificado_el_c` («Cuándo se notificó»), pero el código (`inc/stic-pasar-lista-crm.php`, en el comentario de `sticpa_pl_avi_map()`) dice que **ese campo no se creó**. Si se quiere la fecha del aviso, hay que crearlo de verdad en Studio, o corregir `CAMPOS.md` si no se va a crear. Que lo mire el propietario.

**Esfuerzo:** S.

**Riesgo:** bajo.
- Los textos los tiene que escribir o revisar una persona del MCM: el tono con una familia importa.
- Nunca un `wa.me` al móvil de un menor sin `ajmcm_menorwhatsapp_c`, como ya hace la ficha.

**Recomendación:** **GO con matices.** Los textos los pones tú, y lo de F4 y COO-7 se suma cuando estén hechos.

**Decisión del propietario:** [ ] GO  [ ] NO GO — nota:

---

## F10 — Avisos en el móvil desde MCM App (notificaciones)

**Para quién:** todos. Por ejemplo, «se abre la inscripción de la convivencia», «te falta pagar el Foro» o, a un monitor, «no has pasado lista del sábado».

**Problema:** el área solo habla cuando alguien entra. Lo urgente se sigue mandando por WhatsApp a mano.

**Propuesta:** notificaciones de MCM App. La app pide permiso y registra el móvil, y un proceso nocturno o programado (como el Guardián o los cumpleaños) decide a quién avisar.

**Qué necesita del CRM:** nada para los datos. Hay que decidir dónde se guarda el registro de cada móvil (mejor en WordPress que en el CRM) y la preferencia de cada persona («no quiero avisos de…»).

**Esfuerzo:** L, entre dos repos (app y área) más el proceso que envía.

**Riesgo:** medio. Hacen falta consentimiento y baja fácil, y si se manda de más la gente desactiva las notificaciones para siempre.

**Recomendación:** **Más adelante.** F1 (el calendario, con sus alarmas) cubre buena parte de los recordatorios por una fracción del coste. Cuando se haga, yo empezaría por un solo aviso: el de la lista sin pasar a los monitores.

**Decisión del propietario:** [ ] GO  [ ] NO GO — nota:

---

## F11 — Certificado de horas de voluntariado

**Para quién:** monitores, sobre todo los universitarios, que lo piden para reconocer créditos.

**Problema:** cada certificado se escribe a mano en la delegación, contando sábados de memoria.

**Propuesta:** en el perfil del monitor, «Certificado de voluntariado»: una página imprimible con las horas de cada curso (sacadas de sus asistencias y de la duración de cada sesión), desde cuándo es monitor y su programa. Sale como **borrador** para que la delegación lo firme.

**Qué necesita del CRM:** nada nuevo: `stic_Attendances` (`status`), `stic_Sessions` (`start_date` y `end_date`), `ajmcm_monitor_desde_c`, `ajmcm_vol_descripcion_c`, `ajmcm_vol_programas_c` y `ajmcm_vol_acuerdo_c`.

**Esfuerzo:** M.

**Riesgo:** medio. Un certificado que se queda corto en horas perjudica a quien lo pide.

**Recomendación:** **Más adelante.** Solo hay asistencias desde el piloto (Castellón, 2025-2026), así que hoy se quedaría corto. Con un curso entero pasado en varias delegaciones, se retoma.

**Decisión del propietario:** [ ] GO  [ ] NO GO — nota:

---

## F12 — Tablón o chat de la delegación dentro del área

**Para quién:** todos, en teoría.

**Problema:** los avisos de la delegación van por grupos de WhatsApp, dispersos.

**Propuesta:** un tablón de anuncios (o un chat) dentro del área. El CRM tiene un módulo de Mensajes (`stic_Messages`).

**Qué necesita del CRM:** `stic_Messages`, que hoy no está en `CAMPOS.md` (el propietario lo dejó fuera el 08/10/2026, §7).

**Esfuerzo:** L.

**Riesgo:** alto.
- Sería un canal más que nadie mira, porque la gente ya está en WhatsApp.
- Habría que moderarlo y, con menores dentro, cumplir los protocolos de protección.
- El CRM es demasiado lento para algo que se parezca a un chat.

**Recomendación:** **NO GO.** Lo que de verdad quita mensajes es F1, F5 y F9 (y F10 algún día), que llevan la información a WhatsApp y al calendario en vez de competir con ellos.

**Decisión del propietario:** [ ] GO  [ ] NO GO — nota:

---

## Ya previsto (no cuenta como nuevo)

| Idea | Dónde está |
|---|---|
| Tarjeta «Te falta pagar…» en la portada y chip «Pendiente de pago» en Eventos | `plans/041` §5.3 (quedó para después de F2) |
| Instrucciones de transferencia o Bizum en el pago pendiente, por evento | `TODO.md` PAG-02 · `plans/041` D-5 y F4 |
| Pasar lista de un evento (el bus de la convivencia) | `PASAR-LISTA-ROADMAP.md` melón 1 · `plans/036` (P3). F8 es su primer paso |
| Correo automático a coordinación al crear un aviso | `TODO.md` PL-036 · ROADMAP melón 5 |
| Recordar en la portada la lista olvidada del sábado | Auditoría PL-4, lote L2 del 042 |
| «A quién hay que mirar» también en Coordinación | COO-8, decisión en el 042 |
| Quién lleva el grupo que no ha pasado lista | COO-7, lote L3 del 042 |
| Cambiar de hijo sin volver a la portada | FAM-a8, decisión en el 042 |
| «Apuntarse» y «Mis inscripciones» (o una sola sección) | FAM-a10, decisión en el 042 |
| Que la app abra el enlace del correo en su destino | `TODO.md` EV-10 (repo de la app) |
| Las dos puertas (área y formulario avanzado) en la página pública | `TODO.md` EV-3 |
| Montar el curso de una delegación nueva con una skill | ROADMAP fase 7 |

## Lo que he mirado y no propongo

- **Plazas libres y lista de espera.** `max_attendees` vale `0` en todos los eventos (valor por defecto, no «sin plazas») y no hay clave de `status` para «en espera» (ni siquiera está decidida la de las inscripciones de Pasar Lista, PL-MON-6). El día que una delegación rellene un aforo de verdad, «Quedan N plazas» es un S.
- **El teléfono de los monitores para las familias.** No: el canal con las familias es la delegación, por protección del menor y porque muchos monitores son voluntarios muy jóvenes.
- **Firma electrónica de las autorizaciones de cada salida** (`stic_Signatures`). El módulo existe, pero quedó fuera el 08/10/2026 y `ajmcm_actividadesout_c` ya cubre la autorización general. Volver a mirarlo si alguna actividad exige firma por salida.
- **Pagar varias cosas de golpe (carrito).** Una familia rara vez debe dos cosas a la vez, y complicaría el flujo de pagos que el plan 041 acaba de dejar limpio.
