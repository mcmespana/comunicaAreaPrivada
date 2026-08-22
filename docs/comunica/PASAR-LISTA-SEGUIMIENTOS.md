# Seguimientos de monitores — qué módulo y por qué

Me pediste que decidiera entre tres: el de valoraciones, uno de voluntariado, o
`Notes` modificado. **Ninguna de las tres, y por buenas razones.** La respuesta
es `stic_FollowUps` — el módulo **Seguimientos** que SinergiaCRM ya trae.

---

## 1. Qué encontré

Mirando la
[documentación de SinergiaCRM](https://wiki.sinergiatic.org/index.php/Estructura_de_datos:_m%C3%B3dulos_y_campos)
y preguntando a la instancia:

| Lo que buscabas | Qué hay de verdad |
|---|---|
| «un módulo de valoraciones» | `stic_assessments` **existe** en SinergiaCRM, pero son las *valoraciones previas a la incorporación* de un participante. No es una valoración de trimestre de un monitor |
| «uno nuevo de voluntariado» | **No existe.** «Voluntario» es solo un valor de `stic_relationship_type_c`, no un módulo |
| `Notes` | Existe y es accesible, pero es mala idea (§3) |
| **`stic_FollowUps` (Seguimientos)** | **Existe, y es exactamente esto** |

Y un dato que cambió la decisión: al preguntar por `stic_FollowUps`,
`stic_Assessments` y `stic_Goals`, la instancia **no dice «no existe»**. Dice:

```
The API user does not have access to this module
```

O sea: **los módulos están instalados**. Lo que falta es que el usuario de la API
tenga permiso. Eso es un permiso, no un desarrollo.

---

## 2. Por qué `stic_FollowUps`

- **Es el módulo que SinergiaCRM trae para esto.** Se llama Seguimientos y está
  en el área de Atención directa, junto a Entorno personal —que ya usamos para
  las familias—. Crear un módulo propio que duplique uno que ya existe es
  exactamente el error que el `CLAUDE.md` de este repo prohíbe: acabar con dos
  campos para la misma cosa.
- **Ya tiene `type` y subtipo nativos**, con la mecánica de listas dependientes
  de SinergiaCRM (el subtipo se nombra `tipo_subtipo`). Nuestros tres tipos son
  tres valores de una lista, no un campo nuevo.
- **Tiene sus propios grupos de seguridad**, que es lo que permite que las notas
  de acompañamiento estén protegidas *de verdad* y no solo escondidas en nuestra
  interfaz.
- **Relaciona con personas y proyectos** de serie.

---

## 3. Por qué NO `Notes`

Era la opción tentadora —existe y es accesible ya— y la descarto por cuatro
razones, en orden de gravedad:

1. **`Notes` sale en el panel de Historial del contacto.** Cualquiera que abra la
   ficha de ese monitor en SinergiaCRM vería ahí las notas de acompañamiento.
   Eso rompe lo único que no se puede romper de esta funcionalidad. Nuestra
   pantalla podría ocultarlas; el CRM no.
2. **No tiene fecha del hecho.** Solo `date_entered`. Tú pediste «este día a este
   monitor le pasó esto», y se escribe el lunes lo del sábado: son dos fechas
   distintas y `Notes` solo guarda una.
3. **No tiene tipo.** Habría que añadir el enum igual, así que la ventaja de «no
   crear nada» se desvanece.
4. **Es el cajón de todo.** Las notas de `Notes` cuelgan de llamadas, reuniones,
   tareas y casos. Nuestra lista tendría que filtrar para no mezclarse con notas
   de otros flujos, y ese filtro es lo único que separaría una nota de
   acompañamiento de una nota cualquiera.

Modificar `Notes` habría sido añadirle tres campos y aun así quedarnos con el
problema 1, que es el que importa.

---

## 4. Y NO un módulo nuevo

Lo consideré y lo descarté al ver el error de permisos: si el módulo bueno está
instalado y solo le falta un permiso, crear otro al lado es peor en todo. Un
módulo nuevo también significaría no heredar los informes, los grupos de
seguridad ni las listas dependientes que Seguimientos ya tiene.

**Nota**: `AVI_avisos` (los avisos de conducta de los chavales) **sigue aparte**.
Aunque la forma se parezca —una nota fechada sobre una persona—, el público, las
visibilidades y la semántica (1/2/3 = expulsión, familia avisada) son otra cosa.
Juntarlos metería en la misma tabla los avisos de menores y las notas de
acompañamiento de adultos, y un fallo de filtro ahí es el peor de los fallos
posibles. Dos módulos, cada uno con un público claro.

---

## 5. Los tres tipos y quién ve qué

Esta tabla es la funcionalidad. Todo lo demás es plomería.

| Tipo | Lo escribe | Lo lee |
|---|---|---|
| **Incidencia** — algo concreto de un día | coordinación | coordinación · acompañamiento |
| **Valoración de trimestre** | coordinación | coordinación · acompañamiento |
| **Acompañamiento** — más privada | acompañamiento | **solo acompañamiento** |

Y la regla que no es un descuido: **un monitor no ve seguimientos suyos.** Ni
siquiera los suyos, ni siendo coordinador de otra etapa. Una valoración escrita
para hablarla en persona deja de servir si se lee antes en una pantalla. Está en
una función propia (`sticpa_pl_seg_can_see_own`) para que se lea explícita y para
que el día que se cambie, se vea en el histórico por qué.

**Coordinar y acompañar no son jerárquicos.** Quien hace las dos cosas ve la
unión; quien solo coordina no ve acompañamiento aunque coordine la delegación
entera.

**Dos cierres independientes.** La consulta al CRM pide solo los tipos
permitidos, y además lo que vuelve se filtra otra vez en PHP
(`sticpa_pl_seg_filter`). Redundante a propósito: el coste de un fallo aquí no es
un dato de más.

---

## 6. Qué hay que hacer en el CRM

### 1. Dar acceso al usuario de la API a `stic_FollowUps`

Es lo único imprescindible. Sin esto, la sección de seguimientos no aparece y
todo lo demás sigue funcionando igual.

### 2. Añadir los tres valores a la lista de tipos de Seguimientos

Con las claves internas exactas:

```
mcm_incidencia        Incidencia
mcm_valoracion        Valoración de trimestre
mcm_acompanamiento    Acompañamiento
```

El prefijo `mcm_` es para no chocar con los tipos que la propia entidad ya use en
Seguimientos para otras cosas.

### 3. El papel de acompañamiento

Un valor más en `stic_Contacts_Relationships.relationship_type`:

```
acompanamiento_mic_com
```

Mismo sitio y misma mecánica que `coordinacion_mic_com`: lleva vigencia y
delegación de serie.

### 4. Confirmar los nombres de campo

⚠️ **Esto no lo he podido verificar** porque el usuario de la API no tiene acceso
al módulo. La documentación confirma que el campo de tipo se llama `type`; el
resto son la convención de SuiteCRM. Todos los nombres están **en un solo sitio**
(`sticpa_pl_seg_map()`) y se pueden corregir con un filtro sin tocar código:

| Para qué | Nombre supuesto |
|---|---|
| Módulo | `stic_FollowUps` |
| Tipo | `type` |
| Texto | `description` |
| Título | `name` |
| Fecha del hecho | `date_start` |
| Enlace a la persona | `stic_followups_contacts` |

Por eso la funcionalidad viene **apagada por defecto**
(`sticpa_pl_seguimientos_enabled`): en cuanto se dé el acceso, se comprueban los
nombres de verdad, se corrigen si hace falta y se enciende. Encender algo cuyos
nombres no he podido comprobar sería la forma segura de romper una pantalla en
producción.

---

## 7. Lo que se construye

En la ficha del monitor, debajo de todo:

- La lista de seguimientos que este usuario puede ver, con su tipo en color, su
  fecha y quién lo escribió. Agrupada por trimestre cuando son valoraciones.
- Un formulario de alta rápido: tipo, fecha (hoy por defecto) y el texto. Los
  tipos del desplegable son **solo los que este usuario puede escribir**.
- Si no puede ver nada, la sección **no existe**. No se enseña un bloque vacío
  que insinúe que hay algo detrás.
