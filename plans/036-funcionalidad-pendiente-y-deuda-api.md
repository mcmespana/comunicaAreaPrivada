# 036 — Funcionalidad pendiente, bugs menores y la doctrina de la API

**Prioridad: P2 en conjunto; cada fila lleva la suya.** Esfuerzo: por fila.
Depende de: 033 cerrado antes de abrir melones nuevos (regla de oro del parte:
mientras el guardado no funcione, lo demás es decoración).

Este plan consolida en UN sitio lo que está abierto y disperso entre
`PASAR-LISTA-ESTADO.md` §1, `PASAR-LISTA-ROADMAP.md` («melones») y
`PASAR-LISTA-COORDINACION.md`, con su siguiente paso concreto. Y fija la
**doctrina de la API** que evita que cada funcionalidad nueva tropiece con las
mismas cinco piedras.

> ## Estado al 28/08/2026 (tarde): NO está sin empezar
>
> Tres cosas de este plan se cerraron el 28/08 sin abrirlo formalmente, porque
> salieron persiguiendo otros fallos. Están marcadas ✅ abajo. Lo que queda es,
> casi todo, **decisión de producto o petición al CRM**, no código.
>
> Y la doctrina de §4 se queda corta en un punto que costó caro: **para
> ESCRIBIR también hay que usar el campo plano.** Añadida como regla 8.

## 1. Bugs y flecos abiertos (de ESTADO §1)

| P | Qué | Siguiente paso concreto |
|---|---|---|
| ✅ | ~~Asistencias de 5 sesiones sin `status` (a medio marcar)~~ | **Hecho el 28/08/2026.** Un hueco ya no se lee como avería en ningún sitio: `sticpa_pl_att_track()` cuenta los sin marcar APARTE, los dice en pantalla («24 sábados sin lista»), devuelve «sin datos» en vez de un 0 % cuando no hay nada marcado, y el cuadradito de «sin marcar» es un contorno discontinuo con su entrada en la leyenda — no un gris que parezca un estado. |
| P2 | Monitor de dos grupos: sale, pero sin decidir cómo se enseña | Decisión de producto (propietario): ¿etiqueta doble en el árbol? ¿aviso en la ficha? Después, cambio solo en builders de UI. |
| P2 | El grupo `Najar` (no MIC-COM) aparece en el árbol | Definir el filtro de grupos visibles (ver melón 4 abajo: la solución buena es común). Mientras: filtro por lista negra vía `apply_filters` para no hardcodear. |
| P2 | «Sectores» agrupados a mano | Sin campo en el CRM y no lo va a haber de momento (ROADMAP §6). Dejarlo como está; documentado y punto. |
| P3 | Filtro plano por `..._ida` da 400 en 4 módulos | No es arreglable desde el plugin: es doctrina (abajo). Ya documentado en CAMPOS §9. |

## 2. Melones del ROADMAP, con su próximo paso

| P | Melón | Próximo paso concreto |
|---|---|---|
| P1 | **Recuentos y nombre de monitor en árbol/resumen** (ROADMAP 0, plan cerrado en `PASAR-LISTA-RECUENTOS.md`) | (1) Preguntar a SinergiaCRM si hay acceso a ficheros de la instancia — decide opción A/B; (2) crear los 4 campos en `ajmcm_GRUPOS` (¡mirar CAMPOS.md antes, no duplicar!); (3) script nocturno del Guardián que los rellene (opción B recomendada); (4) UI: entra gratis en la consulta del árbol. Propagar campos nuevos a `CAMPOS.md` y `comunicaFormularios` si toca. |
| ✅ | ~~**Inscripciones automáticas** (ROADMAP 3)~~ | **Hecho el 28/08/2026, y por la vía recomendada: el área privada.** `sticpa_pl_ensure_registration()` crea la inscripción que falte al guardar, comprobando antes el mapa para no duplicar, y la asistencia se escribe atada a ella. Cuesta una llamada por persona la PRIMERA vez y ninguna después. Cierra el hueco «sin inscripción → no se le puede pasar lista», que era además la fábrica de las asistencias `Unknown - Unknown`. Se puede apagar por delegación con `sticpa_pl_crear_inscripciones`. |
| P2 | **Grupos viejos fuera de la navegación** (ROADMAP 4) | Regla propuesta: un grupo sale en Pasar Lista si su etapa tiene evento de sesiones del curso actual Y el grupo tiene ≥1 relación vigente. Cubre también `Najar`. Validar con el propietario antes de codificar. |
| P2 | **Workflow de correo de avisos** (ROADMAP 5) | Configuración del CRM, no código. Bloqueado por definir coordinadores (`relationship_type = coordinador_mic/com`, COORDINACION §6). Escribir la petición concreta para el administrador del CRM. |
| P3 | **Pasar lista de un evento puntual** (convivencia, bus — ROADMAP 1) | Antes de nada: comprobar que `LIS_listas` admite grupo vacío. Reusar pantalla de marcar con fuente = inscritos del evento. No abrir hasta tener el semanal cerrado y rodado. |
| P3 | **Ausencias de monitores con porcentaje** (ROADMAP 2) | Primero el encuadre (dato sensible entre compañeros) con el propietario; la técnica ya existe a medias (monitores ya se marcan). |
| P3 | Verificar `CAMPOS.md` contra el CRM por MCP (ROADMAP 9) | Subagente que recorra `get_module_fields` de los módulos usados y compare con CAMPOS.md. Barato de hacer ya; salida = lista de discrepancias, no cambios automáticos. |

## 3. Pendiente en el CRM (no en el código) — lo que hay que PEDIR

Consolidado de ESTADO §8, para despacharlo de una vez con quien administre el CRM:

1. Workflow de correo al crear aviso (cuando existan coordinadores).
2. `phone_mobile` mal en `CAMPOS.md` → corregir y **propagar a
   `comunicaFormularios`** (regla de CLAUDE.md).
3. Cerrar la relación de monitor del grupo `Najar`.
4. ✅ ~~**Verificar el acceso del usuario API del PLUGIN a `LIS_listas` y
   `stic_Attendances`**~~ — **resuelto por evidencia el 28/08/2026:** el
   usuario SÍ escribe en los dos (hay `LIS_listas` creadas por la aplicación y
   un centenar de `stic_Attendances` escritas por ella, con `status`). No hay
   nada que pedir.
5. Renovar las vigencias de las relaciones del piloto (caducan 31/08/2026 —
   ESTADO §1: si el 1/09 C1 sale vacío, es ESTO, no un bug).
6. 🔴 **NUEVO (28/08/2026): reasignar a la delegación el entorno personal de
   Solete**, hoy a nombre de «Administrador MCM» (id `1`). De `assigned_user_id`
   cuelga el grupo de seguridad, así que el registro existe, está vigente,
   enlaza bien con su madre… y la ficha no lo ve. **Revisar si hay más igual.**
7. 🔴 **NUEVO (28/08/2026): borrar el centenar de `stic_Attendances`
   `Unknown - Unknown | `** (las que no tienen inscripción enlazada). El código
   que las creaba ya está arreglado; las que hay siguen ahí.

## 4. La doctrina de la API (lo que hace sufrir a quien encadena llamadas)

Para que cada funcionalidad nueva no redescubra las trampas a base de días
perdidos. **Regla de oro: no inventes una consulta nueva; copia un cargador
que ya funcione.**

1. **Lecturas con relación**: `get_entry_list` +
   `link_name_to_fields_array` (4º parámetro de `getRecordsModule()`) es la
   vía probada. `get_relationships` con enlace anidado MIENTE por omisión
   (llega 200 sin el enlace): si se usa, SIEMPRE pedir además el campo plano
   `..._ida` y aceptar el que llegue, y SIEMPRE tener respaldo `_direct`
   (patrón de `sticpa_pl_event_registrations`).
2. **Nunca filtrar en el `query` por un campo plano `..._ida`** (400 de base
   de datos en `stic_Sessions`, `LIS_listas`, `stic_Registrations`,
   `stic_Contacts_Relationships`). Filtrar por `assigned_user_id`/fechas y
   cribar en PHP.
3. **Una llamada por colección, nunca por fila.** Cargador nuevo = tope nuevo
   en `tests/CosteLlamadasTest.php`, que es donde los 1+N se ven antes de
   producción.
4. **Los errores del CRM llegan como 200 con `{number, name, description}`.**
   Tras 033, el transporte los registra: cualquier código nuevo debe mirar el
   retorno (`set_entry` null, `set_relationship` false) y contarlo, no
   asumir éxito.
5. **Dobles de test con la forma REAL de la API** (`relationship_list`
   paralela por posición, ensamblada por `attachLinkList()`); un doble con su
   propio ensamblado no prueba nada (trampa §3.2; ya costó 175 tests verdes
   con producción rota).
6. **Los desplegables no se validan**: una clave mal escrita se guarda sin
   protestar. Claves SOLO de `CAMPOS.md`; ante la duda, preguntar, no probar.
7. **Caché**: toda caché nueva se nombra dentro de la familia correcta
   (`state` vs `struct`) o el flush de guardado no la tirará (ya pasó con
   `listas` y `attrange`).
8. **PARA ESCRIBIR, el campo plano también.** Añadida el 28/08/2026, y costó
   cuatro avisos sin dueño y un centenar de asistencias basura. `set_relationship`
   escribe la tabla puente **por detrás del bean**: el registro queda
   relacionado, pero el campo desde el que la pantalla del CRM lo PINTA se queda
   vacío, y el `name` que el CRM compone AL GUARDAR ya se ha calculado sin los
   enlaces — «Unknown - Unknown» para siempre. **Los enlaces van DENTRO del
   `set_entry`, en su `..._ida`**; `set_relationship` es refuerzo, no camino
   principal. Y ojo: un campo *relate* (`ajmcm_sesion_c`) es el que se PINTA;
   el id va en su campo aparte (`stic_sessions_id_c`). Detalle en CAMPOS §9-bis.
9. **Una escritura tiene que poder volver a encontrarse.** Corolario del modelo:
   una asistencia cuelga de la INSCRIPCIÓN, no de la persona. Escribir un
   registro que nada ata a nadie es peor que no escribirlo, porque no se nota y
   se duplica en cada intento. Si no se puede atar, no se escribe.

Dónde dejarlo: esta sección se copia (o enlaza) como cabecera de
`PASAR-LISTA-CAMPOS-CRM.md` §9 si no está ya, y CLAUDE.md ya manda leerla.

## STOP conditions

- STOP en cualquier fila que pida crear campos en el CRM sin haber mirado
  `CAMPOS.md` y preguntado si ya existe algo (regla del repo).
- STOP en decisiones de producto marcadas «propietario» (cómo enseñar el
  monitor doble, filtro de grupos, encuadre de ausencias de monitores): se
  proponen opciones, no se decide en el PR.
- Los cambios de `CAMPOS.md` obligan a propagar a `comunicaFormularios`.
