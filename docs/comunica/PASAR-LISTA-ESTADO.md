# Pasar Lista — parte de estado

**Fecha de este parte: 28 de agosto de 2026 (tarde).** Es el documento que hay que leer
para retomar el trabajo sin reconstruir el contexto. El mapa de todo lo demás
está en [`PASAR-LISTA-README.md`](PASAR-LISTA-README.md); el diseño funcional,
en [`PASAR-LISTA.md`](PASAR-LISTA.md).

Piloto: **MCM Castellón, curso 2025-2026**. Nada interdelegacional.

> **Versión web de este parte**, para leerla o compartirla sin abrir el repo:
> <https://claude.ai/code/artifact/0701d2cf-bf96-4b5d-b3c9-b5db712476a7>
> Es una copia: si cambias este documento, hay que volver a publicarla.

---

## 1. QUÉ ESTÁ ABIERTO AHORA MISMO (28/08/2026, tarde)

Esto es lo único que hay que leer para saber por dónde va. Lo cerrado está más
abajo, en §1-bis, y se conserva porque explica POR QUÉ el código es como es.

### 🔴 Tres cosas que hay que arreglar EN EL CRM, no en el código

Salieron al buscar fallos de diseño y son de datos, no de programa. Mientras no
se toquen, la aplicación seguirá enseñando menos de lo que hay.

| | Qué pasa | Qué hay que hacer |
|---|---|---|
| 🔴 | **El entorno personal de Solete está asignado a «Administrador MCM», no a la delegación.** Existe, está vigente, enlaza bien con su madre (Sol Meseguer, móvil `662065966`)… y la ficha no lo ve: de `assigned_user_id` cuelga el grupo de seguridad, y el usuario con el que lee el plugin no alcanza ese registro. | Reasignar a **MCM Castellón** (`00000cd2-159a-eef9-3639-68cd21b90b6a`) ese registro y **revisar si hay más entornos personales igual**. Es la regla de `CLAUDE.md`: todo lo que se cree va asignado a su delegación. |
| 🔴 | **Un centenar de asistencias basura `Unknown - Unknown \| `** (todas del 28/08 a la 01:10, colgando de la sesión del 02/05/2026, ninguna enlazada a nadie). El código que las creaba ya está arreglado, pero las que hay siguen ahí. | Borrado lógico (`deleted: true`) de las `stic_Attendances` cuyo `name` empiece por `Unknown` **y** no tengan inscripción enlazada. El propietario dijo que las borra él. **No tocar** las 24 de Solete ni ninguna con inscripción. |
| 🟡 | **Dos `LIS_listas` para la sesión del 02/05/2026**: una de `monitores` (pasada, 24/5) y otra de `participantes` (omitida, 0/0). Convivir no es ilegal —son de tipos distintos— pero conviene revisar que la de participantes en `omitida` sea lo que se quiso. | Mirarla y decidir. El código ya elige siempre la misma cuando hay dos, y lo avisa en pantalla. |

### 🟡 Lo que queda por comprobar en producción

Todo lo del 28/08 por la tarde está probado con tests pero **no confirmado
contra el CRM real**. Lo que hay que mirar la próxima vez que se pase lista:

1. **Un aviso nuevo llega con «Persona del aviso» rellena.** Es el arreglo de
   escribir el campo plano `avi_avisos_contactscontacts_ida` en el propio
   `set_entry` en vez de atarlo después con `set_relationship`. Si sigue
   saliendo vacía, el camino bueno es otro y hay que volver a mirarlo.
2. **Un aviso puesto desde la lista llega con su sesión** (`stic_sessions_id_c`).
3. **Guardar la lista de monitores NO crea ninguna `Unknown`**, y a los
   monitores sin inscripción se les crea la inscripción.
4. **La ficha de Solete enseña sus seis sesiones marcadas** (31/01 vino, 07/02
   falta justificada, 14/02, 14/03, 25/04 y 02/05 vino).

### 🟡 Las vigencias caducan el 31/08/2026

Verificado el 27/08: tanto la relación de participante de Solete como la de
monitor de David Soler tienen `end_date` = 2026-08-31. A partir del 1 de
septiembre C1 saldrá vacío y **eso NO es el bug volviendo**: es que el curso
2025-2026 se ha acabado en los datos. Mirar esto antes de buscar en el código.

### 🟡 Otros abiertos, menores

| | Qué |
|---|---|
| 🟡 | Alguien puede ser monitor de dos grupos. Sale, pero hay que decidir cómo se enseña. |
| 🟡 | El grupo `Najar` no es de participantes MIC-COM y aparece en el árbol. Hay que decidir el filtro de qué grupos salen. |
| 🟡 | Los «sectores» (COM I = los dos primeros cursos de la ESO, etc.) se agrupan **a mano**. No hay campo en el CRM y de momento no lo va a haber. |
| 🟡 | El filtro plano por campo de enlace (`..._ida`) en `get_entry_list` devuelve **error 400 de base de datos** en `stic_Sessions`, `LIS_listas`, `stic_Registrations` y `stic_Contacts_Relationships`. Se puede LEER el campo, pero no FILTRAR por él. Para consultar por relación hay que ir por `get_relationships`. Ojo: no es lo mismo que la trampa de §3.1 — ahí el problema es el enlace anidado que no viene; aquí es el filtro que el CRM rechaza. |
| 🟡 | `ajmcm_curso_escolar_c` **existe** en `stic_Contacts_Relationships` y está **vacío en todas** las relaciones reales. El curso del histórico se deduce de `start_date` y `end_date`. Si algún día se rellena, hay que mirar antes las claves internas de su desplegable. |
| 🟡 | `ajmcm_pasar_lista_c` (la casilla del grupo) tiene **`default: 1`** en el CRM: un grupo nuevo entrará solo en Pasar Lista. Probablemente es lo que se quiere; conviene saberlo. |
| 🟡 | `end_date` **no existe** en `stic_Attendances` (la API devuelve 400 si se manda). Solo hay `start_date`. |

### 💡 Anotado para hablarlo: un navegador de fichas sin pasar lista

**No está hecho y no se ha empezado.** El propietario lo pidió el 28/08 y
quiere hablarlo antes.

El problema: hoy, para ver los datos de un chaval o de un monitor, hay que
**«pagar el precio» de entrar a pasar una lista** — portada → árbol de grupos →
marcar → flecha de la fila → ficha. La ficha ya tiene todo lo que hace falta,
pero está enterrada detrás de un flujo que es para otra cosa.

Lo que se quiere: una pantalla que lleve **directamente a fichas y a listas de
personas**, sin pasar por marcar. Navegable por lo que tenga sentido —**por
grupos, alfabética, por cursos**— y para las dos poblaciones: niños y monitores.

Lo que ya está resuelto y se puede reaprovechar tal cual:

- `sticpa_pl_all_relationships()` ya trae **toda** la gente de la delegación en
  una consulta, con su grupo, su papel y su vigencia. El navegador no necesita
  ni una consulta nueva: es re-presentar lo que ya se carga.
- `sticpa_pl_contacts_bulk()` resuelve los datos de lista de mucha gente de una
  vez (nombre, iniciales, edad, móvil).
- Las dos fichas (`_ficha` para participantes, `_monitor` para monitores) ya
  existen, ya agrupan sus consultas y ya son la pantalla que sustituye a abrir
  el CRM.
- La lista de monitores (`_monitores`) ya es medio navegador: tiene alcance por
  etapa y buscador.

Lo que hay que decidir (esto es lo que hay que hablar):

1. **¿Una pantalla o dos?** ¿Niños y monitores juntos con un conmutador, o
   separados como ahora?
2. **¿Cuál es el orden por defecto?** Por grupo es lo que coincide con cómo se
   piensa un sábado; alfabético es lo que sirve cuando buscas a alguien
   concreto y no recuerdas su grupo.
3. **¿Quién lo ve?** Un monitor solo su grupo, o toda la delegación (que es lo
   que ya puede hacer hoy pasando lista de cualquier grupo).
4. **¿Entra en el menú principal** o cuelga de la portada de Pasar Lista?

---

## 1-bis. Historial: lo que ya está cerrado

Se conserva porque explica por qué el código es como es. Si buscas el estado de
hoy, está arriba.

### ✅ Pasar lista ya pasa lista — pendiente de confirmar en producción

**La causa era el JavaScript, y llevaba ahí desde el primer commit de la fase 1**
(`88511ec`). Al enviar el formulario, el manejador de `submit` hacía:

```js
saveBtn.disabled = true;   // dentro del propio manejador de submit
```

Y **un control deshabilitado no se serializa**. El navegador construye los datos
del formulario DESPUÉS de ejecutar el manejador de `submit`, así que el botón
—que es quien lleva `name="pl_action"`— se quedaba fuera. Al servidor llegaba un
POST sin `pl_action`, PHP entraba en `if (!empty($_POST['pl_action']))`, decía
que no, y **se saltaba el guardado entero sin escribir ni una línea ni decir
nada**. La pantalla se recargaba igual y el borrador se había borrado ya.

Encaja con todo lo observado:

- **Cero rastro en el CRM**, que es justo lo que se verificó por MCP: ninguna
  `stic_Attendances` ni `LIS_listas` tocada después de las 14:00 UTC del 27/08
  (todo lo de ese día es de «API User MCP», la limpieza de la mañana).
- **Fallaba igual en participantes y en monitores**: las dos pantallas usan el
  mismo JS y el mismo botón.
- **«Sin registro» sí funcionaba**: ese botón no se deshabilita.
- **Ningún test lo veía**: un POST de PHPUnit trae `pl_action` puesto a mano. Es
  la misma familia de la trampa §3.2 — el doble (aquí, el test entero) no
  reproducía el comportamiento real del navegador.

Todo lo arreglado antes (los enlaces anidados, el `regMap`, la lista duplicada)
era real y necesario, pero estaba detrás de una puerta cerrada.

**El arreglo, por dos vías independientes:**

1. La acción viaja en un **campo oculto** (`<input type="hidden"
   name="pl_action" value="save" data-pl-action>`), que no depende de ningún
   botón. Los botones conservan su `name`/`value` para el caso sin JS, donde
   manda el último valor que llega — y «Sin registro» va después en el DOM, así
   que sigue ganando cuando se pulsa.
2. El JS ya **no deshabilita el botón hasta el tic siguiente**
   (`setTimeout(…, 0)`), y además pone la acción en el campo oculto.

### Y para que nunca vuelva a fallar en silencio

Esto es lo que convierte «no se guarda y no sé por qué» en un diagnóstico de
treinta segundos:

| Qué | Dónde |
|---|---|
| El transporte deja de tragarse los errores del CRM: `set_entry` ya no lee `->id` a ciegas, `set_relationship` mira su cuenta de fallos, y el motivo queda en `$objSCP->lastError` y en el `error_log` | `inc/stic-class-6.php` |
| El fallo de la `LIS_listas` y el de las relaciones **cuentan** como fallo (antes no: se decía «Lista guardada» con el CRM vacío) | `sticpa_pl_save()` |
| **Relectura del CRM tras guardar**: «Lista guardada» solo si se comprueba que está. Sin llamadas extra, la pantalla ya releía | `sticpa_pl_check_saved()` |
| **Diario de intentos** en `wp_options` (`sticpa_pl_save_log`), incluidos los que NO escriben: `nonce`, `sin_marcas`, `post_sin_accion`, con el tamaño del campo crudo recibido | `sticpa_pl_log_save()` |
| **Panel de diagnóstico** de solo lectura: `?pl_diag=1` en la portada, solo coordinación. Enseña el diario y las llamadas al CRM de la petición con sus milisegundos | `inc/stic-pasar-lista-diag.php` |
| El borrador del móvil ya no se borra al enviar, sino cuando el servidor confirma; y la cola sin conexión solo saca una entrada si el servidor confirma (antes un 200 con aviso se daba por enviado y la lista se perdía) | `js/stic-pasar-lista.js` |

### Cómo confirmar que está cerrado

Sigue valiendo el criterio de §7: un guardado real a mano en el navegador tiene
que dejar **una** `LIS_listas` con `estado = pasada`, sus dos enlaces y los
números correctos, y el `status` de cada persona marcada en la asistencia de ESA
sesión. Ahora, además, **la propia pantalla lo comprueba**: si dice «Lista
guardada» es que ha releído el CRM y estaba. Si algo falla, lo dice y lo apunta
en `?pl_diag=1`.

### ✅ Confirmado en producción el 27/08/2026

El propietario pasó lista de verdad y se guardó. **El bug está cerrado.**

### ✅ La lista de monitores ya se escribe (27/08/2026)

Antes se guardaban las asistencias de los monitores y **no quedaba constancia de
que la lista se hubiera pasado**: no había forma de distinguir «ese sábado nadie
la pasó» de «la pasaron y no vino nadie». El campo `ajmcm_tipo_c` existe justo
para esto y el plugin tenía `sticpa_pl_lista_tipos()` **sin que nadie la
llamara**.

Ahora:

- La lista de monitores se escribe con `ajmcm_tipo_c = monitores`, enlazada a la
  sesión y al monitor que la pasó, y **sin grupo**: el alcance de coordinación
  es la etapa, no un grupo.
- Las de participantes mandan su tipo explícito. Es un campo **REQUERIDO** en el
  CRM (verificado con `get_module_fields`): salía bien solo porque el CRM tiene
  `participantes` como valor por defecto, y apoyarse en eso es apoyarse en nada.
- `LIS_listas` se lee **una sola vez** para las dos familias
  (`sticpa_pl_listas_index()`), y cada una tiene su índice:
  `sticpa_pl_all_listas()` por sesión y grupo, `sticpa_pl_all_listas_monitores()`
  por sesión. Así una lista de monitores no puede aparecer como la lista de un
  grupo, que diría que un grupo pasó una lista que no es.
- La pantalla de monitores dice si ya está pasada, con sus números.

> ⚠️ **Límite conocido, a propósito.** Hay **una lista de monitores por sesión y
> delegación**. Si MIC y COM comparten evento —y por tanto sesiones— sus
> coordinadores comparten esa lista y el último que guarde deja sus números. Las
> asistencias de cada monitor son correctas en cualquier caso (son por persona);
> lo que se pisa es el resumen. Separarlas de verdad pide **un campo de etapa en
> `LIS_listas` que hoy no existe**, y aquí no se inventan campos. Si hace falta,
> se pide al CRM y se anota en `CAMPOS.md`.

### ✅ C1 se quedó sin participantes, y la causa era de las gordas (27/08/2026)

El grupo salía con «0 participantes» teniendo su gente viva en el CRM
(verificado: la relación de Solete existe, vigente hasta el 31/08/2026).

**La causa raíz: el CRM devolvía UNA PÁGINA y el plugin se la creía entera.**
Está contada como trampa en §3.5, porque va a volver a morder a quien escriba
una consulta nueva. En corto: `max_results = 0` no significa «sin límite»,
llegaban 20 de 109 relaciones, y los grupos que caían fuera salían vacíos.

Hubo además un agravante nuestro, y de ese día: por matar un 1+N en la pantalla
de monitores se quitó **entero** el respaldo por grupo, que es justo lo que
rescataba a un grupo cuya gente no venía en el mapa. El 1+N estaba en el bucle
sobre ~150 grupos, no en pintar un grupo:

- `sticpa_pl_group_people()` vuelve a rescatar el grupo que se está pintando.
  Cuesta UNA llamada y solo cuando ese grupo sale vacío.
- `sticpa_pl_group_people_bulk()` es la puerta para quien recorre grupos: no cae
  al respaldo nunca. Es la que usa `sticpa_pl_monitors_of()`.

### ✅ La ficha ya encuentra a la familia y sus teléfonos (27/08/2026)

Era el mismo tipo de trampa de §3.1, y llevaba a la ficha sin lo que más se
busca un sábado: el teléfono de casa.

- El código pedía el campo plano `stic_personal_environment_contacts_1contacts_idb`,
  que **no existe**: los DOS lados de la relación acaban en `_ida` (verificado
  con `get_module_fields`).
- Y leía los datos del familiar **solo del enlace anidado**, que esta instancia
  no puebla. Resultado: bloque de familia vacío en todas las fichas, sin un aviso.

Ahora se leen los dos campos planos, se descarta el lado del propio
participante, y los familiares se resuelven en **una sola consulta** para toda
la familia (el teléfono está en `phone_mobile`; no llega por el enlace). El
parentesco se traduce: el CRM lo guarda en inglés (`mother`) y debajo del nombre
de la madre eso se leía raro.

Detalle de campos, en [`PASAR-LISTA-CAMPOS-CRM.md`](PASAR-LISTA-CAMPOS-CRM.md).

### ✅ La casilla `ajmcm_pasar_lista_c` ya existe y está en uso (27/08/2026)

Creada en el CRM y rellenada: **20 grupos marcados de 28** en Castellón (fuera
quedan L1, L2, L3, L4, L6, L7, L8 y el grupo de apoyo sin código). Cuatro de los
que quedan fuera **tienen monitores** (L3, L4, L6, L8), así que esos monitores no
salen en la pantalla de coordinación — es lo que la casilla hace, pero conviene
saberlo antes de buscar el fallo en otro sitio.

> ⚠️ **El valor llega de dos formas distintas** según por dónde salga: la cadena
> `"1"` cuando está marcada y el booleano `false` cuando no. Por eso se normaliza
> con `sticpa_pl_bool_crm()` y no se compara nunca con `=== true` ni con `== '0'`.

Sirve para limpiar el árbol: en el CRM
hay ~150 grupos y la mayoría son históricos.

La regla de seguridad: **mientras no haya ninguna casilla marcada, no se esconde
nada**. Así se puede crear el campo y marcar los grupos poco a poco sin que
Pasar Lista se quede vacío ni un minuto. Y cuando el filtro actúa, el árbol dice
cuántos grupos quedan fuera.

Ficha completa del campo (etiqueta, tipo, interruptores para apagarlo) en
[`PASAR-LISTA-CAMPOS-CRM.md`](PASAR-LISTA-CAMPOS-CRM.md) §2.

### ✅ La ficha del monitor: seguimiento, datos agrupados, grupos e histórico (28/08/2026)

La pantalla que sustituye a abrir el CRM. **El orden ya no es el del CRM sino el
de la conversación de coordinación**, y el certificado de delitos sexuales ya no
la abre: es una obligación legal, sí, pero es una casilla, y una casilla no es
la persona. Ahora va dentro de «En regla», con las otras siete.

Lo que enseña, en este orden:

1. **Cómo va este curso**, en tres pistas de cuadraditos separadas y **nunca
   promediadas**: sábados (con porcentaje), reuniones de programación (con
   fracción, no porcentaje: con cuatro al año un 75 % suena a nota y es una
   sola falta) y listas del grupo (con quién la pasó).
2. **En regla**: ocho comprobaciones, con la cuenta de lo que falta en la
   cabecera. Un permiso no dado —la cesión de imágenes— sale en gris y no en
   rojo: es una decisión de la persona, no una deuda.
3. **Formación** (solo lo que tiene, con el descuadre de «titulado sin
   archivo»), **trayectoria** y **datos personales**, estos últimos plegados.
4. **Sus grupos**: el que lleva y el suyo (la relación `grupo` COM-LC, que en el
   CRM vive en otra pestaña), con los recuentos calculados **en vivo** del mapa
   de relaciones y con quién los comparte.
5. **Por dónde ha pasado**: curso a curso, con quién estaba en cada uno.

**Coste: cuatro tandas paralelas, y ni una consulta por grupo ni por curso.** El
histórico entero sale de las relaciones **terminadas**, que ya venían en la misma
consulta que las vigentes y se tiraban al filtrar por vigencia. Con la caché
caliente son dos consultas. El tope está escrito en `CosteLlamadasTest`.

### ✅ Un hueco no es una falta (28/08/2026)

`sticpa_pl_attendance()` metía las sesiones **sin marcar** en el denominador. Si
el grupo pasó tres listas de diez, un chaval que vino a las tres salía al 30 %
en su ficha: ese 70 % no eran ausencias suyas, era la lista sin pasar, y el
número quedaba escrito como si lo fueran.

La función se ha retirado. Queda `sticpa_pl_att_track()`, que cuenta sobre lo
**marcado**, cuenta los huecos aparte y los dice en pantalla, y devuelve «sin
datos» en vez de un 0 % cuando no hay nada marcado. Las horas siguen la misma
regla. Lo usan las tres pantallas: ficha del participante, ficha del monitor y
lista de monitores.

### ✅ Avisos de seguimiento en la lista de monitores (28/08/2026)

En la lista, una nota en rojo bajo el nombre **solo cuando algo no va** —tres
seguidas sin venir, o menos del 60 % de asistencia— y nada para quien va bien.
Lo que se busca ahí es *a quién hay que mirar* de los treinta: una lista con
treinta porcentajes es una lista que nadie lee. Umbrales en
`sticpa_pl_seguimiento_umbrales`, conservadores a propósito.

### ✅ Dos parejas de consultas que preguntaban lo mismo (28/08/2026)

Los eventos de la delegación se pedían **dos veces** (el cargador por etapa y el
del evento de reuniones) y las relaciones de quien está conectado también (el
alcance de coordinación y el acompañamiento). El memo de la tanda paralela no
las salvaba: **se consume de un solo uso**, así que dos cargadores con la misma
firma se reparten una respuesta y el segundo llama igual. La forma de no pagar
dos veces es no preguntar dos veces, así que ahora hay un cargador crudo debajo
—`sticpa_pl_events_raw()` y `sticpa_pl_mis_rels()`— y los dos de arriba filtran.

Los topes de `CosteLlamadasTest` bajaron a la vez: el árbol de grupos de 8 a 7 y
marcar de 10 a 9. Un tope que se queda holgado deja de proteger nada.

### ✅ Los avisos llegaban sin persona (28/08/2026, tarde)

De cuatro avisos reales en el CRM, **solo uno tenía la relación** con el chaval,
y hasta ese salía con «Persona del aviso» vacía en la pantalla de edición.

La causa: el aviso se creaba con `set_entry` y se ataba DESPUÉS con
`set_relationship`. Eso escribe la tabla puente por detrás y deja sin rellenar
el campo desde el que el CRM pinta el nombre. Ahora la persona va en el campo
plano **`avi_avisos_contactscontacts_ida` dentro del propio `set_entry`**, que
es el camino que usa la pantalla del CRM, y la relación se manda además por su
lado. Y se **comprueba releyendo**: si el aviso no queda a nombre de nadie, la
pantalla lo dice en vez de felicitar.

Y el id de la sesión iba a `ajmcm_sesion_c`, que es el campo que se PINTA. Va a
**`stic_sessions_id_c`**. La sesión, además, viaja desde la lista en el enlace
de la ficha: un aviso puesto un sábado es un aviso DE ese sábado.

### ✅ La fábrica de asistencias «Unknown - Unknown» (28/08/2026, tarde)

Un centenar de registros basura en el CRM en una noche. Al guardar la lista de
monitores se creaba una asistencia por monitor aunque el monitor **no estuviera
inscrito al evento** — y casi ninguno lo está, así que ese camino era el normal,
no la excepción.

Sin inscripción detrás, una asistencia:

- nace **sin nombre** (el CRM lo compone de la inscripción: «Unknown - Unknown»),
- nace **sin fecha**, así que las consultas por rango no la ven nunca,
- y **no se puede volver a encontrar**, porque lo único que la ata a una persona
  es justo la inscripción que no tiene.

Consecuencia: **cada guardado creaba otra**. Ahora se le crea la inscripción que
le falta —una llamada, y solo la primera vez—, los dos enlaces y la fecha van en
el propio `set_entry` (el CRM compone el nombre AL GUARDAR: llegar tarde con los
enlaces deja «Unknown» para siempre), y **si no se puede atar, no se escribe**.

### ✅ La ficha decía «0 de 0 sesiones marcadas» con las marcas puestas (28/08/2026)

Solete tiene 24 asistencias y **seis marcadas**; la ficha no veía ninguna.
`sticpa_pl_contact_marks()` pedía la sesión de cada asistencia por el enlace
anidado —que esta instancia no devuelve, §3.1— y era la única lectura sin el
respaldo que sí tienen las demás. Ahora cae al camino probado: por rango de
fechas, que son columnas de verdad. Cuesta cero llamadas cuando la pantalla ya
las ha pedido.

### ✅ El 1+N que hacía lento el sábado (28/08/2026)

El respaldo que resuelve a la gente de un grupo llamaba al CRM **una vez por
persona**. Un C1 de doce son doce viajes; en móvil, seis segundos con la
pantalla quieta. Es el «cambiar de fecha va lentísimo».

Ahora el campo plano del contacto viaja en la consulta de las relaciones del
grupo y **los contactos se piden todos juntos** (`sticpa_pl_contacts_bulk()`).
De paso, los dos caminos dan la misma lista: el de antes se dejaba fuera a quien
entra al grupo por el papel `grupo` (los +18 en su grupo de referencia).

Y **la ficha del participante hacía TRECE llamadas en fila sin agrupar ni una**,
mientras la del monitor sí agrupaba — de ahí que «la velocidad de monitores vaya
bien» y la de los niños no. Ahora agrupa igual.

Números en el modo que ocurre de verdad (sin enlaces anidados):

| Pantalla | Antes | Ahora |
|---|---|---|
| Portada | 14 llamadas | **9** |
| Árbol de grupos | 8 | **7** |
| Marcar | 13 | **10** |
| Ficha del participante | 16 llamadas / 13 esperas | **13 / 7** |
| Cambiar de fecha (caché caliente) | — | **4 llamadas** |

### ✅ Guardar eran doce escrituras en fila (28/08/2026)

Las asistencias de un guardado son filas distintas de la misma tabla,
independientes entre sí, y salían una detrás de otra. Ahora las que solo hay que
**actualizar** —el caso normal— van en una tanda. Las que hay que **crear**
siguen en serie: necesitan el id que devuelve el CRM para poder atarlas.

### ✅ La vuelta de diseño (28/08/2026, tarde)

- **Márgenes**: `.stic-tab-content` se comía 14 px por lado en un móvil de 390,
  antes de contar lo que mete el tema de WordPress por fuera, y dentro casi todo
  son a su vez tarjetas con su propio margen. Los lados bajan a la mitad
  (`--stic-gutter`). Lo que quede es del tema, no del plugin.
- **Jerarquía**: el título de sección tenía 1,2 rem por arriba y 0,55 por abajo.
  Medio rem no se ve, y la ficha del monitor —ocho secciones seguidas de
  tarjetas blancas sobre página casi blanca— se leía como un muro. Ahora el
  hueco de arriba es más del triple. Separar por proximidad, sin una caja más.
- **«Formación» plegada**, con los títulos (MAT, DAT) en la solapa: plegar sin
  perder.
- **Los cuadraditos, por meses y con el mes escrito**, y el último con anillo.
  A 24 sesiones la fila corrida solo decía «hay rojos», no cuándo — y el cuándo
  es el dato.
- **Contacto del monitor**: cada dato en su fila, el correo se copia de un toque
  y el «otro teléfono» dice que es de urgencia en vez de parecer el suyo.
- **Oscuro**: las reglas de los controles nativos estaban escritas para
  `.stic-form` y los formularios de Pasar Lista son `.pl-field`; y los enlaces
  sin clase se quedaban con el azul del tema de WordPress.
- **La familia sale aunque no tenga teléfono**: la sección era la lista de
  teléfonos, así que quien no tenía número no existía — ni para llamarle ni para
  avisar de que faltaba su número.

---

## 2. Qué SÍ funciona

- Las cinco pantallas se pintan enteras y sin avisos: portada, árbol de grupos,
  marcar, ficha y resumen.
- El grupo C1 enseña a sus participantes (fue un bug grave y está cerrado).
- «Tu grupo» detecta el grupo del monitor conectado.
- El orden de los grupos: por etapa y, dentro, por código en orden natural.
- El botón de refrescar invalida de verdad, y en las cuatro pantallas.
- Los recuentos nocturnos del Guardián se leen en la ficha del grupo.
- El modo sin conexión está construido (apagado por defecto con el filtro
  `sticpa_pl_offline_enabled`).
- La ficha de un monitor enseña su seguimiento del curso, sus datos agrupados,
  sus grupos y por dónde ha pasado, sin abrir el CRM.
- Los porcentajes de asistencia cuentan sobre lo marcado, no sobre lo celebrado.
- La ficha de un participante enseña sus sesiones marcadas (era el «0 de 0»).
- Un aviso queda a nombre de su persona, y si no, la pantalla lo dice.
- Guardar no crea asistencias huérfanas: o se puede atar, o no se escribe.
- Ninguna pantalla resuelve a la gente de un grupo con una llamada por persona.

---

## 3. Las trampas: por qué se rompió lo que se rompió

Esta sección es la que ahorra días. Son seis cosas que **volverán a morder** a
quien no las sepa.

### 3.1 Esta instancia NO devuelve los enlaces anidados

`get_relationships` con `related_module_link_name_to_fields_array` devuelve
**200, con registros, y sin el enlace anidado dentro**. No falla: miente por
omisión. Cinco bugs distintos venían de aquí.

Lo que sí funciona es `get_entry_list` con `link_name_to_fields_array`, que es
el 4º parámetro de `getRecordsModule()`. Está probado en producción desde años
en `pages/list_stic_job_offers.php`.

**La regla:** pide SIEMPRE el campo plano `..._ida` junto al enlace anidado y
usa el que llegue. Los campos planos existen por relación:

```
stic_contacts_relationships_contactscontacts_ida
ajmcm_grupos_stic_contacts_relationshipsajmcm_grupos_ida
stic_registrations_contactscontacts_ida
lis_listas_stic_sessionsstic_sessions_ida
lis_listas_ajmcm_gruposajmcm_grupos_ida
stic_attendances_stic_sessionsstic_sessions_ida
stic_attendances_stic_registrationsstic_registrations_ida
```

Detalle largo en [`PASAR-LISTA-CAMPOS-CRM.md`](PASAR-LISTA-CAMPOS-CRM.md) §9.

### 3.1-bis Y PARA ESCRIBIR, el campo plano también

La cara B de §3.1, y costó cuatro avisos sin dueño y un centenar de asistencias
basura antes de verla.

`set_relationship` escribe la tabla puente **por detrás del bean**. El registro
queda relacionado —una consulta por relación lo encuentra— pero:

- el campo que la pantalla del CRM PINTA se queda vacío («Persona del aviso»),
- y el `name` que el CRM compone AL GUARDAR ya se ha calculado sin los enlaces,
  así que se queda «Unknown - Unknown» para siempre.

**La regla: los enlaces van DENTRO del `set_entry`, en su campo plano `..._ida`.**
Es lo que hace la propia pantalla del CRM, y con eso SuiteCRM crea la fila de la
relación al guardar. `set_relationship` vale como refuerzo, no como camino
principal.

```
avi_avisos_contactscontacts_ida                         ← la persona de un aviso
stic_attendances_stic_sessionsstic_sessions_ida         ← la sesión
stic_attendances_stic_registrationsstic_registrations_ida ← la inscripción
stic_registrations_contactscontacts_ida                 ← la persona inscrita
```

Y los campos **relate** (`ajmcm_sesion_c`, `ajmcm_puesto_por_c`) son los que se
PINTAN: el id va en su campo aparte (`stic_sessions_id_c`, `contact_id_c`).
Escribir un id de 36 caracteres en el campo de pintar deja el registro con un
texto ilegible y, aun así, sin relación.

### 3.2 Un doble de test puede mentir sobre la forma de la API

Hubo **175 tests en verde con la producción rota**. El doble colgaba
`link_list` directamente del registro; la API real lo manda en
`relationship_list`, en paralelo y por posición. El plugin tenía que ensamblarlo
y no lo hacía, y el doble ocultaba justo eso.

**La regla:** un doble construye la forma **real** de la API y la pasa por el
ensamblado del propio plugin (`SugarRestApiCall::attachLinkList()`,
`flattenRelationshipFields()`). Si el doble tiene su propio ensamblado, no
prueba nada.

> **Y también miente por lo que NO modela.** El doble contaba las escrituras de
> la pasada de recolecta como escrituras de verdad: el transporte real no llama
> a nadie mientras recolecta, así que una lista de doce habría parecido
> veinticuatro escrituras y —peor— un `prime()` colado antes de un `set_entry`
> de verdad habría pasado por bueno escribiendo dos veces. Si el doble no modela
> un mecanismo, ese mecanismo no está probado aunque los tests estén verdes.
>
> **Y por lo que esconde.** El doble no devolvía el campo plano
> `stic_contacts_relationships_contactscontacts_ida` en el modo «sin enlaces»,
> aunque el CRM sí lo devuelve (§3.1). Resultado: una llamada por persona
> parecía inevitable, y era un 1+N que se podía matar con una consulta.

### 3.3 El CSS de los artboards es la especificación, no su texto

Los `.dc.html` de `design/pasar-lista/` llevan todo su CSS en línea. Leer solo
el texto produce pantallas con el contenido correcto y el aspecto equivocado:
título a 1,15rem donde el diseño dice 1,45rem, filas de 52 px donde dice
`padding: 15px 16px`, una tarjeta verde que no existe en ningún artboard.

### 3.4 Un token que no existe se ve bien en claro y mal en oscuro

`var(--surface-1, #fff)` con `--surface-1` inexistente **no falla**: se queda
con el valor de reserva, que es el del tema claro. En oscuro sale una tarjeta
blanca. Había cinco tokens inventados así.

Dos más de la misma familia:

- **`--white` no es «blanco»**: es un token de superficie y en oscuro vale
  `#16171a`, *más oscuro* que el fondo de página. Para lo que va encima de un
  relleno de color está `--pl-on-brand`, que es blanco fijo.
- **El atributo del tema es `data-stic-scheme`**, no `data-theme`. Había cuatro
  reglas escritas con `[data-theme]` que no aplicaban nunca.

`tests/TokensCssTest.php` impide que las tres vuelvan. **No pongas valores de
reserva con color en `var()`**: es lo que esconde el fallo.

### 3.5 `max_results = 0` NO es «sin límite» — y por eso un grupo salía vacío

**La trampa más cara del proyecto, encontrada el 27/08/2026.** Todas las
consultas de colección mandaban `'max_results' => 0` dando por hecho que
significaba «tráelo todo». En SuiteCRM v4.1 es un valor **falsy**: no se aplica,
y el servidor usa su `list_max_entries_per_page` — **20 filas** por defecto.

La delegación de Castellón tiene **109 `stic_Contacts_Relationships`**. Llegaban
las 20 primeras. Los grupos cuya gente caía fuera de esa página salían con
**cero participantes**, exactamente igual que un grupo vacío de verdad. Así se
quedó C1 sin participantes un sábado, con el dato intacto en el CRM.

Y era invisible: `total_count`, `result_count` y `next_offset` —los tres campos
que la API devuelve justo para esto— **no aparecían en ninguna línea del repo**,
y los dobles de test construían respuestas sin `total_count`, así que ningún
test podía verlo.

**La regla:** una consulta de colección **pagina**, y el corte lo pone
`total_count` o una página vacía. **NUNCA** una página más corta de lo pedido:
el servidor tiene su propio tope y puede devolver 20 aunque le pidas 200 — que
es el fallo entero. Y como seguro, si una página no trae ningún id nuevo, se
para: hay servidores que ignoran el `offset`.

Está resuelto en `getRecordsModule()` y `getRelatedElementsForLoggedUser()`;
ajustable con `sticpa_crm_page_size` y `sticpa_crm_max_rows`.

> **Corolario para los dobles:** si tu doble devuelve siempre la colección
> entera, no puede detectar un truncado. El de `TransportLinkListTest` simula un
> servidor que pagina Y que ignora un `max_results` mayor que su tope.

### 3.6 El tema de WordPress pinta tus `<button>`… y tus `<label>` y tus `<input>`

**Ampliada el 28/08/2026: no eran solo los botones.** Son tres selectores del
tema y de la base del área que le ganan por especificidad a una clase suelta, y
los tres han producido un fallo visible que nadie vio leyendo el CSS del plugin:

| Quién gana | A quién le gana | Qué se veía |
|---|---|---|
| `.entry-content button` del tema de WP | `.pl-all-present`, `.pl-opt`, `.pl-row`… | «Han venido todos» en letra blanca sobre verde claro |
| `:is(.stic-tab-content, …) label { display: block }` de `stic-base.css` | `.pl-motive`, `.pl-avi-check`, `.pl-field` | El motivo de la hoja, una caja de 130 px con el lápiz ENCIMA del texto en vez de la pastilla de 48 px del artboard |
| `input[type=text\|search] { border-width: 1.5px !important }` de `custom-style.css` §10 | `.pl-motive input`, `.pl-search input` | Una caja con borde DENTRO de la pastilla |

Los tres se neutralizan en `css/pasar-lista.css` §0.b, §0.c y §0.d, con la misma
receta: `!important` para desactivar lo del tema, y que el componente vuelva a
declarar lo suyo también con `!important`.

**La regla, para no repetirlo una cuarta vez: todo componente nuevo de Pasar
Lista que sea un `<button>`, un `<label>` o lleve un `<input>` dentro pasa por
§0.b/§0.c/§0.d antes de darse por bueno.** Y ojo con el efecto secundario: una
regla de `display` con `!important` se come los `display: none` que la esconden,
así que esos también tienen que llevarlo (le pasó al motivo).

### 3.6-bis El texto original sobre los `<button>`

El tema estila `.entry-content button`, con más especificidad que una clase.
Todos los botones de Pasar Lista son `<button>` de verdad (accesibilidad), así
que heredaban su relleno: «Han venido todos» con letra blanca sobre verde claro,
«Sin registro» como barra ámbar sólida. Neutralizado en `css/pasar-lista.css`
§0.b, con el mismo remedio que ya usaba `.stic-pass-toggle`.

---

## 4. Rendimiento: dónde está y qué queda

El problema no era el CRM: era un bug propio (`firstLinked()` daba el primer
bloque de enlace a **todos** los campos pedidos, así que el camino de una sola
llamada fallaba en silencio y caía a respaldos 1+N).

> ⚠️ **LOS NÚMEROS DE ABAJO ESTABAN MEDIDOS EN EL MODO QUE NO OCURRE.**
> El test de coste solo contaba las llamadas con los enlaces anidados puestos, y
> esta instancia **no los devuelve** (§3.1). En el modo real cada pantalla
> costaba bastante más, y encima pedía la ficha del participante con el
> parámetro equivocado —salía por la puerta de «no se ha indicado ningún
> participante»— así que medía **0 llamadas de una pantalla que no se pintaba**.
> Arreglado el 28/08/2026: `CosteLlamadasTest` mide **los dos modos**, con tope
> en los dos, y además tiene tope de **ESPERAS** (viajes de ida y vuelta), que
> es lo que se nota. Diez llamadas en dos tandas paralelas son dos esperas; diez
> en fila son diez.

**Coste real hoy** (sin enlaces anidados, que es como responde esta instancia):

| Pantalla | Llamadas | Esperas |
|---|---|---|
| Portada | 9 | 5 |
| Árbol de grupos | 7 | 3 |
| Marcar | 10 | 6 |
| Resumen | 7 | 3 |
| Ficha del participante | 13 | 7 |
| Lista de monitores | 10 | 4 |
| **Cambiar de fecha** (caché caliente) | **4** | 4 |

Lo que bajó esos números el 28/08: matar el 1+N que resolvía a la gente de un
grupo una llamada por persona, agrupar en tandas la ficha del participante (que
hacía trece llamadas en fila sin agrupar ni una), juntar las dos consultas de
«mis relaciones» y batir las escrituras del guardado.

<details>
<summary>Los números de antes (27/08/2026), medidos solo en el modo «enlaces OK»</summary>

| Pantalla | Antes | Ahora |
|---|---|---|
| Portada | 13 | 6 |
| Árbol de grupos | 10 | 6 |
| Marcar | 14 | 8 |
| Resumen | 15 | **7** |

</details>

`tests/CosteLlamadasTest.php` fija los topes. **Si añades una consulta, ese test
te lo dirá**: es el sitio donde se ve un 1+N nuevo antes de que llegue a
producción.

Cargadores de colección (uno por colección, nunca uno por fila):
`sticpa_pl_all_relationships()`, `sticpa_pl_all_listas()`,
`sticpa_pl_attendances_for_sessions()`.

### Lo hecho el 27/08/2026

- **Un vacío ya no envenena la caché 12 horas** (ver §5). Es lo que convertía un
  hipo del CRM en media jornada de pantalla rota, que se lee como «va lentísimo
  y encima está mal».
- **TTL de estructura 12 h → 24 h**, ahora que el calentado nocturno está
  configurado: con 12 h la caché caducaba a media tarde del sábado, justo
  cuando se usa.
- **Medición de verdad**: cada petición cuenta sus llamadas al CRM y sus
  milisegundos. Las que pasan de 3 s dejan una línea en el `error_log` con el
  desglose, y `?pl_diag=1` lo enseña por pantalla. **Antes de tocar nada más de
  rendimiento, mira esos números**: lo siguiente (paralelizar con `curl_multi`)
  es la parte cara y no se empieza a ciegas.

### Medido y DESCARTADO: no envolver `session_attendances` en una tanda

Parece la jugada obvia —las dos lecturas de asistencias de la pantalla de marcar
son independientes y son dos esperas en fila— pero **sube el coste**: de 10 a 12
llamadas en la primera carga y de 4 a 6 al cambiar de fecha.

La razón: `sticpa_pl_session_attendances()` tiene un respaldo que **no corre en
modo recolecta** (`!sticpa_pl_collecting()`). La tanda trae la consulta
principal, que en esta instancia vuelve vacía, y el respaldo sale igual después:
se paga la tanda Y el respaldo. Para ganar ahí hay que arreglar antes el
respaldo, no envolverlo en una tanda. Queda escrito en el propio archivo.

### Lo siguiente, si sigue lento

Hay plan cerrado y ordenado en
[`../../plans/034-rendimiento-calentar-paralelizar-medir.md`](../../plans/034-rendimiento-calentar-paralelizar-medir.md):
medir (Server-Timing), configurar el calentado nocturno (los 3 secretos, cero
código), **paralelizar con `curl_multi` las llamadas independientes de cada
pantalla** (el coste real que queda: 6-8 llamadas EN SERIE), write-through de
caché tras guardar, y TTL de `struct` arriba.

La pregunta de la **base de datos espejo** quedó analizada y decidida el
27/08/2026 en [`DECISION-BBDD-ESPEJO.md`](DECISION-BBDD-ESPEJO.md): Neon
descartado (dato minúsculo, RTT externo nuevo, RGPD de menores, sync contra la
misma API frágil); si tras el plan 034 sigue lento, espejo de LECTURA en la
MySQL de WordPress con el diseño que ya está escrito allí.

---

## 5. La caché: cómo funciona y qué falta configurar

Dos familias, y **la familia sale del nombre de la caché**:

| Familia | Qué guarda | TTL | Se invalida |
|---|---|---|---|
| `state` | listas, asistencias, ausencias seguidas | 5 min | al guardar una lista |
| `struct` | grupos, personas, eventos, sesiones, inscripciones | 24 h | solo con el botón de refrescar |

La invalidación es por **contador de generación dentro de la clave**, no
borrando transients por nombre: hay claves que llevan un id dentro (las personas
de un grupo) y no se puede saber cuáles existen. El contador vive en
`wp_options`, no en un transient, para que perderlo no resucite datos viejos.

> **Cuidado al añadir una caché nueva:** si se llama de una forma que no está en
> la lista de `state`, cae en `struct` y el flush de después de guardar no la
> tira. Pasó con `listas` y `attrange`.

### Refrescar al momento

El botón circular de la cabecera (`?refrescar=1`) tira las dos familias de tu
delegación. Está en portada, árbol y resumen; y si un grupo sale sin
participantes, la pantalla de marcar ofrece **«Ya lo he arreglado, vuelve a
mirar»**.

### ✅ Calentado nocturno: configurado (27/08/2026)

El Guardián deja la caché hecha de madrugada para que el primero que entra el
sábado no la pague. Los tres secretos ya están puestos
(`STICPA_PL_WARM_SECRET` en `wp-config.php`, y `AREA_PRIVADA_CALENTAR_SECRET` +
`AREA_PRIVADA_URL` en el repo). Detalle en
[`GUARDIAN-NOCTURNO.md`](GUARDIAN-NOCTURNO.md) §5.

**Cómo saber que funciona:** el informe del Guardián de la mañana siguiente ya
no dice que se salta la tarea. Si lo dijera, es que falta o no coincide alguno
de los tres.

### Un vacío ya no se cachea como si fuera un dato

Añadido el 27/08/2026. Una colección vacía puede significar «no hay nada» o «el
CRM no ha contestado», y se guardaban igual: **doce horas**. Un solo hipo del
CRM un sábado por la tarde dejaba el grupo «sin participantes con relación
vigente» hasta la madrugada —con el monitor pulsando refrescar sin entender
nada— y el `regMap` vacío, que es lo que impide escribir cualquier asistencia.

Ahora un resultado vacío caduca en **2 minutos** (`sticpa_pl_ttl_empty`) y uno
lleno conserva su TTL completo. Pasa por `sticpa_pl_cache_put()`: **toda caché
de colección nueva tiene que usarla**.

---

## 6. Cómo probar

```bash
composer install
vendor/bin/phpunit                                        # 285 tests
node --test .github/scripts/guardian/guardian.test.mjs    # 36 tests
```

Contra la instancia de pruebas: `https://comunica.movimientoconsolacion.com/aptest/`

Con el CRM por MCP, dos reglas que no son opcionales:

- **Siempre `fields` acotado.** Sin él la API devuelve todos los campos del
  módulo y se come el contexto.
- **Para explorar, un subagente** (Sonnet, esfuerzo bajo) que devuelva solo el
  dato mínimo, en vez de meter cientos de líneas de JSON en el hilo principal.

No hay herramienta de borrado: se borra con `update_entry` y `deleted: true`.
Y la API **no valida los desplegables**: acepta cualquier cadena, así que una
clave interna mal escrita se guarda sin protestar. Las claves buenas están en
[`CAMPOS.md`](CAMPOS.md).

---

## 7. Los datos reales del piloto (verificado 27/08/2026)

Los IDs, para no volver a buscarlos:

| Qué | ID |
|---|---|
| Delegación **MCM Castellón** (`Users`) | `00000cd2-159a-eef9-3639-68cd21b90b6a` |
| Grupo **C1** (`ajmcm_GRUPOS`) | `00000fda-af65-a98f-e69c-6a8789aca09d` |
| Evento **COM \| Sesiones semanales 2025-2026** | `00000e2a-f3e4-165f-9e5b-6a87868a5297` |
| **Solete Vilarroya** (`Contacts`) | `00000014-0ae5-3f14-28d1-6a4f389295c0` |
| Inscripción de Solete en el COM | `00000d2b-688b-264e-cbdd-6a8844c92e93` |
| **David Soler** (`Contacts`) | `00000c2f-102d-2c5b-04b9-68cec329dfe6` |
| Inscripción de David en el COM | `00000816-f8c7-f889-bc7b-6a8b8bf41458` |

Cómo está montado:

- El evento COM tiene **24 sesiones**, del 18/10/2025 al 02/05/2026, sábados
  16:30-18:00.
- C1 tiene **una** participante vigente (Solete) y **un** monitor (David).
- Hay **17 inscripciones** en el evento COM, pero solo dos son de C1: las otras
  15 son de otros grupos de la misma etapa. Todas en `uninvited` menos la de
  Solete, que está `confirmed`. **`uninvited` no molesta**: el código solo
  excluye `cancelled`.
- Existe una asistencia de Solete para cada una de las 24 sesiones.

### Pizarra en blanco (27/08/2026)

El CRM se dejó **limpio a propósito** para poder comprobar si el guardado
funciona de verdad, sin restos de pruebas anteriores que confundan:

| | Estado verificado |
|---|---|
| `LIS_listas` de la delegación | **0** |
| Asistencias de Solete | **24**, una por sesión |
| …con `status` | **0** — ninguna marcada |
| …enlazadas a su sesión y a su inscripción | **24 de 24** |
| Duplicados | ninguno (24 ids de sesión distintos) |

Lo que se borró (borrado lógico, `deleted: true`):

- Las **dos `LIS_listas`** creadas el 24/08/2026 por las pruebas: la del 25/04
  en `omitida` 0/0 y la del 02/05 en `pasada` 0/1.
- La asistencia duplicada **`Unknown - Unknown`**
  (`00000ea3-0611-9695-a832-6a8c0c33eced`), que tenía `status = yes`
  contradiciendo a la buena del mismo día. Era el rastro del bug de duplicados.

Y se vaciaron los 6 `status` que quedaban (21/02, 28/02, 11/04, 18/04, 02/05).

**Lo que NO se tocó, y no hay que tocar:** las 24 asistencias en sí. Las crea el
CRM al crear la inscripción y son el esqueleto del que cuelga todo — la
asistencia pende de la inscripción, no de la persona. Borrarlas rompería el
modelo, no lo limpiaría.

> ⚠️ **Corrección del propietario (27/08 por la tarde), sin resolver.** El
> usuario afirma que esas asistencias de Solete **las creó Claude a mano por
> MCP** (no el CRM al crear la inscripción) y que ha pedido **borrarlas** para
> empezar de cero. Contradice el párrafo de arriba y no hay que elegir a
> ciegas: **hay que verificarlo empíricamente** (crear una inscripción de
> prueba nueva y mirar si el CRM genera solas las asistencias). Importa mucho:
> si el CRM NO las crea, el camino normal del guardado es CREAR asistencias,
> no actualizarlas — y el camino de crear es justo el que está fallando.
> Además, si se borran las 24, la siguiente prueba de guardado ejercitará
> exclusivamente ese camino de creación. Está recogido en el plan 033.

> **Antes de probar, pulsa refrescar.** La caché de `struct` dura 24 h, así que
> la pantalla puede seguir enseñando el estado de antes de la limpieza. El botón
> circular de la cabecera lo arregla al momento.

**Los monitores están listos sin tocar nada.** David tiene inscripción y sale en
la pantalla de monitores; `uninvited` no le excluye porque el código solo excluye
`cancelled`. No tiene ninguna asistencia, y eso es lo interesante: al guardar por
primera vez desde `single_stic_pasar_lista_monitores` se recorre el camino de
CREAR la asistencia, y el de escribir `yes` explícito para los no marcados, que
en monitores es un dato afirmado y no un hueco.

### Qué se tiene que ver después de pasar una lista

Con la pizarra en blanco, un guardado correcto de una sesión deja **exactamente**
esto, y es el criterio para cerrar el bug:

1. **Una** `LIS_listas` nueva, con `estado = pasada`, su `lis_listas_stic_sessions`
   y su `lis_listas_ajmcm_grupos` apuntando a la sesión y a C1, y
   `n_asistieron` / `n_faltaron` cuadrando con lo marcado.
2. La asistencia de Solete de **esa** sesión con su `status` puesto —
   **actualizada, no una nueva**: siguen siendo 24 en total.
3. Nada en las otras 23 sesiones.

Si aparecen 25 asistencias, o dos listas, o una lista con 0/0, el bug sigue ahí y
ya se sabe por dónde: son los tres fallos silenciosos de §1.

### Detalle que conviene documentar en CAMPOS.md

En `LIS_listas`, el campo `lis_listas_contacts_id` apunta al **monitor que pasó
la lista**, no al participante (verificado: en las dos listas existentes apunta
a David). Y `ajmcm_tipo_c` vale `participantes` cuando la lista es de
participantes.

---

## 8. Pendiente en el CRM, no en el código

**Los tres primeros son de hoy y son los que más se notan.** Están explicados
con detalle en §1.

1. 🔴 **Reasignar a la delegación el entorno personal de Solete** (hoy está a
   nombre de «Administrador MCM»), y revisar si hay más igual. Mientras no se
   haga, la ficha de un participante no puede enseñar a su familia por más que
   el código esté bien.
2. 🔴 **Borrar el centenar de asistencias `Unknown - Unknown`** (las que no
   tienen inscripción enlazada). El código que las creaba ya está arreglado.
3. 🟡 **Revisar la `LIS_listas` de participantes del 02/05 en `omitida`**, que
   convive con la de monitores de la misma sesión.
4. El workflow que avisa por correo a coordinación cuando se pone un aviso nuevo.
5. `phone_mobile` en `CAMPOS.md` está mal, y hay que propagar el arreglo al repo
   `comunicaFormularios`.
6. Cerrar la relación de monitor del grupo `Najar`.
7. ~~Borrar la asistencia basura «Unknown - Unknown»~~ — se hizo el 27/08/2026,
   pero **volvieron a aparecer** esa misma noche: era el código, no un resto de
   pruebas. Ver §1 y el historial de §1-bis.
