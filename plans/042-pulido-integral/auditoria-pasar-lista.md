# 042 · Auditoría — Pasar Lista (lado del monitor/a)

> Auditor de la pasada del 09/10/2026. Hallazgos verificados contra el código y con capturas
> del render offline de las pantallas REALES (`pages/single_stic_pasar_lista*.php` ejecutadas con
> el `FakeSCP` de `tests/PasarListaRenderTest.php`, el CSS real —stic-base, custom-style y
> pasar-lista— y `js/stic-pasar-lista.js` en marcha). Para ver un grupo realista se duplicaron
> las filas de marcar hasta 14 chavales. Las capturas vivían en el scratchpad y no se conservan:
> se rehacen con ese patrón (README §«Cómo retomar», punto 4). Los meses en inglés son del arnés.

## Resumen

El camino del sábado está bien resuelto: abrir → atajo de tu grupo → marcar → guardar son dos toques más los de cada chaval, con borrador local, hoja de estados, «Cambios sin guardar» que se distingue y nada roto a 375 en los dos temas. Lo que falla está en las orillas del camino feliz, y casi siempre se paga con datos perdidos o con un sábado olvidado. Dos botones destruyen marcas sin avisar: «Sin registro» (sin confirmación, pegado a «Guardar», tira lo marcado y deja una lista pasada como «No hubo» con 0/0) y «Han venido todos» (pone en verde también a los que ya estaban en rojo). Las listas atrasadas no tienen quien las recuerde: la portada no avisa del sábado pasado de TU grupo aunque ya tiene los datos cargados, el historial del grupo está roto por un `<div>` sin cerrar y huérfano, la tira del resumen son enlaces de 7 px, volver de una ficha te cambia de fecha, y una lista guardada sin cobertura caduca con el nonce y se atasca en silencio. En la app, la barra de guardar queda bajo la tab bar mientras se marca (la hoja se arregló el 27/09; la barra no). El resto es pulido: el resultado del guardado sale arriba en letra pequeña mientras abajo sigue «Guardar lista», 27 textos en `--gray-400` (2,5:1), plurales y decimales, el «Llamar» de la ficha sin destinatario, el estado de las filas invisible para un lector de pantalla y un atajo de portada del que solo se puede tocar el botón. Menores vistos y no incluidos: «Cambiar» del pañuelo y el enlace al pie del árbol miden 40 px; en el árbol «1º» y «ESO» se parten de línea. Arnés: `audit/pasar-lista/harness/render.php <página> [k=v] [--post] [--fallo] [--propiedad_del_FakeSCP]`, que ejecuta la página real con el doble de los tests.

## PL-1 — «Sin registro» no pide confirmación, está a un pulgar de «Guardar», tira las marcas hechas y pisa una lista ya pasada

**Tipo:** bug · **Para:** monitores · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 5/5

**Problema.** El botón «Sin registro — no me avises más» es un `type="submit"` del mismo formulario que «Guardar», sin `data-pl-confirm`, y al final de la lista queda justo encima de la barra de guardado. Un roce con el pulgar al ir a «Guardar» lo dispara y: 1) las marcas que se acaban de poner NO se escriben (`sticpa_pl_save()` se salta las asistencias entero cuando `$omitida`); 2) si la lista ya estaba pasada, la reescribe como `omitida` con `n_asistieron = 0` y `n_faltaron = 0`, así que el árbol, la portada y el resumen pasan a decir «No hubo» de un sábado que sí se pasó; 3) como la comprobación de un «sin registro» no mira asistencias, la respuesta lleva `data-pl-saved-ok` y el JS borra el borrador local: las marcas no quedan ni en el móvil. Es exactamente lo que design.md §6.2 pide confirmar («Peligro … siempre detrás de una confirmación») y el propio código ya protege su gemelo: «Guardar sin nada marcado no escribe nada» porque «un roce en el botón dejaba un dato falso» (marcar.php:169-172). Aquí el roce deja un dato falso Y pierde los buenos.

**Evidencia.** pages/single_stic_pasar_lista_marcar.php:404-407 (el botón, sin confirmación) y :167 (`$omitida`); inc/stic-pasar-lista-crm.php:3019 (`if (!$omitida)` envuelve TODAS las escrituras de asistencias) y :3064-3078 (la lista existente se actualiza a `omitida` con los contadores a 0); inc/stic-pasar-lista-crm.php:2903-2905 (con `$omitida` la comprobación vuelve sin problemas) → pages/single_stic_pasar_lista_marcar.php:262 y :292 (`data-pl-saved-ok`) → js/stic-pasar-lista.js:1031 (`lsDel(draftKey)`); js/stic-pasar-lista.js:920-985 (el submit no distingue `skip` salvo para la cola offline). La confirmación ya existe como patrón de documento: js/stic-pasar-lista.js:1087-1093 (`[data-pl-confirm]`, la usan el pañuelo y los avisos de la ficha). Medido a 375×812 con 14 chavales: al final del scroll «Sin registro» ocupa 44 px y entre su borde inferior y el principio de la barra de guardado quedan ~30 px; en la captura se ven apilados «Sin registro — no me avises más» (borde discontinuo gris) y, debajo, los contadores y el botón de degradado. La pantalla además lo enseña en una lista que dice «Esta lista ya está pasada».

**Propuesta.** 1) Añadir al botón `data-pl-confirm` con un texto que diga lo que pasa: si hay marcas en pantalla o la lista está pasada, «Se quedará como “sin registro” y no se guardará ninguna marca. ¿Seguro?»; si no, «¿No hubo sesión con este grupo? Se deja de avisar.». El texto varía en PHP según `$lista['estado']` y, para las marcas, el JS puede cambiar el atributo en `refresh()` cuando `nYes + nNo > 0`. 2) En una lista `pasada`, no pintar «Sin registro» (pasar de pasada a omitida no es un caso real; si alguna vez lo es, se hace desde el CRM). 3) Sacarlo de la zona del pulgar: bajo la cabecera, como enlace secundario pequeño («¿No hubo sesión? Sin registro»), que es cuando se decide —antes de marcar—, y no al final de la lista pegado a «Guardar». 4) Test en tests/PasarListaRenderTest.php: una lista pasada no pinta `value="skip"`, y el botón lleva `data-pl-confirm`.

**Ficheros:** `pages/single_stic_pasar_lista_marcar.php`, `js/stic-pasar-lista.js`, `css/pasar-lista.css`, `tests/PasarListaRenderTest.php`

## PL-2 — «Han venido todos» machaca las faltas ya marcadas (y las justificadas con su motivo)

**Tipo:** bug · **Para:** monitores · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 4/5

**Problema.** El botón pone `yes` en TODAS las filas, no solo en las vacías. El diseño (PASAR-LISTA.md §6.3) supone un orden —primero «Han venido todos», luego desmarcar a los dos que faltan—, pero el orden natural de mucha gente es el contrario: «marco a los que no han venido y el resto, todos». Ese orden borra en silencio las faltas, las justificadas y las parciales, sin deshacer. También pasa al revisar una lista ya pasada: un toque y las ausencias del CRM desaparecen de la pantalla (y se escriben como «vino» si se guarda). En chavales el motivo de una justificada se queda colgado de una fila que ahora dice «vino» y se manda igual (`collectNotes()` no mira el estado).

**Evidencia.** js/stic-pasar-lista.js:622-636 (`rows.forEach(… setState(row, 'yes' …))`, sin mirar el estado previo); :360-367 (`collectNotes()` manda el motivo de cualquier fila con motivo). Comprobado en el navegador sobre el render real de marcar: antes «Pau = no_unjustified, Nerea = no_justified»; después de pulsar «Han venido todos», «Pau = yes, Nerea = yes».

**Propuesta.** Que solo rellene lo que está sin marcar: `if (getState(row) === '') setState(row, 'yes', …)`. Y que el texto acompañe al estado, desde `refresh()`: sin ninguna marca, «Han venido todos»; con alguna ya puesta, «El resto ha venido (N)» y deshabilitado (o escondido) cuando N = 0. Así sirven los dos órdenes y ninguno destruye nada. Para el caso raro de querer poner a todos en verde de verdad, ya está el toque fila a fila. Test de JS no hay; dejar el comportamiento descrito en PASAR-LISTA.md §6.3.

**Ficheros:** `js/stic-pasar-lista.js`, `pages/single_stic_pasar_lista_marcar.php` (los dos textos como `data-label-*`), `docs/comunica/PASAR-LISTA.md`

## PL-3 — En MCM App la barra de guardar queda debajo de la tab bar y de la píldora: «Guardar» y «Cambios sin guardar» no se ven mientras marcas

**Tipo:** bug · **Para:** monitores (app) · **Tamaño:** S · **Riesgo:** medio · **Impacto:** 4/5

**Problema.** `.pl-savebar` es `position: sticky; bottom: 0` con `padding-bottom: 1.2rem`, sin `env(safe-area-inset-bottom)` y sin la reserva que CONTRATO-APP-WEBVIEW.md §3 exige a todo lo que se pega abajo dentro de la app. La hoja de estados se arregló el 27/09 por esto mismo (`body.sticpa-app-mode .pl-sheet { padding-bottom: 11.5rem }`) y el contrato dice «Un elemento fijo nuevo abajo tiene que hacer lo mismo»; la barra de guardado, que es la acción principal de la pantalla más usada, se quedó fuera. Con una lista que no cabe en la pantalla (lo normal: 10-15 chavales), mientras se marca la barra va pegada al borde inferior: el botón cae entero en la franja de la tab bar (73 pt) y los contadores y el aviso ámbar de «Cambios sin guardar» en la de la píldora atrás/adelante (168 pt). Solo vuelve a verse al llegar al final del scroll, donde el `contentInset` de la app sí empuja el contenido. La lista de monitores usa la misma barra.

**Evidencia.** css/pasar-lista.css:614-631 (la barra) frente a :712 (la hoja sí reserva); pages/single_stic_pasar_lista_marcar.php:410 y pages/single_stic_pasar_lista_monitores.php:523 (las dos pantallas que la usan); docs/comunica/CONTRATO-APP-WEBVIEW.md:100-118 (medidas del iPhone: tab bar ~73 pt, píldora hasta ~168 pt). Simulado a 390×844 con `body.sticpa-app-mode` y dos franjas fijas de 73 y 168 px: barra de 680 a 844, botón «Guardar» de 771 a 825 (dentro de la tab bar), aviso de 702 a 732 y contadores de 742 a 761 (dentro de la píldora). En la captura, el botón de degradado asoma apagado bajo la franja oscura de la tab bar. Falta confirmarlo en un iPhone con la app (WKWebView no traslada el `contentInset` a lo pegado, que es lo que el contrato midió con la hoja).

**Propuesta.** En css/pasar-lista.css, junto a la regla de la hoja: `.pl-savebar { padding-bottom: calc(1.2rem + env(safe-area-inset-bottom, 0px)); }` y `body.sticpa-app-mode .pl-savebar { bottom: 73px; }` —o, si la píldora tapa el botón, `bottom: 10.5rem` con el mismo comentario que la hoja—. Con `bottom` y no con `padding`, la barra no crece y la lista sigue viéndose detrás. Probar en la app con 14 filas (marcar y monitores) y dejar la medida en CONTRATO §3 al lado de la de la hoja.

**Ficheros:** `css/pasar-lista.css`, `docs/comunica/CONTRATO-APP-WEBVIEW.md`

## PL-4 — La portada no recuerda la lista olvidada del sábado pasado de TU grupo (y los datos para hacerlo ya están cargados)

**Tipo:** flujo · **Para:** monitores · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 4/5

**Problema.** PASAR-LISTA.md §6.1 justifica el bloque «listas que faltan» con que es «lo que hoy no existe y hace que se pierdan semanas». Lo construido solo mira la sesión que toca HOY y solo en tus OTROS grupos: el del atajo no entra (`$gid === $mainGroupId → continue`) y una sesión anterior tampoco (`sticpa_pl_pick_session()` devuelve la de hoy en cuanto hoy hay sesión). Resultado: si el monitor no pasó la lista el sábado pasado, este sábado la portada le ofrece la de hoy y del hueco no dice nada. Solo se ve en el Resumen, en una celda de 14×7 px (PL-6). El comentario que lo justifica («recorrer todas las sesiones de todos los grupos es una consulta por par») es anterior a `sticpa_pl_all_listas()`: hoy la portada ya trae en la primera tanda TODAS las listas de la delegación y las sesiones de cada etapa, así que contar los huecos de tus grupos cuesta cero llamadas.

**Evidencia.** pages/single_stic_pasar_lista.php:193-196 (el comentario obsoleto), :204 (se salta el grupo del atajo), :213-233 (solo la sesión de `pick`); :36 (`sticpa_pl_all_listas()` ya en la tanda 1) y :51-66 (sesiones de cada etapa en la tanda 2); inc/stic-pasar-lista-crm.php:2553-2572 («TODAS las listas de la delegación, en UNA llamada») y :4502-4525 (`sticpa_pl_listas_by_session()`, que no llama a nada); inc/stic-pasar-lista.php:445-457 (`sticpa_pl_list_mark()` ya distingue `gap`). En el arnés, con C1 con dos sesiones anteriores sin lista: la portada pinta el atajo «PASADA · Revisar la lista» y ningún aviso; el Resumen, para el mismo grupo, «AL DÍA» y «2 sin pasar».

**Propuesta.** En la portada, para cada grupo tuyo (incluido el del atajo): `sticpa_pl_listas_by_session($objSCP, $sessions, 4)` y quedarse con las celdas `gap` de sesiones anteriores a la del atajo, como mucho de las últimas 4 (un mes, la misma ventana que el aviso de reuniones). Cada una entra en `$pending` con su fecha y su «Recuperar», que ya lleva a `marcar&grupo=…&sesion=…`. Si son más de 3, una fila «y N más» que va al historial del grupo (`grupos&grupo=…&sesiones=1`). El bloque ya existe y ya se ve bien; solo cambia qué se mete. Actualizar el comentario de :193 y PASAR-LISTA.md §6.1. Test: «una sesión pasada sin lista del grupo del atajo sale en “Te falta 1 lista”» y CosteLlamadasTest sin llamadas nuevas.

**Ficheros:** `pages/single_stic_pasar_lista.php`, `tests/PasarListaRenderTest.php`, `docs/comunica/PASAR-LISTA.md`

## PL-5 — «Guardado» y «no se ha guardado» salen arriba y en letra pequeña; abajo, donde se ha tocado, la barra sigue diciendo «Guardar lista»

**Tipo:** flujo · **Para:** monitores · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 4/5

**Problema.** De los cuatro estados que importan, dos viven en la barra de guardado (sin guardar, sin cobertura) y dos no: el resultado del servidor se pinta como un párrafo suelto encima de la lista. «✓ Lista guardada.» es texto verde de 12,6 px sin fondo; el fallo es un párrafo rojo de cinco líneas, también sin caja, con el icono flotando a media altura. Mientras, abajo —donde el monitor acaba de tocar y donde sigue mirando— la barra vuelve a decir «Guardar lista» con el degradado entero y el aviso de estado escondido, como si no hubiera pasado nada. Es justo lo que la propia hoja de estilos dice evitar: «Una línea, un motivo, siempre en el mismo sitio: encima del botón … no se mueve ni aparece en otro lugar» (css/pasar-lista.css:2110-2112). La variante verde `data-kind="ok"` de la barra existe y solo la usa el «Lo pendiente ya está enviado» de la cola. Además el texto de error se repite («1 bien y 2 con fallo. 2 marcas no han quedado guardadas»).

**Evidencia.** inc/stic-pasar-lista-ui.php:936-939 (éxito: `<p class="pl-notice pl-notice--ok">`) y :941-958 (fallo); pages/single_stic_pasar_lista_marcar.php:335-338 (los dos, arriba, antes de la lista) y :410-411 (la barra con `pl-status` vacío y `hidden`); css/pasar-lista.css:284-297 (`.pl-notice`: 0,79 rem, sin fondo, `align-items: center`) y :2124-2127 (la barra ya tiene `ok`). Capturas a 375 tras un POST real de guardado: arriba «✓ Lista guardada.» en una línea fina sobre los botones «Ver el resumen» y «Otro grupo»; abajo, «2 vinieron · 1 ausencias» y el botón «Guardar lista» idéntico al de antes de guardar. Con el CRM fallando (`failWrites`): el párrafo rojo de cinco líneas arriba y abajo «Guardar (2 sin marcar)» sin ningún aviso.

**Propuesta.** 1) Pasar el resultado del servidor a la barra: `<p class="pl-status" data-pl-status data-kind="ok">Guardada · 2 vinieron, 1 ausencia</p>` sin `hidden` (y `data-kind="error"` con un tono `--danger-soft`/`--danger-dark` nuevo en §«Aviso de estado») y que el JS lo deje estar hasta el primer toque, que lo cambia por «Cambios sin guardar». 2) Con el guardado confirmado y sin cambios, el botón pasa a secundario («Guardada ✓», fantasma) y vuelve a principal con el primer toque (`setDirty(true)`): así el degradado solo invita a guardar cuando hay algo que guardar. 3) Arriba, el fallo usa la tarjeta de aviso que ya existe (`.stic-alert` con variante de peligro) y el texto se queda en dos frases: «No se han guardado 2 marcas. Siguen en la pantalla: vuelve a guardar y, si falla otra vez, avisa a coordinación.». 4) El éxito de arriba se puede quitar: lo dice la barra y lo siguen diciendo «Ver el resumen / Otro grupo».

**Ficheros:** `inc/stic-pasar-lista-ui.php`, `pages/single_stic_pasar_lista_marcar.php`, `pages/single_stic_pasar_lista_monitores.php`, `js/stic-pasar-lista.js`, `css/pasar-lista.css`

## PL-6 — Resumen: la tira de sesiones son enlaces de 14×7 px y «Al día» convive con «2 sin pasar»

**Tipo:** diseño · **Para:** monitores y coordinación · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 3/5

**Problema.** Cada cuadradito de la tira «abre su lista» (se hizo a propósito: «veías el hueco de hace tres sábados y para corregirlo tenías que entrar al grupo y buscar la fecha a mano. Ahora es un toque»), pero mide 14×7 px con 3 px de separación: con doce sesiones son doce enlaces que un dedo no distingue. La cabecera de cada grupo, que también es un enlace, mide 28 px de alto. Y la pastilla de la derecha dice el estado de la ÚLTIMA sesión con la palabra «Al día» mientras debajo pone «2 sin pasar» en ámbar: dos mensajes que se contradicen en la misma fila.

**Evidencia.** pages/single_stic_pasar_lista_resumen.php:216-236 (cada celda es un `<a>`) y :240-249 (la pastilla mira solo `$lastMark`); css/pasar-lista.css:2023-2024 (`.pl-cell { width: 14px; height: 7px }`, `gap: 3px`). Medido a 375: `a.pl-cell` 7 px de alto y `a.pl-grouprow-top` 28 px (design.md §2.1 y §9.5 piden 44). Captura del resumen a 375: la fila «C1 Los Peques» con la pastilla verde «AL DÍA» y debajo tres rayitas y «2 sin pasar».

**Propuesta.** 1) Con puntero grueso (`@media (pointer: coarse)`), que la tira entera sea UN enlace al historial del grupo (`?internalpage=single_stic_pasar_lista_grupos&grupo=…&sesiones=1`, que ya pinta cada sesión como una fila de 44 px con su estado), y las celdas, decoración (`pointer-events: none`); en escritorio se quedan como enlaces. Lo más simple: envolver la tira en `<a class="pl-strip">` y quitar el `<a>` de cada celda, dejando el `title`. 2) `.pl-grouprow-top { min-height: 44px; }`. 3) La pastilla con el mismo vocabulario que la leyenda de la tira: «Pasada» / «Falta» / «Sin registro» (de la última sesión) y «Al día» solo cuando `$gaps === 0`.

**Ficheros:** `pages/single_stic_pasar_lista_resumen.php`, `css/pasar-lista.css`

## PL-7 — Al sol no se lee: 27 textos de Pasar Lista van en `--gray-400` (2,5:1 en claro, 3,4:1 en oscuro)

**Tipo:** accesibilidad · **Para:** monitores · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 3/5

**Problema.** design.md §5 manda los textos de ayuda y metadatos en `--gray-500` o `--mcm-helper`, y §2.1 pide AA. Pasar Lista usa `--gray-400` (#9ca3af en claro, #6b7078 en oscuro) para texto pequeño en 27 reglas, entre ellas cosas que el monitor tiene que leer de pie en un patio: la etiqueta «ALERGIAS» del bloque de salud de la ficha, la fecha y el autor de cada aviso de comportamiento, las explicaciones de la hoja de estados («Cuenta como asistencia», «No es necesario justificar…»), «Anterior / 3 de 3» del paso entre fichas, el «4 grupos» de la portada y los «1 grupo · 1 mon.» del resumen. Todos entre 9,6 y 12,8 px.

**Evidencia.** `grep -c "color: var(--gray-400)" css/pasar-lista.css` → 27; entre ellas :772 (`.pl-opt-desc`, con `!important`), :1455, :1610-1614 (`.pl-data-label`), :1718 (`.pl-avi-when`), :1960, :2030, :3279-3306 (paginador). Tokens: css/custom-style.css:141 y :4205. Medido sobre el render real con un escáner de contraste: ficha 2,54:1 en «ALERGIAS», «8 Nov · lo puso Mercedes», «Anterior» y 2,37:1 en «3 de 3»; portada 2,54:1 en «4 grupos»; resumen 2,54:1 en «1 grupo · 1 mon.»; en oscuro, 3,38:1 en los mismos. En la captura de la ficha a 375, «ALERGIAS» casi no se distingue del fondo blanco de la tarjeta.

**Propuesta.** Cambiar a `var(--gray-500)` (#6b7280: 4,83:1 en claro; #9aa0a9: 6,2:1 en oscuro) todas las reglas de `color: var(--gray-400)` que pintan TEXTO, y dejar `--gray-400` solo en iconos (`svg`), bordes y `::placeholder`. Son una veintena de sustituciones en css/pasar-lista.css; repasar a mano las que llevan `!important`. Volver a pasar el escáner a portada, marcar (con la hoja abierta), ficha y resumen en los dos temas.

**Ficheros:** `css/pasar-lista.css`

## PL-8 — El «Historial de listas» de un grupo está roto (un `<div>` sin cerrar) y nada enlaza a él; el selector de fecha no dice qué sábados faltan

**Tipo:** bug · **Para:** monitores · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 3/5

**Problema.** PASAR-LISTA.md §6.3 dice que el estado de cada lista se mira en el historial del grupo (`?internalpage=single_stic_pasar_lista_grupos&grupo=…&sesiones=1`) «porque eso no cabe en un desplegable». Pero esa pantalla abre `<div class="pl-head">` y no lo cierra nunca: la lista de sesiones se mete DENTRO de la cabecera, que es una fila flex, y sale a la derecha del título, estrujada en ~225 px, con cada fecha partida en tres líneas. Además, desde que la fecha se elige con el `<select>` nativo, ningún enlace del área lleva a esa pantalla (solo su propio comentario y un test que mira cadenas, no estructura). Y el desplegable, que es lo único que queda, enseña «3 · 15 nov» sin decir si esa lista está pasada, falta o es «sin registro»: para encontrar el sábado olvidado hay que ir abriendo fechas.

**Evidencia.** pages/single_stic_pasar_lista_grupos.php:64 (abre `.pl-head`), :67-74 (abre y cierra solo `.pl-head-titles`), :88-127 (la lista y el `return` sin cerrar la cabecera); compárese con la rama del árbol, :162 en adelante. `grep -rn "sesiones=1" pages inc js` → solo el comentario de :9. inc/stic-pasar-lista-ui.php:753-773 (las opciones del desplegable: número y fecha, sin estado). Captura a 375 del historial de C1: a la izquierda la flecha y «C1 / Los Peques / Historial de listas» en columna; a la derecha, la tarjeta con «Saturday 15 / de November / · 16:30 ✓ 2 vinieron · 0 ausencias», «Saturday 8 de / November · / 16:30 ○ Sin pasar»…; media pantalla vacía debajo.

**Propuesta.** 1) Cerrar la cabecera (`$html .= '</div>';` después de :74) y añadir a `test_selector_de_sesiones` una aserción de estructura (que `pl-list` no quede dentro de `pl-head`, p. ej. contando `<div` y `</div>` del fragmento). 2) Poner el estado en cada opción del desplegable, que no cuesta llamadas (las listas ya están en `sticpa_pl_all_listas()`): «3 · 15 nov · pasada», «2 · 8 nov · falta», «1 · 1 nov · sin registro»; `sticpa_pl_session_select_html()` recibe el mapa de listas del grupo. 3) Volver a dar entrada al historial: es el destino natural del «y N más» de PL-4 y de la tira del resumen (PL-6), y un enlace discreto «Ver todas las fechas» bajo la leyenda de marcar.

**Ficheros:** `pages/single_stic_pasar_lista_grupos.php`, `inc/stic-pasar-lista-ui.php`, `pages/single_stic_pasar_lista_marcar.php`, `tests/PasarListaRenderTest.php`

## PL-9 — Una lista guardada sin cobertura solo se envía si se vuelve a abrir Pasar Lista antes de que caduque el nonce, y si se atasca casi nadie se entera

**Tipo:** bug · **Para:** monitores · **Tamaño:** M · **Riesgo:** medio · **Impacto:** 4/5

**Problema.** El modo sin conexión está ENCENDIDO por defecto (`sticpa_pl_offline_enabled()` devuelve `true`; PASAR-LISTA-ESTADO.md §2 dice lo contrario). Sin cobertura, «Guardar» mete la lista en una cola de `localStorage` y dice «Guardado en el móvil. Se enviará solo al volver la cobertura.». Pero: 1) la cola solo se vacía en una pantalla de Pasar Lista (el JS no se carga en el resto del área), así que quien cierra la app y vuelve el sábado siguiente no envía nada en toda la semana; 2) cada entrada lleva el nonce del formulario, que caduca a las 12-24 h, y un reenvío con el nonce caducado no guarda (marcar.php:120) — tras 5 intentos queda «atascada»; 3) solo la pantalla de marcar lo dice, en gris (el mismo `data-kind="offline"` que «sin cobertura») y con un genérico «Vuelve a marcar y guardar» que no dice qué grupo ni qué día; en la portada y el árbol el reenvío es mudo, acierte o falle. Encima, si en la pantalla abierta se envía una entrada de OTRO grupo, se borra el borrador de la pantalla actual (`lsDel(draftKey)`). Con PL-4, esa lista olvidada tampoco aparece en la portada.

**Evidencia.** inc/stic-pasar-lista-sw.php:36-39 (`apply_filters('sticpa_pl_offline_enabled', true)`); sinergiacrm-private-area.php:194-197 y inc/stic-pasar-lista.php:95-106 (el JS solo en pantallas de Pasar Lista); js/stic-pasar-lista.js:283-292 (fuera de marcar, `queueFlush()` sin callback: sin mensaje), :206-221 (intentos y atasco), :939-951 (la entrada guarda el `pl_nonce` del momento), :1002-1016 (el aviso de atasco, con `say('offline', …)`, y el `lsDel(draftKey)` tras enviar cualquier entrada); pages/single_stic_pasar_lista_marcar.php:117-127 (nonce caducado → no se escribe nada).

**Propuesta.** 1) Nonce fresco al reenviar: si la respuesta no trae `data-pl-saved-ok`, `fetch(entry.url)` por GET, sacar el `pl_nonce` del HTML y reintentar una vez (misma sesión y mismo origen: no debilita la protección CSRF). 2) Guardar en la entrada `label` (código del grupo y fecha corta) y pintar, en la portada de Pasar Lista y en el árbol, un aviso «Tienes 1 lista guardada solo en el móvil: C1 · sáb 15 nov» con enlace a `entry.url`; en ámbar si está pendiente y con `data-kind="error"` si está atascada. 3) Vaciar la cola también desde `js/stic-ui.js` (que carga en todo el área) leyendo la misma clave `sticpa_pl_queue`, o al menos enseñar allí el aviso con el enlace. 4) Borrar solo el borrador de la entrada enviada (`STORE_DRAFT + entry.session + '_' + entry.group`), no el de la pantalla. 5) Corregir PASAR-LISTA-ESTADO.md §2.

**Ficheros:** `js/stic-pasar-lista.js`, `js/stic-ui.js`, `pages/single_stic_pasar_lista.php`, `pages/single_stic_pasar_lista_grupos.php`, `css/pasar-lista.css`, `docs/comunica/PASAR-LISTA-ESTADO.md`

## PL-10 — Copy: «1 vinieron», «4.5 h», un «Llamar» que no dice a quién y un vacío que manda a un voluntario al CRM

**Tipo:** copy · **Para:** monitores · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 3/5 · ⚖️ Decide el propietario (solo el punto 5: «chavales» o «participantes» en las dos secciones)

**Problema.** Pequeñas cosas que, juntas, hacen que la pantalla suene a máquina:
1. **Plurales fijos.** Los contadores de la barra son un número que cambia el JS y una palabra fija: con una persona sale «1 vinieron» y «1 ausencias». Lo mismo en el atajo de la portada («1 vinieron, 1 ausencias») y en el historial.
2. **Decimales en inglés.** La ficha dice «3 h de 4.5 h»: el float va a `sprintf('%s')` sin `number_format_i18n()`.
3. **«Llamar» y «WhatsApp» sin destinatario.** Los dos botones grandes de la ficha van al familiar de referencia (la madre), pero junto al nombre del chaval se leen como «llamar al chaval». El destinatario solo está en el `aria-label`.
4. **El vacío más común del curso habla de CRM.** Un grupo sin relaciones vigentes (lo que pasa cada 1 de septiembre) dice en marcar «…con relación vigente. Revisa las relaciones en el CRM.» a un monitor que no tiene CRM. Mis grupos ya lo resolvió bien («No es un fallo de la aplicación… coordinación las renueva»).
5. **Unidades que no se ven.** En «Pasar lista de otro grupo» unas pastillas dicen «9» (participantes) y otras «1 gr.» (grupos), y la diferencia solo está en un `title`, que en el móvil no existe. En Mis grupos el mismo grupo cuenta «chavales» y en Pasar Lista «participantes».
6. **Un chip que parece botón.** «Mantén pulsado» es un `<span>` con borde, fondo y letra de marca, como un botón secundario, y justo debajo la nota repite «Mantén pulsado para parcial o justificar».

**Evidencia.** 1) pages/single_stic_pasar_lista_marcar.php:413-420 y js/stic-pasar-lista.js:437-439 (solo cambia el número); pages/single_stic_pasar_lista.php:156-160; pages/single_stic_pasar_lista_grupos.php:104-109. En la captura tras guardar: «2 vinieron · 1 ausencias». 2) pages/single_stic_pasar_lista_ficha.php:514-519; inc/stic-pasar-lista.php:1200 (`round($horas, 1)`); captura de la ficha: «3 h de 4.5 h». 3) pages/single_stic_pasar_lista_ficha.php:283-319 (`$quick` es el familiar de referencia; texto visible «Llamar»). 4) pages/single_stic_pasar_lista_marcar.php:350-353 frente a pages/single_stic_mis_grupos.php:205. 5) pages/single_stic_pasar_lista.php:325-338; inc/stic-pasar-lista-ui.php:176. Captura de la portada: «MIC 9 · COM 11 · LC 1 gr.». 6) inc/stic-pasar-lista-ui.php:529-530 y :544-550; css/pasar-lista.css:527-537.

**Propuesta.** 1) Etiquetas con plural en el JS: `data-label-yes-one="vino" data-label-yes-many="vinieron"`, `ausencia/ausencias`, y `_n()` en las tres frases de PHP. 2) `number_format_i18n($track['hours'], 1)` y quitar el «,0». 3) Texto visible con el nombre corto y el parentesco: «Llamar a Marta (madre)» / «WhatsApp a Marta», con `sticpa_pl_nombre_corto_ficha()` o el primer nombre; si no cabe a 375, a dos líneas dentro del botón. 4) «Este grupo no tiene a nadie apuntado este curso todavía. Suele pasar al empezar el curso: avisa a coordinación.» y el enlace «Ya lo he arreglado» solo para coordinación. 5) Pastillas siempre con unidad: «9 chavales» / «1 grupo», y un solo término en las dos secciones (propuesta: «chavales», que es como se habla y como ya dice Mis grupos). 6) Quitar el chip y dejar la nota (o al revés): una sola explicación del gesto, sin aspecto de botón.

**Ficheros:** `pages/single_stic_pasar_lista_marcar.php`, `pages/single_stic_pasar_lista.php`, `pages/single_stic_pasar_lista_grupos.php`, `pages/single_stic_pasar_lista_ficha.php`, `inc/stic-pasar-lista-ui.php`, `js/stic-pasar-lista.js`, `css/pasar-lista.css`

## PL-11 — Con lector de pantalla, la lista no dice el estado de nadie y la barra no avisa de nada

**Tipo:** accesibilidad · **Para:** monitores · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 2/5

**Problema.** Cada fila es un `<button>` con `aria-label` = el nombre. El `aria-label` sustituye a todo el contenido, así que el estado (el círculo y su glifo, `aria-hidden`) y la nota de debajo («Parcial», «3 faltas seguidas») no se anuncian nunca: VoiceOver dice «Lucía Ferrer Albiol, botón» esté marcada o no, y al tocar no cambia nada audible. El aviso de la barra (`<p class="pl-status">`: «Cambios sin guardar», «Sin cobertura…», «Guardado en el móvil…») no lleva `role="status"` ni `aria-live`, y los contadores tampoco.

**Evidencia.** inc/stic-pasar-lista-ui.php:422-431 (`aria-label="' . esc_attr($person['name'])`); js/stic-pasar-lista.js:394-428 (`setState()` cambia `data-state` y no toca ningún atributo ARIA; los únicos `setAttribute('aria-…')` del fichero son de la hoja y de los desplegables); pages/single_stic_pasar_lista_marcar.php:411 y :413-421.

**Propuesta.** Quitar el `aria-label` del botón (el nombre ya es su texto) y añadir un `<span class="screen-reader-text" data-pl-sr-state>` dentro de `.pl-row-body` que el JS rellene en `setState()` con la etiqueta del estado («vino», «no vino», «sin marcar»…; ya están en `sticpa_pl_states()`). `role="status"` en `.pl-status` y `aria-live="polite"` en `.pl-counts`. Aplica igual a la lista de monitores.

**Ficheros:** `inc/stic-pasar-lista-ui.php`, `js/stic-pasar-lista.js`, `pages/single_stic_pasar_lista_marcar.php`, `pages/single_stic_pasar_lista_monitores.php`

## PL-12 — En la portada solo es pulsable el botón blanco del atajo, en el tercio de arriba de la pantalla

**Tipo:** flujo · **Para:** monitores · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 2/5

**Problema.** El atajo del sábado es la tarjeta más grande de la portada (≈200 px de alto) y «la acción de la pantalla», pero solo el botón blanco de dentro es un enlace: 54 px, entre los 218 y 272 px de una pantalla de 812, que con una mano es la zona a la que peor llega el pulgar. Tocar el nombre del grupo, la fecha o el degradado no hace nada.

**Evidencia.** pages/single_stic_pasar_lista.php:135-185 (`.pl-hero` es un `<div>`; el único `<a>` es `.pl-hero-cta`, :181-184); css/pasar-lista.css:1308-1318 y :1366-1378. Captura de la portada a 375: la tarjeta va de 93 a 293 px y el botón «Revisar la lista» de 218 a 272.

**Propuesta.** Patrón de enlace estirado, sin tocar el marcado: `.pl-hero { position: relative; }` y `.pl-hero-cta::after { content: ''; position: absolute; inset: 0; border-radius: inherit; }`. La tarjeta no tiene otros elementos pulsables, así que no hay conflicto. El `:active` con `scale(0.98)` pasa a la tarjeta entera (`.pl-hero:has(.pl-hero-cta:active)`), con su `prefers-reduced-motion`.

**Ficheros:** `css/pasar-lista.css`

## PL-13 — Al volver de una ficha a la lista, se pierde la fecha: el monitor que estaba recuperando el sábado pasado aterriza en el de hoy

**Tipo:** bug · **Para:** monitores · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 3/5

**Problema.** La fila de marcar (y el «Ficha y teléfonos» de la hoja) abre la ficha con `&sesion=…`, pero la flecha de volver de la ficha lleva a `marcar&grupo=…` SIN la sesión, y marcar, sin sesión, elige la de hoy. Si el monitor estaba pasando una lista atrasada —con el desplegable o con «Recuperar»— y mira la ficha de un chaval (lo normal al marcar una falta: «lo siguiente que se quiere es el teléfono de casa», plan 037), al volver ve OTRA fecha: sus marcas parecen haberse borrado (siguen en el borrador de la otra sesión, invisibles) y lo fácil es volver a marcar sobre el día equivocado. Lo mismo después de pasar de ficha con «Anterior / Siguiente», que no lleva la sesión a propósito («si se está leyendo, no se está marcando»), así que la vuelta desde la segunda ficha también cae en hoy.

**Evidencia.** pages/single_stic_pasar_lista_marcar.php:392-394 (la ficha se abre con `&sesion=`); pages/single_stic_pasar_lista_ficha.php:25 (`$sessionId` se lee) y :138-146 (la vuelta se arma sin él); :849-862 (el paginador sin sesión, decidido así). En el arnés, la ficha de c1 abierta con `sesion=s1` pinta `class="pl-back" href="?internalpage=single_stic_pasar_lista_marcar&grupo=g1"`, y la marcar sin `sesion` elige la de hoy (`sticpa_pl_pick_session()`, inc/stic-pasar-lista.php:275).

**Propuesta.** En la vuelta de la ficha, añadir `&sesion=` cuando `$sessionId !== ''` (ficha.php:145). Para que sobreviva al paginador sin reabrir lo decidido (la sesión no debe colgarse de los avisos de las fichas siguientes), pasarla con otro nombre que solo use la vuelta, p. ej. `&vsesion=`, que el paginador arrastra y la flecha traduce a `&sesion=`. Test: «la vuelta de una ficha abierta con sesión vuelve a esa sesión», también tras un salto de paginador.

**Ficheros:** `pages/single_stic_pasar_lista_ficha.php`, `tests/PasarListaRenderTest.php`
