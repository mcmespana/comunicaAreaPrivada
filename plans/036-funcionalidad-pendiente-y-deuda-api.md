# 036 — Funcionalidad pendiente, bugs menores y la doctrina de la API

**Prioridad: P2 en conjunto; cada fila lleva la suya.** Esfuerzo: por fila.
Depende de: 033 cerrado antes de abrir melones nuevos (regla de oro del parte:
mientras el guardado no funcione, lo demás es decoración).

Este plan consolida en UN sitio lo que está abierto y disperso entre
`PASAR-LISTA-ESTADO.md` §1, `PASAR-LISTA-ROADMAP.md` («melones») y
`PASAR-LISTA-COORDINACION.md`, con su siguiente paso concreto. Y fija la
**doctrina de la API** que evita que cada funcionalidad nueva tropiece con las
mismas cinco piedras.

## 1. Bugs y flecos abiertos (de ESTADO §1)

| P | Qué | Siguiente paso concreto |
|---|---|---|
| P1 | Asistencias de 5 sesiones sin `status` (a medio marcar) | Con 033 cerrado, decidir si un estado vacío en sesión pasada se enseña como «sin pasar» en resumen/árbol (hoy puede leerse como avería). Solo UI. |
| P2 | Monitor de dos grupos: sale, pero sin decidir cómo se enseña | Decisión de producto (propietario): ¿etiqueta doble en el árbol? ¿aviso en la ficha? Después, cambio solo en builders de UI. |
| P2 | El grupo `Najar` (no MIC-COM) aparece en el árbol | Definir el filtro de grupos visibles (ver melón 4 abajo: la solución buena es común). Mientras: filtro por lista negra vía `apply_filters` para no hardcodear. |
| P2 | «Sectores» agrupados a mano | Sin campo en el CRM y no lo va a haber de momento (ROADMAP §6). Dejarlo como está; documentado y punto. |
| P3 | Filtro plano por `..._ida` da 400 en 4 módulos | No es arreglable desde el plugin: es doctrina (abajo). Ya documentado en CAMPOS §9. |

## 2. Melones del ROADMAP, con su próximo paso

| P | Melón | Próximo paso concreto |
|---|---|---|
| P1 | **Recuentos y nombre de monitor en árbol/resumen** (ROADMAP 0, plan cerrado en `PASAR-LISTA-RECUENTOS.md`) | (1) Preguntar a SinergiaCRM si hay acceso a ficheros de la instancia — decide opción A/B; (2) crear los 4 campos en `ajmcm_GRUPOS` (¡mirar CAMPOS.md antes, no duplicar!); (3) script nocturno del Guardián que los rellene (opción B recomendada); (4) UI: entra gratis en la consulta del árbol. Propagar campos nuevos a `CAMPOS.md` y `comunicaFormularios` si toca. |
| P1 | **Inscripciones automáticas** (ROADMAP 3) | Decidir vía: workflow del CRM en after_save de la relación vs. crearla el área privada al inscribir. Recomendación: área privada (visible, testeable, sin depender de acceso al CRM), con verificación de duplicado antes de crear. Además cubre el hueco «participante sin inscripción → no se le puede pasar lista». |
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
4. **Verificar el acceso del usuario API del PLUGIN a `LIS_listas` y
   `stic_Attendances`** (edición y campos `status`/`estado` no read-only) —
   sale del diagnóstico del 033 y tiene precedente (`stic_FollowUps`).
5. Renovar las vigencias de las relaciones del piloto (caducan 31/08/2026 —
   ESTADO §1: si el 1/09 C1 sale vacío, es ESTO, no un bug).

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

Dónde dejarlo: esta sección se copia (o enlaza) como cabecera de
`PASAR-LISTA-CAMPOS-CRM.md` §9 si no está ya, y CLAUDE.md ya manda leerla.

## STOP conditions

- STOP en cualquier fila que pida crear campos en el CRM sin haber mirado
  `CAMPOS.md` y preguntado si ya existe algo (regla del repo).
- STOP en decisiones de producto marcadas «propietario» (cómo enseñar el
  monitor doble, filtro de grupos, encuadre de ausencias de monitores): se
  proponen opciones, no se decide en el PR.
- Los cambios de `CAMPOS.md` obligan a propagar a `comunicaFormularios`.
