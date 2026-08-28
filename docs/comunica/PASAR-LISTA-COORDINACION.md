# Pasar Lista — coordinación y monitores

Lo de los monitores y lo que puede hacer coordinación. El diseño de la pantalla
de participantes está en [`PASAR-LISTA.md`](PASAR-LISTA.md); los campos, en
[`PASAR-LISTA-CAMPOS-CRM.md`](PASAR-LISTA-CAMPOS-CRM.md); las fases, en
[`PASAR-LISTA-ROADMAP.md`](PASAR-LISTA-ROADMAP.md).

---

## 1. ¿Evento aparte para los monitores, o el mismo?

Preguntabas opinión. **El mismo evento y las mismas sesiones para lo semanal, y
un evento aparte solo para las reuniones de programación.** Y no es un término
medio para quedar bien: son dos casos que no se parecen.

### Lo semanal: el mismo evento

El sábado a las 16:30 **es el mismo hecho** para el chaval y para el monitor. Si
se duplica en dos eventos, hay dos calendarios que dicen cuándo es la sesión, y
en cuanto se mueva un sábado por una fiesta local, uno de los dos se queda
viejo. Es exactamente el problema que nos hizo tirar el modelo de «un evento por
grupo»: **dos fuentes de verdad para un solo hecho.**

Y hay algo más concreto: duplicar el evento obliga a **duplicar las 24 sesiones**
y a mantenerlas en paralelo a mano, todos los cursos y en todas las
delegaciones. Eso no se sostiene.

Los monitores se **inscriben al mismo evento** (`stic_Registrations`), y con eso:

- Sus asistencias las genera el CRM solas, igual que a los chavales.
- Su `attendance_percentage` sale gratis y es correcto.
- La pantalla de marcar monitores usa las **mismas sesiones**, así que no hay
  nada que sincronizar.

### Sobre la «contaminación» de los números

Lo dices y tienes razón a medias, así que conviene ser preciso en qué se
contamina y qué no:

| | ¿Se mezcla? |
|---|---|
| El % de asistencia de cada persona | **No.** Es por inscripción, y una inscripción es de una persona |
| El % de un grupo, tal como lo calcula la pantalla | **No.** Se calcula sobre los participantes del grupo |
| `total_attendances` / `validated_attendances` del **evento** | **Sí**, suman chavales y monitores |
| El recuento de inscritos del evento | **Sí** |

O sea: lo único que se mezcla son **dos contadores agregados a nivel de evento
que la pantalla no usa** (y que ya dijimos que no sirven a mitad de curso, §6.5
de `PASAR-LISTA.md`). Todos los números que se ven en el área privada los calcula
la pantalla contando lo que toca.

Así que la contaminación es real pero está en un sitio que no leemos. Cambiar el
modelo por eso sería pagar dos calendarios para arreglar un número que nadie
mira.

**Si algún día molesta de verdad**, la salida no es duplicar el evento: es
enseñar el recuento partido en el propio resumen (X chavales, Y monitores), que
es un cálculo de pantalla, no de modelo.

### Las reuniones: evento aparte, y este sí

Una reunión de programación **no es el sábado**. Es otra actividad, otra
duración, otra gente (los monitores de la etapa, no los grupos) y otras 3-4
fechas al año que no siguen el calendario semanal. Meterla como sesión del evento
semanal metería en el calendario de las familias una reunión que no es para
ellas.

Un evento por curso y delegación:

```
Monitores | Reuniones de programación 2025-2026
```

Y **coordinación le añade sesiones desde el área privada**: nombre, fecha y
duración. Es un evento que crece durante el curso, y por eso tiene sentido
poderlo tocar desde aquí en vez de entrar al CRM.

---

## 2. Quién coordina qué

La relación se llama **`coordinacion_mic_com`**, en
`stic_Contacts_Relationships` (el por qué está en
`PASAR-LISTA-CAMPOS-CRM.md` §7: esa tabla ya lleva vigencia y delegación).

Y lo bueno: **el alcance sale de la propia relación**, sin campos nuevos.
`stic_Contacts_Relationships` ya tiene `ajmcm_etapa_relacion_c`, y con el
segmento que se cree en Grupos la regla queda así:

| La relación de coordinación lleva… | Coordinas… |
|---|---|
| etapa `COM` + segmento `com_2` | los grupos COM II y sus monitores |
| etapa `COM`, sin segmento | todos los grupos del COM y sus monitores |
| nada (ni etapa ni segmento) | **toda la delegación** |

Es la misma idea que «tu grupo» pero un nivel arriba: **si tienes alcance, entras
directo a lo tuyo; si no lo tienes, navegas por todo.** Quien no tenga segmento
ni grupo puede moverse entre segmentos y ver dónde se ha pasado lista y dónde no,
que es justo lo que necesita alguien que mira el conjunto.

Los monitores de un segmento salen de los **grupos** de ese segmento: no hace
falta marcar la etapa en cada monitor.

---

## 3. La lista de monitores: el defecto es al revés, y a propósito

En los chavales, sin marcar **no** es una falta: no sabemos si vino y escribirlo
metería una ausencia falsa en el porcentaje que ve la familia.

En los monitores es lo contrario: **se asume que vienen siempre.** Coordinación
no repasa doce nombres uno a uno, sino que afirma «vinieron todos menos estos».
Así que la pantalla arranca **todo en verde** y solo se marca en rojo quien no
estuvo.

Consecuencia importante en el guardado: al guardar se escribe **`yes` explícito**
para todos los no marcados, no se dejan vacíos. Si se dejaran vacíos, el
porcentaje del monitor saldría a cero aunque hubiera venido siempre. Aquí el
verde **es un dato**, no un hueco — y por eso se puede escribir.

Es la única pantalla del sistema donde no marcar significa algo. Está dicho aquí
para que nadie lo «arregle» dentro de un año.

---

## 4. Los datos del monitor: mejor que el CRM, y en otro orden

**Reescrita el 28/08/2026.** La primera versión abría con el certificado de
delitos sexuales; el propietario pidió lo contrario: «poniendo más arriba los
datos de más interés (por ejemplo el de delitos sexuales pues el primero no hace
falta)». Tiene razón — es una obligación legal, sí, pero es una casilla, y una
casilla no es la persona.

El orden es el de la conversación real de seguimiento (afinado el 28/08/2026
por el propietario: **el papeleo, al final**):

1. **Quién es y cómo se le llama.** Nombre grande, la línea que le sitúa
   (grupos · curso · edad · desde cuándo es monitor) y los dos botones de
   WhatsApp y Llamar. Debajo, **una sola línea**: el correo, que se LEE, y el
   otro teléfono, que solo se PULSA y por eso cabe en un botón redondo con el
   número en su `aria-label`. El móvil no se repite: ya está en los botones.
   Si ha pedido que no se le llame, se dice aquí y no enterrado abajo.
2. **Cómo va este curso** — es el motivo de abrir la ficha. Tres pistas de
   cuadraditos **separadas y nunca promediadas** (plan 038):
   - *Sábados*, con porcentaje.
   - *Reuniones de programación*, con fracción y no porcentaje: con tres o
     cuatro al año, un 75 % suena a nota y es una sola falta.
   - *Listas de su grupo*, con quién la pasó. **Esta fila se lee junto a la de
     sábados o no se lee**: una lista de grupo la puede pasar cualquiera que
     cubra ese sábado, así que «no la pasó» puede querer decir «no vino y la
     pasó un compañero», que es correcto. Está dicho en la propia pantalla.
   - Un sábado sin marcar es un hueco, no una falta: no cuenta en el
     porcentaje y se dice aparte.
3. **Sus grupos**: el que lleva y **el suyo** —la relación `grupo` COM-LC, que en
   el CRM está en otra pestaña y aquí no—, con los recuentos calculados en vivo
   y con quién los comparte. El que lleva enlaza a su lista.
4. **Seguimientos**, y solo los de ESTE curso: el CRM devuelve las notas de
   todos los años en la misma lectura y una lista de cinco cursos es un
   archivo, no una pantalla de seguimiento. Un enlace discreto trae los del
   curso anterior, y no cuesta ninguna consulta porque los datos ya estaban.
   Sin nada apuntado, un vacío tranquilo —contorno discontinuo y una frase—, no
   un aviso con icono de información: que no haya notas en octubre es lo normal.
   El alta va **detrás de un botón** que abre el formulario y pone el foco en el
   desplegable: se escribe entera sin tocar la pantalla otra vez.
5. **Por dónde ha pasado**: curso a curso y con quién estaba. Plegable y
   abierto de partida, con la cuenta de cursos en la cabecera.
6. **El papeleo, al final**: «En regla» (aquí dentro va el certificado de
   delitos sexuales), «Datos MCM» (nivel, etapa, pañuelo, desde cuándo) y
   «Formación» (solo lo que tiene, con el aviso de «titulado pero sin archivo»).
   Y cerrando, plegados, los **datos personales**: documento, nacimiento,
   dirección, centro educativo y nº de persona.

**Sin familia y sin salud.** Un monitor es un adulto: sus datos de salud no son
asunto de coordinación en esta pantalla.

En la **lista** de monitores, en cambio, no va nada de esto: solo una nota en
rojo cuando algo no va. Ahí la pregunta es *a quién hay que mirar* de los
treinta, y una lista con treinta porcentajes es una lista que nadie lee.

---

## 5. Cómo conviven grupos y monitores en la misma pantalla

Preguntas si los monitores van encima o debajo de los grupos. **Debajo, y como
una sección más del mismo árbol.**

El razonamiento: coordinación también tiene grupos (o no), así que no puede haber
dos interfaces. La pantalla es **la misma** y lo que cambia es cuántas secciones
tiene:

```
Tu grupo                        ← si lo tienes (ya existe)
MIC · COM · LC                  ← los grupos de tu alcance (ya existe)
─────────────────────────────
Monitores                       ← solo si coordinas
Reuniones                       ← solo si coordinas
```

Debajo y no encima porque **lo frecuente es pasar lista de tu grupo**, incluso
siendo coordinador: eso pasa cada sábado, y coordinar monitores pasa una vez al
mes. Lo que se usa más va arriba, y el orden no cambia según quién entre — que
es lo que permite aprender la pantalla una vez.

---

## 6. Campos y valores que hay que crear

Pocos, y ninguno nuevo en Personas.

### 🔨 `stic_Contacts_Relationships.relationship_type` → un valor más

```
coordinacion_mic_com
```

El alcance se lee de `ajmcm_etapa_relacion_c` (ya existe) y del segmento del
grupo. No hace falta ningún campo nuevo en la relación.

### 🔨 `LIS_listas` → `ajmcm_tipo_c`

| | |
|---|---|
| **Módulo** | `LIS_listas` |
| **Etiqueta** | Tipo de lista |
| **Tipo** | Desplegable |
| **Valores** | `participantes` [Participantes] · `monitores` [Monitores] |
| **Por defecto** | `participantes` |

Por qué hace falta: hoy una lista es sesión × grupo. La lista de monitores de un
sábado es otra cosa distinta en la **misma** sesión, y sin este campo las dos se
pisarían. Con él, «la lista del C1» y «la lista de monitores del COM» conviven.

Un solo campo, y las listas que ya existen siguen valiendo porque el valor por
defecto es el que ya son.

> **Estado 27/08/2026: hecho y en uso.** El campo existe en el CRM y es
> **requerido** (verificado con `get_module_fields`). El plugin lo manda
> explícito en las dos familias, y `LIS_listas` se lee una vez para ambas
> (`sticpa_pl_listas_index()`), con un índice por familia.
>
> La lista de monitores va **sin grupo**, porque el alcance es la etapa. Eso
> deja **una por sesión y delegación**: si MIC y COM comparten evento, sus
> coordinadores comparten la lista y el último que guarde deja sus números (las
> asistencias por persona siguen siendo correctas). Separarlas pediría un campo
> de etapa en `LIS_listas`, que **no existe** y no se ha inventado. Si se decide
> que hace falta, se pide al CRM y se documenta en `CAMPOS.md`.

### 🔨 El evento de reuniones

No es un campo: es un registro. Uno por delegación y curso, asignado al usuario
de la delegación:

```
Monitores | Reuniones de programación 2025-2026
```

Se puede crear a mano una vez, o dejar que lo cree la pantalla la primera vez que
coordinación añada una reunión. **Lo crea la pantalla**, que es una cosa menos
que recordar en septiembre.

### ⏳ Depende de lo ya pedido

- `ajmcm_GRUPOS.ajmcm_segmento_com_c` — sin él, el alcance por segmento no
  filtra (la etapa sí).

---

## 7. Anotado para la siguiente iteración: seguimientos de monitores

**No se hace ahora.** Queda escrito para no perderlo:

Dos tipos de nota, que son distintas y no conviene mezclar:

1. **Incidencia puntual** — «este día, a este monitor le pasó esto». Fecha,
   quién lo escribe, qué pasó.
2. **Valoración de trimestre** — un texto por monitor y trimestre, más largo y
   más pensado.

Y una tercera cosa, con visibilidad propia:

3. **Notas de acompañamiento** — más privadas todavía. Las escribe y las lee
   **solo quien acompaña**. Ni coordinación general.

Las reglas de quién ve qué, que es lo delicado:

| | Ve las suyas | Ve las de otros |
|---|---|---|
| Un monitor | **No** | No |
| Coordinación (de su alcance) | — | Sí (1 y 2) |
| Acompañante | — | Sí, y además las de acompañamiento |

Que un monitor **no vea ni su propia valoración** es una decisión de encuadre,
no un descuido: una valoración escrita para hablarla en persona deja de servir si
se lee antes por una pantalla.

**Antes de crear módulo, hay que buscar si ya existe uno** de valoraciones o
notas en SinergiaCRM que sirva. Es lo mismo que hicimos con las listas: mirar
antes de crear.

---

## 8. Lo que arregla coordinación desde aquí

La lista de «datos por revisar» ya está en el resumen. Sobre los participantes
sin grupo, la regla exacta:

- Solo **participantes MIC-COM** (`relationship_type = participante_mic_com`).
  Un monitor sin grupo no es el mismo problema y no se mezcla en la misma lista.
- Solo relaciones **vigentes**: una relación acabada el curso pasado no es un
  dato que falte, es historia.
- Se asigna el grupo tocando **la relación**, no la persona: el grupo de alguien
  es un dato con vigencia.
- El grupo destino tiene que ser **de la propia delegación**. Se comprueba en el
  guardado, no solo al pintar el desplegable.
