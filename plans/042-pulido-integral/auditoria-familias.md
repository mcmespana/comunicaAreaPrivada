# 042 · Auditoría — familias y participantes

> Auditor de la pasada del 09/10/2026. Hallazgos verificados contra el código y con capturas
> del render offline (las capturas vivían en el scratchpad de la sesión y no se conservan;
> se rehacen con los arneses de `tests/manual/` y el patrón de la §«Cómo retomar» del README).

## Resumen

Lo de las familias está muy trabajado: el acceso, Pagos (plan 041) y las fichas de registro están finos y se ven bien en claro y en oscuro. Fallan las costuras entre pantallas. Lo más grave es un fallo de lógica, no de diseño: una madre con dos o más hijos que entra por un enlace a un evento (WhatsApp, correo con destino, la app) queda «viendo a» sí misma con el menú de participante, y «Inscribirme» la apunta a ELLA (comprobado en el arnés y en el código). Le siguen tres cosas que se notan en el móvil. En la ficha de un evento con información de la web, «Inscribirme» queda a 2.183 px (2,7 pantallas). La portada tiene scroll horizontal a 360, 375, 390 y 414 px en cuanto la agenda trae una sesión semanal (le falta un min-width:0). Y la botonera de todos los formularios nunca ha aplicado su flex por especificidad (a 375 px ocupa 154 px fija abajo, con «Atrás» encima). El resto es pulido de copy y jerarquía: en la vista de familia las pantallas hablan en primera persona («Mis inscripciones», «ya estás inscrito», en masculino) cuando son del hijo; «Ver detalle» repite el enlace de la tarjeta, cosa que design.md §6.1 prohíbe; y el formulario de subir documento enseña campos del CRM a las familias. Arneses reutilizables en audit/familias/harness/ (lib.php extrae del fichero principal las funciones que pintan; capturas con tools/shot2.js, que espera a que acaben las animaciones).

## FAM-a1 — Familia con 2+ hijos que entra por enlace profundo: el área la trata como «participante» de sí misma e «Inscribirme» apunta a la madre

**Tipo:** bug · **Para:** familias · **Tamaño:** M · **Riesgo:** medio · **Impacto:** 5/5

**Problema.** Una madre que solo es familiar, con dos o más hijos, abre por primera vez el área desde un enlace a un evento (página pública → «Entrar al área privada», correo con destino de EV-8, la app). sticpa_bootstrap_family() solo elige solo cuando hay un hijo y no pone scp_tutor_is_user. Con eso, sticpa_viewing_context() calcula viendoSe=false y la audiencia sale 'participante', pero scp_user_id sigue siendo el de la madre. Lo que ve: el menú entero, «Datos de Marta», el saludo «Aquí ves los datos de Marta y le inscribes a las actividades» y el selector de la barra con su propio nombre, no «Yo misma». Si el evento no restringe el perfil, el formulario de inscripción manda contact_ida = la madre y el handler la ata a ella. Si restringe, le sale «tu ficha no tiene ese perfil». Las dos salidas están mal y es el camino más corriente de una familia nueva. docs/ACCESO.md §4.b lo describe más suave de lo que es («lo ve como ella misma»).

**Evidencia.** inc/stic-family.php:546 (solo el caso de 1 hijo fija el participante), inc/stic-family.php:396 y :405 (viendoSe=false → 'participante'), pages/single_stic_registrations.php:273-275 (contact_ida = $_SESSION['scp_user_id']), inc/stic-action.php:550 (el handler ata la inscripción a scp_user_id), docs/ACCESO.md:177. Simulación: audit/familias/checks/deep-link-varios-hijos.php → «scp_user_id = f1 · scp_tutor_is_user no puesto · audiencia = participante · secciones: list_stic_events, list_stic_registrations…». Capturas: audit/familias/shots/home-deep-375-light_p0.png y audit/familias/shots2/inscribirse-deep-375-light.png (el formulario «Te inscribes a…» con el campo oculto value='f1').

**Propuesta.** 1) En sugar_crm_portal_index() (sinergiacrm-private-area.php), después de sticpa_bootstrap_family(): si sticpa_es_familiar() && !sticpa_familiar_es_miembro() && count(sticpa_available_profiles()) > 1 && !isset($_SESSION['scp_tutor_is_user']) y la página pedida no es la de selección, pintar single_stic_profile_selection en vez de la página pedida y guardar el destino con sticpa_login_destination_args($_GET). 2) La pantalla de selección añade ese destino a los enlaces de cada tarjeta (sticpa_url_with_destination, inc/stic-magic-login.php:97), y prefix_admin_single_stic_profile_selection (inc/stic-action.php:65-75) redirige a él en vez de a la home cuando viene validado (la misma lista blanca que el login: internalpage, action, id, from). 3) Si el destino es un evento, el título pasa a «¿A quién quieres apuntar?». 4) Test en tests/FamilyContextTest.php para «enlace profundo con varios hijos» (hoy solo está el de un hijo, testConEnlaceProfundoTambienSeMontaLaSesionDeFamilia). 5) Corregir docs/ACCESO.md §4.b.

**Ficheros:** `sinergiacrm-private-area.php`, `inc/stic-family.php`, `inc/stic-action.php`, `pages/single_stic_profile_selection.php`, `inc/stic-magic-login.php`, `tests/FamilyContextTest.php`, `docs/ACCESO.md`

## FAM-a2 — Ficha del evento: «Inscribirme» queda 2,7 pantallas por debajo cuando el evento trae la información de la web

**Tipo:** flujo · **Para:** familias · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 4/5

**Problema.** La ficha pinta, por este orden, cabecera, cartel, datos clave, «Toda la información» (el cuerpo de la web), documentos y, al final, la acción. Con el cartel y el cuerpo de la web, que es justo lo que las delegaciones preparan para las convivencias, el botón queda fuera de la vista a 375 px y también a 1280 px. Quien llega desde WhatsApp para apuntar a su hijo tiene que hacer scroll hasta abajo sin ninguna pista de que el botón exista.

**Evidencia.** inc/stic-record-view.php:578-590 (la fila de la acción va después de las secciones); inc/stic-events.php:1022-1027 (la acción principal «Inscribirme en esta actividad»). Medido con la ficha de ejemplo de tests/manual/render-events.php y un cartel de 800×1131: el botón empieza a 2.183 px con 812 de alto de pantalla a 375, y a 1.411 px a 1280. Capturas: audit/familias/shots/ev-detalle-web-375-light.png y audit/familias/shots/ev-detalle-web-1280-light.png (la primera pantalla sin el botón). Prototipo: con position:sticky; bottom:0 en .stic-rec-cta-row (≤640 px) el botón se ve ya en la primera pantalla.

**Propuesta.** Añadir a sticpa_record_detail_html() una opción 'sticky_cta' => true que ponga la clase stic-rec-detail--cta-sticky. En una sección nueva al final de css/custom-style.css: @media (max-width:640px) { .stic-rec-detail--cta-sticky .stic-rec-cta-row { position:sticky; bottom:0; z-index:5; background:var(--surface-2); padding:.6rem 0 calc(.6rem + env(safe-area-inset-bottom,0px)); } } (el mismo patrón que la botonera de los formularios, §28), con la cta_note encima del botón o escondida cuando está pegada. En escritorio, repetir solo la acción principal justo después de los datos clave cuando hay cartel. sticpa_event_detail_html() la pide solo cuando hay una acción primary (inscribirse). Capturar a 375 y 1280 en claro y oscuro.

**Ficheros:** `inc/stic-record-view.php`, `inc/stic-events.php`, `css/custom-style.css`

## FAM-a3 — La portada tiene scroll horizontal en todos los móviles cuando la agenda trae una sesión semanal

**Tipo:** bug · **Para:** todos · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 4/5

**Problema.** En móvil, .stic-home-layout es un grid de grid-template-columns:1fr, y .stic-home-aside no lleva min-width:0. El subtítulo de la agenda (.stic-agenda-sub) va con white-space:nowrap para cortarse con «…», pero su ancho mínimo agranda la columna. Con un nombre de evento real («MIC | Sesiones semanales 2026-2027 · CS»), la columna crece a 404 px y arrastra las tarjetas de la portada fuera de la pantalla. Afecta a toda familia o miembro con sesiones semanales, que es lo normal en MIC y COM. Dentro de la app se traduce en scroll lateral o en contenido cortado.

**Evidencia.** css/custom-style.css:4632-4636 (grid 1fr), :4647 (solo .stic-home-main lleva min-width:0), :4721-4728 (nowrap). Medido con el relleno real del tema (30 px de Astra + Elementor y el margen de −18 px de custom-style.css:1014): scrollWidth 416 a 360, 375, 390 y 414 px. Inyectando .stic-home-aside{min-width:0} queda en 375. Captura: audit/familias/shots/home-hijo-375-light.png (las tarjetas «Inscripciones» y «Pagos» salen por la derecha). También sale con un miembro normal: audit/familias/home-miembro.html.

**Propuesta.** En la §46 de css/custom-style.css, cambiar a grid-template-columns: minmax(0, 1fr) o añadir .stic-home-aside { min-width: 0; }. Es una línea. Comprobar a 340, 375, 390 y 1280 en los dos temas con audit/familias/harness/render-home-familia.php hijo|miembro.

**Ficheros:** `css/custom-style.css`

## FAM-a4 — En la vista de familia, las pantallas hablan en primera persona (y en masculino) cuando son del hijo

**Tipo:** copy · **Para:** familias · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 3/5

**Problema.** Cuando Marta ve a Lucía, el menú ya dice «Datos de Lucía» (sticpa_datos_de_label), pero el resto sigue hablándole a Marta como si fuera ella la inscrita: título «Mis inscripciones» y «Mis documentos», «Te inscribes a…», «Listo: ya estás inscrito.», «Pago hecho. Ya estás inscrito.», «Ya estás inscrito», «Ya tienes una inscripción para esta actividad», «Todavía no tienes ninguna inscripción». Con dos hijos no dice a cuál se ha apuntado. Y «inscrito» en masculino incumple design.md §1 (Género: reformula antes de resolver).

**Evidencia.** pages/list_stic_registrations.php:30, pages/list_stic_documents.php:20, pages/single_stic_registrations.php:382 y :433, inc/stic-registrations.php:311 y :1611-1623, inc/stic-events.php:892 y :1018, inc/stic-documents.php:145. Precedente bueno: menu.php:96-108 (sticpa_datos_de_label). Captura: audit/familias/shots2/inscribirse-375-light.png (el kicker «TE INSCRIBES A» sin nombre; solo el selector de la barra dice que es Lucía).

**Propuesta.** Un ayudante sticpa_viendo_a_nombre() en inc/stic-family.php que devuelva el nombre de pila del participante cuando la audiencia es 'participante' y '' en otro caso, y variantes de copy con él: «Inscripciones de Lucía» / «Documentos de Lucía» como título, «Inscribes a Lucía en» como kicker, «Listo: Lucía ya tiene su plaza.» o, sin familia, «Listo: ya tienes tu plaza.», «Lucía ya está apuntada» o «Ya tienes tu plaza» en el bloqueo, y «Todavía no hay inscripciones de Lucía». Fuera todos los «inscrito/inscrita»: reformular con «plaza» o «apuntarse». Todo con __().

**Ficheros:** `inc/stic-family.php`, `pages/list_stic_registrations.php`, `pages/list_stic_documents.php`, `pages/single_stic_registrations.php`, `inc/stic-registrations.php`, `inc/stic-events.php`, `inc/stic-documents.php`

## FAM-a5 — Formulario de inscripción: fecha numérica, «Atrás» que no vuelve al evento y un tercer degradado metido en la tarjeta

**Tipo:** flujo · **Para:** familias · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 3/5

**Problema.** 1) La tarjeta «Te inscribes a» enseña «31-10-2026 – 02-11-2026», justo el formato que design.md §6.1 prohíbe; el formulario de modificar ya usa sticpa_record_date_line(). 2) El botón «Atrás» (msgid inglés 'Back', traducido a «Atrás», mientras el resto del área dice «Volver») lleva a Mis inscripciones y no a la ficha del evento de la que se viene. 3) El de guardar sale de 'Register' («Inscribirse»). 4) La tarjeta del evento es un bloque de degradado dentro de la tarjeta blanca del formulario: con la barra y el botón principal son tres degradados en una pantalla (design.md §3 dice uno, dos como mucho) y es una tarjeta dentro de otra (§8).

**Evidencia.** pages/single_stic_registrations.php:371-374 (formatValue 'date' → d-m-Y, inc/stic-formatter.php:26-28), :217-222 ('Back'/'Register'), :220 (vuelta a list_stic_registrations), :382; css/custom-style.css:2175-2183 (.stic-event-card con var(--grad-brand)). Capturas: audit/familias/shots2/inscribirse-375-light.png, audit/familias/shots2/inscribirse-1280-light.png y audit/familias/shots2/inscribirse-375-dark.png.

**Propuesta.** En el alta, $dateLine = sticpa_record_date_line(start_ts, end_ts) a partir de sticpa_event_view_model($eventNvl), igual que en el modificar. Botones: __('Volver') y __('Apuntarme') (o «Apuntar a Lucía», con FAM-a4), con «Volver» a ?internalpage=single_stic_events&action=detail&id=$eventId cuando $fromEvent. Pintar la tarjeta del evento como un resumen neutro (fondo var(--surface-2), cápsula de fecha de .stic-rec-badge, sin degradado), de modo que el único degradado de la pantalla sea el botón.

**Ficheros:** `pages/single_stic_registrations.php`, `inc/stic-registrations.php`, `css/custom-style.css`

## FAM-a6 — La botonera de TODOS los formularios nunca ha aplicado su flex: dos botones a lo ancho, «Atrás» encima y 154 px fija abajo

**Tipo:** bug · **Para:** todos · **Tamaño:** S · **Riesgo:** medio · **Impacto:** 3/5

**Problema.** La §23.a de custom-style.css quiere los botones en fila y a la derecha en escritorio, y apilados con «Atrás» al final en móvil. Pero .stic-send (especificidad 0,1,0) pierde contra .stic-form li {display:block} (stic-base.css:78) y, a partir de 640 px, contra .stic-form-two-col li {display:inline-block} (stic-base.css:638). Con eso, gap, justify-content y order no hacen nada. Resultado: a 1280 px, dos botones de 1200 px apilados; a 375 px, la botonera pegajosa ocupa 154 px (un 19 % de una pantalla de 812, más en un iPhone SE) con «Atrás» por encima del botón principal. Pasa en Inscribirme, Modificar inscripción, Subir documento y Usuario y contraseña. design.md §8: «Adivinar la especificidad. Mide en el navegador».

**Evidencia.** css/custom-style.css:2076-2091 y :2124-2129; css/stic-base.css:78-83 y :638-642 (@media min-width:640px); inc/stic-formController.php:186 (todo formulario lleva stic-form-two-col por defecto). Medido en audit/familias/inscribirse.html: li.stic-send display=block a 375 e inline-block a 1280; botones 1200 px; barra de 154 px. Con .stic-form li.stic-send {display:flex…} la barra baja a 106 px y en escritorio los botones quedan en 148 px alineados a la derecha. Capturas: audit/familias/shots2/inscribirse-1280-light.png y audit/familias/shots2/inscribirse-375-light.png.

**Propuesta.** Subir los selectores de la §23.a a .stic-form li.stic-send (especificidad 0,2,1, gana a los dos de stic-base) sin !important nuevo. En ≤640 px, en vez de apilar, poner «Volver» compacto y el principal con flex:1 en la misma fila (flex-wrap:nowrap), que deja la barra en unos 106 px. Capturar Mis datos, la inscripción, la contraseña y subir documento a 375 y 1280 en los dos temas: lo tocan todos los formularios del motor.

**Ficheros:** `css/custom-style.css`

## FAM-a7 — Eventos: cada tarjeta lleva «Ver detalle», que hace lo mismo que tocar la tarjeta (design.md §6.1 lo prohíbe)

**Tipo:** visual · **Para:** familias · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 3/5

**Problema.** La tarjeta entera ya enlaza a la ficha (stic-rec-main) y además lleva una barra con «Ver detalle» al mismo sitio. design.md §6.1 lo decidió «mirando capturas, para no volver a discutirlo»: una tarjeta que enlaza a su ficha no lleva un botón que haga lo mismo; la barra va solo si lleva a OTRO sitio. En los eventos cerrados o aún no abiertos la barra es solo «Ver detalle»: 61 px de 154 que no aportan nada. El origen es el plan 025 (julio), anterior a esa regla.

**Evidencia.** inc/stic-events.php:903 (acción 'Ver detalle' → $detailUrl) y :919 (la misma URL en 'url'); inc/stic-record-view.php:313-314 (la tarjeta es un <a>). Medido a 375: barra de 61 px en cada tarjeta; «Encuentro de monitores» 154 px, «Campamento de verano» 190 px. tests/EventAudienceTest.php:774 da por buena la presencia de 'Ver detalle'. Captura: audit/familias/shots/events-375-light_p0.png.

**Propuesta.** En sticpa_events_cards(), quitar la acción 'Ver detalle' y dejar solo «Inscribirme» o «Mi inscripción» cuando toque. Sin acciones, sin barra. Cambiar el assert de tests/EventAudienceTest.php:774 por assertStringNotContainsString('Ver detalle') y por que la tarjeta enlace a la ficha.

**Ficheros:** `inc/stic-events.php`, `tests/EventAudienceTest.php`

## FAM-a8 — Cambiar de hijo te manda SIEMPRE a la portada: apuntar a dos hermanos al mismo evento cuesta 7 toques

**Tipo:** flujo · **Para:** familias · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 3/5 · ⚖️ **Decide el propietario**

**Problema.** Marta apunta a Lucía a la convivencia y quiere apuntar también a Martín. Hoy: selector (1) → Martín (2) → portada → Eventos (3) → tarjeta del evento (4) → scroll → Inscribirme (5) → forma de pago (6) → Inscribirse (7). El handler redirige siempre a la portada y lo justifica con «acabas de decir quiero ver a X». Argumento nuevo: la ficha de un evento no es de nadie, así que quedarse en ella con el otro hijo es seguro. Las fichas personales (inscripción, pago) sí tienen que irse a la portada, porque la comprobación de propiedad las negaría.

**Evidencia.** inc/stic-action.php:65-75 («SIEMPRE a la home»); menu.php:205-216 (el selector no pasa la página actual); pages/single_stic_profile_selection.php:84-85.

**Propuesta.** Que el selector de la barra mande la página actual (internalpage y, si es single_stic_events, su id) y que el handler vuelva a ella solo si está en una lista blanca de páginas no personales: list_stic_events, single_stic_events&action=detail, single_stic_activities_calendar. En cualquier otro caso, a la portada como ahora. Se reutiliza sticpa_login_destination_args() para validar. Con FAM-a2 (botón visible) el segundo hermano cuesta 5 toques.

**Ficheros:** `inc/stic-action.php`, `menu.php`, `inc/stic-magic-login.php`, `tests/FamilyContextTest.php`

## FAM-a9 — «Subir un documento» enseña a la familia campos del CRM (Estado, Categoría, Enlace al documento compartido) y lleva status_id dos veces

**Tipo:** copy · **Para:** familias · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 3/5

**Problema.** Para subir, por ejemplo, la autorización firmada de una convivencia, la familia ve seis campos: Nombre, archivo, «Estado» (desplegable del CRM: Activo, Borrador, Caducado…), «Enlace al documento compartido» (https://…), «Categoría» y Descripción. Es un volcado del módulo Documents. Además, en el alta status_id va dos veces: un oculto 'Active' y un select visible con el mismo name e id, así que manda el select y una familia puede dejar su documento en «Borrador» (luego sale con ese chip en su lista). El título es 'Document' y el campo de archivo 'Choose a file', msgids en inglés que el .po traduce de usted («Seleccione un fichero»); el área tutea.

**Evidencia.** pages/single_stic_documents.php:128-132 (status_id oculto 'Active'), :155-162 (status_id, stic_shared_document_link_c y category_id siempre visibles), :23 ('Document'), :124 ('Choose a file'); languages/sticpa-es_ES.po → «Seleccione un fichero». Render: audit/familias/subir-doc.html, con dos name='status_id' e id duplicado. Captura: audit/familias/shots2/subir-doc-375-light_p0.png. Las etiquetas y opciones del arnés son las de serie de SuiteCRM, sin verificar en Comunica.

**Propuesta.** En el alta y la edición, quitar del $fieldList el status_id visible, stic_shared_document_link_c y category_id (el oculto 'Active' se queda). Título __('Subir un documento') y etiqueta __('El archivo'). Opcional, para cuando lo haya: un 'hint' que diga qué se espera («la autorización firmada, una foto del DNI…»). Hay que probar la subida de verdad (es SEC-10, todavía pendiente).

**Ficheros:** `pages/single_stic_documents.php`

## FAM-a10 — Eventos e Inscripciones se solapan, y el parche fue un aviso; con cuatro secciones bastaría

**Tipo:** flujo · **Para:** familias · **Tamaño:** M · **Riesgo:** medio · **Impacto:** 3/5 · ⚖️ **Decide el propietario**

**Problema.** La familia tiene Eventos, Inscripciones, Calendario, Pagos y Documentos. Desde el 30/09, Eventos ya tiene «Para apuntarte» y «Te has apuntado» (con «Mi inscripción»), y la agenda de la portada y el Calendario también enseñan lo apuntado. El 08/10 se puso en Inscripciones una caja azul («Aquí están las actividades… mira la sección Eventos») porque la gente se liaba. Es el síntoma: dos secciones para la misma pregunta. Los nombres tampoco coinciden: el menú dice «Inscripciones» y el título y la vuelta de la ficha, «Mis inscripciones».

**Evidencia.** inc/stic-events.php:838-849 (los dos bloques en Eventos); inc/stic-registrations.php:396-402 (la caja del 08/10); menu.php:56-60; pages/list_stic_registrations.php:30; inc/stic-registrations.php:700. Captura: audit/familias/shots2/registrations-375-light_p0.png (la caja encima de la lista).

**Propuesta.** Opción A (S): renombrar en el menú y la portada «Eventos» → «Apuntarse» y «Inscripciones» → «Mis inscripciones» (o «Inscripciones de Lucía», con FAM-a4) y quitar la caja. Opción B (M): una sola sección «Actividades» con tres bloques: «Para apuntarte», «Te has apuntado» y «Ya pasadas», plegado. Inscripciones queda como ficha y como lista de lo pasado, y la portada de una familia pasa de 5 tarjetas a 4 (2×2, sin la ancha suelta). Cualquiera de las dos toca menu.php, la portada y sticpa_section_meta().

**Ficheros:** `menu.php`, `pages/single_stic_home.php`, `sinergiacrm-private-area.php`, `inc/stic-events.php`, `inc/stic-registrations.php`, `pages/list_stic_events.php`, `pages/list_stic_registrations.php`

## FAM-a11 — Selección de participante y portada del familiar: nombres en formato CRM, etiquetas que sobran y un selector de 31 px

**Tipo:** visual · **Para:** familias · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 2/5

**Problema.** 1) Las tarjetas de «¿A quién quieres ver?» y «A quién tienes a tu cargo» ponen «Messeguer Villarroya, Lucía» (apellidos primero, como el CRM), mientras la barra dice «Lucía Messeguer» (sticpa_short_name). 2) Cada hijo lleva la etiqueta «PARTICIPANTE», que no distingue nada (design.md §6.4: lo que lleva todo el mundo no distingue). 3) La entradilla son 4 líneas a 375 que cuentan lo que ya dice la pantalla. 4) La misma acción se pinta de dos formas: avatar con inicial y degradado en la selección, icono gris en la portada. 5) El selector de la barra, que es EL control de una familia, mide 172×31 px (design.md §2 pide 44). 6) En la portada del familiar, «ACTIVIDADES» encabeza un único acceso: «Pagos».

**Evidencia.** pages/single_stic_profile_selection.php:103 (entradilla), :118 (name crudo), :119 ('Participante'); pages/single_stic_home.php:132-133 y :258; menu.php:202 (short_name en la barra). Medido a 375: .stic-part-switch-btn 172×31. Capturas: audit/familias/shots/home-seleccion-375-light.png y audit/familias/shots/home-solofamiliar-375-light.png.

**Propuesta.** Usar sticpa_short_name() en los dos sitios; quitar la etiqueta «Participante» y dejar solo «Viéndolo ahora» y «Yo»; entradilla en una línea («Elige a quién quieres ver. Puedes cambiar desde arriba cuando quieras.») o ninguna; en la portada del familiar, las mismas tarjetas de avatar que en la selección (.stic-profile-card) en vez de record_list con icono; min-height de 44 px en .stic-part-switch-btn en móvil, ajustando el padding de la barra para no crecerla; y en la portada del familiar, la etiqueta «Tus pagos» o sin etiqueta cuando el bloque solo tiene Pagos.

**Ficheros:** `pages/single_stic_profile_selection.php`, `pages/single_stic_home.php`, `css/custom-style.css`

## FAM-a12 — Fichas de pago y de inscripción: un recibo devuelto da dos instrucciones contrarias y aparece un dato de jerga

**Tipo:** copy · **Para:** familias · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 2/5

**Problema.** 1) En la ficha de un pago devuelto, el aviso rojo dice «Ponte en contacto con tu delegación para volver a intentarlo» y justo debajo está el botón «Pagar 120,00 € con tarjeta» (cuando se puede pagar). La ficha de la inscripción, en el mismo estado, dice lo correcto: «Puedes pagarlo con tarjeta o hablar con tu delegación». 2) La ficha de la inscripción enseña «TIPO DE PARTICIPACIÓN: Participante/Asistente». El área siempre lo guarda como 'attendant', así que es el mismo dato para todas las familias y suena a CRM.

**Evidencia.** inc/stic-payments.php:590 (texto) frente a :643-645 (botón de pagar); inc/stic-pay-card.php:530 (el texto bueno); inc/stic-registrations.php:557-560 (dato «Tipo de participación»); pages/single_stic_registrations.php:288-292 (participation_type oculto 'attendant'). Capturas: audit/familias/shots2/payments-375-light_p1.png (aviso del devuelto) y audit/familias/shots2/registrations-375-light_p1.png («Tipo de participación»).

**Propuesta.** En sticpa_payment_detail_html(), si $pay['pagable'], poner el texto de inc/stic-pay-card.php:530; si no, el actual. Quitar el dato «Tipo de participación», o pintarlo solo cuando no sea 'attendant'.

**Ficheros:** `inc/stic-payments.php`, `inc/stic-registrations.php`

## FAM-a13 — Portada: 11 elementos con degradado, «Ver calendario» como único botón de marca y la agenda diciendo dos veces «abierto»

**Tipo:** visual · **Para:** todos · **Tamaño:** S · **Riesgo:** bajo · **Impacto:** 2/5 · ⚖️ **Decide el propietario**

**Problema.** A 375 px, en la portada de una familia hay 11 elementos con el degradado de marca: barra, saludo, 7 iconos de acceso, el icono de la agenda y el botón «Ver calendario». design.md §3 pide uno por pantalla, dos como mucho, y el §8 rechaza el «degradado en todas partes». El único botón de marca de la portada lleva al calendario, que no es a lo que viene nadie. En la agenda, cada evento abierto pone «Abierto a inscripción» y además la píldora «INSCRÍBETE».

**Evidencia.** Medido en audit/familias/home-hijo.html: nav 351×70, saludo 351×108, 7 .stic-dash-icon, .stic-agenda-ico y .stic-agenda-cta 375×42 con background-image de degradado. css/custom-style.css:1247-1258 (.stic-dash-icon con var(--grad-brand) y --grad-brand-rev); inc/stic-calendar.php:719 (subtítulo = etiqueta de la paleta) y :792-794 (píldora). Captura: audit/familias/shots/home-hijo-375-light_p0.png.

**Propuesta.** Iconos de acceso y de la agenda en tinta suave (fondo var(--primary-50) y trazo var(--primary-color), como .stic-rec-fact-ico); «Ver calendario» como .stic-rec-btn--ghost; en la agenda, el subtítulo de un evento abierto con el lugar (o nada) y solo la píldora «Inscríbete». Quedan dos bloques de marca, barra y saludo, y el saludo ya se puede cerrar.

**Ficheros:** `css/custom-style.css`, `inc/stic-calendar.php`

## FAM-a14 — Nueva funcionalidad (go/no-go): en la ficha del evento, «¿A quién apuntas?» con el estado de cada hijo

**Tipo:** flujo · **Para:** familias · **Tamaño:** L · **Riesgo:** medio · **Impacto:** 4/5 · ⚖️ **Decide el propietario**

**Problema.** Para una familia con varios hijos en el MCM, apuntar a dos a la misma convivencia exige cambiar de participante entre una inscripción y otra, y la ficha solo dice si está apuntado el hijo activo. La pregunta real es «¿a cuáles de mis hijos he apuntado ya?», y hoy no hay ninguna pantalla que la conteste.

**Evidencia.** pages/single_stic_events.php:50-55 (prefix_user_has_active_registration solo del scp_user_id activo); inc/stic-calendar.php:207-212 (la caché de inscripciones es por participante); inc/stic-family.php (sticpa_available_profiles ya tiene los hijos en sesión).

**Propuesta.** En sticpa_event_detail_html(), cuando la audiencia es de familia y hay 2+ participantes, un bloque «¿A quién apuntas?» con una fila por hijo (avatar y nombre): «Apuntada ✓ · Ver» si ya lo está, o el botón «Apuntar» si no. El botón cambia de participante por el handler con destino al formulario de inscripción del evento (reutiliza FAM-a1 y FAM-a8). Para saber el estado de cada hijo, una consulta de inscripciones por hijo en una sola tanda paralela (sticpa_pl_prime), o la caché del calendario de cada uno si está caliente. Hay que subir el tope de tests/CosteLlamadasAreaTest.php en la misma PR. La audiencia (curso, perfil) se evalúa por hijo, así que un hijo puede salir «no es para su curso».

**Ficheros:** `inc/stic-events.php`, `pages/single_stic_events.php`, `inc/stic-family.php`, `inc/stic-action.php`, `css/custom-style.css`, `tests/CosteLlamadasAreaTest.php`
