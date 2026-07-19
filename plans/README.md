# Implementation Plans — auditoría `/improve`

Generado por la skill **improve** (shadcn/improve) el **2026-07-19**, contra el commit
`bc3c436`. Auditoría en paralelo (4 subagentes read-only) sobre el código del plugin
(NO las librerías vendorizadas: fullcalendar, jQuery, DataTables, Selectize, iban.js).

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
| 009 | Cachear `get_module_fields` también en `makeList` | P1 | S | — | **DONE** (Fable, 2026-07-19) |
| 010 | Servir el CSS de DataTables local + enqueue condicional | P1 | M | — | TODO |
| 011 | Eliminar los N+1 de listados, calendario y selector | P1 | L | 013 | TODO |
| 012 | Sustituir `getAllEmail()` por una consulta puntual | P1 | S | — | TODO |
| 013 | Establecer una base de verificación (PHPUnit + mocks) | P1 | M | — | TODO |
| 014 | Retirar assets muertos y arreglar docs desfasadas | P2 | S | — | **DONE** (Fable, 2026-07-19) |
| 015 | Conectar o bloquear el formulario de pago del familiar | P1 | M | 013 | TODO |

Estados: TODO · IN PROGRESS · DONE · BLOCKED (motivo) · REJECTED (motivo).

## Notas de dependencia

- **001 va primero**: 002, 003 y 004 asumen que el handler ya rechaza peticiones sin sesión;
  cada uno añade además su comprobación de propiedad/allow-list.
- **013 (tests) antes de 011**: los N+1 viven en rutas de dinero/datos sin red de seguridad;
  escribir tests de caracterización antes de refactorizar evita romperlas en silencio.
- **015 depende de 013** por la misma razón (toca datos de pago).

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

## Findings considered and rejected

- **Migración FullCalendar 5→6 / reemplazar Selectize**: sin CVE crítico en los pins actuales;
  radio de impacto alto (API rompedora). No merece la pena ahora; revisar solo si se toca esa UI.
- **B2 escala de z-index / B3 glow→outline (UI)**: mejoras de baja palanca; reordenar z-index en
  un CSS de 121 KB con `!important` entrelazado arriesga regresiones de apilamiento. Hacer de forma
  deliberada con prueba visual, no en esta tanda. (El resto de la UI — a11y, motion, metadata — ya
  se aplicó directamente en `bc3c436`.)
- **`case 'bool';` con `;` en vez de `:`**: es PHP válido (etiqueta), funciona; no tocar por churn.
