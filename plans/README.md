# Implementation Plans — auditoría `/improve`

Generado por la skill **improve** (shadcn/improve) el **2026-07-19**, contra el commit
`bc3c436`. Auditoría en paralelo (4 subagentes read-only) sobre el código del plugin
(NO las librerías vendorizadas: fullcalendar, jQuery, DataTables, Selectize, iban.js).

> **Segunda pasada (2026-07-19, commit `c2d7cff`)**: auditoría específica de **UI/UX,
> accesibilidad, dark mode, animaciones y rendimiento frontend** (3 subagentes; la seguridad quedó
> EXCLUIDA a petición del mantenedor — sigue cubierta por los planes 001-008). Los ~25 hallazgos de
> valor S/M de esa pasada se **implementaron directamente** en `c2d7cff` (ver tabla al final);
> los de calado M/L son los planes **016-021**.

Cada plan es **autocontenido**: quien lo ejecute no ha visto esta auditoría. Léelo entero
antes de empezar, respeta sus "STOP conditions" y actualiza su fila de estado al terminar.

> ⚠️ **Contexto de seguridad importante.** Los usuarios del área privada son **contactos del
> CRM, no usuarios de WordPress**. Por eso *toda* petición es "nopriv" para WordPress, y la
> ÚNICA autenticación de los endpoints `admin_post_*` es la comprobación por handler de
> `$_SESSION['scp_user_id']`. Varios handlers la omiten → los planes 001–004 son P0.

## Orden de ejecución y estado

| Plan | Título | Prioridad | Esfuerzo | Depende de | Estado |
|------|--------|-----------|----------|------------|--------|
| 001 | Exigir sesión autenticada en los handlers que mutan datos | P0 | S | — | TODO |
| 002 | Eliminar IDOR + mass-assignment en las escrituras al CRM | P0 | M | 001 | TODO |
| 003 | Asegurar ver/descargar documentos + validar subidas | P0 | M | 001 | TODO |
| 004 | Validar el destino del selector de participante | P0 | M | 001 | TODO |
| 005 | Corregir el open redirect vía `scp_current_url` | P1 | S | — | TODO |
| 006 | Escapar los valores del CRM al pintarlos (XSS almacenado) | P1 | M | — | TODO |
| 007 | Regenerar el ID de sesión al autenticar (session fixation) | P1 | S | — | TODO |
| 008 | Endurecer el transporte al CRM: TLS + queries de login | P0 | M | — | TODO |
| 009 | Cachear `get_module_fields` también en `makeList` | P1 | S | — | **DONE** → `archive/` |
| 010 | Servir el CSS de DataTables local + enqueue condicional | P1 | M | — | **DONE** → `archive/` |
| 011 | Eliminar los N+1 de listados, calendario y selector | P1 | L | 013 | TODO |
| 012 | Sustituir `getAllEmail()` por una consulta puntual | P1 | S | — | TODO (ver nota de riesgo) |
| 013 | Establecer una base de verificación (PHPUnit + mocks) | P1 | M | — | **DONE** → `archive/` (baseline; ver seguimiento) |
| 014 | Retirar assets muertos y arreglar docs desfasadas | P2 | S | — | **DONE** → `archive/` |
| 015 | Conectar o bloquear el formulario de pago del familiar | P1 | M | 013 | TODO |
| 016 | Tema claro/oscuro AUTOMÁTICO (dispositivo + app MCM) | P2 | L | 018 | **DONE** → [`archive/016-dark-theme.md`](archive/016-dark-theme.md) |
| 017 | Foto de perfil por endpoint con miniatura (fuera base64) | P1 | M | — | **DONE** → `archive/` |
| 018 | Consolidar CSS: un solo :root, menos duplicados/!important | P2 | L | — | **PARCIAL** (F1 hecha; F2/3 medidas = no-batch, ver ficha) |
| 019 | Integrar (o retirar) el chrome de DataTables en listados | P2 | M | 010 | **DONE** → `archive/` |
| 020 | Aligerar el coste de pintura del login (blur/glass) | P2 | S-M | — | **DONE** → `archive/` |
| 021 | Externalizar los script inline de init (DT/FullCalendar) | P3 | S-M | 019 | **DONE** → `archive/` |
| 022 | Calendario MES en móvil: dots + tap-tooltip (eventContent) | P2 | M | — | **DONE** → `archive/` (verificado en render offline) |
| 023 | Home móvil: accesos compactos (2 col) + orden con sentido | P2 | S-M | — | **DONE** → [`archive/023-home-mobile-first.md`](archive/023-home-mobile-first.md) |
| 024 | Login compacto en móvil + encaje visual con WordPress | P2 | M | — | **PARCIAL** — A (login) hecho en el 026; B (grises de WordPress) pendiente de ver el sitio real |
| 025 | Eventos: tarjetas propias + ficha de detalle | P2 | M | — | **DONE** → [`archive/025-eventos-tarjetas-y-detalle.md`](archive/025-eventos-tarjetas-y-detalle.md) |
| 026 | Tarjetas en TODOS los listados + login rediseñado + home | P2 | M | 025 | **DONE** → [`archive/026-tarjetas-globales-login-y-home.md`](archive/026-tarjetas-globales-login-y-home.md) |
| 027 | Cliente CRM: keep-alive, HTTP/1.1, timeouts y gzip | P0 | S-M | — (coordina con 008) | **DONE** (`cb79e9c`) |
| 028 | Soltar el lock de sesión PHP (foto/descargas en paralelo) | P1 | S-M | — | **PARCIAL** (`122289f`) — falta el cierre durante el render |
| 029 | Quick wins de lecturas CRM (guard, caches, typo de pago) | P1 | M | — | **DONE** (`967ef24`) |
| 030 | Tap instantáneo: feedback en enlaces + prefetch + puente app | P1 | S-M | — | **DONE** (`d2873dd` + prefetch 2026-08-13) |
| 031 | Dieta de assets: CSS minificado en deploy + Inter local | P2 | M | — | **PARCIAL** (`7dc928a`) — falta retirar DataTables |
| 032 | Acotar listados: ventana de eventos + techo de filas | P2 | M | — (tras 011 en páginas compartidas) | **PARCIAL** (`113255e`) — falta el techo de filas |
| 033 | Pasar Lista: guardado visible y cierre de EL bug | **P0** | M | — | **HECHO** — confirmado en producción el 27/08/2026 |
| 034 | Pasar Lista: rendimiento (calentar, paralelizar, medir) | P1 | M | 033 | **HECHO** — 1+N de monitores muerto y `curl_multi` en las cinco pantallas (viajes: 7→3, 6→2, 8→4, 7→3, 9→4). Falta la foto real de producción |
| 035 | Pasar Lista: fidelidad al diseño de los artboards | P2 | M | — | **HECHO** (28/08) — las seis pantallas con artboard, verificadas con captura en los dos temas. Tres fallos de fondo, todos del tema de WordPress ganando por especificidad: los `<label>` sin su `display:flex`, los `<input>` con caja dentro de la caja, y 32 px de scroll lateral |
| 036 | Pasar Lista: funcionalidad pendiente + doctrina de la API | P2 | por fila | 033 | TODO |
| 037 | Pasar Lista: UX — fluidez, menos toques, ficha a un gesto | P1-P2 | S-M por fila | 033 | **PARCIAL** — filas 1, 3 y 5 hechas (la 3 completada el 28/08 con la ficha del monitor); la 2 DESCARTADA por el propietario (se llama desde la ficha); 4 y 6 esperan medición |
| 038 | Pasar Lista: seguimiento de monitores (asistencia y listas) | P2 | M | 033, paginación | **HECHO** (28/08/2026) — tres pistas en la ficha del monitor y el aviso mínimo en la lista. Con tres cambios de diseño sobre lo escrito, en §7 del plan |
| 039 | Unificar el lenguaje visual MCM (tokens, breakpoints, deuda de diseño) | P2 | S por fila | — | **HECHO** (31/08) — las 9 filas. Tokens semánticos en las dos superficies, escala de breakpoints, secciones de CSS sin duplicados, CAMPOS.md sincronizado solo e Inter autoalojada también en los formularios. Sale de escribir [`../design.md`](../design.md) |

> Los planes 033-037 salen de la revisión del 27/08/2026 (bug de guardado
> verificado contra el CRM por MCP, rendimiento, diseño, funcionalidad y UX).
> La decisión sobre la BBDD espejo está en
> [`../docs/comunica/DECISION-BBDD-ESPEJO.md`](../docs/comunica/DECISION-BBDD-ESPEJO.md):
> Neon descartado; journal de escrituras (033) y, si hiciera falta tras medir,
> espejo de lectura en `wpdb`.

### Estado tras implementar la pasada de rendimiento (2026-08-12)

Todo lo de abajo está en la rama `claude/mcm-performance-improvement-rwrovk`, con
lint global y PHPUnit verdes (56 tests, 7 nuevos en `tests/CalendarCacheTest.php`).

**Entregado:**

| Área | Qué cambió |
|---|---|
| Transporte al CRM (027) | Handle cURL reutilizado + HTTP/1.1 + `TCP_KEEPALIVE` (antes: socket nuevo y handshake TLS por llamada, con 2-40 llamadas por pantalla) · `CONNECTTIMEOUT`/`TIMEOUT` (antes: ninguno) · `Accept-Encoding` · sesión del CRM con marca de tiempo (renovación proactiva a 20 min) · el reintento por sesión caducada ya refresca el `session` de los parámetros, que antes reenviaba el id muerto |
| Candado de sesión (028) | `session_write_close()` en el endpoint de la foto y en la descarga de documentos: dejaban en cola cualquier otra petición del usuario · la cookie deslizante se reenvía 1×/día en vez de en cada respuesta del sitio |
| Lecturas (029) | Guard de inscripción memorizado + leído del transient del calendario si está caliente (era `1+N` × 4 veces por flujo) · aviso del monitor cacheado en sesión · flags de certificados en un solo `set_entry` · 4 formularios de alta dejan de pedir un registro con id null · formulario de pago con definición cacheada y rebotes antes de tocar el CRM |
| Bug (029) | El redirect tras inscribirse con pago apuntaba a `single_stic_payments_form`, que no existe → pantalla vacía |
| Velocidad percibida (030) | Overlay de carga al tocar **cualquier** enlace interno (antes solo 4 formularios), con red de seguridad para bfcache y descargas · `postMessage` `sticpa:nav` para que la app pinte indicador nativo (§4.a del contrato) · pantalla puente del enlace mágico sin 4 de sus 5 animaciones infinitas |
| Assets (031) | Minificado de CSS/JS propio en el job de deploy: 268→162 KB de CSS y 80→40 KB de JS, sin tocar los fuentes del repo · Inter autoalojada (fuente variable, subsets latin + latin-ext): fuera dos orígenes externos encadenados del camino crítico · cropper solo en las 4 páginas con input de archivo |
| Listados (011 parcial) | Pagos: el bucle `1+N` que rellenaba una columna **que no se pinta** ya no se ejecuta para personas adultas normales · Sesiones: dedup por evento · guards `is_array` para tolerar el nuevo timeout |
| Eventos (032 parcial) | Ventana temporal compartida con el calendario (**-14…+12 meses**, filtrable) en el listado y en el desplegable de inscripción (solo al crear). 14 meses hacia atrás porque las actividades son anuales y la edición anterior sigue siendo la referencia útil |
| Precarga (030) | Las secciones del menú se precargan al apoyar el dedo (Speculation Rules). Requirió cambiar `no-store` por `private, no-cache, must-revalidate` en las páginas del área — decisión aprobada por el mantenedor |
| Validación (027) | Arnés en `tests/manual/` que prueba el cliente del CRM contra un servidor real, sin credenciales. Keep-alive medido: 1ª llamada 1947 ms, siguientes ~350 ms sin abrir conexión |

**Pendiente y por qué (decisiones tomadas al implementar):**

- **Prefetch especulativo del menú (030, paso 2) — HECHO el 2026-08-13**, tras
  aprobarlo el mantenedor. Estaba aparcado porque el sitio responde
  `cache-control: no-store` (limitador de sesión de PHP) y la especificación de
  Speculation Rules no usa respuestas `no-store`: el prefetch habría sido
  **inerte**. Ahora las páginas del área —y solo ellas— responden
  `private, no-cache, must-revalidate` (`sticpa_area_cache_headers`, en
  `template_redirect`): ninguna caché compartida guarda nada y el navegador no
  puede reutilizar sin revalidar, así que tras cerrar sesión el botón atrás
  acaba en el login. **Lo que se acepta a cambio**: la respuesta puede quedar en
  la caché de disco del navegador, recuperable por alguien con acceso al perfil
  del navegador en un dispositivo compartido.
  `eagerness` por defecto **conservative** (al apoyar el dedo, nunca en hover),
  porque cada precarga es un render con sus llamadas al CRM y la barra de menú es
  horizontal: con `moderate`, un barrido del ratón precargaría media. Subirlo es
  un filtro de una línea: `sticpa_prefetch_eagerness`.
- **Cerrar la sesión durante el render (028) — PENDIENTE.** Es donde está el
  resto del beneficio del lock, pero el cliente del CRM escribe
  `api_session_id` a mitad de render cuando renueva sesión: cerrar antes
  provocaría un login al CRM **por petición**, peor que el lock. Necesita las 3
  piezas anotadas en la ficha del 028 (forzar antes la detección del rol, excluir
  `single_stic_profile_selection`, y que `inc/stic-class-6.php` reabra la sesión
  para escribir).
- **Techo de filas en listados (032, paso 3) — PENDIENTE.** Las 4 páginas piden
  con `order_by` vacío, así que un límite mostraría los registros **más
  antiguos** y esconderías los recientes. Hace falta fijar el `order_by`
  descendente correcto por módulo, validando nombres de columna contra el CRM
  real.
- **Colapsar los N+1 estructurales (011) — PENDIENTE.** Asistencias, el triple
  anidado de Sesiones y el selector de participante necesitan reescribir las
  consultas con `related_module_link_name_to_fields_array`, cuya forma de
  respuesta no es verificable sin el CRM (y `getRelatedElementsForLoggedUser`
  descarta hoy el `relationship_list`, así que también habría que tocar el
  cliente). Un fallo ahí deja listados vacíos en rutas de datos y dinero.
  Atenuante: Sesiones y Asistencias **no están en el menú**, así que no afectan
  a la velocidad percibida actual.
- **Retirar DataTables (031, paso 4) — PENDIENTE.** 108 KB para aportar solo una
  caja de búsqueda en cliente (paginación y ordenación ya están desactivadas en
  los 11 listados), pero sustituirla cambia la UI de todos ellos y necesita QA
  visual en el sitio real.
- **`select_fields` en las fichas de perfil — PENDIENTE.** Recorta payload, pero
  el motor de formularios puede leer campos no declarados: requiere QA pantalla
  a pantalla.

Los planes **DONE** están en producción y sus fichas se movieron a
[`plans/archive/`](archive/) (2026-07-20; el 016 el 2026-07-26). En `plans/` quedan solo los **pendientes** y los
**parciales/aparcados**, para que la carpeta refleje de un vistazo qué falta.

> **Estado tras la pasada UI/UX + rendimiento (2026-07-20, en producción).**
> - **Entregado y en vivo**: 009, 010, 014, 017, 019, 020, 021 + la Fase 1 del 018 + la pasada
>   directa de UI/a11y/motion (login compacto, foco, validación inline, `datetime-local`, etc.).
> - **016 (tema oscuro): HECHO en 2ª vuelta (2026-07-26), ahora AUTOMÁTICO.** La 1ª vuelta era
>   opt-in con un conmutador en la barra y se retiró. Rediseño:
>   · **automático por defecto** (`prefers-color-scheme`), resuelto en un script inline de `<head>`
>     antes del primer pintado (sin flash);
>   · **dentro de la app MCM manda la app** (`?theme=` / cookie `mcm_theme` / `data-mcm-theme` en
>     `<html>`, incluido el cambio en caliente sin recargar) y el control propio NO se pinta;
>   · en navegador, control **discreto** de 3 estados (Auto/Claro/Oscuro) en el **pie** del área, no
>     en la barra (donde ya compiten identidad, participante, salir y menú);
>   · el CSS se engancha a `data-stic-scheme` y §20.d ya no fuerza claro siempre, así que el oscuro
>     no pelea con `!important`;
>   · lógica extraída a `inc/stic-theme.php` (con el modo app) y cubierta por `tests/ThemeTest.php`
>     (18 tests: prioridades + saneado); contrato en `docs/comunica/CONTRATO-APP-WEBVIEW.md`.
> - **018 PARCIAL**: hecha la Fase 1 (un solo `:root`, sin `var()` huérfanos). La Fase 2/3 se intentó
>   y se **midió a nivel de píxel** (2026-07-22): los `!important` son *portantes* (quitarlos cambia el
>   render de las tarjetas ~16–20k px; base compite desde varios sitios). NO es un batch seguro; queda
>   como QA visual iterativo con el runbook (curl-login → render offline → diff Pillow) que está en la
>   ficha `018-css-consolidation.md`. No se envió nada de F2/3 a producción para no romper el render.
>
> **013 (base de tests): HECHO.** `composer.json` + PHPUnit 11 + `tests/bootstrap.php` con stubs
> mínimos de WP; suites `MagicLinkTest` (HMAC del acceso mágico: firma válida/manipulada, caducado,
> módulo fuera de whitelist, payload malformado, base64url) y `SessionTest`
> (`sticpa_establish_session` mapea el registro del CRM a `$_SESSION` sin llamar al CRM). **10 tests
> verdes.** El deploy a producción ahora depende de un job `test` (lint de todo el PHP + PHPUnit):
> si algo falla, no se despliega. Los ficheros de dev (`vendor/`, `tests/`, `composer.*`, `phpunit.*`)
> se excluyen del FTP.
> - *Seguimiento 013*: los tests a nivel de HANDLER (signup con email duplicado, login por token, la
>   guarda de sesión del plan 001) se dejan como paso siguiente — necesitan stubear más WP + un doble
>   de `SugarRestApiCall`, y por diseño cada uno llega con su plan (002/011/015 "añaden sus tests").
>   La lógica pura crítica (firma HMAC) ya está cubierta, que era el objetivo del baseline.
>
> **Recomendación para continuar (leído contra el código):**
> - **013 primero.** Es la única pieza segura que no toca runtime y **desbloquea** 011/012/015. Sin
>   ella, los siguientes son inverificables.
> - **012** parece "S/LOW" pero toca la **lógica de alta de cuentas** (duplicados de email) y
>   `getContactByEmail` no cubre el módulo `'Any'`. No mandar a producción a ciegas: hacer con 013 o
>   probar el signup en staging.
> - **011 / 015**: dinero y datos. **Requieren 013** y, en 015, saber dónde viven los campos de pago
>   en el CRM (es un *spike* de decisión, no de código).
> - Verificación visual de todo lo ya desplegado: pendiente de una revisión en el sitio real.

Estados: TODO · IN PROGRESS · DONE · BLOCKED (motivo) · REJECTED (motivo).

## Notas de dependencia

- **001 va primero**: 002, 003 y 004 asumen que el handler ya rechaza peticiones sin sesión;
  cada uno añade además su comprobación de propiedad/allow-list.
- **013 (tests) antes de 011**: los N+1 viven en rutas de dinero/datos sin red de seguridad;
  escribir tests de caracterización antes de refactorizar evita romperlas en silencio.
- **015 depende de 013** por la misma razón (toca datos de pago).
- **Pasada de rendimiento (027-032)**: 027 y 030 primero (máxima ganancia percibida, riesgo
  bajo, no dependen de nada); 028 y 029 después (independientes entre sí); 031 y 032 al
  final. **027 coordina con 008** (ambos tocan `inc/stic-class-6.php:52`: 027 pone HTTP/1.1,
  008 pone TLS verify — no duplicar). **029 st.1 acopla el guard de inscripción al gather del
  calendario**: si se ejecuta el 011 (que refactoriza ese gather), preservar la forma de
  `registered_events`. **032 va después del 011** en las páginas que comparten
  (`list_stic_payments`, `list_stic_attendances`).

## Tabla de hallazgos — Seguridad (vetados contra el código)

Confirmados de forma independiente por dos pasadas de auditoría.

| # | Hallazgo | Impacto | Evidencia | Plan |
|---|----------|---------|-----------|------|
| S1 | Handlers `admin_post_nopriv_*` sin comprobar sesión → crear/editar/borrar registros del CRM y descargar documentos sin autenticar | Crítico | `inc/stic-action.php` handlers de documents(142), relationships(233), payment_commitments(279), payments(317), registrations(355), job_applications(473), contacts(962) | 001 |
| S2 | `id` desde el request + volcado de todo `$_REQUEST` a `set_entry` → IDOR y mass-assignment; se puede fijar `stic_pa_password_c`/`ajmcm_pa_token_c` de cualquier contacto (toma de control) | Crítico | `inc/stic-action.php:57-83` (profile) y ~8 handlers gemelos; exemplar ya arreglado: `prefix_admin_single_stic_tutor_profile:90-118` | 002 |
| S3 | `download_document($_REQUEST['id'])` sin auth ni propiedad; detalle de registros por `id` sin comprobar pertenencia; filename sin sanear en `Content-Disposition` | Alto | `inc/stic-action.php:146,750-775`; páginas `single_*` `getRecordDetail($_REQUEST['id'])` | 003 |
| S4 | El selector de participante asigna `scp_user_id` a cualquier GUID sin validar que sea del familiar | Alto | `inc/stic-action.php:21-37` (no consulta `scp_available_profiles`) | 004 |
| S5 | Open redirect: `wp_redirect($_REQUEST['scp_current_url'].'…')` (host no validado) | Alto | `inc/stic-action.php:77,79,128,130,267,305,343,568-577,674,700-702,995-997` | 005 |
| S6 | XSS almacenado: valores del CRM pintados sin escapar en listados, `readOnly`/`info`/`image` y `<option>` | Alto | `inc/stic-listController.php:84`; `inc/stic-formController.php:357,403,415,421,434` | 006 |
| S7 | Sin `session_regenerate_id()` al autenticar (fixation), agravado por cookie de 1 año | Medio | `sinergiacrm-private-area.php` login; `inc/stic-magic-login.php::sticpa_establish_session` | 007 |
| S8 | Subidas sin validar tipo/tamaño en documentos; certificado valida tamaño pero no tipo | Medio | `inc/stic-action.php:204-214, 916-956` | 003 |
| S9 | *(TODO SEC-02)* Inyección SuiteQL: usuario/contraseña concatenados sin escapar | Crítico | `inc/stic-class-6.php:128,300,322` | 008 |
| S10 | *(TODO SEC-04)* Verificación TLS desactivada (`VERIFYPEER=0`, `VERIFYHOST` sin fijar) | Alto | `inc/stic-class-6.php:54` | 008 |

*(No planificados aquí, ya trackeados en TODO.md y correctos de aplazar con contexto:
SEC-03 contraseñas en claro — cambio de calado; SEC-05 nonces CSRF — combinar con 001–004;
SEC-06 cookies seguras — ya aplicado.)*

## Tabla de hallazgos — Rendimiento

| # | Hallazgo | Evidencia | Plan |
|---|----------|-----------|------|
| P1 | `makeList` no cachea `get_module_fields` (los formularios sí) → 1 llamada CRM/listado | `inc/stic-listController.php:8` vs `inc/stic-formController.php:65-74` | 009 |
| P2 | CSS de DataTables desde CDN externo, inyectado en el body en cada listado | `inc/stic-listController.php:13` | 010 |
| P3 | Todas las libs pesadas (FullCalendar, DataTables, Selectize…) se cargan en todas las páginas | `sinergiacrm-private-area.php` enqueue sin ramificar por página | 010 |
| P4 | N+1 triple-anidado en Sesiones y Calendario (`1+N+N×M` llamadas) | `pages/list_stic_sessions.php:72-117`, `pages/single_stic_activities_calendar.php:22-82` | 011 |
| P5 | N+1 por fila en Pagos, Asistencias y selección de participante | `pages/list_stic_payments.php:89-104`, `pages/list_stic_attendances.php:74-96`, `pages/single_stic_profile_selection.php:60-80` | 011 |
| P6 | `getAllEmail()` descarga toda la columna de emails del CRM en cada signup | `inc/stic-action.php:726` → `inc/stic-class-6.php:338-356` | 012 |
| P7 | cURL en HTTP/1.0 y conexión nueva por llamada (sin keep-alive) | `inc/stic-class-6.php:48,52,69` | 011 (nota) |

## Tabla de hallazgos — Tech-debt / DX / Docs

| # | Hallazgo | Evidencia | Plan |
|---|----------|-----------|------|
| T1 | Cero infraestructura de test/healthcheck; deploy FTPS sin puerta | sin composer.json/phpunit; `.github/workflows/deploy-produccion.yml` | 013 |
| T2 | Handlers CRUD casi idénticos duplicados (~6) con drift real en la serialización multi-valor | `inc/stic-action.php:234,280,318,416,474` (drift: 252-254 vs 291-293) | 013→(refactor futuro) |
| T3 | Assets muertos en producción: `prueba.html`, `js/custom-utils.js` vacío enqueued, helpers `debug()`/`my_log_file()` | root `prueba.html`; `sinergiacrm-private-area.php:49-71,104-105` | 014 |
| T4 | `PLAN.md` enlaza a `css/stic-modern-style.css`, borrado por UI-15 | `PLAN.md:123,191` | 014 |
| T5 | `$_REQUEST[...]` sin `isset` extendido (warnings de índice) | `pages/single_*` y `inc/stic-action.php` (MNT-02) | 014 (nota) |

## Hallazgos de dirección (opciones para el mantenedor)

- **Formulario de pago del familiar que descarta datos** — `single_stic_tutor_profile.php`
  muestra IBAN/titular con nombres `ajmcm_pago_*_c` provisionales que el CRM ignora; el guardado
  es un no-op y la UI lo confiesa. Es la mayor brecha entre UI publicada y backend. → **Plan 015**.
- **Motor CRUD para el lado de escritura** — el lado de lectura ya es declarativo (`makeList`/
  `makeForm`); el de escritura es copy-paste por módulo (T2). Un motor simétrico haría que "añadir
  un módulo" = "añadir un archivo de config", y CSRF/allow-list serían un cambio en un solo sitio.
  Spike, no build. (No planificado como archivo; candidato tras 013.)
- **Panel de salud de la conexión al CRM** — fachada sobre una API remota lenta sin ninguna
  superficie que reporte alcanzabilidad/latencia/estado de auth. Encaja en `sticpa_render_admin_tools`.
  (No planificado; candidato de valor operativo.)

## Segunda pasada (UI/UX + a11y + perf frontend) — implementado en `c2d7cff`

Hallazgos verificados y aplicados directamente (no necesitan plan):

| Área | Qué se arregló |
|---|---|
| Perf assets | FullCalendar `.min` (269 KB vs 718 KB) + solo el locale activo; CSS `.min` con filemtime; `preconnect` a los dos orígenes de Google Fonts; fuera `jquery-3.6.0.min.js` (89 KB) y `Sorting icons.psd`; keyframes muertos eliminados; `loading=lazy` en iframe/imágenes; fotos con `width/height` (sin CLS) |
| Perf JS/CSS | `layoutNav` sin layout-thrashing (medir 1 vez, mover en lote); listeners de scroll pasivos con early-return; 16× `transition:all` → lista explícita de propiedades compositor |
| A11y | Focus trap + restauración en el cropper; tooltips con `aria-describedby`; mensajes con `role=status/alert` y despedida por temporizador; secciones colapsables con `<button>` real dentro del `h5` (headings intactos); `aria-current` en menú; `aria-controls` + foco al abrir en "Más" y selector de participante; anillos de foco en item activo, paneles y todos los botones; `th scope=col`; `ordering:false` por defecto en DataTables (cabeceras ocultas atrapaban foco); targets táctiles ≥44px; `safe-area-inset-bottom` en botonera sticky; spinner visible bajo reduced-motion |
| UI/UX | Tokens semánticos `--success/--danger/--warning-*` (39 hex unificados); `--grad-brand-soft`/`--shadow-glow` derivados con `color-mix`; forzado de claro completo (`color-scheme:light` + `scrollbar-color`) y override oscuro acotado al área (ya no pisa al tema WP); `datetime-local` vacío ya se renderiza; overlay "Guardando…" en todos los formularios del motor; validación inline con `aria-invalid` (fuera `alert()`); error de pago con marca y CTAs; leyenda del calendario + HTML válido; contraste (gray-400→500, placeholders); `-webkit-backdrop-filter` en sticky bar/chip |

## Tercera pasada (2026-08-09, commit `337ec6a`): RENDIMIENTO PERCIBIDO

Auditoría enfocada en la lentitud percibida dentro de la app MCM (WebView): "cada tap pesa,
las inscripciones tardan, mostrar datos tarda". 3 subagentes read-only (camino de llamadas al
CRM · frontend percibido · flujos y overhead WordPress); todos los hallazgos citados abajo
fueron vetados abriendo el código citado. Resultado: planes **027-032** + corrección de las
referencias desfasadas del **011** (el N+1 del calendario vive ahora en `inc/stic-calendar.php`,
no en la página).

**El diagnóstico en una frase**: todos los datos vienen de un CRM remoto; cada pantalla hace
2-40+ llamadas cURL SECUENCIALES, cada una con conexión TLS nueva (HTTP/1.0, sin keep-alive,
sin timeout), con el lock de sesión PHP retenido toda la petición (serializa la foto y
cualquier tap paralelo), sin feedback visual en los enlaces, y con ~268 KB de CSS sin
minificar re-parseados en cada navegación. La inscripción — el flujo estrella — son 3
documentos HTTP y `6+2R` llamadas al CRM, pagando 3 veces el mismo guard `1+R`.

### Hallazgos → planes (orden de ejecución recomendado)

| # | Hallazgo (evidencia clave) | Plan |
|---|----------------------------|------|
| N1 | cURL: handle nuevo + HTTP/1.0 por llamada, sin `CURLOPT_TIMEOUT`, sin gzip (`inc/stic-class-6.php:48,52,50-67`) | **027** |
| N2 | `session_write_close()` inexistente en el repo; la foto (`inc/stic-action.php:949`) espera al HTML; `Set-Cookie` en cada respuesta (`sinergiacrm-private-area.php:1099-1120`) | **028** |
| N3 | Guard de inscripción `1+R` ejecutado hasta 4 veces por flujo (`inc/stic-action.php:372-411,448`; `pages/list_stic_events.php:41`; `single_stic_events.php:55`; `single_stic_registrations.php:152`), con el mismo dato ya cacheado por el calendario (`inc/stic-calendar.php:391`) | **029** (st.1) |
| N4 | Home consulta el CRM para un booleano de monitor sin caché (`pages/single_stic_home.php:101` → `inc/stic-comunica-roles.php:157-169`) | **029** (st.2) |
| N5 | **BUG**: redirect de pago a página inexistente `single_stic_payments_form` (`inc/stic-action.php:466`; el archivo es `single_stic_payment_form.php`) + definición sin cachear (`pages/single_stic_payment_form.php:30`) + `alert()` bloqueantes | **029** (st.3-4) |
| N6 | `getRecordDetail(null)` en 4 formularios de creación (`single_stic_documents.php:160`, `single_stic_contacts.php:106`, `single_stic_relationships.php:82`, `single_stic_payment_commitments.php:127`) | **029** (st.5) |
| N7 | Guardar perfil con 4 certificados = hasta 18 llamadas; 4 `set_entry` de flags colapsables en 1 (`inc/stic-action.php:1155-1160,1206`) | **029** (st.6) |
| N8 | Ningún feedback al tocar enlaces (solo 4 formularios tienen overlay, `js/stic-ui.js:50-67`); cero prefetch/speculation; sin canal web→app (`postMessage` inexistente) | **030** |
| N9 | Pantalla puente del enlace mágico: 5 animaciones infinitas + `backdrop-filter` sobre fondo animado (`inc/stic-magic-login.php:329-378`) | **030** (st.4) |
| N10 | 268 KB de CSS render-blocking sin minificar (27 % comentarios); Inter desde Google Fonts (2 orígenes encadenados, 2 conjuntos de pesos distintos: `sinergiacrm-private-area.php:1189`, `inc/stic-magic-login.php:322`); cropper (13 KB) encolado siempre | **031** |
| N11 | Eventos sin filtro temporal (`pages/list_stic_events.php:34` vs la ventana del calendario `inc/stic-calendar.php:369`); desplegables con el módulo entero; `limit=0` en todos los listados; opción `sticpa_scp_case_per_page` registrada y sin usar | **032** |
| N12 | N+1 de listados/calendario/selector SIGUEN VIGENTES tal y como los describe el plan 011 (con la referencia del calendario corregida); el flush del calendario al inscribirse (`inc/stic-action.php:461`) recoloca el pico en la home | **011** (actualizado) |
| N13 | Sesión CRM cacheada sin timestamp → renovación reactiva de 3 round-trips en el primer tap al volver (`inc/stic-class-6.php:22-24,76-80`) | **027** (st.6) |

### Menores, verificados y NO planificados (fáciles de coger sueltos)

- `js/stic-init.js:87-126` — `paintDots` del calendario recorre todos los eventos por cada
  celda del mes (O(celdas×eventos)); construir un índice por día lo arregla. S/LOW.
- `js/stic-ui.js:711-716` — `layoutNav` corre 3 veces por carga (ready + fonts + timeout);
  cortocircuito por firma. Solo afecta ≥768 px. S/LOW.
- `pages/single_stic_documents.php:216-224` — `?download` pinta una página que se auto-envía
  (3 ciclos de carga por descarga); servir el fichero directo. S/LOW-MED.
- 10 páginas con `<script>` inline pendientes de migrar al patrón `data-*` del plan 021.
- Foto de perfil sin `ETag`/`Last-Modified` (no hay 304 al expirar el `max-age`;
  `inc/stic-action.php:1011-1013`). S/LOW, compone con 028.

### Considerado y RECHAZADO en esta pasada

- **AJAX-ificar formularios / intercambio parcial de contenido / View Transitions** (el salto
  de arquitectura): coste L-XL y riesgo alto con handlers que solo saben redirigir (35
  `wp_redirect` en `inc/stic-action.php`). No merece la pena **hasta medir el efecto de
  027-030**: el transporte + el lock + el feedback capturan la mayor parte de la ganancia
  percibida. Si tras eso sigue corto, el punto de entrada natural ya está identificado: el
  contenido vive aislado en `.stic-tab-content` y el único precedente de endpoint no-HTML es
  `admin_post_stic_profile_photo`. Reevaluar entonces.
- **`NumberFormatter` por celda en `inc/stic-formatter.php:16-19`**: microsegundos frente a
  round-trips de cientos de ms. No cambia nada perceptible.
- **`filemtime()`/`file_exists()` del encolado** (~10 stats/petición): ruido.
- **Quitar jQuery del área** (~103 KB con migrate): real pero M/MED-riesgo (scripts inline de
  varias páginas dependen de `$`); solo tiene sentido DESPUÉS del plan 031 st.4 (DataTables) y
  de terminar la migración de inlines del 021. Anotado, no planificado.
- **Carga diferida de los 13 includes del plugin** (~336 KB de PHP por petición): con OPcache
  (lo normal incluso en hosting compartido) el ahorro es marginal; medir antes de refactorizar.

## Findings considered and rejected

- **Migración FullCalendar 5→6 / reemplazar Selectize**: sin CVE crítico en los pins actuales;
  radio de impacto alto (API rompedora). No merece la pena ahora; revisar solo si se toca esa UI.
- **B2 escala de z-index / B3 glow→outline (UI)**: mejoras de baja palanca; reordenar z-index en
  un CSS de 121 KB con `!important` entrelazado arriesga regresiones de apilamiento. Hacer de forma
  deliberada con prueba visual, no en esta tanda. (El resto de la UI — a11y, motion, metadata — ya
  se aplicó directamente en `bc3c436`.)
- **`case 'bool';` con `;` en vez de `:`**: es PHP válido (etiqueta), funciona; no tocar por churn.
- **Recortar pesos de Inter (400-800)**: los 5 pesos están en uso real en el CSS; no hay ganancia.
- **Navegación por flechas/Home/End en los desplegables** (patrón menu-button completo): el foco ya
  entra al panel al abrir (`c2d7cff`) y Tab/Escape funcionan; la mejora restante es de baja palanca.
  Retomar solo si llega feedback real de usuarios de teclado.
- ~~**Modo oscuro automático por SO**: decisión de producto vigente = claro por defecto. El oscuro
  será OPT-IN (plan 016), nunca automático.~~ → **REVERTIDO** (2026-07-26, a petición del
  mantenedor): el área es **automática** y sigue al dispositivo; dentro de la app MCM sigue a la app.
  Ver plan 016.
