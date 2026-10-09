# 042 · Auditoría — la carcasa y el encaje en todos los dispositivos

> Auditor de la pasada del 09/10/2026. Hallazgos verificados contra el código y con capturas
> del render offline: la barra real (`menu()` de `menu.php`) y la portada real
> (`pages/single_stic_home.php`) de un MONITOR (`^grupo^,^monitor^`) y de COORDINACIÓN
> (`^grupo^,^monitor^,^coordinacion_mic_com^`), con el CSS real (stic-base + custom-style) y el
> JS real de la barra (`js/stic-ui.js`, para que el «Más» de escritorio se reparta como en
> producción), en modo app (`body.sticpa-app-mode`) y en navegador, a 375, 768, 1024, 1280 y
> 1440 px y en los dos temas. Además, el HTML servido de `/ap/?app=1` (sin sesión, 09/10/2026)
> para lo que pone el tema de WordPress en `<head>`. Las capturas vivían en el scratchpad y no
> se conservan: se rehacen con el arnés de la auditoría de familias (`harness/lib.php`) y el
> patrón del README §«Cómo retomar», punto 4. Los meses en inglés son del arnés.

## Resumen

(Se completa al cerrar la auditoría.)

## CAR-1 — Para el equipo, sus herramientas van las últimas: en el móvil «Pasar lista» queda bajo el pliegue y en escritorio «Coordinación» vive dentro de «Más»

**Tipo:** flujo · **Para:** monitores y coordinación · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 4/5 · ⚖️ **Decide el propietario**

**Problema.** El orden del 28/09 (menu.php:10-28) es el mismo en la barra, en el menú del móvil y en la portada: Actividades, Equipo de monitores, Tu perfil. La portada lo justifica así: el equipo «se usa más que los datos personales y menos que apuntarse a algo» (pages/single_stic_home.php, comentario de la sección `stic-home-equipo`). Para un monitor es al revés. Pasa lista todos los sábados, unas 30 veces al curso, y se apunta a algo tres o cuatro veces. Con ese orden, lo que viene a hacer cada semana es lo que más cuesta alcanzar en todos los anchos:
- **Móvil (375×812, en la app):** la tarjeta «Pasar lista» empieza en y = 980 (monitor) y 986 (coordinación), debajo de las cinco tarjetas de Actividades y de la agenda. «Coordinación», en y = 1.085. Sin scroll no hay ni rastro del equipo. Desde el menú son dos toques.
- **Tablet (768):** la barra solo enseña Inicio, Eventos, Inscripciones y Calendario. Las otras **8** secciones de coordinación van dentro de «Más», entre ellas las tres del equipo. En la portada, «Pasar lista» queda en y = 1.260.
- **Escritorio (1024):** «Pasar lista», «Grupos y fichas» y «Coordinación», en «Más».
- **Escritorio (1280-1440):** la barra llega a «Pasar lista». «Grupos y fichas» y «Coordinación» siguen en «Más» y la tarjeta de la portada está en y = 910. Coordinación es justo quien más trabaja en escritorio (COO-5). Además, en el navegador el área lleva encima la cabecera del tema.

**Evidencia.** menu.php:55-73 (el orden de `getSticMenuElements()`), :138-143 (los grupos de `sticpa_nav_layout()`, en el mismo orden), pages/single_stic_home.php:256-269 (`$mainCards` + agenda en `.stic-home-layout`) y :271-287 (`$equipoCards` en la sección siguiente, con el comentario «se usa más que los datos personales y menos que apuntarse a algo»), js/stic-ui.js:270-323 (`layoutNav()` manda a «Más» todo lo que no cabe, empezando por el final). Medido con el arnés (contenido de 1.200 px a partir de 1280, como el tema):

| Ancho | En la barra | En «Más» (coordinación) | «Pasar lista» en la portada |
|---|---|---|---|
| 375 | menú hamburguesa | — | y = 986 (pantalla de 812) |
| 768 | Inicio … Calendario | 8 secciones, el equipo incluido | y = 1.260 |
| 1024 | Inicio … Documentos | Pasar lista, Grupos y fichas, Coordinación, perfil | y = 1.111 |
| 1280 | Inicio … Pasar lista | Grupos y fichas, Coordinación, perfil | y = 910 (pantalla de 900) |

Captura a 375 en claro: el saludo, cinco tarjetas de Actividades, la agenda con tres filas y su botón «Ver calendario»; el bloque «EQUIPO DE MONITORES · COORDINACIÓN» aparece por fin en la segunda pantalla. Captura a 1440: barra con «Inicio · Eventos · Inscripciones · Calendario · Pagos · Documentos · Pasar lista · Más» y el bloque del equipo por debajo del pliegue.
**Prototipo** (moviendo en el DOM el bloque del equipo detrás de «Inicio» y la sección delante de `.stic-home-layout`): a 375, «Pasar lista» pasa a y = 264 y «Coordinación» a y = 364, las dos en la primera pantalla. A 1280, la barra queda «Inicio · Pasar lista · Grupos y fichas · Coordinación · Eventos · Inscripciones · Más», con Calendario, Pagos, Documentos y el perfil en «Más».

**Propuesta.** Opción A (S, recomendada). Para quien es del equipo (`sticpa_equipo_es_del_equipo()`), el bloque del equipo pasa a ir primero en los tres sitios a la vez, así se mantiene la regla de «el mismo orden en todas partes»:
1. En `getSticMenuElements()` (menu.php), meter `$delEquipo` antes de Actividades cuando `$esDelEquipo`.
2. En `sticpa_nav_layout()`, el orden de `$groups` pasa a ser inicio, equipo, dia, cuenta cuando hay claves del equipo.
3. En pages/single_stic_home.php, pintar la sección `stic-home-equipo` antes de `.stic-home-layout`, con `margin-bottom` propio. En el prototipo, «ACTIVIDADES» queda pegado a la tarjeta de Coordinación.
4. Actualizar el comentario de menu.php:10-28 y el de la portada, y el test del orden del menú si lo hay.

Las familias y los miembros sin papel de equipo no cambian. Opción B (si se quiere conservar el orden): dejarlo como está y que la portada del equipo lleve arriba un atajo de una sola fila con «Pasar lista» (y «Coordinación» si toca). Es peor, porque la barra de escritorio sigue escondiendo Coordinación. Se pregunta porque deshace el orden decidido el 28/09.

**Ficheros:** `menu.php`, `pages/single_stic_home.php`, `css/custom-style.css` (margen de la sección), `tests/` (el del orden del menú)

## CAR-2 — Zona segura: el `<head>` no lleva `viewport-fit=cover`, así que `env(safe-area-inset-*)` vale 0, y nada de lo que se pega abajo reserva la tab bar de la app

**Tipo:** bug · **Para:** todos (dentro de MCM App) · **Tamaño:** S · **Riesgo:** medio · **Impacto:** 4/5

**Problema.** El contrato con la app (CONTRATO-APP-WEBVIEW.md §3) da dos cosas por hechas, y ninguna se cumple:
1. «Para que `env(safe-area-inset-*)` no valga siempre 0 hace falta que el `<head>` lleve `viewport-fit=cover` (lo pone el tema de WordPress)». El HTML servido de `/ap/?app=1` lleva `<meta name="viewport" content="width=device-width, initial-scale=1">`, sin `viewport-fit`. Es la línea fija del `header.php` de Astra: va entre `<meta charset>` y `<link rel="profile">`. Sin `cover`, WebKit deja `env(safe-area-inset-*)` a 0, así que las tres reservas que hay en el CSS no reservan nada.
2. «Ya aplicado en la botonera sticky de los formularios». La botonera solo suma `env(safe-area-inset-bottom)`, que además vale 0. La única regla del área que esquiva la tab bar de la app es la de la hoja de estados de Pasar Lista (`body.sticpa-app-mode .pl-sheet`). En todo `custom-style.css` no hay ni una regla `sticpa-app-mode`.

Hoy no se nota porque ningún `sticky` se pega (VEL-1: la hoja de los formularios públicos pone `overflow-x:hidden` en `html` y `body`). En cuanto se arregle VEL-1, la botonera de TODOS los formularios (Mis datos, Mis datos de monitor, inscribirse, subir un documento, contraseña) quedará pegada a `bottom: 0` y, en la app, debajo de la tab bar. Lo mismo pasará con la barra de guardar de Pasar Lista (PL-3) y con la acción pegada que propone FAM-a2. Son tres arreglos sueltos para un mismo problema de la carcasa.

**Evidencia.** HTML servido de `https://comunica.movimientoconsolacion.com/ap/?app=1` (09/10/2026, sin sesión), línea 5: el `meta viewport` de arriba, con `body` en `sticpa-app-mode`. `grep -rn viewport inc/ sinergiacrm-private-area.php`: el plugin no pone ninguno en las páginas del área, solo en sus dos páginas propias (inc/stic-action.php:951 e inc/stic-magic-login.php:416, también sin `cover`). Las tres reservas con `env()`: css/custom-style.css:2964 (botonera de los formularios, con el comentario «en la WebView de la app el botón Guardar quedaba bajo el indicador de inicio»), :4455 (Apariencia en el login, que en la app no se pinta) y css/pasar-lista.css:693 (la hoja). La única reserva de la app es css/pasar-lista.css:712. docs/comunica/CONTRATO-APP-WEBVIEW.md:108-126.
Simulación a 390×844 con el formulario real de inscribirse (arnés de familias), `sticky` funcionando (sin la hoja de VEL-1), una franja fija de 73 px (la tab bar que midió el contrato) y la píldora atrás/adelante: la botonera va de y = 690 a 844 y el botón principal (754-802) queda **entero dentro de la tab bar**. En la captura, «Register» asoma apagado bajo la franja oscura y la píldora tapa el botón secundario. Falta confirmarlo en un iPhone con la app.

**Propuesta.**
1. Un `meta viewport` propio en las páginas del área: `add_action('wp_head', …, 0)` con `sticpa_has_area_shortcode()` que imprima `<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">`. El último `meta viewport` es el que vale en WebKit y en Blink, pero el de Astra va antes de `wp_head`, así que hay que comprobar el orden en el HTML servido. Si no gana, quitar el de Astra con su filtro, si lo tiene, o con un `ob_start` en `template_redirect` acotado al área. Con `cover`, el contenido llega hasta los bordes en horizontal, así que hay que comprobar a 375 y en apaisado que el relleno del tema (30 px por lado) sigue separando el área de la muesca.
2. Dos tokens de la carcasa en custom-style.css §1: `--stic-safe-bottom: env(safe-area-inset-bottom, 0px)` y, bajo `body.sticpa-app-mode`, `--stic-safe-bottom: 73px` (la tab bar del contrato) y `--stic-app-pill: 168px`. Todo lo que se pega abajo los usa en vez de inventarse su reserva: la botonera de los formularios (§28: `bottom: var(--stic-safe-bottom)`, no `padding`, para que no crezca), la barra de guardar de Pasar Lista (PL-3), la acción pegada de FAM-a2 y la hoja (`padding-bottom: calc(var(--stic-app-pill) + 1rem)`, el mismo valor que hoy).
3. Corregir el contrato §3: quitar «lo pone el tema» y «ya aplicado en la botonera», y dejar escritos los dos tokens como la forma de reservar. Al checklist del §6, «¿se pega abajo? usa `--stic-safe-bottom`».
4. Probar en la app (iPhone con muesca y un Android) con un formulario largo: Mis datos de monitor.

**Ficheros:** `sinergiacrm-private-area.php` o `inc/stic-theme.php` (el meta), `css/custom-style.css` (§1 y §28), `css/pasar-lista.css` (hoja y barra de guardar, junto con PL-3), `docs/comunica/CONTRATO-APP-WEBVIEW.md`

## CAR-3 — «Volver» de tres maneras distintas, y ninguna vuelve: en la app, después de usar la flecha de la pantalla, la cápsula «atrás» te devuelve a la ficha de la que acabas de salir

**Tipo:** flujo · **Para:** todos (sobre todo dentro de la app) · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 3/5

**Problema.** El área tiene tres formas de volver, cada una con su aspecto: la píldora blanca «← Eventos / Pagos / Mis inscripciones» dentro del degradado de las fichas (`.stic-rec-back`), la flecha redonda de 44 px sin texto de Pasar Lista y Coordinación (`.pl-back`) y nada en los listados (Eventos, Pagos, Documentos, Calendario…, con `.stic-entry-header`). Las dos primeras son **enlaces a una URL fija**, no un paso atrás, y cada toque mete una entrada nueva en el historial. En la app, la cápsula atrás/adelante y el botón atrás de Android van por ese historial (CONTRATO §4). El recorrido corriente se tuerce así: Eventos → ficha de la convivencia → «← Eventos» → cápsula «atrás»… y aparece otra vez la ficha. Se sale con un segundo «atrás» o se entra en un bucle. Cuando la flecha lleva a un sitio distinto del que se vino, las dos «atrás» llevan a pantallas diferentes. COO-3 lo vio en el Resumen y PL-13 en la ficha del chaval, que pierde la fecha. El contrato, además, dice lo contrario de lo que hay: «El área **no** añade botones de volver propios» (CONTRATO-APP-WEBVIEW.md:134).

**Evidencia.** inc/stic-record-view.php:414-418 (`<a class='stic-rec-back' href=…>` con la URL fija de `$spec['back']`); las URLs, en inc/stic-events.php:1050, inc/stic-payments.php:648 y :972, inc/stic-registrations.php:700. Los `.pl-back` con `href` fijo, en pages/single_stic_pasar_lista_marcar.php:306, pages/single_stic_pasar_lista_grupos.php:65 y :163, pages/single_stic_pasar_lista_resumen.php:82, pages/single_stic_pasar_lista_reuniones.php:78, pages/single_stic_pasar_lista_monitores.php:39, pages/single_stic_pasar_lista_ficha.php:153 y pages/single_stic_mis_grupos.php:113. `grep -rn "history\." js/` sin resultados: nadie vuelve por el historial. Prueba en Chromium con dos páginas (lista → ficha → enlace «Volver» → `history.back()`): se aterriza en la ficha, con 3 entradas de historial donde el usuario cree tener 1. Títulos: «Eventos» a 22,4 px con la barrita de marca (`.stic-entry-header h3`, custom-style.css:1672-1694); «Pasar lista» del árbol de grupos a 18,4 px detrás de la flecha (`.pl-title-code`, pasar-lista.css:230); el de la ficha, a 20,8 px en blanco dentro del degradado (CAR-6). Los dos estilos de «volver»: css/custom-style.css:5307-5323 (píldora de 44 px con texto, sobre el degradado) y css/pasar-lista.css:210-220 (círculo de 44 px, solo icono, sobre el fondo).

**Propuesta.**
1. Una sola regla en js/stic-ui.js para `a.stic-rec-back, a.pl-back` (o un atributo común `data-stic-back`). Al tocar, si `document.referrer` es del mismo origen y su ruta+query coincide con el `href` del enlace (o, más laxo, con su `internalpage`), `e.preventDefault(); history.back();`. Si no coincide (se entró por un enlace de WhatsApp, del correo o desde otra sección), el enlace navega como hoy. Así se conserva todo lo que el destino traía (la fecha de PL-13, el `desde` de COO-3, el scroll de la lista) sin tocar las URLs, y el historial de la app queda limpio. La URL fija sigue de red: sin JS o en un enlace profundo funciona igual que ahora.
2. Que el overlay de carga y `notifyApp('start')` también salten con el `history.back()`: hoy `bindLoadingLinks()` (js/stic-ui.js:115-150) los dispara en el `click`, y con el bfcache la página vuelve al instante.
3. Unificar la forma: la flecha de Pasar Lista con la etiqueta de destino al lado («← Grupos»), como la píldora de las fichas, en tinta (`--gray-700`) y no sobre degradado. Que lo decida quien haga el lote junto con COO-3. Lo mínimo: que las dos digan a dónde llevan (`aria-label` ya lo hace en `.pl-back`; a la vista, no).
4. Corregir CONTRATO-APP-WEBVIEW.md §4: el área sí tiene «volver» propio, y vuelve por el historial cuando viene de ahí.
5. «Dónde estoy»: en las fichas ningún elemento del menú se marca como actual (menu.php:450-452 compara la clave exacta, y la ficha de un evento es `single_stic_events`, no `list_stic_events`). En el arnés, la ficha de evento y el árbol de grupos salen con `.current-menu-item` vacío, así que en escritorio la barra no marca nada y en el móvil el desplegable no resalta ningún mosaico. COO-3 (punto 3) ya propone `sticpa_menu_section_for($page)` para Pasar Lista y Coordinación. Que ese mismo mapa cubra también `single_stic_events` → Eventos, `single_stic_registrations` → Inscripciones, `single_stic_payments`, `single_stic_payment_form` y `single_stic_payment_commitments` → Pagos, `single_stic_documents` → Documentos, `single_stic_sessions` → Calendario y `single_stic_mis_grupos` con `grupo` → Grupos y fichas. Es una sola función y un solo test para las dos auditorías.

**Ficheros:** `js/stic-ui.js`, `inc/stic-record-view.php` (atributo), las páginas de Pasar Lista de la lista de arriba (atributo), `css/pasar-lista.css` (si se unifica la forma), `docs/comunica/CONTRATO-APP-WEBVIEW.md`

## CAR-4 — El «Más» de escritorio mide 140 px por una regla global de stic-base y su cálculo no descuenta el relleno: a 1024 px se sale de la barra y pierde las esquinas

**Tipo:** bug · **Para:** todos (escritorio y tablet) · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 2/5

**Problema.** Dos fallos pequeños que se suman en el mismo botón:
1. css/stic-base.css:1723-1779 pone `button, input[type=submit], … { width:auto; min-width:140px }` a partir de 640 px, **sin acotar** (design.md §8: «Selectores globales sin acotar… rompe el tema del sitio»). Ya hay cinco parches con `min-width: 0 !important` para esquivarla (custom-style.css:468, :4485, :5713, :5939 y pasar-lista.css:100; el de :4483 lo dice con estas palabras). «Más» no lleva parche: mide 140 px con un texto de 32, la mitad del botón vacía.
2. `layoutNav()` reparte con `available = list.clientWidth`, que incluye el relleno de la lista (17,6 px por lado a partir de 768). Calcula que cabe un elemento más de los que caben y empuja «Más» fuera de la caja de contenido. La barra tiene `overflow:hidden` y el radio de la tarjeta, así que lo recorta.

**Evidencia.** js/stic-ui.js:291 (`var available = list.clientWidth;`), css/custom-style.css:915-921 (`.stic-nav-more`, sin `min-width`), :991-998 (el relleno de 1,1rem de la lista a partir de 768, con el comentario «"Más" acaba al final, alineado con "Salir"»). Medido con la barra de coordinación: a 1024 la lista tiene `clientWidth` 984 y `scrollWidth` 1.010, «Más» va de x = 872 a 1.012 y la barra acaba en 1.004 (8 px recortados, sin las esquinas redondeadas de la derecha). A 768 llega a 734, encima del relleno. `getComputedStyle(.stic-nav-more).minWidth` = 140px. Captura a 1024 en claro (antes): la píldora «··· Más» pegada al borde derecho, cortada en recto. Con el prototipo (`.stic-container .stic-nav-more{min-width:0}` y `available` menos el relleno): «Más» mide 91 px, acaba en x = 986, alineado con «Salir», `scrollWidth` = `clientWidth` en 768, 1024 y 1280, y la píldora sale entera.

**Propuesta.**
1. En `layoutNav()`: `var cs = getComputedStyle(list); var available = list.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);`.
2. Acotar la regla de stic-base.css:1771-1779 a lo que la necesita: los botones del motor de formularios (`.stic-form :is(button, input[type="submit"], input[type="button"]), .stic-container :is(a.button, .btn, .stic-button)`). Luego, quitar los cinco `min-width: 0 !important` que solo existían para ganarle. Riesgo: algún botón del motor que hoy se apoya en los 140 px. Capturar el login, Mis datos, inscribirse, subir documento y marcar a 640, 768 y 1280.
3. Si se prefiere no tocar stic-base en este lote, basta con `.stic-container .stic-nav-more { min-width: 0; }` junto al punto 1.

**Ficheros:** `js/stic-ui.js`, `css/stic-base.css`, `css/custom-style.css`, `css/pasar-lista.css`

## CAR-5 — En el móvil, «Salir» está a 6 px de «Menú», sin confirmación, y los dos miden menos de 44 px

**Tipo:** visual · **Para:** todos (móvil; monitores de pie, con una mano) · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 3/5 · ⚖️ **Decide el propietario** (solo el punto 2)

**Problema.** Arriba a la derecha de la barra van dos botones de icono iguales, uno al lado del otro: «Cerrar sesión» (36×36) y la hamburguesa (38×32), con 6 px entre ellos. La hamburguesa es el control más usado del móvil y no llega a los 44 px de design.md §2. «Salir» es un enlace GET a `?logout=true`, sin confirmación. Un toque que se desvía 7 px cierra la sesión, y en la app eso significa volver a pedir el código por correo (la sesión de la app dura un año deslizante y casi nadie se acuerda de cómo entró). La pantalla ya tenía pensado otro sitio para «Salir»: `menu.php` construye `$logoutItem` (un elemento «Salir» con su icono, para el final del menú) y no lo pinta nunca, y la CSS para colocarlo al final del desplegable, separado por una raya, existe (custom-style.css:1118). En escritorio no pasa: «Salir» lleva texto y la hamburguesa no se ve.

**Evidencia.** menu.php:408-411 (`$logoutItem`, sin usar: `grep -n logoutItem` solo da esa línea), :420-421 (el `.stic-logout` de la barra), :425-428 (la hamburguesa); css/custom-style.css:1016-1021 (en móvil, la hamburguesa con `padding: 0.55rem`), :1023 (`.stic-iconbtn { padding: 0.55rem }`), :786 (el `gap: 0.4rem` entre los dos), :1118 (`.stic-nav-logout-item`). Medido a 375 en la portada del monitor: `.stic-logout` en x = 273, 36×36; `.stic-nav-toggle` en x = 315, 38×32. Captura a 375 en claro: los dos cuadrados translúcidos pegados en la esquina superior derecha, el de salir primero.

**Propuesta.**
1. (Sin decisión) Hamburguesa de 44×44 en móvil (`min-width:44px; min-height:44px` en `.stic-nav-toggle` y `.stic-iconbtn` dentro de `@media (max-width:767px)`) y `gap: 0.75rem` entre los dos. Como la barra ya mide 70 px, no crece.
2. (⚖️, porque el comentario de menu.php:416 dice «"Salir" SIEMPRE arriba a la derecha») En móvil, quitar «Salir» de la barra y pintarlo como último elemento del desplegable con `$logoutItem` dentro de `<li class='stic-nav-item stic-nav-logout-item'>`, que ya tiene su CSS. La barra queda con la identidad y un solo botón grande, el del menú. En escritorio no cambia nada. *Recomendación: GO*: salir es lo que menos se hace en la app y lo que más cuesta deshacer.
3. Quitar de menu.php:417-418 el comentario de que «el modo oscuro queda aparcado… De momento todo claro»: es de antes del plan 016 y hoy es falso (el control de Apariencia está en el pie).

**Ficheros:** `menu.php`, `css/custom-style.css`

## CAR-6 — Cada ficha (evento, inscripción, pago) abre con dos bloques de degradado seguidos que no se pueden cerrar: la barra y la cabecera de la ficha

**Tipo:** visual · **Para:** todos · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 3/5 · ⚖️ **Decide el propietario**

**Problema.** design.md §3 lo dice con todas las letras: «**Nunca dos bloques con degradado seguidos** que la persona no pueda cerrar (barra de identidad + tarjeta de bienvenida se comían media pantalla)». docs/design-system.md lo repite en sus anti-patrones («Dos bloques con degradado de marca seguidos que no se puedan cerrar (§11.4)»). La portada lo resolvió con el cierre con memoria. Las fichas no: `.stic-rec-hero`, la cabecera de TODAS las fichas de registro, es un bloque de `var(--grad-brand)` justo debajo de la barra, que también lo es. En la ficha de una convivencia, la del enlace de WhatsApp, a 375 px hay 347 px de degradado en los primeros 374 (nav de 26 a 87 y cabecera de 105 a 374). Con «Inscribirme» son tres degradados en una pantalla (§3: «uno por pantalla, dos como mucho»). La cabecera de la ficha, además, es la única de la carcasa que va en blanco sobre marca: los listados (`.stic-entry-header`) y Pasar Lista (`.pl-head`) llevan el título en tinta sobre el fondo.

**Evidencia.** css/custom-style.css:5286-5302 (`.stic-rec-hero` con `background: var(--grad-brand)` y un brillo radial encima), :5324-5334 (el título con `#fff !important` y `-webkit-text-fill-color: #fff !important`, hex fijo en vez de `--on-brand`), :5340-5341 (chips translúcidos sobre la marca); inc/stic-record-view.php:411-418 (la cabecera con su «volver» dentro). La usan sticpa_event_detail_html (inc/stic-events.php:1049), las dos fichas de pagos (inc/stic-payments.php:647 y :971) y la de inscripción (inc/stic-registrations.php:699). Medido con la ficha de ejemplo de tests/manual/render-events.php debajo de la barra real, a 375 en claro: elementos con degradado en la primera pantalla, `stic-nav` (y = 26) y `stic-rec-hero` (y = 105). Captura (antes): dos tarjetas de marca apiladas, la segunda de 268 px con la píldora «← Eventos», el título en blanco, la fecha y el chip «INSCRIPCIONES ABIERTAS» translúcido. **Prototipo** (CSS inyectado): cabecera sobre `var(--surface)` con borde `--gray-200`, título en `--gray-900`, «← Eventos» como píldora en `--surface-2` y tinta `--gray-700`, chip con su tono de estado (verde suave). Solo queda el degradado de la barra, la ficha se lee igual de bien en claro y en oscuro (en oscuro, el título claro sobre `#1c1d21`) y el único degradado de la pantalla pasa a ser la acción principal.

**Propuesta.** En css/custom-style.css §49, la cabecera de la ficha pasa a superficie neutra: `.stic-rec-hero { background: var(--surface); color: var(--gray-900); border: 1px solid var(--gray-200); box-shadow: var(--shadow-xs); }`, sin el `::after`. El título con `color`/`-webkit-text-fill-color: var(--gray-900)`, el subtítulo y la fecha en `--gray-600`/`--gray-700` con el icono en `--primary-color`, y los chips con su tono normal (`.stic-rec-chip--…`, como en las tarjetas del listado), sin la versión translúcida de :5340-5341 ni :6029-6035. El «volver» sigue el patrón unificado de CAR-3. La marca queda donde design.md §3 la pone (barra, botón primario, cápsula de fecha), y no hace falta cierre con memoria. Capturar las fichas de evento (con y sin cartel), pago devuelto, pago pagado e inscripción a 375 y 1280 en los dos temas. Se pregunta porque cambia la cara de todas las fichas. *Recomendación: GO*: es la regla de la casa, y la portada ya se arregló por lo mismo.

**Ficheros:** `css/custom-style.css` (§49, §56 y §59 si tocan la cabecera)

## CAR-7 — Dentro de la app, la mitad de lo que se toca da el destello gris de la web: la regla del toque está repartida en diez sitios y falta en la barra, en las filas de grupos y en los «volver»

**Tipo:** visual · **Para:** todos (dentro de la app); monitores los primeros · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 2/5

**Problema.** design.md §8: «El feedback táctil real es `:active` con `scale(0.97)`, y `-webkit-tap-highlight-color: transparent` en todo lo pulsable». Hoy se pone a mano, componente a componente (diez declaraciones sueltas en custom-style.css y una en pasar-lista.css). Lo que se ha quedado fuera da el destello por defecto de WebKit (un rectángulo gris que delata «página web») y además no tiene `:active`, así que el destello es su única respuesta al dedo: los mosaicos del menú, «Salir», el avatar, los dos «volver», «Inscribirme» de la ficha y, en Pasar Lista, las filas del árbol de grupos, el atajo de tu grupo y el selector de sesión, que es justo lo que un monitor toca cada sábado.

**Evidencia.** `grep -n tap-highlight css/*.css`: custom-style.css:813, :3006, :3418, :3549, :3680, :3700, :4947, :5175, :5729 y pasar-lista.css:350. Medido en Chromium (`getComputedStyle(el).webkitTapHighlightColor`) con la barra real, la portada, la ficha de evento y el árbol de grupos de Pasar Lista a 375: transparentes solo `.stic-nav-toggle`, `.stic-hero-close`, `.stic-dash-card`, `.stic-agenda-link` y `.stic-agenda-cta`. Con el valor por defecto (`rgba(0,0,0,.18)` en Chromium; en iOS es más oscuro): `.stic-nav-link`, `.stic-iconbtn` (Salir), `.stic-avatar-link`, `.stic-rec-back`, `.stic-rec-btn`, `.stic-rec-fact-a`, `.pl-back`, `.pl-group`, `.pl-mine-inner`, `.pl-session-pick`, `.pl-orphans` y `.pl-footnote-link`. Sin ninguna regla `:active` (grep en los dos ficheros): `.pl-group`, `.pl-back`, `.pl-mine-inner`, `.pl-session-pick`, `.stic-rec-back` y `.stic-iconbtn`.

**Propuesta.**
1. Una sola regla en custom-style.css (sección nueva «Toque»): `:is(.stic-container, .stic-auth-shell) :is(a, button, summary, label, [role="button"]) { -webkit-tap-highlight-color: transparent; }`. Borrar las diez declaraciones sueltas.
2. `:active` para los que no tienen nada, dentro de `@media (hover: none)`: filas (`.pl-group`, `.pl-mine-inner`, `.pl-session-pick`) con `background: var(--gray-50)`, el patrón de `.pl-row:active` (pasar-lista.css:371); botones de icono y «volver» (`.pl-back`, `.stic-rec-back`, `.stic-iconbtn`, `.stic-nav-toggle`) con `transform: scale(0.94)`. Solo `transform` y `background`, sin `blur` (design.md §2.4).
3. Al checklist de CONTRATO-APP-WEBVIEW.md §6: «¿lo nuevo pulsable tiene `:active`? (el destello ya lo quita la regla común)».

**Ficheros:** `css/custom-style.css`, `css/pasar-lista.css`, `docs/comunica/CONTRATO-APP-WEBVIEW.md`
