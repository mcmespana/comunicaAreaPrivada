# Pasar Lista — parte de estado

**Fecha de este parte: 27 de agosto de 2026.** Es el documento que hay que leer
para retomar el trabajo sin reconstruir el contexto. El mapa de todo lo demás
está en [`PASAR-LISTA-README.md`](PASAR-LISTA-README.md); el diseño funcional,
en [`PASAR-LISTA.md`](PASAR-LISTA.md).

Piloto: **MCM Castellón, curso 2025-2026**. Nada interdelegacional.

> **Versión web de este parte**, para leerla o compartirla sin abrir el repo:
> <https://claude.ai/code/artifact/0701d2cf-bf96-4b5d-b3c9-b5db712476a7>
> Es una copia: si cambias este documento, hay que volver a publicarla.

---

## 1. El bug: ENCONTRADO Y ARREGLADO (27/08/2026)

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

### 🟡 Las vigencias caducan el 31/08/2026 (anotado, no bloquea)

Verificado en el CRM el 27/08/2026: **tanto la relación de participante de
Solete como la de monitor de David Soler tienen `end_date` = 2026-08-31**. En
cuanto pase esa fecha, C1 se queda sin miembros vigentes y la pantalla volverá a
decir «0 participantes».

Eso se va a leer como «ha vuelto el bug grave» y **no lo será**: será que el
curso 2025-2026 se ha acabado en los datos.

Decisión del 27/08/2026: **no bloquea**, se sigue probando y se renuevan las
relaciones cuando toque. Pero si a partir del 1 de septiembre C1 sale vacío,
**mira esto antes de buscar el fallo en el código**.

### Otros abiertos, menores

| | Qué |
|---|---|
| 🟡 | Alguien puede ser monitor de dos grupos. Sale, pero hay que decidir cómo se enseña. |
| 🟡 | El grupo `Najar` no es de participantes MIC-COM y aparece en el árbol. Hay que decidir el filtro de qué grupos salen. |
| 🟡 | Los «sectores» (COM I = los dos primeros cursos de la ESO, etc.) se agrupan **a mano**. No hay campo en el CRM y de momento no lo va a haber. |
| 🟡 | El filtro plano por campo de enlace (`..._ida`) en `get_entry_list` devuelve **error 400 de base de datos** en `stic_Sessions`, `LIS_listas`, `stic_Registrations` y `stic_Contacts_Relationships`. Se puede LEER el campo, pero no FILTRAR por él. Para consultar por relación hay que ir por `get_relationships`. Ojo: no es lo mismo que la trampa de §3.1 — ahí el problema es el enlace anidado que no viene; aquí es el filtro que el CRM rechaza. |
| 🟡 | Las asistencias de 5 sesiones existen **sin `status`**: quedaron a medio marcar. Es un estado válido (una sesión sin pasar), pero conviene saber que existe. |

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

### 3.2 Un doble de test puede mentir sobre la forma de la API

Hubo **175 tests en verde con la producción rota**. El doble colgaba
`link_list` directamente del registro; la API real lo manda en
`relationship_list`, en paralelo y por posición. El plugin tenía que ensamblarlo
y no lo hacía, y el doble ocultaba justo eso.

**La regla:** un doble construye la forma **real** de la API y la pasa por el
ensamblado del propio plugin (`SugarRestApiCall::attachLinkList()`,
`flattenRelationshipFields()`). Si el doble tiene su propio ensamblado, no
prueba nada.

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

### 3.6 El tema de WordPress pinta tus `<button>`

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

Llamadas al CRM por pantalla:

| Pantalla | Antes | Ahora |
|---|---|---|
| Portada | 13 | 6 |
| Árbol de grupos | 10 | 6 |
| Marcar | 14 | 8 |
| Resumen | 15 | **7** |

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

- El workflow que avisa por correo a coordinación cuando se pone un aviso nuevo.
- `phone_mobile` en `CAMPOS.md` está mal, y hay que propagar el arreglo al repo
  `comunicaFormularios`.
- Cerrar la relación de monitor del grupo `Najar`.
- ~~Borrar la asistencia basura «Unknown - Unknown»~~ — hecho el 27/08/2026,
  junto con las dos `LIS_listas` de prueba. Ver §7, «Pizarra en blanco».
