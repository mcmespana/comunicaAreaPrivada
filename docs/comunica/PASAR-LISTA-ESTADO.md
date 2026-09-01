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

### ✅ La salud, arriba en la ficha (01/09/2026)

Estaba tras contacto, asistencia y avisos: cuatro secciones de scroll hasta
«Frutos secos (anafilaxia)». Ahora va justo debajo de los botones de contacto.

Sube **entera y sin partirse**: un resumen arriba y el detalle abajo es la forma
segura de que alguien lea solo la mitad. Lo que cambia es el peso dentro del
bloque — alergias e intolerancias con franja roja y una etiqueta «Ojo con la
comida»; tratamientos, enfermedades y otras patologías como estaban—, porque una
alergia y unas gafas no son la misma urgencia y pintarlas igual hace que ninguna
destaque.

Si no hay ningún dato de salud, **no se pinta nada**: una tarjeta vacía en todas
las fichas enseña a no mirarla, y el día que tenga contenido tampoco se mirará.
Eso obligó a arreglar el doble de test, que devolvía la MISMA ficha para
cualquier id — o sea que las fichas sin alergias no existían en los tests.

Cierra la fila 3 del plan 037 en su parte de urgencia.

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

### ✅ Los márgenes laterales: resueltos (01/09/2026)

Pedido el 28/08 («se pierde mucho espacio por los lados, hace falta un poco pero
no tanto») y arreglado el 01/09.

**Por qué no se arreglaba.** El relleno lateral de `.stic-tab-content` ya valía
CERO (§20.a lo pisa con `!important`), así que tocarlo no movía un píxel. El
hueco lo mete el tema de WordPress por fuera de `.stic-container`. El plan
anterior era medirlo en el móvil y restarlo — o sea, adivinar un número que
cambia con el tema, con el ancho y con la plantilla.

**Lo que se hizo en vez de eso.** `margin-inline: calc(50% - 50vw)` sobre
`.stic-tab-content`: medio contenedor menos medio viewport es exactamente lo que
sobra a cada lado, **sea el que sea**. No hay número que medir ni que mantener.
Después se devuelve el aire deseado con `padding-inline: var(--pl-gutter)`, que
está en un solo sitio (hoy `0.85rem`).

Tres cosas que lo sostienen, y las tres tienen test (`TokensCssTest`):

1. **Se sangra el HIJO, no `.stic-container`.** `50vw` incluye la barra de
   desplazamiento vertical en los navegadores de escritorio que la pintan
   encima, y eso son ~15 px de desbordamiento; el `overflow-x: clip` del padre
   —que ya estaba— los recorta. Sangrar el padre habría dejado ese recorte sin
   nadie que lo aplicara.
2. **`clip` y no `hidden`.** `hidden` crearía un contenedor de scroll y se
   llevaría por delante el `position: sticky` de la barra de guardar.
3. **`width: auto !important`.** `menu.php` pinta ese div con
   `style="width:100%"` EN LÍNEA; con un ancho fijado, el margen negativo
   desplaza la caja a la izquierda en vez de ensancharla — el sangrado
   descoloca la página y no gana un píxel. Una hoja con `!important` gana a un
   estilo en línea que no lo lleva.

A partir de tablet (48rem) se desactiva: ahí no hay nada que recuperar, y
estirar hasta el viewport dejaría una lista de nombres de un metro de ancha.

Solo afecta a Pasar Lista y Mis Grupos: `pasar-lista.css` únicamente se carga
ahí.

### 🟡 Otros abiertos, menores

| | Qué |
|---|---|
| ✅ | ~~Alguien puede ser monitor de dos grupos~~ — resuelto el 28/08/2026, ver §2. |
| 🟡 | El grupo `Najar` no es de participantes MIC-COM y aparece en el árbol. Hay que decidir el filtro de qué grupos salen. |
| 🟡 | Los «sectores» (COM I = los dos primeros cursos de la ESO, etc.) se agrupan **a mano**. No hay campo en el CRM y de momento no lo va a haber. |
| 🟡 | El filtro plano por campo de enlace (`..._ida`) en `get_entry_list` devuelve **error 400 de base de datos** en `stic_Sessions`, `LIS_listas`, `stic_Registrations` y `stic_Contacts_Relationships`. Se puede LEER el campo, pero no FILTRAR por él. Para consultar por relación hay que ir por `get_relationships`. Ojo: no es lo mismo que la trampa de §3.1 — ahí el problema es el enlace anidado que no viene; aquí es el filtro que el CRM rechaza. |
| 🟡 | `ajmcm_curso_escolar_c` **existe** en `stic_Contacts_Relationships` y está **vacío en todas** las relaciones reales. El curso del histórico se deduce de `start_date` y `end_date`. Si algún día se rellena, hay que mirar antes las claves internas de su desplegable. |
| 🟡 | `ajmcm_pasar_lista_c` (la casilla del grupo) tiene **`default: 1`** en el CRM: un grupo nuevo entrará solo en Pasar Lista. Probablemente es lo que se quiere; conviene saberlo. |
| 🟡 | `end_date` **no existe** en `stic_Attendances` (la API devuelve 400 si se manda). Solo hay `start_date`. |

### ✅ El navegador de fichas ya está: es «Mis grupos» (28/08/2026)

Se pidió y se hizo el mismo día. Sección propia en el menú, al lado de Pasar
Lista, con tres vistas (grupos / cursos / A-Z), buscador, recuentos, fotos y
anterior-siguiente entre fichas. Para coordinación, los monitores por etapa.

**El detalle entero está en [`MIS-GRUPOS.md`](MIS-GRUPOS.md)**, incluido cómo se
resolvieron las cinco preguntas que tenía abiertas. Lo que queda por decidir es
menor y está en su §6.

Lleva también el color graduado por curso, el orden de monitores por curso →
grupo → apellido con sus flechas, y la vista de **quien no está en ningún
grupo**, donde coordinación lo vincula en dos gestos con el mismo escritor que
ya usaba el resumen.

Lo que importa aquí: **no tiene ni un cargador propio**. Tres llamadas en una
sola tanda partiendo de frío, cero con la caché caliente. Si alguien añade una
consulta ahí, casi seguro está repitiendo una que ya existe — y salta el tope de
`CosteLlamadasTest`.

---

## 1-bis. El historial está en otro archivo

Lo cerrado —con su causa y su fecha— se ha sacado de aquí:
**[`PASAR-LISTA-HISTORIAL.md`](PASAR-LISTA-HISTORIAL.md)**.

Se conserva porque explica por qué el código es como es, pero ocupaba doscientas
líneas ANTES de lo que sí hace falta leer para retomar el trabajo. Este
documento es ahora lo que promete su nombre: el estado.

⚠️ Las **trampas** no se han movido: siguen en §3, aquí abajo, y son de lectura
obligada antes de escribir una consulta nueva.

---

## 2. Qué SÍ funciona

### ✅ Un monitor que lleva varios grupos (28/08/2026)

Sale **una sola vez** en la lista de monitores, con los códigos de todos sus
grupos en la línea de debajo. Una fila, una marca: dos filas de la misma persona
en una lista de marcar acaban contradiciéndose, y eso es un dato peor que
ninguno.

La variante que costaba encontrar es la de **dos etapas**. Quien lleva un grupo
de MIC y otro de COM se coloca en la sección de su curso más bajo, así que el
coordinador que mira la sección de COM no lo veía y daba por hecho que no
estaba. Ahora la sección que no lo tiene **lo nombra**: «También lleva grupo de
esta etapa: David Soler (MIC)».

Y el caso peor, que es el que descubrió el test: si TODOS los de una etapa están
prestados, la sección **desaparecía entera** y la pantalla decía, sin decirlo,
«en COM no hay monitores». Ahora se pinta igual, con su cabecera, su «0
monitores» —que es la verdad: aquí no hay ninguno que marcar— y la línea que
explica dónde están.

Las otras variantes ya estaban resueltas y conviene no re-abrirlas:

| Variante | Dónde | Qué hace |
|---|---|---|
| Dos grupos de la misma etapa | Lista de monitores | Una fila, «C1 · C2» debajo del nombre |
| Dos grupos, dos etapas | Lista de monitores | Una fila en la etapa de su curso más bajo + la otra sección lo nombra |
| Varios grupos en la portada | Portada | El atajo lleva a uno, y los demás tienen su fila propia (`$otherMine`); si alguno debe lista, sale en «Te faltan N listas» |
| Lleva un grupo Y tiene el suyo | Ficha del monitor | Dos etiquetas distintas: «lo lleva» y «su grupo» |
| Dos relaciones con el MISMO grupo | Lista de monitores | El código no se repite |

### ✅ Las ausencias de monitores, con porcentaje (28/08/2026)

Era el melón 2 del roadmap. Lo que faltaba no era dónde guardar la asistencia
—eso ya se guarda— sino **el número donde coordinación lo necesita**: en la
lista, sin abrir treinta fichas.

Va **pequeño y gris**, al final de la línea que ya dice de qué grupo es cada
uno, con cifras de ancho fijo para poder comparar dos filas seguidas. Treinta
porcentajes gritando no se leen: todos pesan lo mismo y ninguno destaca. Lo que
salta a la vista sigue siendo **la nota roja**, que solo sale cuando hay algo
que mirar — y por debajo del umbral el porcentaje se pone del mismo rojo, porque
es el mismo mensaje y dos idiomas para lo mismo se leen como dos problemas.

**Con menos de cuatro sesiones marcadas no se pinta nada.** Un porcentaje sobre
dos datos es una anécdota, y una anécdota con pinta de dato es peor que ningún
dato. El umbral sale de `sticpa_pl_seguimiento_umbrales`.

> El encuadre que pedía el roadmap («es un dato sensible entre compañeros»)
> está en la forma: el número acompaña, no acusa. Quien va bien no lleva nada
> rojo, y el porcentaje se lee igual que la edad o el curso.

### ✅ «Cambios sin guardar», que se ve (28/08/2026)

Se marcaba a diez chavales, la pantalla se quedaba igual, y lo único que avisaba
era el diálogo del navegador **al salir** — o sea, cuando ya te ibas. Quien
cerraba la app sin más se iba creyendo que estaba guardado.

Ahora, en cuanto hay una marca sin enviar, la barra de guardar dice **«Cambios
sin guardar · los datos están solo en tu móvil»**, en ámbar, con contorno propio
—es el único aviso de esa barra que lo lleva— y un punto que late despacio. Se
distingue a un metro del verde de «guardado en el CRM»: uno es una promesa y el
otro un hecho.

El latido no es adorno: un aviso quieto en una barra que siempre está ahí se
convierte en parte del mueble a los dos sábados. Late a 1,8 s y con poco
recorrido, que es la diferencia entre «esto sigue pendiente» y una alarma. Solo
`opacity` y `transform`, y con `prefers-reduced-motion` se queda el punto quieto
—no se quita: es lo que distingue este aviso del borrador, que es del mismo
ámbar—.

Sin cobertura manda el aviso de cobertura: ahí «solo en tu móvil» es cierto pero
no es la noticia, y dos avisos discutiendo en la misma línea no los lee nadie.



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
| **Mis grupos** (índice, y sus tres vistas) | **3** | **2** |
| Mis grupos → un grupo | 3 (5 si el mapa falla) | 2 |
| Mis grupos → monitores | 4 | 3 |
| Mis grupos → sin grupo | 3 | 2 |
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
