# Plan: edición de datos Comunica por rol dentro del Área Privada

> **Creado:** 2026-06-14
> **Repo:** `mcmAreaPrivada` (plugin WordPress) · **CRM:** `movimientoconsolacion.sinergiacrm.org` (Comunica)
> **Objetivo:** que un contacto entre al área privada, el sistema detecte su rol
> (monitor / laico, ampliable a más en el futuro) y le muestre las pantallas de edición
> correctas, con el look premium del área privada.

---

## Contexto

- El plugin es una **fachada** sobre SinergiaCRM (SuiteCRM) vía API REST v4.1. No guarda
  usuarios; todo vive en el CRM. Detalle en [`README.md`](README.md).
- Los formularios originales de Comunica (alta de laicos/monitores, subida de foto y
  documentos) viven en el repo `mcmFormulariosComunica`. Migramos esa funcionalidad de
  **edición** al plugin, reusando su motor (`makeForm`, sesión, login).
- Catálogo de campos del CRM Comunica: [`docs/comunica/CAMPOS.md`](docs/comunica/CAMPOS.md).
- Notas técnicas de la API (subida de documentos, `set_document_revision`, etc.):
  [`docs/comunica/AGENTS-comunica.md`](docs/comunica/AGENTS-comunica.md).
- Ejemplos de API (no copiados, referencia): `mcmFormulariosComunica/ejemplos_api_sinergiaCRM/`.

## Decisiones tomadas

| Decisión | Elección |
|----------|----------|
| Dónde se construye | **Dentro del plugin `mcmAreaPrivada`** (nuevas `pages/`, reusar login/sesión/`makeForm`) |
| Autenticación | **Login del área privada — magic link** (ya funciona contra Comunica) |
| Estilos | Base premium actual del plugin + colores de marca Comunica si hace falta |

---

## Fases

### Fase 0 — Conexión y autenticación
- [x] Plugin configurado contra el CRM de Comunica
- [x] Login por **magic link** funcionando
- [x] Rediseño UI premium + menú mobile-first (priority-nav, "Más", iconos) — ya en `main`

### Fase 1 — Detección de rol (monitor / laico) · ✅ implementada (falta verificar en vivo)
- [x] **Mecanismo confirmado:** campo multienum `stic_relationship_type_c` del contacto
      (lista `stic_contacts_relationships_types_list`; labels: Monitor/a, Grupo COM-LC, Socio AJ)
- [x] Implementado `sticpa_get_comunica_role()` + `sticpa_store_comunica_role()` + filtros
      (`sticpa_comunica_role`, `sticpa_comunica_role_map`) en
      [`inc/stic-comunica-roles.php`](inc/stic-comunica-roles.php). Detección **tolerante**
      (subcadena), prioridad **monitor > laico**, ampliable
- [x] `$_SESSION['scp_role']` se setea en login mágico/token (`sticpa_establish_session`) y
      clásico (`sugar_crm_portal_check_user_and_login`)
- [ ] **Verificar en vivo**: confirmar las claves internas exactas del desplegable y que un
      contacto monitor+laico resuelve a `monitor`. Pendiente de un login real (no pude sondear
      el CRM: credenciales `api_user` del `crm_proxy.php` están caducadas → `Invalid Login`)

### Fase 2 — Dispatcher de pantallas por rol · ✅
- [x] `getSticMenuElements()` ([`menu.php`](menu.php)) condicional por `sticpa_get_comunica_role()`
      (perfil común + monitor **o** laico). Menú genérico antiguo conservado como referencia
      (tras un `return`, inactivo). Default landing = `single_stic_comunica_perfil`
- [x] Iconos + descripción de las 3 secciones en `sticpa_section_meta()`
      ([`sinergiacrm-private-area.php`](sinergiacrm-private-area.php)). El dashboard
      [`single_stic_home.php`](pages/single_stic_home.php) genera las tarjetas solo

### Fase 3 — Páginas de edición (motor del plugin) · ✅
- [x] [`pages/single_stic_comunica_perfil.php`](pages/single_stic_comunica_perfil.php) — identidad
      (readonly), contacto, foto, dirección, RGPD
- [x] [`pages/single_stic_comunica_laico.php`](pages/single_stic_comunica_laico.php) — etapa, COM,
      pañuelo, talla, grupo, MCM local, asamblea
- [x] [`pages/single_stic_comunica_monitor.php`](pages/single_stic_comunica_monitor.php) —
      trayectoria, formación, voluntariado, legal, subida de certificados (MAT/DAT/DS/otros)
- [x] Handler compartido `prefix_comunica_save_contact()` + `comunica_upload_certificate()`
      en [`inc/stic-action.php`](inc/stic-action.php). Foto vía `upload_file_to_record`;
      certificados vía `set_entry(Documents)` + `set_relationship` + `set_document_revision` + flag.
      Seguridad: el `id` se toma de la **sesión**, nunca del request

### Fase 4 — Estilos · ✅ (ya estaba)
- [x] `css/custom-style.css` ya trae los tokens de marca Comunica (azul `#1c6fb3`,
      magenta `#9D1E74`) y el sistema premium completo. Sin cambios necesarios

### Fase 5 — Pruebas (deploy) · ⬜ pendiente del usuario
- [ ] Subir y probar con un login real (magic link). Verificar:
  - rol detectado (monitor con DNI 20905896T → debe salir **Monitor**)
  - guardado de campos en cada pantalla
  - subida de foto y de certificados (que crean Documento y marcan el flag)

---

## Bloqueante actual

**Fase 1.** Necesito una de estas dos para avanzar:
1. **DNI de 1 monitor y 1 laico** de ejemplo → lanzo `get_module_fields` / `get_relationships`
   (read-only) y confirmo el campo de rol, o
2. me indicáis **el campo/relación** donde marcáis monitor vs laico y voy directo a
   `sticpa_get_comunica_role()`.

## Email del enlace mágico

- [x] **URL absoluta**: usaba `REQUEST_URI` (relativa) → enlace inservible. Ahora base =
      `sticpa_scp_area_url` o `home_url($path)`. [`stic-action.php`](inc/stic-action.php)
- [x] **Email HTML branded** (mobile-first, colores MCM) con botón "Acceder a mi área privada"
      hiperenlazado + enlace de respaldo. Helper `sticpa_magic_email_html()`
- [x] **En español** (era deploy viejo en inglés)
- [ ] **Deliverability (spam)**: `wp_mail` por PHP del hosting cae en spam. Pendiente: enviar por
      **SMTP autenticado / Resend** (el repo Comunica ya usa Resend) + SPF/DKIM del dominio

## Rediseño UI (en curso)

- Skill `ui-ux-pro-max` consultada. Mantener colores MCM (azul #1c6fb3 / magenta #9d1e74),
  estilo simple, mobile-first, animaciones sutiles (150–300ms), focus visible, reduced-motion.
- **Decisión:** NO reescritura ciega de auth/nav/overlay (acoplados a `js/stic-ui.js`,
  funcionan y se ven bien). Capa limpia centrada en formularios/dashboard (lo que se editaba):
  aplanado de cajas (sección 20), forzar claro, cabeceras con acento, 2 columnas, inputs
  cómodos, foco coherente, **entrada escalonada de campos** + press del botón (sección 21).
- Pendiente: desplegar para ver el estado limpio real (las capturas del usuario eran de un
  deploy anterior, en oscuro/inglés/cajas).
- **Iteración tras deploy bueno:** (a) chip "Perfil detectado" reubicado **arriba-derecha** del
  hero; (b) **datos agrupados en tarjetas por sección** (form más cómodo); (c) **listados
  (Eventos/Documentos/…) renderizados como TARJETAS** en vez de tabla: `data-label` por celda en
  [`stic-listController.php`](inc/stic-listController.php) + CSS sección 22 (cabeceras ocultas,
  cada fila = tarjeta, acciones como botones, 2 por fila en escritorio). DataTables sigue dando
  buscador/paginación.
- **Pulido extra (sección 23 CSS):** botonera de formulario (Atrás/Guardar) en fila con
  secundario sutil; botones sueltos (crear/certificado) en su propia línea; **FullCalendar**
  rebajado (botones limpios, cabecera de días sin gradiente, hoy resaltado, rejilla de marca);
  formularios sin cabeceras quedan agrupados en una tarjeta única.

## Fuga de estilos al tema (resuelto)

- **Problema:** el menú/base de WordPress salía con gradiente y barras raras en móvil. Causa:
  el CSS del plugin usaba **selectores globales** (`*{margin:0}`, `body`, `button`, `input`,
  `table`, `thead`…) que se aplicaban a TODA la página, no solo al área privada.
- **Arreglo:** acotados a `.stic-container` / `.stic-tab-content` / `.stic-auth-shell` en
  [`css/custom-style.css`](css/custom-style.css) (secciones 10-13) y en
  [`css/stic-modern-style.css`](css/stic-modern-style.css) (reset, body, botones, inputs,
  tablas). El `<button>` genérico ya NO recibe gradiente (rompía menú del tema y FullCalendar).

## Pulido iterativo (UI + arreglos)

- [x] Tarjetas de listados (Eventos/Documentos): `display:flex` con etiqueta/valor separados
      (antes el reset `display:block` los pegaba).
- [x] Botones de marca con **texto blanco** (antes salía negro, sin contraste).
- [x] Pagos: botón "Certificado de donaciones" daba **"invalid template"** (templateID de otro
      CRM). Ahora solo aparece si se configura `sticpa_donations_template_id`.
- [x] Documentos: al **editar**, enlace "Ver / descargar archivo" del documento subido.
- [x] Chip "Perfil detectado" rediseñado (pastilla blanca, marca dentro).
- [x] Certificados: file inputs como **dropzone** + cada bloque en subtarjeta.
- [x] **"Salir"** movido a la barra superior (arriba-derecha), fuera de la fila de secciones.
- [ ] Pendiente: "línea encima del menú" (posible elemento del **tema**, no del plugin —
      confirmar con el DOM).

## Pulido en vivo (extensión Chrome)

Conectado al navegador del usuario para inspeccionar/previsualizar la web desplegada.

- [x] **Línea/scrollbar fantasma** bajo los listados: era `overflow-x:auto` del wrapper
      (`.stic-table-responsive`) sobrando 9px → `overflow:visible`.
- [x] **Línea blanca del header** (separaba identidad del menú): quitado `border-top` de `.stic-nav-list`.
- [x] **Títulos de página** (Documentos/Eventos/forms) con **barra de acento de marca** a la izquierda.
- [x] **"Perfil detectado"** quitado de Inicio (el rol sigue detectándose para el menú; queda solo en `?rol_debug=1`).
- [x] **Documentos**: lista con un solo botón **"Abrir"** → pantalla única (editar + **descarga
      directa** del archivo vía `admin-post.php?...&download=true`, sin rodeo por "Ver").
- [x] Eventos: labels "Ver"/"Inscribirse" en español.

- [x] **Listados sin "NOMBRE"**: la primera columna se pinta como **CABECERA de la tarjeta**
      (banda con tinte de marca, título en negrita). `makeList` marca esa celda con
      `stic-cell-title`; CSS la estiliza. Afecta a Eventos/Documentos/Inscripciones/Pagos.
- [x] **Celdas vacías en forms**: los campos ocultos (`<span>`) ocupaban hueco en la cuadrícula
      → `.stic-form > form > ul > span{display:none}`. Inscripción y demás forms ya cuadran.

- [x] **Forms RESPONSIVE**: móvil **1 columna**, escritorio **2 columnas** (grid limpio, sin el
      bug de wrap del base; textarea/foto a ancho completo). Verificado en vivo a 1440px y 390px.
      **No hizo falta reescribir el motor** — era CSS en conflicto.

- [x] **Eventos: Ver + Inscribirse en UNA pantalla**. Quitado "Ver"; "Inscribirse" muestra arriba
      un **banner del evento** (nombre + fechas + descripción) y debajo el formulario. Estado va
      fijo "confirmado" (oculto). PHP en [`single_stic_registrations.php`](pages/single_stic_registrations.php)
      + [`list_stic_events.php`](pages/list_stic_events.php); CSS `.stic-event-card`.
- [x] **Menú**: quitados los **subrayados** de los enlaces (los metía el tema) y **alineada** la
      fila de secciones con la barra de identidad. Estirado de inicio a fin con
      `justify-content: space-between` (solo escritorio).
- [x] **Doble inscripción bloqueada**: la detección de "ya inscrito" usaba un filtro SQL que la
      API no aplica bien → no detectaba. Cambiada al método fiable (recorrer inscripciones del
      usuario y mirar su evento vinculado). Si ya está inscrito: tarjeta del evento + aviso
      "Ya estás inscrito" + "Volver", sin botón de guardar.
      [`single_stic_registrations.php`](pages/single_stic_registrations.php).

## Registro de avances

- **2026-07-05 (b)** — Iteración 3 (feedback con capturas):
  - **Cropper de fotos móvil-first** (`js/stic-cropper.js`, sin dependencias): al elegir
    imagen se abre modal con lienzo, arrastre 1 dedo, pinch 2 dedos, slider de zoom;
    devuelve JPEG 800×800 al propio input vía DataTransfer (el form no cambia). Botón
    "Usar sin recortar" conserva el original. Se engancha a cualquier input de imagen.
  - **Tooltips ⓘ nunca cortados**: ahora `position:fixed` posicionados por JS con
    clamping al viewport (encima si hay sitio, debajo si no). Se cierran al hacer scroll.
  - **Hero/identidad**: el tema pisaba colores ("Hola," azul, nombre azul en la barra) →
    forzados a blanco con !important. Gradientes de nav/hero ahora ESTÁTICOS (perf).
  - **Botones-enlace largos** ("Ver tutorial…"): envuelven en varias líneas (adiós cortes).
  - **Autorizaciones legales rediseñadas**: frase + SWITCH (checkbox estilizado) + enlace
    pequeño "Ver condiciones". Hidden con '0' para que desmarcado guarde No.
  - **Aviso correo institucional**: monitor sin @movimientoconsolacion.com → nota ámbar
    "Mejor utiliza…"; resto de miembros → nota suave "Si tienes, usa…"; monitor con el
    correo puesto → nada.
  - **"Ya subido" + enlace** "Revisarlo en Documentos →" en los certificados del monitor.
  - **Rendimiento**: PERF-01 (transient 6h para get_module_fields, bypass
    ?refresh_fields=1) y PERF-02 (fuera animaciones infinitas de gradiente). Análisis
    completo y plan en TODO.md §Rendimiento (PERF-03…PERF-08).

- **2026-07-05** — Iteración 2 (feedback de pruebas en móvil):
  - **Tooltips ⓘ arreglados**: la capa base (`.stic-form li span{width:100%;display:block}`)
    los estiraba como una elipse azul y soltaba los ':' a otra línea → override con
    !important en la sección 29 del CSS.
  - **Campos "solo año"** (`yearOnly` en el motor): "Pertenezco al MCM desde…" y
    "Monitor/a desde…" muestran/editan solo AAAA; internamente se guarda AAAA-01-01
    (conversión en `sticpa_apply_year_only_fields`, stic-action.php).
  - **Alerta de Certificado de Delitos Sexuales** pendiente (modo manual sin archivo):
    tarjeta ámbar accionable en la home y en Monitor/a (`sticpa_monitor_ds_pending`).
    + hint "Debes elegir Automático o Manual" cuando no hay opción elegida.
  - **Audiencias en "Mis datos"** (`sticpa_profile_audience`): participante → "Sus datos"
    (menú incluido, sin sección Monitor/a), familiar sin rol → sin MCM, miembro → todo.
    Estructura preparada (`$sectionsByAudience` + filtro) para divergir contenidos sin
    crear páginas nuevas. Soportado el caso adulto familiar+miembro.
  - **Secciones colapsables** con memoria en localStorage (por página+sección),
    accesibles por teclado y con desactivación de required mientras están plegadas.
  - Texto de bienvenida "Hola X, estos son los datos que tenemos" en la ficha.

- **2026-07-04** — Gran iteración "la mejor área privada de la historia":
  - **Sistema de diseño documentado** en [`docs/design-system.md`](docs/design-system.md)
    (tokens, componentes, motor de formularios, checklist, anti-patrones). README enlaza.
  - **Motor de formularios ampliado** (`inc/stic-formController.php`): claves `help`
    (tooltip ⓘ accesible), `hint`, `placeholder`, tipo `note`, campos condicionales
    `data-visible-when` (JS genérico en `stic-ui.js`), `label[for]`, y escapado de
    valores del CRM (los apóstrofes rompían inputs).
  - **Formularios Comunica replicados** desde `comunicaFormularios`: «Mis datos» ahora
    incluye TODO lo general (contacto+emergencia, dirección completa con provincia/CCAA,
    foto, MCM común, salud con 5 campos y tooltips, RGPD con enlaces legales);
    «Monitor/a» con formación completa (tooltips MAT/DAT/FA/premonitores), congresos,
    voluntariado, delitos sexuales con tarjetas Automático/Manual y archivos. La sección
    «Laico/a» se retiró (todo era general; la página antigua redirige). Lo de la
    Asamblea de mayo 2026 NO se replicó (ya pasó).
  - **Perfiles de familia**: selección de participante con tarjetas premium
    (`single_stic_profile_selection.php`), selector rápido SIEMPRE visible en la barra
    (menu.php, `.stic-part-switch`), pantalla de datos del familiar con medio de pago
    front-only (`ajmcm_pago_*_c` provisionales). Sin conexión Sinergia todavía:
    `?familia_demo=1` + filtros para previsualizar.
  - **Login**: tabs segmentadas Enlace mágico / Contraseña + sello de confianza.
  - **Seguridad**: whitelist de `?internalpage` (path traversal), `exit` tras cada
    `wp_redirect`, saneado del handler de selección de perfil.
  - CSS: secciones 29–35 nuevas; borrados los `*.backup`.

- **2026-06-14** — Creado el plan. Copiadas `CAMPOS.md` y `AGENTS-comunica.md` a `docs/comunica/`.
  Auth (magic link) y rediseño UI ya estaban hechos en `main`.
- **2026-06-14** — Fase 1 implementada: helper de rol `inc/stic-comunica-roles.php` enganchado
  en ambos flujos de login. Rol vía `stic_relationship_type_c`, prioridad monitor>laico.
  Bloqueante resuelto (mecanismo confirmado por el usuario). Queda verificación en vivo.
- **2026-06-14** — Fases 2-4 completadas: menú por rol, 3 páginas de edición
  (perfil/laico/monitor), handler de guardado compartido con subida de foto y certificados,
  iconos en el dashboard. Estilos ya estaban en marca Comunica. Listo para subir y probar.
- **2026-06-14** (revisión) — Tras feedback: (a) **restaurado el menú original**
  (Eventos/Inscripciones/Documentos/Pagos/Calendario/Cambiar contraseña) + las secciones de
  edición Comunica; (b) **todo en español** (los textos estaban en inglés por usar msgid en
  inglés); (c) **detección de rol perezosa** en `sticpa_get_comunica_role()` para sesiones ya
  abiertas; (d) **arreglados estilos** de las tarjetas (hover ilegible en modo oscuro,
  subrayados del tema, paddings) en la sección 19 de `custom-style.css`; (e) **indicador de rol**
  visible en la bienvenida + modo `?rol_debug=1` que muestra el valor crudo de
  `stic_relationship_type_c`.
- **2026-06-15** — Pulido fino y robustez:
  - **Corrección de borrado de Documentos**: Resuelto el fallo de SuiteCRM que rechazaba eliminar si no se pasaba el campo obligatorio `document_name`. Ahora se consulta al CRM antes del borrado para incluirlo en el payload.
  - **Redirecciones PHP seguras**: Agregadas sentencias `exit;` después de cada `wp_redirect` en `inc/stic-action.php`.
  - **Homogeneización de botones**: Establecido el estilo `.stic-back-button` secundario para los botones "Atrás" y "Eliminar" en las vistas de detalle de documentos, inscripciones y pagos.
  - **Prevención de inscripciones duplicadas**: Implementada consulta en base de datos mediante `related_module_query` en `single_stic_registrations.php` para bloquear de forma fiable la reinscripción a eventos activos.
  - **Inputs de archivo premium**: Mejorados visualmente los controles de archivo como "Dropzones" interactivos con bordes discontinuos animados, botones en píldora con el gradiente MCM y un badge de estado "Ya subido" estilizado.
  - **Popup de confirmación personalizado**: Reemplazados los diálogos nativos `window.confirm` por un modal interactivo estilizado con efecto glassmorphism, accesibilidad de teclado, cierre flexible y cadenas multilingües.
  - **Corrección de nombre de archivo residual (`admin-post.php`)**: Se eliminó `filename` del payload inicial `$moduleData` en `inc/stic-action.php` para evitar que peticiones incompletas o canceladas guarden accidentalmente el string del script como nombre del archivo en el CRM.
  - **Bloqueo condicional de envíos**: Corregido el JS en `single_stic_documents.php` para que solo muestre el aviso "Subiendo archivo..." e inactive el formulario si el usuario ha seleccionado de verdad un archivo adjunto.


