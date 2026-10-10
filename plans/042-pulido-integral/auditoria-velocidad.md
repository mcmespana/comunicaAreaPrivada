# 042 · Auditoría — velocidad

> Auditor de la pasada del 09/10/2026. Read-only: nada del CRM (ni MCP ni llamadas), todo
> sale del código, de los tests y de arneses offline, más cuatro ficheros públicos del sitio
> (el HTML de `/ap/?app=1` sin sesión, `crm_comunica_estilos.css`/`.js` y las hojas de
> fuentes de Elementor). Las cifras de «viajes» son esperas
> de ida y vuelta al CRM contadas con los dobles de los tests (`FakeSCP` de
> `tests/PasarListaRenderTest.php` y un doble propio para las pantallas de familias) y con
> el troceo real de `callMany()` (4 en 4). Un viaje cuesta ~350 ms con keep-alive (plan 027).
> Los arneses vivían en el scratchpad de la sesión y no se conservan: cada hallazgo dice
> cómo se rehace la medida.

## Resumen

Antes de la primera llamada útil no se pierde nada (rol, familia y delegación salen de la sesión; definición de campos de 6 h; sesión técnica del CRM compartida) y, con todo caliente, casi todas las pantallas cuestan 0-1 viajes. Lo lento está en tres sitios.
**Lo que el área no pide:** la hoja de los formularios públicos va en cada pantalla (48 KB que bloquean el pintado, +140 ms de hilo principal por toque) y su `overflow-x: hidden` en `html, body` apaga todos los `sticky`: el «Guardar lista» no se queda abajo (VEL-1, capturado). Y la negrita del área se descarga de un dominio provisional de Hostinger (VEL-7).
**Consultas independientes en fila:** la portada en frío crece con el historial de inscripciones (de 5 a 11 esperas, VEL-3); marcar, guardar la lista, la ficha del evento, Eventos y Pagos tienen 1-2 esperas que se juntan con el mecanismo de tandas que ya existe (VEL-2, 4, 5; probados en una copia, la suite en verde salvo los tests que fijan la forma de hoy), e inscribirse con pago otras 4 (VEL-9, por lectura del código). Las tandas de más de 4 se esperan dos veces y el test las cuenta como una (VEL-6).
**La precarga del plan 030:** en el móvil apenas gana nada y, si el dedo empieza un scroll sobre una tarjeta, el toque bueno espera ~0,9 s el candado de sesión (VEL-8, medido en banco).
Sin números de producción no se puede contrastar nada de esto (VEL-10).

## Mapa de viajes por pantalla

Esperas de ida y vuelta al CRM, ~350 ms cada una. Familias: doble propio con 3 inscripciones
activas. Pasar Lista: `FakeSCP` de los tests en el modo «sin enlaces anidados» (el de esta
instancia, `PASAR-LISTA-ESTADO.md` §3.1) y, entre paréntesis, con enlaces; estructura caliente
(la deja el calentado nocturno) y estado caducado (5 min), que es el sábado normal.

| Pantalla | Cuándo | Hoy | Con las propuestas | Hallazgo |
|---|---|---|---|---|
| Portada | caché de 5 min fría (la primera del día) | 5 (11 con 12 inscripciones) | 3-4 | VEL-3, VEL-6 |
| Portada | caliente | 0 | 0 | — |
| Eventos | calendario frío | 3 | 2 | VEL-5 |
| Ficha del evento (enlace de WhatsApp) | calendario frío | 4 | 2 | VEL-5 |
| Eventos / ficha | calientes | 1 | 1 | — |
| Inscripciones | caliente | 1 | 1 | — |
| Pagos | siempre (sin caché, a propósito) | 3 | 2 | VEL-4 |
| Documentos · Mis datos | siempre | 1 | 1 | — |
| Inscribirse con pago (envío) | siempre | ~11 + la pantalla de destino | ~7 | VEL-9 |
| Pasar Lista: portada | sábado normal | 3 (1) | 3 (1) | — |
| Árbol · Resumen · Coordinación | sábado normal | 1 | 1 | — |
| Marcar | sábado normal | 5 (3) | 4 (2) | VEL-2 |
| Guardar la lista | estructura caliente | 8 (6) | 6 (4) | VEL-2 |
| Ficha del participante | sábado normal | 5 (3) | 5 (3) | — |
| Lista de monitores | sábado normal | 2 | 2 | — |
| Mis grupos | sábado normal | 0 | 0 | — |

Mirado y descartado (no se propone):
- **Mandar la carcasa antes del CRM (flush temprano).** El contenido del área sale de un
  shortcode dentro del maquetado de Elementor, que se arma como cadena; lo único que se podría
  adelantar es el `<head>`. Y adelantarlo empeora lo que se ve: el navegador confirma la
  navegación, quita la página de origen con su overlay de «Cargando…» y enseña una página en
  blanco mientras espera al CRM. Lo mismo que el plan 037 (fila 4) dice de los skeletons.
- **Subir TTL o cachear más lecturas.** PERF-08 sigue aparcado (se prefiere ver al momento los
  cambios del CRM). Nada de lo de arriba alarga una caché.
- **Service worker de Pasar Lista** (alcance `/`, sin *navigation preload*): medido con el SW
  parado y CPU ×4, +8 ms de TTFB en una navegación que no es de Pasar Lista. No compensa.
- **Transients:** 11-19 lecturas por pantalla de Pasar Lista, del orden de 10-20 ms sin caché
  de objetos. Irrelevante al lado de un viaje.
- **View Transitions entre páginas:** suavizan el cambio pero no acortan la espera, y con
  recargas completas no hay nada que mostrar antes de que llegue el HTML.
- **Imágenes de la ficha del evento (nota menor, sin hallazgo propio):** el cartel lleva
  `loading='lazy'` aunque está arriba (`inc/stic-record-view.php:460-461`) y el área no pasa
  `miniatura` a `mcm_cuerpo_html()` (`inc/eventos-cuerpo.php:877-883`), así que cartel y fotos
  van a tamaño original. Vale la pena quitarle el `lazy` al cartel; reducir tamaños necesita
  saber cómo suben los carteles las delegaciones.

## VEL-1 — La capa de los formularios públicos se carga en todas las pantallas del área: 48 KB de CSS que bloquean el pintado, 81 KB de JS y, de propina, ningún `position: sticky` funciona (el «Guardar lista» no se queda abajo)

**Tipo:** rendimiento + bug · **Para:** todos (monitores los primeros) · **Tamaño:** S · **Riesgo:** medio · **Impacto:** 5/5

**Problema.** En el HTML servido de `/ap/?app=1` (la página 441 de WordPress, que es la que
pinta TODAS las pantallas del área por `?internalpage=`) van `crm_comunica_estilos.css`
(handle `crm-comunica-estilos`, en `<head>`, justo después de `custom-style.css`) y
`crm_comunica_script.js` (handle `crm-comunica-script`, al pie). Son la capa de los
**formularios públicos** (los ficheros que `comunicaFormularios` despliega en la raíz del
hosting): el área no usa ni una clase suya. No los encola este repo (no hay ni una
referencia en el código), sino algo de fuera que los pone en todo el sitio.

Cuestan en cada toque, porque el área son recargas completas:

- 191.602 B de CSS (47.921 B gzip) que **bloquean el primer pintado**, y 309.360 B de JS
  (80.562 B gzip) que se parsean y compilan aunque no hagan nada aquí.
- Medido con Playwright, CPU ×4 (móvil medio), mediana de 5, la pantalla de Eventos con su
  menú real, con y sin estos dos ficheros: tareas del hilo principal **254 → 397 ms
  (+143 ms)**, primer pintado **252 → 352 ms (+100 ms)**, layout 63 → 105 ms. Sin red: en
  un móvil con mala cobertura hay que sumar la descarga de 48 KB bloqueantes la primera vez.

Y lo peor no es el peso. Esa hoja trae, para los formularios, `html, body { overflow-x:
hidden; … }` (línea 311 del fichero desplegado). `overflow-x: hidden` en `html` **y** en
`body` convierte el `body` en contenedor de scroll, y con eso **todo `position: sticky` del
área deja de pegarse**: la barra «Guardar lista» de Pasar Lista (`css/pasar-lista.css:615`),
el buscador del árbol de grupos (`:1191`), la botonera de los formularios
(`css/custom-style.css:2958`) y la columna de la portada en escritorio (`:4643`). El propio
CSS de Pasar Lista lo sabía y lo esquivó a mano (`css/pasar-lista.css:2826-2833`: «`clip` y
NO `hidden`: `hidden` … rompería el `position: sticky` de la barra de guardar»), pero la
regla le llega desde fuera.

**Evidencia.**
- HTML servido (`/ap/?app=1`, sin sesión, 09/10/2026): `<link id='crm-comunica-estilos-css'
  href='…/crm_comunica_estilos.css?ver=1791454463'>` en `<head>` tras `custom-style-css`, y
  `<script id="crm-comunica-script-js" src="…/crm_comunica_script.js">` al pie.
  `grep -rn crm_comunica` en este repo: solo `design.md:142` y el plan 039.
- Prueba mínima en Chromium (375×700, scroll a 1000 px): sin la regla, una barra `sticky;
  bottom:0` queda a 640 px (dentro de la pantalla); con `html,body{overflow-x:hidden}`, a
  2.040 px, y una `sticky; top:0` se va a −1000 px.
- Pantalla real de marcar (render offline de `pages/single_stic_pasar_lista_marcar.php` con
  el doble de los tests, CSS real del área, 375×560): sin la hoja de formularios, «Guardar
  lista» está pegado abajo (top 435 px, se ve el botón con su degradado sobre la lista); con
  ella, el botón cae a 561 px, fuera de la pantalla, y lo último visible es la leyenda y
  «Sin registro — no me avises más». Con un grupo real de 15-20 chavales el botón queda a
  varias pantallas.

**Propuesta.**
1. En `sinergiacrm-private-area.php`, junto a `sugar_crm_portal_style_and_script()`
   (:1404), una función enganchada a `wp_enqueue_scripts` con prioridad 100 (después de
   quien los encole) que, si `sticpa_queried_page_has_area_shortcode()`, haga
   `wp_dequeue_style('crm-comunica-estilos')` y `wp_dequeue_script('crm-comunica-script')`.
   Antes de tocar nada, abrir el código fuente de una pantalla CON sesión (p. ej. Pasar
   Lista) y confirmar que también llevan los dos ficheros: debería, porque es la misma
   página 441.
2. Hacer antes FAM-a3 (`.stic-home-aside { min-width: 0 }`): hoy el `overflow-x: hidden` de
   esa hoja ESCONDE el desborde horizontal de la portada, y al quitarla saldría a la vista.
3. Fondo en claro: hoy el gris de la página (`#f1f5f9`) lo pone esa hoja. Capturar portada,
   Eventos y marcar a 375 y 1280 en claro y oscuro antes y después; si el fondo claro cambia
   al del tema, fijarlo en `custom-style.css` §44 con el token de fondo del área, igual que
   ya se hace en oscuro (`:root[data-stic-scheme="dark"] body`, :4298).
4. Avisar en `comunicaFormularios` de que su encolado debería ser solo en las páginas de
   formularios (arreglo de raíz; lo de aquí es el cinturón).
5. Comprobar en la captura de marcar con 20 filas que «Guardar lista» queda pegado abajo y
   que el buscador del árbol se queda arriba al hacer scroll.

**Ahorro estimado:** ~140 ms de hilo principal por toque en un móvil medio (100 ms de primer
pintado), 48 KB menos bloqueando la primera carga, y vuelven a funcionar las barras pegadas.

**Ficheros:** `sinergiacrm-private-area.php`, `css/custom-style.css` (solo si cambia el fondo)

## VEL-2 — Marcar y guardar en Pasar Lista: las lecturas del final van en fila y pueden ir en una tanda (−1 espera al abrir, −2 al guardar)

**Tipo:** rendimiento · **Para:** monitores · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 4/5

**Problema.** Después de las dos tandas del principio, la pantalla de marcar pide en fila
las asistencias de la sesión (`sticpa_pl_session_attendances`) y las rachas de ausencias
(`sticpa_pl_group_streaks`, que lee las asistencias de las últimas sesiones). Son
independientes: las dos solo necesitan `$sessions` y `$regMap`, que ya están. Y al GUARDAR
es peor: tras escribir se tira la caché de estado y la misma pantalla vuelve a leer, en
fila, asistencias, listas (el índice de `sticpa_pl_all_listas`, de donde lee `sticpa_pl_lista`) y rachas. Es
el momento de máxima atención del monitor: está mirando la rueda hasta que salga «Lista
guardada».

Hay un comentario que dice que esto se midió y se descartó
(`pages/single_stic_pasar_lista_marcar.php:231-240`: «meter estas dos lecturas en una tanda
SUBE el coste» porque el respaldo de las asistencias salía igual después). **Ese argumento
ya no vale**: el comentario es del 24/09 (`2412f7e`) y el 26/09 (`a9e6634`) el respaldo
pasó a salir SOLO si el CRM falla o devuelve asistencias sin inscripción
(`inc/stic-pasar-lista-crm.php:1617-1619`), no con cualquier vacío.

**Evidencia.** Línea de tiempo con el doble de los tests (sesión ya pasada, grupo g1, dos
marcas), en los dos modos que mide `CosteLlamadasTest`:

| Momento | Modo | Hoy | En una tanda |
|---|---|---|---|
| Abrir marcar un sábado (estructura caliente, estado caducado: lo normal) | enlaces OK | 3 esperas | **2** |
| Abrir marcar un sábado | sin enlaces anidados | 5 | **4** |
| Abrir marcar (todo frío) | enlaces OK | 4 | **3** |
| Abrir marcar (todo frío) | sin enlaces anidados (el real, `PASAR-LISTA-ESTADO.md` §4) | 6 | **5** |
| Guardar (estructura caliente) | enlaces OK | 6 | **4** |
| Guardar (estructura caliente) | sin enlaces anidados | 8 | **6** |

Hoy, al guardar (enlaces OK): asistencias → escribe asistencias → escribe lista →
asistencias → `LIS_listas` → `stic_Attendances` (rachas). Con la tanda: asistencias →
escribe → escribe → TANDA(asistencias, rachas, listas). Probado en una copia del repo: la
suite entera pasa salvo `test_marcar_agrupa_sus_consultas_en_dos_tandas`
(`tests/PasarListaRenderTest.php:3752`), que fija «dos tandas» y ahora son tres.

Una cosa que mirar de paso con `?pl_diag=1` en producción: en el modo «sin enlaces» del doble,
2 de esas 5 esperas del sábado son el respaldo de la gente del grupo
(`sticpa_pl_group_people_direct`, `inc/stic-pasar-lista-crm.php:950`), que NO tiene caché y
sale en cada visita y en cada guardado. Si el panel enseña
`ajmcm_GRUPOS:ajmcm_grupos_stic_contacts_relationships` en marcar, cachearlo con el TTL de
estructura quita dos esperas más; si no aparece, es que el mapa de relaciones trae los grupos
(lo esperable según `PASAR-LISTA-ESTADO.md` §3.1) y no hay nada que hacer.

Una trampa que hay que cerrar a la vez: el respaldo de las rachas
(`inc/stic-pasar-lista-crm.php:2524`, `empty($porSesion) ? sticpa_pl_session_attendances(…)`)
**no lleva `!sticpa_pl_collecting()`**, la regla del plan 034 para todo cargador que entre
en una tanda. Sin la guarda, en la recolecta `$porSesion` sale vacío y se cuelan en la
tanda dos consultas inútiles (medido: TANDA de 4 en vez de 2).

**Propuesta.**
1. `inc/stic-pasar-lista-crm.php:2524`: `(empty($porSesion) && !sticpa_pl_collecting()) ? … : array()`.
2. `pages/single_stic_pasar_lista_marcar.php:242`, justo antes de leer: una
   `sticpa_pl_prime($objSCP, fn)` que llame a `sticpa_pl_session_attendances($objSCP,
   $session['id'], $regMap)` y `sticpa_pl_group_streaks($objSCP, $sessions, $session['id'],
   $regMap)`, y además a `sticpa_pl_all_listas($objSCP)` **solo si `is_array($saved)`**
   (el mismo patrón que ya usa `pages/single_stic_pasar_lista_monitores.php:213-224`). Las
   líneas de después no cambian. Metiendo las listas siempre, el sábado normal bajaría a
   1 espera, pero en frío total la tanda repite `LIS_listas` (el índice de la primera tanda
   no se reaprovecha: hay que mirar por qué antes de hacerlo).
3. Reescribir el comentario de :231-240 con la medida nueva y la fecha (para que nadie lo
   deshaga creyendo que sigue «medido y descartado»).
4. Tests: `test_marcar_agrupa_sus_consultas_en_dos_tandas` pasa a tres tandas (4, 2 y 2) y
   se añade uno que fije que guardar no relee nada en fila después de escribir. Bajar los
   topes de esperas de `CosteLlamadasTest` y la tabla de `PASAR-LISTA-ESTADO.md` §4.
5. La verificación del plan 033 (releer el CRM tras guardar) se mantiene entera: solo cambia
   que las tres lecturas se esperan una vez.

**Ahorro estimado:** ~350 ms cada vez que se abre marcar con el estado caducado (o en
frío) y ~700 ms en cada guardado.

**Ficheros:** `pages/single_stic_pasar_lista_marcar.php`, `inc/stic-pasar-lista-crm.php`, `tests/PasarListaRenderTest.php`, `tests/CosteLlamadasTest.php`, `docs/comunica/PASAR-LISTA-ESTADO.md`

## VEL-3 — La portada y el calendario en frío crecen con el historial: dos consultas por cada inscripción de toda la vida

**Tipo:** rendimiento · **Para:** todos (más cuantos más años lleve la persona) · **Tamaño:** M · **Riesgo:** medio · **Impacto:** 4/5

**Problema.** `sticpa_gather_calendar_data()` alimenta la portada (agenda y «Próximas
actividades»), el calendario y, cuando está caliente, la lista de Eventos, la ficha de un
evento y el alta de inscripción. Su caché dura **5 minutos** (`inc/stic-calendar.php:265`)
y es por persona, así que quien abre la app una vez al día la encuentra siempre fría. En
frío pide TODAS las inscripciones de la persona, sin ventana (`:285-289`, solo `id` y
`status`), y por cada una no cancelada lanza dos consultas (su evento y sus asistencias,
tanda 1 en `:355`) y luego una por evento (sus sesiones, tanda 2 en `:370`). Las tandas se
trocean de 4 en 4 (VEL-6), así que el coste crece con los años que lleve alguien en el MCM:
las inscripciones de cursos pasados se descargan y se procesan enteras (sesiones y
asistencias incluidas) para una portada que solo enseña lo próximo; solo se verían yendo
mes a mes hacia atrás en el calendario. La lista de Eventos y los «abiertos» del calendario
ya usan la ventana de −14…+12 meses (`sticpa_events_window_filter()`).

Además, la consulta de los eventos de la ventana (`:485`) no depende de las inscripciones
(solo se usan después para apartar los ya inscritos) y va sola, en fila, al final.

**Evidencia.** Portada en frío con un doble que devuelve N inscripciones activas (troceo real
de 4 en 4; definición de campos de eventos ya cacheada, que es lo normal porque dura 6 h y
es de todo el sitio):

| Inscripciones activas | Hoy | Con ventana (las de los 2 últimos años: 3) | + eventos en la 1ª tanda |
|---|---|---|---|
| 3 | 5 esperas (~1,8 s) | 5 | **4** |
| 7 | 8 (~2,8 s) | 5 | **4** |
| 12 | 11 (~3,9 s) | 5 | **4** |

La lista de Eventos con el calendario frío hace lo mismo a menor escala
(`prefix_user_active_registration_map`, `inc/stic-action.php:405-437`): 3, 4 y 5 esperas
con 3, 7 y 12 inscripciones (definición de campos ya cacheada). Probado en una copia: con
la consulta de eventos dentro de la primera tanda la portada pasa de «inscripciones →
TANDA(6) → TANDA(3) → eventos» a «TANDA(inscripciones, eventos) → TANDA(6) → TANDA(3)».

**Propuesta.**
1. Pedir `registration_date` en la consulta de inscripciones (`inc/stic-calendar.php:289` y
   `inc/stic-action.php:405`; el campo ya lo usa el área, `inc/stic-registrations.php:49`)
   y descartar ANTES de las tandas las inscripciones con fecha anterior a «ventana hacia
   atrás + 10 meses» (24 meses con la ventana de hoy). Una inscripción sin fecha se queda
   (prudencia). Lo único que cambia a la vista: en el calendario, las sesiones de hace más
   de dos años dejan de salir al ir hacia atrás. No hace falta tocar el `WHERE` del CRM (el
   que esta instancia rechaza a veces con un 400): se filtra en PHP sobre la lista que ya
   llega en una sola consulta.
2. Meter la consulta de eventos de la ventana en la misma `sticpa_pl_prime` que la de
   inscripciones (calcular `$filter` y `$eventFields` antes). En la recolecta, si la
   definición de campos estuviera fría, la firma no coincidiría y la consulta saldría en la
   pasada normal, como hoy: no se rompe nada, solo no se gana esa vez.
3. Lo mismo en `prefix_user_active_registration_map()`: el filtro de fecha, y en la lista de
   Eventos (`pages/list_stic_events.php:39` y :46) las dos consultas en una tanda. OJO: su
   `static $memo` no puede guardar nada mientras `SugarRestApiCall::isCollecting()`, o
   memorizaría un mapa vacío de la recolecta.
4. Tests en `tests/CalendarParallelTest.php`: una persona con inscripciones de hace tres
   años no lanza consultas por ellas; y las esperas de la portada fría no pasan de 4. Dos
   tests fijan la forma de hoy y hay que actualizarlos a la vez (probado en una copia):
   `CalendarParallelTest::test_cada_nivel_sale_en_una_tanda` y
   `CosteLlamadasAreaTest::testHoyNingunaDeEstasPantallasAgrupaSusLlamadas` (este último
   pide literalmente que se actualice cuando alguien agrupe).

**Ahorro estimado:** 1 espera siempre (~350 ms) y, con historial, 3 más con 7 inscripciones
o 6 más con 12 (1-2 s), en la primera pantalla del día.

**Ficheros:** `inc/stic-calendar.php`, `inc/stic-action.php`, `pages/list_stic_events.php`, `tests/CalendarParallelTest.php`, `tests/CosteLlamadasAreaTest.php`

## VEL-4 — Pagos hace tres viajes en fila en cada visita, y los dos primeros no dependen uno del otro

**Tipo:** rendimiento · **Para:** familias · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 3/5

**Problema.** Pagos no tiene caché (bien: es dinero y se quiere ver al momento, PERF-08), así
que cada visita paga lo mismo: los compromisos de quien paga
(`sticpa_payments_commitment_rows`, `pages/list_stic_payments.php:64`), después sus pagos
(`:70`) y después, en tanda, los pagos de cada compromiso vivo (`sticpa_payments_complete`,
`inc/stic-payments.php:184`). Las dos primeras consultas son independientes (las dos solo
necesitan el id de la persona); la tercera sí depende de la primera.

**Evidencia.** Con el doble de las pantallas de familias (todo lo cacheable ya caliente):
«compromisos → pagos → pagos del compromiso», 3 esperas, en cualquier visita. Probado en una
copia: con las dos primeras en una `sticpa_pl_prime`, «TANDA(compromisos, pagos) → pagos del
compromiso», **2 esperas**. La suite pasa salvo
`CosteLlamadasAreaTest::testHoyNingunaDeEstasPantallasAgrupaSusLlamadas`, que pide que se
actualice cuando alguien agrupe.

Una trampa: `sticpa_payments_settle()` (`:66`) puede ESCRIBIR (al volver del TPV reclama el
pago con tarjeta) y entonces se vuelven a leer los compromisos. Si se escribe, la respuesta
de pagos traída en la tanda es de ANTES de escribir.

**Propuesta.** En `pages/list_stic_payments.php`, antes de :64, `sticpa_pl_prime($objSCP,
fn)` con `sticpa_payments_commitment_rows(…)` y `$objSCP->getRelatedElementsForLoggedUser($params)`
(el `$params` de :44 ya está construido). Dentro del `if (sticpa_payments_settle(…))`, lo
primero `SugarRestApiCall::forgetMemo()`, para que tras un cambio todo se relea de verdad.
Actualizar el test de arriba y bajar el tope de `list_stic_payments` si se cuentan esperas.

**Ahorro estimado:** ~350 ms en cada visita a Pagos.

**Ficheros:** `pages/list_stic_payments.php`, `tests/CosteLlamadasAreaTest.php`

## VEL-5 — La ficha de un evento (la del enlace de WhatsApp) y la lista de Eventos piden en fila lo que podría ir junto

**Tipo:** rendimiento · **Para:** familias y participantes · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 4/5

**Problema.** La ficha de un evento es la pantalla a la que se llega desde fuera (WhatsApp,
correo con destino, la app): muchas veces es la PRIMERA del día, con el calendario frío. Hoy
pide en fila el evento (`pages/single_stic_events.php:31`), después tus inscripciones para
saber si ya tienes plaza (`:56` → `prefix_user_active_registration_map`,
`inc/stic-action.php:405`), su tanda de «evento de cada inscripción», y al final los
documentos del evento (`:70` → `sticpa_event_documents`, `inc/stic-event-web.php:88`,
cacheados 10 min). El evento, tus inscripciones y los documentos solo necesitan el id del
evento y el tuyo. La lista de Eventos igual: los eventos de la ventana
(`pages/list_stic_events.php:39`) y tus inscripciones (`:46`) van en fila.

**Evidencia.** Con el doble de familias, la definición de campos ya cacheada (lo normal: 6 h y
de todo el sitio) y el calendario frío:

| Pantalla | Hoy | Con una tanda (probado en una copia) |
|---|---|---|
| Ficha del evento | 4: evento → inscripciones → TANDA(3) → documentos | **2**: TANDA(evento, inscripciones, documentos) → TANDA(3) |
| Lista de Eventos | 3: eventos → inscripciones → TANDA(3) | **2**: TANDA(eventos, inscripciones) → TANDA(3) |

Con caché caliente las dos se quedan en 1 espera, igual que hoy. La suite pasa salvo
`CosteLlamadasAreaTest::testHoyNingunaDeEstasPantallasAgrupaSusLlamadas` (la lista de Eventos
está entre sus pantallas; es el mismo test que piden actualizar VEL-3 y VEL-4).

Dos detalles que hay que cuidar al hacerlo:
- `prefix_user_active_registration_map()` y `prefix_user_active_event_ids()` guardan el
  resultado en un `static $memo`. Llamadas dentro de la recolecta devuelven vacío y lo
  MEMORIZARÍAN para el resto de la petición («ya tienes plaza» saldría siempre falso). Hay
  que no tocar el memo mientras `SugarRestApiCall::isCollecting()` (probado así en la copia).
- La ficha pide además la definición de `status` con su propia lista
  (`pages/single_stic_events.php:46`, `array('status')`), otra clave de caché para un campo
  que ya trae `sticpa_event_field_definition()`, justo lo que esa función dice que no se
  haga (`inc/stic-events.php:418-433`). Cuesta una llamada cada 6 h para todo el sitio:
  poco, pero es una línea.

**Propuesta.**
1. `pages/single_stic_events.php`, antes de :31: `sticpa_pl_prime` con
   `getRecordDetail($eventId, 'stic_Events', sticpa_event_fields_to_request($objSCP, true))`,
   `prefix_user_active_registration_map($objSCP)` y `sticpa_event_documents($objSCP, $eventId)`.
2. `pages/list_stic_events.php`, antes de :39: lo mismo con `getRecordsModule(…)` y
   `prefix_user_active_registration_map($objSCP)`.
3. `inc/stic-action.php`: en las dos funciones con `static $memo`, no asignarlo durante la
   recolecta.
4. `pages/single_stic_events.php:46`: leer `status` de `sticpa_event_field_definition($objSCP)`.
5. Tests en `tests/CosteLlamadasAreaTest.php` con la ficha del evento (hoy no está entre sus
   pantallas) y el tope de esperas.

**Ahorro estimado:** ~700 ms en la ficha a la que se llega por enlace, ~350 ms en Eventos con
el calendario frío.

**Ficheros:** `pages/single_stic_events.php`, `pages/list_stic_events.php`, `inc/stic-action.php`, `tests/CosteLlamadasAreaTest.php`

## VEL-6 — Las tandas de más de 4 se esperan dos veces, y el test de esperas las cuenta como una

**Tipo:** rendimiento + medición · **Para:** todos · **Tamaño:** S · **Riesgo:** medio · **Impacto:** 3/5 · ⚖️ Decide el propietario (solo la concurrencia)

**Problema.** `callMany()` parte la tanda con `array_chunk($requests, 4)`
(`inc/stic-class-6.php:549-555`) y espera a que acabe cada trozo entero antes de lanzar el
siguiente. Una tanda de 5 o 6 son DOS esperas, y además la segunda no arranca hasta que
acaba la más lenta de las cuatro primeras. Pasa en la portada en frío (2 consultas por
inscripción: con 3 inscripciones ya son 6), en la primera tanda de la portada de Pasar
Lista, de la de coordinación y de la lista de monitores (5 cada una con la estructura fría)
y en la ficha del participante.

Y no se ve, porque `CosteLlamadasTest` cuenta cada tanda como una espera
(`tests/CosteLlamadasTest.php:234-235`: `count($this->scp->batches) + sueltas`), y la tabla
de «Coste real hoy» de `PASAR-LISTA-ESTADO.md` §4 sale de ahí. Por ejemplo, la portada de
Pasar Lista «4 esperas» son 5 de verdad, y la ficha del participante «7» son 8.

**Evidencia.** Transporte real (`SugarRestApiCall::callMany`) contra un servidor de prueba
con 350 ms por petición y keep-alive: con concurrencia 4, tandas de 2 y 4 peticiones = 361
y 394 ms; de 5, 6 y 8 = **788, 783 y 784 ms**. Con concurrencia 6: 5 y 6 peticiones = 395 y
396 ms. Tandas que se pasan de 4 en `CosteLlamadasTest` (modo sin enlaces, estructura
fría): portada de Pasar Lista (5+2), la de coordinación (5+3), ficha del participante
(2+5+2), lista de monitores (5+2+2), coordinación (5). Con la estructura caliente (el
calentado nocturno la deja hecha) esas tandas bajan de 4; la que queda siempre es la de la
portada de cualquiera con 3 o más inscripciones (VEL-3).

**Propuesta.**
1. Que el test cuente la verdad: `$viajes = Σ ceil(tamaño de la tanda / 4) + sueltas`, y lo
   mismo en `CosteLlamadasAreaTest`. Corregir la tabla de `PASAR-LISTA-ESTADO.md` §4.
2. Ventana deslizante en `callMany()`: en vez de olas de 4, mantener 4 en vuelo y añadir la
   siguiente en cuanto acabe una (`curl_multi_info_read` dentro del bucle). Misma carga
   máxima para el CRM; se gana cuando las latencias son desiguales (una consulta grande de
   800 ms y cinco pequeñas de 200 ms: 1.000 ms en olas, 800 ms con ventana).
3. ⚖️ Subir la concurrencia por defecto de 4 a 6 (`sticpa_crm_multi_concurrency`, una línea).
   Ahorra una espera entera en todas las tandas de 5-6. El plan 034 dejó 4 con la condición
   de parar si la instancia de SinergiaCRM se resentía; decidirlo con el proveedor y mirar
   el `error_log` («Petición lenta», `inc/stic-pasar-lista-diag.php:97`) la semana siguiente.
   Se vuelve atrás con el mismo filtro.

**Ahorro estimado:** con concurrencia 6, una espera (~350-400 ms) por cada tanda de 5 o 6: en
la portada fría de quien tiene 3 inscripciones, siempre. La ventana deslizante sola gana menos
y depende de lo desiguales que sean las consultas.

**Ficheros:** `inc/stic-class-6.php`, `tests/CosteLlamadasTest.php`, `tests/CosteLlamadasAreaTest.php`, `docs/comunica/PASAR-LISTA-ESTADO.md`

## VEL-7 — Tres juegos de Inter y uno sale de un dominio provisional: la negrita del área se descarga de `steelblue-mallard-178509.hostingersite.com`, y Google Fonts sigue bloqueando el primer pintado

**Tipo:** rendimiento · **Para:** todos · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 3/5

**Problema.** El plan 031 autoalojó Inter para sacar del camino crítico los orígenes externos
(`sinergiacrm-private-area.php:1424-1427` y el `preload` de :1392-1402). Pero en la página del
área siguen entrando otras dos tipografías que el área no pide:

1. **Astra** encola `astra-google-fonts` (`fonts.googleapis.com/css?family=Inter:400,600|Plus+Jakarta+Sans:600`)
   en `<head>`, con su `dns-prefetch` y sus dos `preconnect`: un origen externo que bloquea
   el pintado (y un segundo, `fonts.gstatic.com`, para los ficheros).
2. **Elementor** encola `elementor-gf-local-inter` (126 `@font-face`, 53 KB) y
   `elementor-gf-local-lato`, «locales»… que apuntan TODOS a
   `https://steelblue-mallard-178509.hostingersite.com/wp-content/uploads/elementor/google-fonts/fonts/…`,
   el dominio provisional de Hostinger, no a `comunica.movimientoconsolacion.com`. Es un
   tercer origen (DNS + TLS) para las fuentes, y si algún día deja de responder, cada carga
   espera a que falle.

Lo grave: la de Elementor se declara DESPUÉS de la del área y, como se ve en la prueba de
abajo, para los pesos 600 y 700 (títulos, botones, etiquetas, la cápsula de fecha) el
navegador usa la Inter de Elementor y no la que el área precarga. La negrita del área llega
del dominio provisional.

**Evidencia.**
- HTML servido de `/ap/?app=1` (09/10/2026): en `<head>`, `<link rel='dns-prefetch'
  href='//fonts.googleapis.com'>`, `preconnect` a `fonts.googleapis.com` y
  `fonts.gstatic.com`, `astra-google-fonts-css`, y al final de las hojas
  `elementor-gf-local-inter-css` (`…/elementor/google-fonts/css/inter.css?ver=1747152607`) y
  `elementor-gf-local-lato-css`. Las dos hojas de Elementor descargadas: sus 126 + 20 `src`
  apuntan a `steelblue-mallard-178509.hostingersite.com`.
- El área solo usa Inter (`--font-family`, `css/custom-style.css:98`); ni Lato ni Plus
  Jakarta Sans aparecen en su CSS.
- Prueba en Chromium con el mismo orden de hojas que producción (la Inter del área con su
  `preload`, después la `inter.css` de Elementor con sus URL redirigidas a un servidor
  local): se descargan **dos** ficheros, el del área y uno de Elementor; `document.fonts`
  da por cargadas la cara «100 900» del área (texto normal) y las caras **600 y 700 de
  Elementor** (negrita).

**Propuesta.**
1. En `sinergiacrm-private-area.php`, en la misma función de prioridad 100 que VEL-1 y solo
   en páginas del área: `wp_dequeue_style('elementor-gf-local-inter')` y
   `wp_dequeue_style('elementor-gf-local-lato')` siempre (el área no usa ninguna de las dos;
   la Inter del área ya cubre 100-900), y `wp_dequeue_style('astra-google-fonts')` al menos
   en modo app (`sticpa_is_app_mode()`), donde la cabecera del tema no se ve. Quitar también
   el `dns-prefetch` y los `preconnect` a Google con el filtro `wp_resource_hints` en esas
   mismas páginas.
2. Fuera del repo (lo hace quien administra WordPress, una vez, y arregla también la web
   pública): en Elementor, volver a generar las fuentes locales y el CSS (Herramientas →
   Regenerar archivos) para que apunten al dominio de verdad. Si hace falta Plus Jakarta Sans
   en la cabecera de la web, que Astra la sirva local.
3. Comprobar en una captura a 375 y 1280 que títulos y botones siguen en Inter 600/700 (el
   mismo dibujo; solo cambia de dónde sale el fichero) y, en la pestaña Red, que no queda
   ninguna petición a `fonts.googleapis.com`, `fonts.gstatic.com` ni `hostingersite.com`.

**Ahorro estimado:** dos orígenes externos menos en el camino crítico (sin medir en
producción; en 4G, cada origen nuevo es una resolución DNS y un saludo TLS, del orden de
150-400 ms, que se pagan en la primera carga del día, cuando caduca la hoja de Google,
`max-age=86400`) y la negrita deja de depender de un dominio provisional.

**Ficheros:** `sinergiacrm-private-area.php`

## VEL-8 — La precarga al apoyar el dedo apenas adelanta nada en el móvil, y cuando el dedo empieza un scroll sobre una tarjeta, el toque de verdad espera casi un segundo más

**Tipo:** rendimiento · **Para:** familias (Eventos, portada) en navegador móvil · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 3/5 · ⚖️ Decide el propietario

**Problema.** El plan 030 precarga cualquier enlace `internalpage=` con Speculation Rules en
modo `conservative` (`sticpa_speculation_rules()`, `sinergiacrm-private-area.php:1360-1385`),
que se dispara al APOYAR el dedo. En un móvil eso pasa también al EMPEZAR UN SCROLL: en
Eventos, la portada o Inscripciones casi todo es tarjeta-enlace (`a.stic-rec-main`), así que
bajar por la lista con el dedo lanza la precarga de la tarjeta que se ha tocado para
arrastrar. Cada precarga es un render completo con sus llamadas al CRM y, como la sesión de
PHP no se suelta durante el render (plan 028, descartado lo que faltaba), **tiene el candado
de la sesión**: cuando la persona toca por fin la tarjeta que quería, su petición espera a
que acabe la de la tarjeta que no quería. Y la precarga del toque bueno se queda en cola
detrás y se pinta entera después, para nada.

A cambio, la ganancia en un toque normal es como mucho el rato entre apoyar y levantar el
dedo (≈100 ms): el render del servidor sigue siendo el mismo.

**Evidencia.** Banco de pruebas: PHP con `session_start()` (candado de fichero, como el área),
1,5 s de «CRM» por ficha, `Cache-Control` de las páginas del área, las mismas reglas de
precarga, y Chromium en modo móvil (375 px, eventos táctiles por CDP; en un Android real
conviene repetirlo con `chrome://inspect`, por si la heurística del móvil fuera otra):

| Gesto | Sin reglas | Con las reglas de hoy |
|---|---|---|
| Toque en la tarjeta 3 | 1.557 ms | 1.546 ms (la precarga se reaprovecha) |
| Scroll que empieza sobre la tarjeta 1 y toque en la 3 | 1.584 ms, 1 render | **2.458 ms**, 3 renders (ficha 1 precargada, ficha 3 esperando el candado, y otra ficha 3 precargada después) |

En el área real la ficha del evento cuesta 1-4 viajes al CRM (VEL-5): cada precarga perdida
es eso mismo, cobrado al CRM, y el candado lo paga quien espera.

Queda una duda que se resuelve en dos minutos: si la WebView de MCM App, en Android y en
iOS, ejecuta estas reglas (`HTMLScriptElement.supports('speculationrules')` desde el
inspector remoto de cada una). Si no las ejecuta, la precarga del 030 nunca ha llegado a la
app, ni para bien ni para mal, y lo de arriba es de quien entra por el navegador (los
enlaces de WhatsApp se abren en Chrome).

**Propuesta.** ⚖️ La precarga se aprobó en su día (030), por eso se pregunta.
*Recomendación: GO a* precargar solo con ratón. En `sticpa_speculation_rules()`, en vez de
imprimir el `<script type="speculationrules">` tal cual, imprimir un `<script>` mínimo que lo
inserte solo si `matchMedia('(hover: hover) and (pointer: fine)').matches` (las reglas
insertadas por JS funcionan igual). En escritorio no cambia nada; en el móvil se acaban las
precargas por arrastrar y el candado ocupado. Lo de subir `eagerness` (que proponía el
encargo) no se recomienda en el móvil por lo mismo: más renders con candado.
Comprobar con el mismo banco: en móvil, scroll + toque = sin reglas; en escritorio, el
`Sec-Purpose: prefetch` sigue llegando al pasar el ratón y hacer clic.

**Ahorro estimado:** hasta ~0,9 s en el toque que sigue a un scroll en las listas, y las
llamadas al CRM de cada precarga perdida.

**Ficheros:** `sinergiacrm-private-area.php`

## VEL-9 — Apuntarse a una actividad que se paga son ~11 esperas en fila antes de la redirección; cuatro se pueden juntar

**Tipo:** rendimiento · **Para:** familias · **Tamaño:** S · **Riesgo:** medio · **Impacto:** 3/5

**Problema.** Al enviar «Inscribirme» en una actividad con precio, el manejador
(`prefix_admin_single_stic_registrations`, `inc/stic-action.php:465`) hace, en fila:

1. el evento (`:610`, `getRecordDetail`);
2. tus inscripciones activas, sin caché a propósito (`:615`, `prefix_user_active_event_ids($objSCP, true)`):
   1 consulta + una tanda con el evento de cada inscripción;
3. guarda la inscripción (`:676`, `set_entry`);
4. y `sticpa_registration_ensure_commitment()` (`:700`/`:718` → `inc/stic-registrations.php:1201`):
   lee si la inscripción ya tiene compromiso (`:1240`, necesario: un *logic hook* del CRM lo
   crea a veces, `CAMPOS.md` §«No es un workflow»), lo crea, y después **tres
   `set_relationship` uno detrás de otro** (`:1258`, `:1261`, `:1263`: pagador,
   destinatario e inscripción), lee el pago que acaba de generar el CRM (`:1268`) y le pone
   la persona.

Son 10 + ⌈N/4⌉ esperas (11 con tres inscripciones, ~4 s) antes de que el navegador reciba la
redirección, y luego se pinta la pantalla de destino con las suyas. El evento (1) y tus
inscripciones (2) no dependen uno de otro. Las tres relaciones y la lectura del pago
tampoco: el pago lo crea el CRM al guardar el compromiso, ANTES de las relaciones (lo dice
el propio comentario de `:1264-1266`).

**Evidencia.** Lectura del código, llamada por llamada (las de las líneas de arriba; la
definición de los métodos de pago y la delegación salen de caché y de la sesión). El
patrón de escribir en tanda ya existe y está probado en Pasar Lista
(`sticpa_pl_prime_attendance_updates`, `inc/stic-pasar-lista-crm.php:2046`; test
`test_guardar_manda_las_asistencias_en_una_tanda`): la recolecta no escribe, la tanda escribe
una vez y la pasada normal consume la respuesta.

**Propuesta.**
1. `inc/stic-action.php`, antes de :610: `sticpa_pl_prime` con el `getRecordDetail` del evento
   y `prefix_user_active_event_ids($objSCP, true)` (con la guarda del `static $memo` de VEL-5).
   −1 espera.
2. `inc/stic-registrations.php:1257-1268`: las tres `set_relationship` y
   `sticpa_commitment_payments($objSCP, $id)` en UNA `sticpa_pl_prime`; el bucle que pone la
   persona a cada pago queda igual, después. −3 esperas.
3. Riesgo medio porque toca dinero (plan 041): probar con una inscripción de prueba en una
   actividad con precio, por domiciliación y por tarjeta, y mirar en el CRM que el
   compromiso tiene sus tres relaciones, que el pago tiene persona y sale en Pagos, y que no
   hay compromisos dobles. `tests/RegistrationManageTest.php` debería fijar que las
   relaciones salen en una tanda.

Guardar «Mis datos» (`prefix_comunica_save_contact`, `inc/stic-action.php:1632`) es una sola
escritura: ahí no hay nada que ganar.

**Ahorro estimado:** 4 esperas (~1,4 s) en cada inscripción con pago.

**Ficheros:** `inc/stic-action.php`, `inc/stic-registrations.php`, `tests/RegistrationManageTest.php`

## VEL-10 — No hay números de producción: la cabecera `Server-Timing` que cita el código no existe y la tabla de mediciones del plan 034 sigue vacía

**Tipo:** medición · **Para:** el propietario y quien implemente · **Tamaño:** M · **Riesgo:** bajo · **Impacto:** 3/5

**Problema.** «Sigue yendo lentito» no se puede contrastar con nada. El comentario de los
contadores dice que los lee `sticpa_crm_timing_header()` «para la cabecera `Server-Timing`,
que es la única forma de saber si una pantalla va lenta por el CRM o por otra cosa»
(`inc/stic-class-6.php:28-36`), pero esa función no existe en el repo. Lo que hay es el
aviso al `error_log` de las peticiones con más de 3 s de CRM
(`inc/stic-pasar-lista-diag.php:82-99`) y el panel `?pl_diag=1`, que solo ve coordinación y
solo cuenta el CRM. La tabla «Mediciones» de `plans/archive/034-…md` (:174-178) sigue en
«(pendiente fase 0)». Sin eso no se sabe cuánto es CRM, cuánto WordPress y cuánto red y
navegador, ni si VEL-1…9 han servido.

Y la cabecera no se puede mandar como se pensó: cuando el shortcode pinta, el tema ya ha
enviado las cabeceras (`pages/single_stic_payment_form.php:22-24`).

**Evidencia.** `grep -rn sticpa_crm_timing_header` → solo el comentario. Los contadores sí
existen y se rellenan (`SugarRestApiCall::$callCount`, `$callMs`, `$callLog`,
`inc/stic-class-6.php:34-36`, :402-418 y :620-633).

**Propuesta.**
1. En `wp_footer`, solo en páginas del área y con sesión: un `<script>` en línea que deje en
   `window.sticpaPerf` lo del servidor (pantalla, ms de CRM, llamadas, tandas, ms de PHP con
   `timer_stop()`), y que al cargar mande con `navigator.sendBeacon` eso más lo del
   navegador (`responseStart` y `domContentLoadedEnd` de Navigation Timing, y el FCP de un
   `PerformanceObserver`) y si va en la app (`sticpa_is_app_mode()`).
2. Un `admin_post_nopriv_*` que lo guarde en una opción NO autocargada, como anillo de las
   últimas ~500 visitas (sin ids de persona: pantalla, tiempos y si es app), y una tabla en
   la página de ajustes del plugin con la mediana y el p90 por pantalla. Como todo manejador
   nuevo, pasa por `sticpa_require_session()` (`SecurityTest` lo exige) y acota lo que
   acepta (números y una clave de pantalla de la lista blanca).
   Versión mínima, si se quiere empezar por algo: una línea por petición del área en el
   `error_log` con lo del servidor (ampliar `sticpa_pl_diag_log_slow()` con un umbral 0
   detrás de un filtro), sin la parte del navegador.
3. Con eso, rellenar la tabla del 034 antes y después de cada lote del 042.

**Ahorro estimado:** ninguno por sí mismo; es lo que permite saber qué ha servido.

**Ficheros:** `inc/stic-pasar-lista-diag.php` (o uno nuevo `inc/stic-perf.php`), `sinergiacrm-private-area.php`, `inc/stic-class-6.php` (el comentario)
