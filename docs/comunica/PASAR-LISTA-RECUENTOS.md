# Recuentos y monitores en la ficha del grupo

**Qué falta y por qué merece la pena.** Documento de decisión.

> **Estado: los cuatro campos ya existen, verificados con `get_module_fields`
> (23/08)** — `ajmcm_n_participantes_c` (entero), `ajmcm_n_monitores_c`
> (entero), `ajmcm_monitores_c` (texto 255) y `ajmcm_recuento_al_c`
> (fecha y hora), con los nombres exactos de §3. El rellenador ya está hecho —
> es la tarea `recuentos-grupos` del [Guardián Nocturno](GUARDIAN-NOCTURNO.md),
> que pasa cada noche a la 1:30 (opción B, §4) — y en cuanto ese workflow se
> mergee escribirá de verdad la primera noche. **Lo que sigue sin hacer es el
> lado de leerlos en el área privada (§5)**: el árbol y el resumen todavía no
> los usan.

Relacionado: [`PASAR-LISTA.md`](PASAR-LISTA.md) (diseño) ·
[`PASAR-LISTA-CAMPOS-CRM.md`](PASAR-LISTA-CAMPOS-CRM.md) (campos) ·
[`PASAR-LISTA-ROADMAP.md`](PASAR-LISTA-ROADMAP.md) (fases)

---

## 1. El problema, en una frase

Dos pantallas quieren decir **cuánta gente hay** y **quién es el monitor**, y
ninguno de los dos datos se puede saber sin una consulta por grupo.

| Dónde | Lo que dice el diseño | Lo que dice hoy |
|---|---|---|
| Árbol de grupos (`single_stic_pasar_lista_grupos`) | `Mercedes · 1º ESO · 10 participantes` | `MIC · 2025-2026` |
| Resumen, tarjetas por etapa (`single_stic_pasar_lista_resumen`) | Número grande = **93 chavales**, debajo `8 grupos · 12 mon.` | Número grande = **8 grupos** |

En el resumen el titular cambia de significado: el diseño quiere «en el MIC hay
93 niños» y la pantalla dice «en el MIC hay 8 grupos». No es lo mismo y no
contesta la pregunta con la que se abre esa pantalla.

## 2. Por qué no se hace y ya está

La pertenencia a un grupo **no vive en el grupo**: vive en registros de
`stic_Contacts_Relationships` («esta persona pertenece a este grupo, desde esta
fecha, con este papel»). Eso es lo correcto —el grupo de alguien es un dato con
vigencia— pero significa que para saber cuántos hay en C1 hay que pedirle al CRM
las relaciones de C1.

**Una llamada a la API por grupo.** Con 23 grupos, 23 llamadas cada vez que se
abre el árbol. En un móvil con datos eso es la diferencia entre abrir en un
segundo y abrir en diez, y el árbol se abre cada sábado.

### El atajo que parece existir y no vale

Sí hay **una** llamada que trae todas las relaciones de la delegación de golpe
—`sticpa_pl_participants_without_group()` ya la usa. El problema es que en esa
respuesta el grupo vuelve como **texto con el nombre** («Los Peques»), no como
su id:

```php
$grupo = isset($v->grupo->value) ? trim((string) $v->grupo->value) : '';
```

Para contar por grupo habría que emparejar por nombre, y eso se rompe en dos
casos que existen de verdad:

- dos grupos con el mismo nombre en delegaciones o etapas distintas;
- grupos donde `sticpa_pl_group_label()` se **queda solo con el código** porque
  el nombre y el código son iguales (no se pinta «C1 · C1»), así que el nombre
  con el que compararías no es el que enseña la pantalla.

Contar mal y no enterarse es peor que no contar. Por eso no se hizo.

## 3. La solución: llevar el dato al grupo

Que el recuento **viva en el registro del grupo**. El área privada ya lee los
grupos en **una** llamada:

```php
// inc/stic-pasar-lista-crm.php · sticpa_pl_groups()
$fields = array('id', 'name', 'code', 'level', 'cursos_c');
$rows = $objSCP->getRecordsModule('ajmcm_GRUPOS', $query, $fields);
```

Añadir campos ahí **cuesta cero llamadas nuevas**: entran en la consulta que ya
se hace. Eso es lo que lo hace la solución buena y no un parche.

### Los campos que hacen falta, en `ajmcm_GRUPOS`

| Campo propuesto | Tipo | Para qué |
|---|---|---|
| `ajmcm_n_participantes_c` | Entero | El recuento del árbol y el número grande del resumen |
| `ajmcm_n_monitores_c` | Entero | El `· 12 mon.` de las tarjetas del resumen |
| `ajmcm_monitores_c` | Texto (255) | Los nombres, separados por comas: `Juan, Antonio, María` |
| `ajmcm_recuento_al_c` | Fecha y hora | **Cuándo se calculó.** Sin esto no hay forma de saber si el número es de anoche o de hace tres meses |

Cuatro cosas que importan de esta tabla:

1. **Los nombres van separados por comas, sin «y».** El «Juan, Antonio y María»
   lo monta la pantalla, que es la que sabe en qué idioma está y cuánto sitio
   tiene. Guardar la frase ya montada es guardar una decisión de presentación en
   la base de datos, y luego no se puede cambiar sin reescribir 150 registros.
2. **No hay campo de año académico.** El recuento es «cuánta gente hay en este
   grupo AHORA», que es lo que quieren las pantallas. El histórico por curso ya
   lo da la vigencia de las relaciones, y guardarlo aquí otra vez sería tener el
   mismo dato en dos sitios. (`cursos_c`, ojo, es el curso ESCOLAR del grupo —
   «1º ESO», «Adultos» — no el año.)
3. **`ajmcm_recuento_al_c` no es opcional.** Es lo que permite que la pantalla
   diga «10 participantes» con confianza, o se calle si el dato es viejo (ver §6).
4. **Van en el grupo y no en un módulo nuevo.** Un módulo de recuentos sería
   otra tabla, otra relación y otra llamada — justo lo que estamos evitando. El
   recuento es un atributo del grupo, no una entidad.

### Antes de crearlos

Comprobar que no existan ya con otro nombre. Es el aviso de
[`CLAUDE.md`](../../CLAUDE.md): es fácil acabar con dos campos para lo mismo. Y
si se crean, **hay que apuntarlos en `CAMPOS.md`** — y mirar si
`comunicaFormularios` escribe en ese módulo.

---

## 4. Quién rellena esos campos

### Opción A — dentro del CRM

**⚠️ Comprobar esto antes de invertir tiempo: el módulo de flujos de trabajo de
SuiteCRM no tiene, de serie, una acción de «contar registros relacionados».**
Sabe mirar condiciones y modificar campos, pero no agregar. Si en SinergiaCRM
existe algo así, esta opción pasa a ser la mejor de todas; si no, no se puede
hacer con flujos y hay que bajar un nivel:

- **Logic hook** (`after_relationship_add` / `after_relationship_delete` sobre
  `stic_Contacts_Relationships`). Recalcula el grupo afectado en el momento, así
  que **el dato nunca está viejo**. Es la mejor versión técnica.
- **Trabajo programado** (Admin → Programador, con una clase en
  `custom/Extension/modules/Schedulers/`). Nocturno, como los que ya pasan.

Las dos necesitan **subir ficheros PHP a la instancia de SinergiaCRM**. Si no
hay ese acceso —y en una instancia alojada y compartida es lo normal— esta
opción está cerrada y no hay más que hablar. **Eso es lo primero que hay que
averiguar.**

### Opción B — un script nuestro por API ✅ recomendada

Un script en este repo que:

1. pide los grupos de la delegación (1 llamada);
2. pide las relaciones de cada grupo (N llamadas, y aquí **sí da igual**: nadie
   está esperando delante de una pantalla);
3. calcula participantes vigentes, monitores vigentes y sus nombres;
4. escribe los cuatro campos en el grupo **solo si alguno ha cambiado**.

Ese punto 4 no es un detalle: escribir los 150 grupos cada noche llena el
registro de auditoría del CRM de cambios que no son cambios, y luego no se
puede ver quién tocó un grupo de verdad. Es la misma regla que ya seguimos con
el motivo de una asistencia.

**Por qué es la recomendada:** funciona sin tocar la instancia del CRM, se
prueba en local, va versionada aquí y se lee en el repo. Y a mano cuando haya un
cambio gordo, o en un GitHub Action nocturno, según apetezca.

Sitio: `.github/scripts/` (ya hay precedente ahí con `cumples/`) y un workflow
con `schedule:` + `workflow_dispatch:` para poder lanzarlo a mano desde la
pestaña Actions.

### La comparación, corta

| | A (logic hook) | A (programador) | **B (script + Action)** |
|---|---|---|---|
| Frescura del dato | Al instante | Cada noche | Cada noche, o cuando se lance |
| Hace falta acceso a ficheros del CRM | **Sí** | **Sí** | No |
| Versionado y revisable en este repo | No | No | **Sí** |
| Se puede probar antes de soltarlo | Regular | Regular | **Sí** |
| Se lanza a mano | — | Regular | **Sí** (`workflow_dispatch`) |

**Empezar por B.** Si algún día hay acceso a la instancia, el logic hook de A es
mejor (dato siempre fresco) y B se apaga sin tocar el área privada: los campos
son los mismos y a la pantalla le da igual quién los rellena.

---

## 5. La cara del área privada

Cuando los campos existan:

- **`sticpa_pl_groups()`** los pide en el array `$fields` que ya construye. Ojo:
  la API **devuelve error si se pide un campo que no existe**, así que hay que
  pedirlos con la misma cautela que `ajmcm_segmento_com_c` — mirar antes si
  están (`sticpa_pl_has_segmento()` es el patrón a copiar) y caerse con
  elegancia si no.
- **El árbol** sustituye `etapa · curso` por `monitores · curso · N participantes`
  y borra el comentario de `$groupMeta` que explica por qué no estaban.
- **El resumen** pone la suma de `ajmcm_n_participantes_c` de cada etapa como
  número grande, y `N grupos · M mon.` debajo.

Es poco código —el lado de leer son unas veinte líneas— y no hace falta esperar
a tener el rellenador: mientras los campos estén vacíos, la pantalla se queda
exactamente como está hoy.

## 6. El dato viejo: decirlo, no esconderlo

Un recuento nocturno **está desactualizado por diseño**. Si alguien se apunta el
martes, hasta el miércoles el número dice uno menos. Dos reglas:

1. **Para mirar, vale; para calcular, no.** El recuento es un titular. El
   denominador de un porcentaje de asistencia, o cualquier cosa que se le
   enseñe a una familia, sale de contar de verdad y no de aquí.
2. **Si el dato es viejo, la pantalla se calla.** Con `ajmcm_recuento_al_c` de
   hace más de unos días, mejor no pintar el recuento que pintar un número en el
   que nadie puede confiar. Un hueco se entiende; un número mal no se detecta.

Y en la lista de marcado el recuento **sigue saliendo de contar de verdad**: esa
pantalla ya trae las personas del grupo en una llamada, así que ahí el número
exacto es gratis. Estos campos son para las pantallas que **no** traen la gente.

---

## 7. Resumen para el que llegue nuevo

1. Averiguar si hay acceso a ficheros de la instancia de SinergiaCRM. Decide
   entre A y B.
2. Comprobar que los cuatro campos no existan ya con otro nombre. Crearlos en
   `ajmcm_GRUPOS`. Apuntarlos en `CAMPOS.md` y mirar `comunicaFormularios`.
3. Montar el rellenador (B: script en `.github/scripts/` + Action nocturna con
   `workflow_dispatch`). Que escriba **solo lo que cambia**.
4. Leerlos en `sticpa_pl_groups()` con la cautela de los campos que pueden no
   existir, y usarlos en el árbol y en el resumen.
5. No usarlos para ningún cálculo que se le enseñe a una familia.
