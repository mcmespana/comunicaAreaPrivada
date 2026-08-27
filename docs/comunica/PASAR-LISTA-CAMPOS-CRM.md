# Pasar Lista — campos y módulos del CRM

Checklist de lo que hay que tocar en SinergiaCRM para que «Pasar Lista» funcione.
Cuando esté hecho y confirmado, esto se integra en
[`CAMPOS.md`](CAMPOS.md) (y de ahí al repo `comunicaFormularios`).

Estado: ✅ hecho · 🔨 por crear · ❓ duda que hay que resolver antes · ❌ descartado

---

## 1. Módulos

| Módulo | Estado | Notas |
|---|---|---|
| `LIS_listas` — «Pasando Lista» | ✅ **creado y verificado** | Estructura correcta, ver §4 |

No hace falta ningún otro módulo nuevo. Todo lo demás son campos.

---

## 2. Lo que hay que crear

### 🔨 `ajmcm_GRUPOS` → `ajmcm_pasar_lista_c`  ← **pedido el 27/08/2026**

| | |
|---|---|
| **Módulo** | `ajmcm_GRUPOS` (Grupos MCM) |
| **Etiqueta** | Entra en Pasar Lista |
| **Tipo** | Casilla de verificación (`bool`) |
| **Por defecto** | Desmarcada |
| **Obligatorio** | No |

**Para qué.** En el CRM hay ~150 grupos y la mayoría son históricos. Salían
todos: en el árbol, en el buscador y en el alcance de coordinación. Esta casilla
dice qué grupos están de verdad en el sistema, y limpia la pantalla sin borrar
nada del CRM.

**La regla de seguridad, que es lo importante.** El día que se cree el campo
estará **vacío en los ~150 grupos**. Si el filtro actuara sin más, Pasar Lista
se quedaría sin un solo grupo y parecería que se ha roto todo. Por eso:

> **Si no hay NINGUNA casilla marcada en la delegación, no se esconde nada.**
> El filtro se enciende solo cuando alguien empieza a marcar.

Así se puede crear el campo con calma y marcar los grupos poco a poco: hasta que
haya el primero marcado, todo sigue exactamente como ahora. Y desde el primero,
solo salen los marcados — y el árbol dice cuántos quedan fuera, para que nadie
busque un grupo que está ahí pero sin marcar.

**Mientras no exista el campo**, si el CRM protestara por pedir una columna que
no tiene, se apaga sin tocar código:

```php
add_filter('sticpa_pl_has_grupo_activo', '__return_false');
```

Y si se decide otro nombre, se ajusta con `sticpa_pl_grupo_activo_field`.

**No hay que propagar nada a `comunicaFormularios`**: ningún formulario público
escribe en este campo, es de gestión interna.

### 🔨 `ajmcm_GRUPOS` → `ajmcm_segmento_com_c`

| | |
|---|---|
| **Módulo** | `ajmcm_GRUPOS` (Grupos MCM) |
| **Etiqueta** | Segmento COM |
| **Tipo** | Desplegable (enum) |
| **Valores** | `com_1` [COM I] · `com_2` [COM II] · `com_3` [COM III] |
| **Obligatorio** | No (solo aplica a grupos con `level = com`) |

**Por qué en Grupos y no en Personas ni en Relaciones con Personas.**

El segmento es una propiedad **del grupo**: un grupo entero *es* COM II. No tiene
sentido que dentro de un mismo grupo haya chavales de COM I y de COM II — si los
hubiera, serían dos grupos. Por tanto el dato vive donde vive la cosa que
describe.

Y sobre todo: **no se puede confundir con el nivel personal, que ya existe.**
`ajmcm_nivel_com_c` en Personas es otra cosa — el itinerario personal de cada
uno (I Conocimiento, II Incorporación, III Crecimiento, IV Opción Responsable).
Son dos ejes distintos:

```
Segmento (grupo)   →  cómo organizamos los grupos del COM: COM I / II / III
Nivel (persona)    →  por dónde va cada chaval en su itinerario: I / II / III / IV
```

Meterlo en Personas sería duplicar un dato que ya se deduce de su grupo.
Meterlo *además* en `stic_Contacts_Relationships` sería tener dos fuentes de
verdad para lo mismo, y el día que alguien cambie el segmento de un grupo y no
las relaciones, tendríamos dos respuestas distintas a la misma pregunta. Como
cada curso se crea una relación nueva a un grupo nuevo, el histórico del
segmento queda registrado igual a través del grupo.

❓ **Duda a resolver antes de crearlo:** ¿son 3 valores o hay que casarlos con
los 4 niveles personales? Si COM III agrupa «Crecimiento + Opción Responsable»
conviene dejarlo escrito, porque si no dentro de un año nadie se acuerda.

---

### Eventos (`stic_Events`)

| Campo | Tipo | Para qué |
|---|---|---|
| `ajmcm_etapa_c` | **selección múltiple** (`MIC`, `COM`, `LC`) | A qué etapas sirve el evento de sesiones semanales |

Es de selección **múltiple** a propósito: en una delegación pequeña el sábado es
el mismo para MIC y para COM, y entonces hay UN evento marcado con las dos. El
evento aparece en las dos etapas y comparte sesiones, que es exactamente lo que
pasa en la realidad.

Antes la etapa se deducía de lo que había antes del `|` en el nombre del evento,
y eso se rompía en cuanto alguien renombraba un evento. Ahora **manda el campo**;
el nombre solo se mira si el campo está vacío, para los eventos creados antes de
que existiera. Ver `sticpa_pl_event_etapa_field()` y
`sticpa_pl_etapas_from_multi()`.

> Ojo: en `Contacts` ya existe un `ajmcm_etapa_c` (la etapa de la persona). Son
> campos distintos en módulos distintos y el nombre repetido es a propósito, para
> que se lea igual en los dos sitios.

---

## 3. Lo que YA EXISTE y no hay que crear

Esto es la mitad del valor de este documento: evitar que se creen campos
duplicados. Todo lo de abajo lo necesita «Pasar Lista» y **ya está en el CRM**.

### Personas (`Contacts`)

| Campo | Para qué lo usa Pasar Lista |
|---|---|
| `ajmcm_etapa_c` | MIC / COM / LC — el primer nivel del árbol de navegación |
| `ajmcm_nivel_com_c` | Nivel personal I-IV. **No** es el segmento del grupo |
| `ajmcm_panuelo_c` | Ficha del participante, editable con confirmación |
| `ajmcm_soloacasa_c` | Ficha: «puede irse solo a casa» |
| `stic_age_c` | Ficha: la edad, ya calculada desde `birthdate` |
| `birthdate` | Ficha |
| `ajmcm_descripcion_allergies__c` y hermanos (intolerancias, tratamientos, enfermedades, otros) | Ficha: bloque de salud |
| `ajmcm_menorwhatsapp_c` | Si se puede o no meter al menor en WhatsApp — **condiciona el botón de WhatsApp de la ficha** |
| `ajmcm_centro_educativo_c` | Ficha (secundario) |
| `stic_relationship_type_c` | Detección de rol (monitor / laico), ya se usa hoy |
| `email1`, `phone_other` | Contacto. `phone_other` es «contacto de emergencias» |

### Grupos (`ajmcm_GRUPOS`)

| Campo | Para qué |
|---|---|
| `code` | El código corto: «C1». Es lo primero que se lee en pantalla |
| `name` | Nombre del grupo |
| `level` | Etapa del grupo: `mic` · `com` · `lc` · `apoyo` |
| `cursos_c` | Curso escolar, texto libre |
| `ajmcm_grupos_accounts_*` | Delegación |
| `ajmcm_pasar_lista_c` | **Casilla: este grupo entra en Pasar Lista.** Ver §2 — mientras no haya ninguna marcada, no filtra |

### Entorno personal / familia (`stic_Personal_Environment`)

Verificado contra el CRM el **27/08/2026**, y aquí estaba un bug que dejaba la
ficha sin teléfonos:

| Campo | Qué es |
|---|---|
| `stic_personal_environment_contactscontacts_ida` | Un lado de la relación (en el piloto, **el participante**) |
| `stic_personal_environment_contacts_1contacts_ida` | El otro lado (**el familiar**) |
| `relationship_type` | Parentesco. **Las claves están en INGLÉS**: `mother` verificado. Obligatorio |
| `reference_contact`, `authorized_signer` | Casillas |
| `end_date` | Si está pasada, la relación ya no cuenta |

⚠️ **Los DOS lados acaban en `_ida`.** No hay ningún `_idb`. El plugin pedía
`stic_personal_environment_contacts_1contacts_idb` —que no existe— y leía los
datos del familiar SOLO del enlace anidado, que esta instancia no puebla: el
bloque de la familia salía vacío en todas las fichas sin decir nada. Arreglado
el 27/08/2026.

⚠️ **El enlace anidado NO trae los datos del contacto** (ni nombre completo ni
teléfono), solo los campos `_name`. Para el teléfono hay que leer el contacto
aparte — el plugin lo hace en **una sola consulta para toda la familia**.

El teléfono del familiar está en **`phone_mobile`** (verificado). `phone_home`,
`phone_work` y `phone_other` venían vacíos en el caso mirado.

Desde `Contacts`, la relación solo contesta por el enlace
`stic_personal_environment_contacts`; el `_1` devuelve cero. El plugin pregunta
por los dos porque puede estar creada en cualquier sentido.

### Relaciones con Personas (`stic_Contacts_Relationships`)

| Campo | Para qué |
|---|---|
| `relationship_type` | `participante_mic_com` · `monitor` · `grupo` · `familiar_menor` |
| ↳ `grupo` | El papel de los **+18** en su grupo de referencia. Cuenta como participante del grupo a todos los efectos (lista de marcado y recuento) — corregido el 24/08, `sticpa_pl_rel_types()` en PHP y `PAPELES` en el Guardián |
| `ajmcm_grupos_stic_contacts_relationships_*` | **El vínculo persona ↔ grupo.** La pieza central |
| `ajmcm_etapa_relacion_c` | Etapa de esa relación |
| `ajmcm_curso_escolar_c`, `ajmcm_delegacion_c` | |
| `start_date` / `end_date` / `active` | Vigencia — permite el histórico por curso |

### Eventos, Sesiones, Inscripciones, Asistencias

Nada que crear. `attendance_percentage` y `attended_hours` de la inscripción los
calcula el CRM solo, y las asistencias las genera solo al crear la inscripción.

**Claves de `stic_Attendances.status` — confirmadas.** Son las cuatro que me
pasaste, y son las que pinta la pantalla de marcado:

| Clave | En pantalla | Cuenta como asistencia |
|---|---|---|
| `yes` | Vino | ✅ |
| `partial` | Parcial | ✅ |
| `no_justified` | Justificada | ❌ |
| `no_unjustified` | No | ❌ |

Sin valor = **sin marcar**, que no es lo mismo que una falta (§6.4 del diseño).

**`stic_Attendances.description` — comprobado contra el CRM** (tipo `text`). Es
donde va el **motivo** opcional que se escribe en la hoja de los cuatro estados
(«avisó la madre por la mañana»). No estaba en `CAMPOS.md` porque ese documento
cubre Personas: el módulo de asistencias no tiene ficha propia allí. Se verificó
con `get_module_fields` antes de escribir en él, no se dio por bueno.

El motivo solo se escribe **cuando cambia**: mandarlo igual en cada guardado
llenaría el registro de auditoría del CRM de cambios que no son cambios.

Ojo con esto: la API **no valida los enum**. Está comprobado — se le puede
escribir un valor inventado y lo guarda. Así que las cuatro claves de arriba se
tratan como constantes cerradas en el código, nunca como algo que se derive de
lo que venga del CRM.

### Familias (`stic_Personal_Environment`)

Nada que crear. `relationship_type`, `reference_contact` y `authorized_signer`
dan de sobra para el bloque de familia de la ficha.

---

## 4. `LIS_listas` — verificado

La estructura que has creado encaja con el diseño:

| Campo | Uso |
|---|---|
| `lis_listas_stic_sessions` | La sesión |
| `lis_listas_ajmcm_grupos` | El grupo |
| `lis_listas_contacts` | Quién pasó la lista |
| `estado` | Estado de la lista |
| `pasada_el` | Cuándo |
| `n_asistieron` / `n_faltaron` | Contadores, para el resumen sin recontar |
| `assigned_user_id` + `SecurityGroups` | Delegación |

Un registro = la lista de un grupo en una sesión concreta.

❓ **Duda: los valores del desplegable `estado`.** La API no devuelve las
opciones de los enum, así que no puedo leerlos. Necesito que sean estos tres
(o me digas cuáles has puesto):

- `pasada` [Pasada] — el monitor la ha pasado
- `omitida` [Sin registro] — se salta a propósito y **deja de avisar**
- *(sin registro en el módulo)* = pendiente

Sobre `omitida`: la conversación cerró que no hace falta distinguir «no hubo
reunión» de «se me olvidó y ya no me acuerdo». Un solo valor que signifique
«esto ya no me lo recuerdes» es más simple y cubre los dos casos. Si algún día
interesa separarlos, se añade un valor más sin romper nada.

---

## 5. Descartado a propósito

### ❌ Campos de horario en Grupos

En una versión anterior de este documento propuse `ajmcm_dia_semana_c`,
`ajmcm_hora_inicio_c` y `ajmcm_hora_fin_c` en `ajmcm_GRUPOS`. **Fuera.**

El horario ya está en las sesiones del evento: todos los grupos del COM de
Castellón se reúnen los sábados a las 16:30 porque las sesiones del evento
`COM | Sesiones semanales` son sábados a las 16:30. El grupo no necesita
repetirlo.

Y los grupos que se reúnen en horarios especiales (mayores, universitarios,
grupos de adultos) **no pasan lista**: Pasar Lista es para los grupos de
menores, hasta 1º de Bachillerato incluido, que son los que tienen evento e
inscripción. Un campo de horario en el grupo solo serviría para los grupos que
no lo van a usar.

---

## 6. Avisos de comportamiento — módulo `AVI_avisos` ✅ creado y verificado

Los «Aviso 1 / 2 / 3» de la app de AppSheet eran tres casillas en la persona
que sumaban 0-3, más una explicación común. **Se hace módulo**, decidido.

> **Estado: el módulo existe y el área privada escribe en él.** Verificado
> contra el CRM con `get_module_fields` (23/08). Dos diferencias con lo que
> decía esta especificación al escribirse, las dos ya corregidas en el código:
>
> - El campo con el id del relate `ajmcm_puesto_por_c` **no se llama**
>   `ajmcm_puesto_por_c_id` (la suposición razonable de antes de mirarlo), sino
>   **`contact_id_c`** — mismo patrón que `stic_sessions_id_c` para el relate
>   `ajmcm_sesion_c`.
> - **`ajmcm_notificado_el_c` no se creó.** Solo existe el booleano
>   `ajmcm_notificado_familia_c`; el «cuándo se avisó» no se guarda. El código
>   ya no lo lee ni lo escribe (antes lo intentaba, y la API lo ignoraba en
>   silencio sin decir nada). Si algún día se crea, se añade a
>   `sticpa_pl_avi_map()` y a las dos funciones que lo usan
>   (`sticpa_pl_avisos()` / `sticpa_pl_create_aviso()`) — nada más.
>
> Todos los nombres del módulo están en **`sticpa_pl_avi_map()`**, en un solo
> sitio: si alguno acaba llamándose distinto, se toca ahí y nada más.

Tres booleanos no guardan cuándo pasó ni quién lo puso, que es justo lo que
hace falta cuando un aviso se discute con la familia. Y no se pueden limpiar al
cambiar de curso sin borrar la historia.

#### El «mucho a mucho»: no hay ninguno

Esto es lo importante y es más simple de lo que parece:

| Relación | Cardinalidad | Cómo se monta |
|---|---|---|
| Participante → avisos | **uno a muchos** | Relación real `Contacts` 1:N `AVI_avisos`. Un participante tiene muchos avisos; **un aviso es de una sola persona.** |
| Aviso → quién lo puso | muchos a uno | **Campo relate** a `Contacts`, no relación |
| Aviso → sesión | muchos a uno | **Campo relate** a `stic_Sessions`, opcional |

Solo la primera es una relación de verdad, porque es la única que se navega en
las dos direcciones (desde la ficha quieres los avisos del chaval). Las otras
dos son campos relate: nunca vas a pedir «los avisos que ha puesto Mercedes»
desde la ficha de Mercedes, y un relate no crea tabla intermedia ni ensucia el
detalle del contacto con dos paneles de avisos que significan cosas distintas.

**Un `Contacts` 1:N `AVI_avisos` y dos relate. Ningún N:M.**

#### Campos

| Campo | Tipo | Obligatorio | Para qué |
|---|---|---|---|
| `name` | Texto | — | Que lo rellene el flujo o quede vacío; el título útil es el motivo |
| `fecha` | Fecha | ✅ | **El dato que faltaba en AppSheet.** El día que pasó |
| `motivo` | Área de texto | ✅ | Qué pasó, en palabras del monitor |
| `avi_avisos_contacts` | Relación 1:N | ✅ | El participante |
| `ajmcm_puesto_por_c` | Relate → `Contacts` | ✅ | El monitor que lo pone (nombre a mostrar; se resuelve solo) |
| `contact_id_c` | id | — | ✅ **Confirmado.** El id real del relate anterior — es el que hay que escribir para fijar la relación |
| `ajmcm_sesion_c` | Relate → `stic_Sessions` | — | La sesión, si pasó en una |
| `stic_sessions_id_c` | id | — | El id real del relate anterior, por el mismo motivo que `contact_id_c` |
| `ajmcm_notificado_familia_c` | Casilla | — | **Si se ha hablado con la familia** |
| ~~`ajmcm_notificado_el_c`~~ | Fecha | — | ❌ **No se creó.** El «cuándo se le dijo» no se guarda; solo el booleano de arriba |
| `assigned_user_id` + `SecurityGroups` | — | ✅ | Delegación |

**Sin campo de «nivel».** El 1, el 2 y el 3 salen de ordenar los avisos del
curso por fecha. Si guardas el nivel a mano acabas con un participante que
tiene «aviso 1» y «aviso 3» y ningún 2 porque alguien retiró el de en medio.
Contando, retirar un aviso renumera los siguientes, que es lo que quieres.

**Sin campo de «curso».** Sale del rango de fechas del curso.

#### En pantalla: la escala sube de color

El tercer aviso es la salida del grupo, así que se ve venir:

| | Color | |
|---|---|---|
| Aviso 1 | ámbar `#f59e0b` | «ojo» |
| Aviso 2 | naranja `#c2410c` | «esto va en serio» |
| Aviso 3 | rojo `#dc2626` | expulsión |

> **Dos cambios respecto a lo que decía antes esta tabla, los dos por
> contraste** (medidos, no estimados):
>
> - El naranja del aviso 2 era `#ea580c`. Con el número blanco dentro del
>   círculo se quedaba en **3,6:1**, por debajo del 4,5:1 que necesita un texto
>   pequeño. `#c2410c` lo sube a **5,2:1** y a ojo es el mismo naranja.
> - El número del aviso 1 **no va en blanco**: sobre el ámbar daba 2,2:1. Va en
>   `#451a03` (7,0:1). Lo decide `sticpa_pl_aviso_ink()`, no el CSS, porque
>   depende del relleno.
>
> Los hex de la escala son **rellenos** y por eso son fijos e iguales en claro y
> en oscuro: «el naranja» tiene que significar siempre lo mismo. El texto
> naranja de la cabecera («2 de 3») y del botón de añadir NO son fijos: salen de
> `--warning-dark`, que es el par de texto de este estado y se aclara solo en
> oscuro (§44.4 del sistema de diseño). Mezclarlo era dejar el botón en 3,5:1.
>
> La escala se puede cambiar sin tocar código con el filtro
> `sticpa_pl_aviso_escala`, y el límite de 3 con `sticpa_pl_avisos_limite`.

Los puntitos «2 de 3» de la cabecera usan los mismos colores, y el hueco del
tercero va con borde rojo punteado. Debajo del segundo aviso, un aviso en rojo
recuerda que el siguiente implica la salida y que hay que hablar con
coordinación antes. Cada aviso lleva su chip de **Familia avisada / Familia sin
avisar**, que es el dato que se pregunta siempre.

#### ⏰ Recordatorio: el flujo de trabajo de correo

**Queda pendiente crear un flujo de trabajo en el CRM** sobre `AVI_avisos` que,
al crear un aviso, mande un correo a coordinación de la delegación. No es
código del área privada, es configuración de SuiteCRM — pero sin él un aviso
puede quedarse solo en la ficha. Anotado también en el roadmap.

Para eso hace falta saber **quién es coordinación**, que es lo de abajo.

---

## 7. Los coordinadores de etapa: dónde van

Hoy no hay sitio para «quién coordina el COM de Castellón», y hace falta para
tres cosas distintas: mandarles el correo de los avisos, dejarles editar los
«datos por revisar», y enseñarles el resumen de grupos.

**Recomendación: una fila más en `stic_Contacts_Relationships`**, la misma
tabla donde ya viven monitor, participante y grupo:

```
relationship_type = coordinador_mic | coordinador_com | coordinador_delegacion
```

Por qué ahí y no en otro sitio:

- **Ya lleva vigencia.** Un coordinador lo es de un curso, no para siempre, y
  esa tabla tiene fechas. Un campo en la persona no las tiene.
- **Ya lleva delegación**, por el `assigned_user_id`. Sale gratis.
- **El área privada ya la lee.** Es la misma consulta que saca los monitores de
  un grupo; no hay código nuevo de datos.
- Permite **varios coordinadores** por etapa sin inventar nada.

Lo que **no** conviene:

- `stic_relationship_type_c` en `Contacts` (el multienum): no tiene vigencia, no
  distingue etapa, y es el campo del que sale el rol de login. Meterle
  coordinadores lo convierte en dos cosas a la vez.
- Un grupo de seguridad de SuiteCRM: vale para permisos, pero no sirve para
  saber a quién escribir ni para separar MIC de COM.

**Duda abierta: los «sectores».** Mencionas sectores además de etapas. En el CRM
no existe hoy nada que agrupe delegaciones en sectores (`stic_professional_
sector_c` es la profesión de la persona, otra cosa). Si un sector es un conjunto
de delegaciones, lo natural sería un campo en la delegación (`Accounts`), no en
la persona. Dime qué es exactamente un sector y lo cierro.

---

## 8. El móvil del participante: resuelto

Los del COM tienen teléfono propio para llamar y WhatsApp. En `CAMPOS.md`
figuraba `phone_mobile` como **«No usar»**, y en los datos reales sí estaba
relleno — lo dejé como contradicción abierta.

**Confirmado: `phone_mobile` SÍ se usa.** Es el móvil de la persona, y es el que
pinta el botón de llamar y el de WhatsApp del participante. `phone_other` sigue
siendo el contacto de emergencias.

⚠️ **Hay que corregir `CAMPOS.md`** para que `phone_mobile` no diga «No usar», y
—por la regla del `CLAUDE.md`— **subir ese mismo cambio al repo
`comunicaFormularios`**, que escribe en el mismo campo.

Y ojo: el botón de WhatsApp del menor respeta `ajmcm_menorwhatsapp_c`. Si no
autoriza, no se pinta. El de llamar sí, porque autorizar WhatsApp y tener
teléfono son cosas distintas.

---

## 9. Los enlaces anidados de la API: la trampa que se repitió cinco veces

Este apartado existe porque el mismo error se repitió en cinco funciones
distintas y costó varias vueltas. Si vas a leer una sola cosa antes de tocar la
capa de CRM, lee esta.

### Qué se asumió mal

La API v4.1 deja pedir, en la misma llamada, los registros relacionados **y** un
enlace poblado dentro de cada uno (`related_module_link_name_to_fields_array` en
`get_relationships`, `link_name_to_fields_array` en `get_entry_list`). Es un
parámetro documentado, así que se construyó Pasar Lista encima.

**En esta instancia, `get_relationships` no lo devuelve.** Ni error ni aviso:
responde 200, los registros llegan, y el enlace pedido no aparece. El síntoma
siempre es el mismo y siempre parece otra cosa: una lista vacía, un grupo con
«0 participantes», un «no tienes ningún grupo asignado», una lista duplicada en
cada guardado.

Y la pista estaba en el repo desde el principio: **todo el código que funciona en
producción desde años pasa ese array VACÍO** y hace N+1 llamadas. Eso no era
«nadie se molestó», era «aquí no funciona».

### Lo que sí funciona, por orden de preferencia

1. **El campo plano del propio registro.** Cada enlace tiene su columna con el
   id: `..._ida` (y a veces `..._name` con el nombre). Salen en
   `get_module_fields` filtrando por `ida`. Es lo más barato: no cuesta ninguna
   llamada extra, solo pedir el campo.
2. **`get_entry_list` con `link_name_to_fields_array`** (o sea
   `getRecordsModule()` con su cuarto parámetro). Esta vía SÍ funciona — la usa
   `list_stic_job_offers.php` en producción desde siempre.
3. **Preguntar por el enlace**, una llamada por registro. Es el último recurso y
   solo para uno o dos registros, nunca en un bucle sobre un grupo entero.

### La regla

> Pide **siempre** el campo plano `..._ida` junto al enlace anidado, y resuelve
> con el primero que venga. Nunca dependas solo del enlace.

`SugarRestApiCall::flattenRelationshipFields()` hace eso por ti y, además,
resuelve **qué bloque es de qué campo**: primero por nombre y, si la API no
nombra los bloques, **por posición**. Eso último importa mucho: pidiendo dos
enlaces sin nombre, la versión anterior daba el primer bloque a los dos campos,
así que «el grupo» se quedaba con el id de «la persona». Con eso, la consulta de
una sola llamada se daba por fallida y todo caía a los respaldos de 1+N — que es
lo que hacía la pantalla lenta.

### El coste, medido

`tests/CosteLlamadasTest.php` cuenta las llamadas de cada pantalla y las imprime.
No falla nunca a propósito: es una medida, para que el número no suba sin que
nadie se dé cuenta. Con los enlaces resolviéndose bien, la portada baja de 13 a
6 llamadas y la de marcado de 14 a 9 — y eso con un grupo de prueba de cuatro
personas; con doce, el respaldo es 1+N y la diferencia es mucho mayor.
