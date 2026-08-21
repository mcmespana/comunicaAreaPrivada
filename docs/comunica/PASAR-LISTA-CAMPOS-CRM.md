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

## 2. Lo único que hay que crear

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

### Relaciones con Personas (`stic_Contacts_Relationships`)

| Campo | Para qué |
|---|---|
| `relationship_type` | `participante_mic_com` · `monitor` · `grupo` · `familiar_menor` |
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

## 6. Para más adelante (no ahora)

### 🔨 Avisos de comportamiento — fase 3

Los «Aviso 1 / 2 / 3» de la app de AppSheet eran tres casillas en la persona
que sumaban 0-3, más una explicación común.

**Recomendación: un módulo, no tres casillas.** Tres booleanos no guardan
cuándo pasó ni quién lo puso, que es justo lo que hace falta cuando un aviso se
discute con la familia. Un módulo `AVI_avisos` resuelve eso y además se limpia
solo al cambiar de curso, porque los avisos quedan atados a su fecha en vez de
acumularse para siempre en la persona.

| Campo | Uso |
|---|---|
| `avi_avisos_contacts` | El participante |
| `fecha` | Cuándo (obligatorio: es el dato que faltaba) |
| `puesto_por` → `Contacts` | Qué monitor lo pone |
| `motivo` | Texto libre |
| `avi_avisos_stic_sessions` | Opcional: la sesión en la que pasó |
| `assigned_user_id` + `SecurityGroups` | Delegación |

El contador «2 de 3» de la ficha sale de contar los registros del curso, y
retirar un aviso es borrar un registro, no desmarcar una casilla.

**Esto no bloquea nada.** El front de la ficha ya está diseñado (`Ficha.dc.html`,
bloque «Avisos de comportamiento») y se puede construir sin campos detrás: se
pinta vacío hasta que exista el módulo. Fase 3.

### ❓ El móvil del participante

Tú comentaste que los del COM tienen teléfono propio para llamar y WhatsApp.
En `CAMPOS.md` hay un conflicto que hay que aclarar:

- `phone_mobile` figura como **«No usar»**
- `phone_other` figura como **«Contacto de emergencias»**

Pero en los datos reales `phone_mobile` **sí está relleno** (lo he visto en
monitores). Así que: ¿cuál es el campo del móvil del participante? Si es
`phone_mobile`, hay que corregir `CAMPOS.md`; si hay que crear uno nuevo,
dímelo. Sin cerrar esto no puedo pintar el botón de llamar del participante.

Y ojo: el botón de WhatsApp del menor tiene que respetar
`ajmcm_menorwhatsapp_c`. Si no autoriza, no se pinta.
