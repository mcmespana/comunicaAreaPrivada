# Pasar Lista — historial de lo cerrado

Lo que se arregló, con su causa y su fecha. **No es el estado actual**: para eso
está [`PASAR-LISTA-ESTADO.md`](PASAR-LISTA-ESTADO.md), que empieza por lo que
sigue abierto.

Esto se conserva por una razón concreta: **explica por qué el código es como
es.** Media docena de decisiones que parecen raras —el respaldo que solo salta
cuando el mapa entero falla, los campos planos pedidos junto al enlace anidado,
el tipo explícito en cada `LIS_listas`— son cicatrices de los bugs de aquí. Si
alguien las «simplifica» sin leer esto, vuelven.

Las trampas que siguen mordiendo NO están aquí: están en §3 del parte de estado,
que es de lectura obligada.

---

## Lo cerrado, por orden

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

- **Márgenes: NO arreglados, y conviene saber por qué.** El primer intento fue
  bajar el relleno lateral de `.stic-tab-content`… que ya vale CERO: §20.a de
  `custom-style.css` lo pisa entero con `!important`, en una regla global. El
  cambio no movía un píxel y se ha retirado, dejando el aviso escrito donde
  engaña. **El hueco lateral lo mete el tema de WordPress POR FUERA de
  `.stic-container`**, y comérselo pide sangrar con margen negativo — que es lo
  que ya hacen algunos bloques y lo que provocó los 32 px de desbordamiento que
  tapa `overflow-x: clip` (plan 035). Antes de volver a intentarlo hay que
  **medir el relleno real del tema en la página de verdad**; adivinarlo es lo
  que salió mal las dos veces.
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

