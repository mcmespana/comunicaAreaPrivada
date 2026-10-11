# Pasar lista en un evento — el autobús

> Pedido por el propietario el 10/10/2026: «poder pasar lista por eventos,
> pensado para el momento de subir al autobús y ver que estamos todos». Ágil,
> rápido y que aguante si falla la red.

Pasar Lista de los sábados es la lista de un **grupo** en una sesión semanal.
Esto es la de un **evento** —una convivencia, una salida, un campamento— con la
gente que está inscrita.

| Pieza | Dónde |
|---|---|
| Lógica (eventos, lista, sesión del día, guardado) | [`inc/stic-pasar-lista-eventos.php`](../../inc/stic-pasar-lista-eventos.php) |
| La pantalla (elegir evento y la lista) | [`pages/single_stic_pasar_lista_evento.php`](../../pages/single_stic_pasar_lista_evento.php) |
| Entrada desde la portada de Pasar Lista | [`pages/single_stic_pasar_lista.php`](../../pages/single_stic_pasar_lista.php) |
| Filtro, «Están todos» y la cola sin cobertura | [`js/stic-pasar-lista.js`](../../js/stic-pasar-lista.js) (lo de marcar, más el bloque «Lista de un evento») |
| Estilos | [`css/pasar-lista.css`](../../css/pasar-lista.css), sección «042 · Pasar lista en un EVENTO» |
| Pruebas | `tests/PasarListaRenderTest.php`, las `test_ev_*` y `test_home_ofrece_el_evento_de_hoy_*` |

---

## 1. Cómo se usa

1. **Pasar lista** → arriba sale la tarjeta del evento que **empieza o acaba hoy**
   («Hoy empieza · Convivencia MIC y COM · Pasar lista»). Un toque y estás en
   la lista. Si no hay ninguno hoy, la fila **«Pasar lista en un evento»** (debajo
   de los grupos) lleva a todos los del curso.
2. En la lista: **nadie está marcado**. Una inscripción confirmada no es «está
   en el autobús»: eso lo afirma quien pasa lista. Toque = está / no está;
   mantener pulsado = parcial o justificada (con motivo), igual que los sábados.
3. **Por etapa** (MIC, COM, LC) y, arriba, **chips por curso** con cuántos hay
   en cada uno («1º ESO 12»). Con un chip elegido se ve solo ese curso, y
   **«Están todos»** marca solo lo que se ve y solo lo que está sin marcar.
4. **Buscador** por nombre («¿y Lucía?»).
5. Las inscripciones **canceladas** (o rechazadas…) salen al final, en
   «No van», apagadas y sin poder marcarse: contestan «¿y fulanito?» sin salir.
6. **Guardar**. Si no hay cobertura, se queda en el móvil y se envía solo al
   volver (ver §4).

---

## 2. Qué eventos salen

- Los de **tu delegación** (`stic_Events.assigned_user_id` = la delegación),
  del **curso escolar en marcha** (1 de septiembre – 31 de agosto) y con fecha
  de inicio.
- Fuera: los que duran **más de 50 días** (son de todo el curso —las sesiones
  semanales de una etapa, una actividad anual— y eso es Pasar Lista de grupos;
  decidido el 10/10/2026, filtro `sticpa_pl_ev_max_dias`), el evento de
  **sesiones semanales** de cada etapa y el de **reuniones de programación**.
- Orden: **hoy** (empieza hoy, acaba hoy, o está en marcha), luego los
  **próximos** (el más cercano arriba) y los **pasados del curso** (el más
  reciente arriba). Con más de 8, buscador.
- **Cero llamadas propias**: salen del cargador común de eventos
  (`sticpa_pl_events_raw()`), que la portada de Pasar Lista ya ha leído. La
  portada no hace ni una llamada más por enseñar la tarjeta de hoy (lo fija un
  test).

⚖️ **Eventos nacionales (decisión del propietario).** Un evento organizado por
ECE u otra delegación tiene inscritos de todas, y lo de un evento es de quien
lo organiza (CLAUDE.md, decidido el 01/10/2026). Por eso **no salen**: «nada
interdelegacional». Si se quiere pasar lista del autobús de TU delegación a un
evento nacional, habría que filtrar sus inscritos a la gente de tu delegación y
decidir de quién son las asistencias. Pendiente de decisión.

---

## 3. Quién sale y con qué datos

| Dato | De dónde |
|---|---|
| Inscritos | `stic_Events` → `stic_registrations_stic_events`: `id`, `status`, `ajmcm_curso_escolar_c` y el contacto (`stic_registrations_contactscontacts_ida`, o el enlace anidado; si falta, el mapa de siempre con su respaldo) |
| Nombre y etapa | `Contacts`: `first_name`, `last_name`, `ajmcm_etapa_c` (una consulta por id para todos) |
| Curso | `stic_Registrations.ajmcm_curso_escolar_c` (lista `ajmcm_curso_escolar_c_list`, dominio completo en `CAMPOS.md`). `na` y vacío = «Sin curso» |
| Grupo (para la ficha) | El mapa de relaciones de la delegación, ya cacheado |

- **Etapa**: la de la ficha. Si está vacía, se **deduce del curso**: primaria →
  MIC; ESO, Bachillerato y FP → COM. Universitario y «otros» no se deducen
  («Sin etapa»). Es una deducción, no un campo: si la ficha tiene etapa, manda.
- **Estados que no van** (`sticpa_pl_ev_estados_fuera()`, con filtro):
  `cancelled` (lo escribe el área al cancelar), `rejected`, `uninvited`,
  `not_invited`, `didnt_take_part`. Todo lo demás —`confirmed`, vacío u otro— se
  marca: ante la duda, que salga en la lista del autobús.
- Una persona con dos inscripciones (una cancelada vieja y una confirmada
  nueva) sale **una vez**, con la activa.
- Las inscripciones se cachean con el TTL de **estado** (5 min, familia
  `state`, clave `evins`): el día de la salida la gente se apunta y desapunta, y
  el guardado tira esa caché.

---

## 4. Qué se escribe

Una **asistencia** (`stic_Attendances`) por persona marcada, en la **sesión del
evento de ese día**, con el motor de siempre (`sticpa_pl_write_attendances()`):
actualiza la que ya hay —el CRM las genera al crear la inscripción si el evento
ya tenía sesiones— y crea la que falta, atada a su inscripción y en tandas. Los
cuatro estados de `PASAR-LISTA-CAMPOS-CRM.md` §3; lo no marcado no se escribe.

- **Qué sesión**: la de hoy; si no hay, la regla de los sábados
  (`sticpa_pl_pick_session()`: la última que ya pasó, o la próxima). En un
  evento de varios días el autobús de ida cae en la del primer día y el de
  vuelta en la del último. Con varias, desplegable en la cabecera.
- **Evento sin sesiones en el CRM**: la pantalla lo dice («Al guardar se crea la
  lista del sábado 15 de noviembre») y al guardar se crea **la sesión de ese
  día** (`stic_Sessions`, de la delegación, atada al evento por el campo plano y
  por `set_relationship`): hora del evento si ese día empieza o acaba, si no las
  9:00, y una hora de duración. Antes de crearla se releen las sesiones sin
  caché, para no duplicarla si la cola reenvía o dos monitores guardan a la vez.
- **No** se escribe `LIS_listas`: esas son las listas de los grupos de los
  sábados y alimentan el Resumen de coordinación.
- **Comprobado al releer**: «Lista guardada · 12 están, 1 falta» y «Guardada ✓»
  solo salen si al volver a leer el CRM las marcas están (`sticpa_pl_check_saved()`).
  Si no, las marcas siguen en pantalla y se dice.

### Sin cobertura y con la red mala

Es el mismo marcado y el mismo JS que la lista de los sábados, así que hereda
todo lo del plan 033 y PL-9: borrador en el móvil a cada toque, **cola** que se
envía sola al volver la cobertura (también desde la portada), reintento con
**nonce fresco** si caducó, y el borrador solo se tira cuando el servidor
confirma el guardado (`data-pl-saved-ok`).

Dos detalles propios: la cola guarda **la URL del formulario** (lleva el evento,
la sesión y, si el evento no tiene sesiones, el **día** en que se marcó: un
envío que llega mañana crea la sesión de hoy, no la de mañana); y la clave del
borrador es la sesión o `nueva-<día>`, así que no se mezcla con otro día.

---

## 5. Seguridad

- El evento tiene que estar entre los de **tu delegación y de este curso**; si
  no, la pantalla dice lo mismo exista o no el id.
- Lo que llega del navegador se filtra: solo personas **activas de ese evento**
  (no canceladas, no ajenas) y solo los cuatro estados conocidos. El motivo, con
  `sanitize_textarea_field` y 255 caracteres.
- Nonce por evento (`pl_save_ev_<id>`). Todo lo pintado, escapado.

---

## 6. Cómo probarlo

- `vendor/bin/phpunit --filter _ev_` — la clasificación, la etapa, el armado de
  la lista, el selector, la lista, el guardado (solo lo marcado, solo gente con
  plaza), el nonce caducado, el evento sin sesiones y el evento ajeno. El doble
  del CRM tiene un interruptor `$conEventos` con una convivencia que empieza
  «hoy» (15/11/2025).
- **En producción**, la primera vez: una salida con sesiones y otra sin ellas;
  marcar a unos, guardar, y mirar en el CRM las asistencias de esa sesión (y la
  sesión nueva en el segundo caso). Probar también en modo avión: marcar,
  guardar («Guardado en el móvil»), volver a tener red y abrir Pasar Lista.
