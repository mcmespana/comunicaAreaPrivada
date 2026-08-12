# Plan 029: Quick wins de lecturas al CRM — el mismo dato no se pide dos veces (ni cuando no hace falta)

> **Executor instructions**: Sigue este plan paso a paso. Cada step es INDEPENDIENTE:
> si uno cae en STOP, repórtalo y continúa con el siguiente. Ejecuta cada comando de
> verificación antes de avanzar. Al terminar, actualiza la fila de este plan en
> `plans/README.md` indicando qué steps se completaron.
>
> **Drift check (ejecutar primero)**:
> `git diff --stat 337ec6a..HEAD -- inc/stic-action.php inc/stic-comunica-roles.php pages/`
> Si algún archivo in-scope cambió, compara los extractos de "Current state" con el
> código vivo; ante un desajuste en un step, ese step es STOP (los demás siguen).

## Status

- **Priority**: P1
- **Effort**: M (compuesto por 6 arreglos S independientes)
- **Risk**: LOW-MED (cada step lo detalla)
- **Depends on**: none (compone con 027; NO pisa al plan 011 — ver Scope)
- **Category**: perf
- **Planned at**: commit `337ec6a`, 2026-08-09
- **Estado**: **DONE** — los 6 steps en `967ef24` (2026-08-12).
  Dos desvíos deliberados respecto a la ficha, ambos a mejor:
  (1) el guard lee el transient del calendario SOLO si ya está caliente, en vez de
  llamar a `sticpa_gather_calendar_data()`: si estuviera frío, generarlo cuesta más
  que la propia consulta `1+N`, así que no se provoca desde el listado de Eventos;
  (2) la lectura de esa caché se extrajo como función pura
  `sticpa_event_ids_from_calendar_cache()` en `inc/stic-calendar.php` y se cubrió con
  7 tests (`tests/CalendarCacheTest.php`): la distinción null / array-vacío es lo que
  evita inscripciones duplicadas.
  En el step 4 los rebotes NO usan `wp_redirect` (imposible: el shortcode se pinta con
  las cabeceras ya enviadas); usan el lenguaje de error de
  `pages/single_stic_payment_error.php` con su CTA, y `return` para no seguir.

## Why this matters

Cada llamada al CRM son cientos de milisegundos. La auditoría encontró un puñado de llamadas
que se repiten con el mismo resultado dentro de una petición o entre pantallas consecutivas,
y llamadas que se hacen cuando su resultado se va a descartar. Son arreglos pequeños y
locales — sin refactor — que quitan 1-11 round-trips POR PANTALLA en los flujos más usados:
Eventos, la ficha de evento, el alta de inscripción (el flujo estrella), la home y el
formulario de pago. Además corrige un bug real: el redirect al formulario de pago apunta a
una página que no existe.

## Current state

Archivos y hechos (todo verificado contra el código en `337ec6a`):

### A. El guard de inscripción es `1+R` llamadas y se ejecuta hasta 4 veces por flujo

- `inc/stic-action.php:372-411` — `prefix_user_active_event_ids($objSCP)`: 1 llamada
  (`getRelatedElementsForLoggedUser`, inscripciones del usuario) + **una llamada por
  inscripción** para resolver su evento (`:394-401`). Sin memoización.
- Se invoca desde 4 sitios: `pages/list_stic_events.php:41`,
  `pages/single_stic_events.php:55`, `pages/single_stic_registrations.php:152` (los tres vía
  `prefix_user_has_active_registration`, `inc/stic-action.php:413-419`) y el handler POST
  `inc/stic-action.php:448`.
- **El mismo dato ya existe cacheado**: `inc/stic-calendar.php:233-399`
  (`sticpa_gather_calendar_data($objSCP)`) devuelve `'registered_events'` (array de
  `['id','name','start','end']`) y lo guarda en un transient de 300 s
  (`:239-244`, `:395-397`), que el propio handler de inscripciones invalida al guardar
  (`inc/stic-action.php:460-462` → `sticpa_calendar_flush_cache()`).

### B. La home consulta el CRM para un booleano que casi nunca cambia

- `pages/single_stic_home.php:101` llama `sticpa_monitor_ds_pending()` sin argumento.
- `inc/stic-comunica-roles.php:157-169` — con `$data === null` hace un `getRecordDetail`
  al CRM solo para leer `ajmcm_aut_del_sex_c` y `ajmcm_cert_del_sex_c`. Sin caché.
- El único sitio que CAMBIA ese estado es `comunica_upload_certificate()`
  (`inc/stic-action.php:1171-1211`, paso 4: `set_entry` del flag `:1206`).
- El patrón bueno ya existe: `pages/single_stic_comunica_monitor.php:209` pasa `$data` ya
  cargado y no genera llamada.

### C. El formulario de pago se salta la caché de definiciones y su redirect está roto

- `pages/single_stic_payment_form.php:30`:
  ```php
  $fieldsDefinitionResults = $objSCP->getFieldDefinition('stic_Payment_Commitments', array('payment_method'));
  $paymentMethodOptions = $fieldsDefinitionResults->module_fields->payment_method->options;
  ```
  Llamada DIRECTA al CRM en cada vista. El resto de formularios usan
  `sticpa_cached_field_definition()` (`inc/stic-formController.php:65-78`, transient 6 h)
  — ojo: el helper devuelve **array**, la llamada directa devuelve **objeto**.
- `inc/stic-action.php:466` — tras crear una inscripción con `action == 'payment'`:
  ```php
  $redirectUrl = ... . "?internalpage=single_stic_payments_form&registrationId=" ...
  ```
  La página real es `pages/single_stic_payment_form.php` (**payment**, singular). El
  saneador `sticpa_resolve_page_file()` (`sinergiacrm-private-area.php:935-944`) devuelve
  `''` para nombres inexistentes → pantalla vacía.
- `pages/single_stic_payment_form.php:13-19` y `:47-52` — cuando faltan datos emite
  `alert()` bloqueante + `window.location.href` por JS, pero **sigue ejecutando** las
  llamadas al CRM de la página (`:21`, `:30`, `:40`) cuyo resultado se descarta.

### D. Formularios de creación piden al CRM un registro con id null

- `pages/single_stic_documents.php:160`, `pages/single_stic_contacts.php:106`,
  `pages/single_stic_relationships.php:82`, `pages/single_stic_payment_commitments.php:127`
  — todos con el mismo patrón:
  ```php
  $data = $objSCP->getRecordDetail($_REQUEST['id'] ?? null, $formSettings['moduleName'])->entry_list[0]->name_value_list;
  ```
  En modo creación (`action=create`, sin `id`) la llamada se hace igual y devuelve nada útil.
- **El patrón correcto ya existe** en `pages/single_stic_registrations.php:122-124`:
  ```php
  $data = null;
  if (!empty($_REQUEST['id']) && $_REQUEST['action'] !== 'create') {
      $data = $objSCP->getRecordDetail($_REQUEST['id'], $formSettings['moduleName'])->entry_list[0]->name_value_list;
  ```
- `makeForm` tolera `$data = null` (usa `empty($data->id->value)` con null-safe en
  `inc/stic-formController.php:126`).

### E. Guardar el perfil de monitor con certificados hace un `set_entry` de flag POR archivo

- `inc/stic-action.php:1155-1160` — bucle sobre 4 certificados; cada
  `comunica_upload_certificate()` hace 4 llamadas, la última (`:1206`):
  ```php
  $objSCP->set_entry('Contacts', array('id' => $contactId, $meta['flag'] => '1'));
  ```
  Con 4 archivos son 4 `set_entry('Contacts')` colapsables en 1.

### Convenciones del repo

- PHP procedural, compatible 7.4+. Helpers `sticpa_*` / `prefix_*` en `inc/`.
- Comentarios en español que explican el PORQUÉ (mira `inc/stic-calendar.php:225-232`).
- Cachés = transients de WordPress (hosting compartido, sin object cache) o `$_SESSION`.
- `static` por petición es aceptable para memoizar (patrón nuevo pero estándar en WP).

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint global | `find . -name "*.php" -not -path "./vendor/*" -not -path "./.agents/*" -not -path "./.claude/*" -print0 \| xargs -0 -n1 -P4 php -l >/dev/null` | exit 0 |
| Tests (con vendor/) | `composer test` | verdes |

## Scope

**In scope**:
- `inc/stic-action.php` (funciones: `prefix_user_active_event_ids`,
  `prefix_admin_single_stic_registrations` solo la línea del typo, `prefix_comunica_save_contact`,
  `comunica_upload_certificate`)
- `inc/stic-comunica-roles.php` (solo `sticpa_monitor_ds_pending`)
- `pages/single_stic_payment_form.php`
- `pages/single_stic_documents.php`, `pages/single_stic_contacts.php`,
  `pages/single_stic_relationships.php`, `pages/single_stic_payment_commitments.php`
  (solo la línea del `getRecordDetail` de creación)

**Out of scope** (NO tocar):
- Los N+1 internos de `sticpa_gather_calendar_data` y de los listados
  (`list_stic_sessions/payments/attendances`, `single_stic_profile_selection`) — **plan 011**.
- `inc/stic-class-6.php` — plan 027/008.
- Añadir `select_fields` a los `getRecordDetail` de fichas de perfil — deliberadamente
  aplazado: el motor de formularios puede depender de campos no declarados; hacerlo requiere
  QA visual por página (anotado en Maintenance notes).
- Cualquier cambio de UI/HTML más allá del mensaje del step 4.

## Git workflow

- Rama: la del operador; si no, `advisor/029-crm-read-quickwins`.
- Un commit por step (así un STOP no bloquea el resto), estilo del repo:
  `perf(eventos): el guard de inscripción reutiliza la caché del calendario`.
- NO push/PR salvo instrucción.

## Steps

### Step 1: Memoizar el guard de inscripción y alimentarlo del transient del calendario

En `inc/stic-action.php`, reescribe `prefix_user_active_event_ids` así (conserva el docblock
existente y añade el porqué):

```php
function prefix_user_active_event_ids($objSCP, $fresh = false)
{
    // Memo por petición: el guard se consulta desde el listado de eventos, la
    // ficha de evento, el formulario de inscripción Y el handler de guardado.
    static $memo = null;
    if ($memo !== null && !$fresh) {
        return $memo;
    }

    // La lista de eventos inscritos ya la calcula (y cachea 300s) el calendario:
    // misma fuente (stic_registrations_*), cero llamadas extra si está caliente.
    if (!$fresh && function_exists('sticpa_gather_calendar_data')) {
        $cal = sticpa_gather_calendar_data($objSCP);
        if (isset($cal['registered_events']) && is_array($cal['registered_events'])) {
            $memo = array_values(array_unique(array_filter(array_column($cal['registered_events'], 'id'))));
            return $memo;
        }
    }

    // Camino directo (usado con $fresh=true por el handler de guardado).
    // ... (el cuerpo actual de la función, SIN cambios: :374-410)
    $memo = array_values(array_unique($ids));
    return $memo;
}
```

Y en el guard del handler POST (`inc/stic-action.php:448`), pásale frescura real — el guard
anti-duplicado de una ESCRITURA no debe fiarse de una caché de 300 s:

```php
// antes: if (prefix_user_has_active_registration($objSCP, $eventId)) {
if (in_array($eventId, prefix_user_active_event_ids($objSCP, true), true)) {
```

(`prefix_user_has_active_registration` (`:413-419`) queda como está para las vistas.)

Detalle importante: `sticpa_gather_calendar_data` ignora inscripciones `cancelled`
(`inc/stic-calendar.php:269`), igual que el guard actual (`inc/stic-action.php:391`) —
misma semántica, verificado.

**Verify**: `php -l inc/stic-action.php` → sin errores.
**Verify**: `grep -n "prefix_user_active_event_ids(\$objSCP, true)" inc/stic-action.php` → 1 resultado (el handler).

### Step 2: Cachear en sesión el aviso de certificado pendiente del monitor

En `inc/stic-comunica-roles.php`, `sticpa_monitor_ds_pending` (`:153`): antes de la rama
`if ($data === null)`, consulta la caché; después de calcular, guárdala:

```php
function sticpa_monitor_ds_pending($data = null)
{
    if (sticpa_get_comunica_role() !== 'monitor') {
        return false;
    }
    // Con $data del llamante no hay llamada al CRM: se calcula y refresca la caché.
    if ($data === null && isset($_SESSION['scp_ds_pending'])) {
        return (bool) $_SESSION['scp_ds_pending'];
    }
    // ... (cuerpo actual: la llamada al CRM si $data === null, y el cálculo)
    $_SESSION['scp_ds_pending'] = $pending; // justo antes de cada return del resultado
    return $pending;
}
```

Ajusta los nombres al cuerpo real de la función (léela entera primero; el cálculo final
devuelve un booleano a partir de `ajmcm_aut_del_sex_c` / `ajmcm_cert_del_sex_c`).

E invalida donde cambia el estado — `inc/stic-action.php`, `comunica_upload_certificate()`,
justo después del `set_entry` del flag (`:1206`):

```php
    unset($_SESSION['scp_ds_pending']);
```

**Verify**: `php -l inc/stic-comunica-roles.php && php -l inc/stic-action.php` → sin errores.
**Verify**: `grep -c "scp_ds_pending" inc/stic-comunica-roles.php` → ≥ 2, y
`grep -c "scp_ds_pending" inc/stic-action.php` → 1.

### Step 3: Corregir el typo del redirect de pago

En `inc/stic-action.php:466`, cambia `single_stic_payments_form` por
`single_stic_payment_form` (el nombre del archivo real en `pages/`).

**Verify**: `grep -rn "single_stic_payments_form" inc/ pages/` → sin resultados.

### Step 4: El formulario de pago usa la caché de definiciones y no llama al CRM cuando va a rebotar

En `pages/single_stic_payment_form.php`:

1. Sustituye la llamada directa (`:30-31`) por el helper cacheado (devuelve ARRAY, no objeto):
   ```php
   $paymentMethodDef = sticpa_cached_field_definition($objSCP, 'stic_Payment_Commitments', array('payment_method'));
   $paymentMethodOptions = $paymentMethodDef['payment_method']['options'] ?? array();
   ```
   Y adapta el bucle de `:34-36` a acceso de array:
   ```php
   foreach ($paymentMethod as $elem) {
       $label = $paymentMethodOptions[$elem]['value'] ?? $elem;
       $paymentMethodOptionsHtml .= "<option value='" . $elem . "'>" . $label . "</option>";
   }
   ```
2. Convierte los dos rebotes en salidas tempranas: tras emitir el bloque de
   `:14-18` (falta `registrationId`) añade un `return`-equivalente — al ser un archivo
   incluido que concatena a `$html`, envuelve el resto de la página en el `else`, o usa
   `if (...) { $html .= ...; } else { ...resto de la página... }`. Igual con el bloque de
   `:47-52` (faltan campos del contacto): muévelo ANTES de montar el formulario y salta el
   resto si falta algo. Sustituye los `alert('...')` por un aviso con las clases del área
   (`<span class='error' role='alert'>…</span>`) seguido del `window.location.href` SIN alert.

**Verify**: `php -l pages/single_stic_payment_form.php` → sin errores.
**Verify**: `grep -n "getFieldDefinition" pages/single_stic_payment_form.php` → sin resultados.
**Verify**: `grep -c "alert(" pages/single_stic_payment_form.php` → 0.

### Step 5: No pedir el registro al CRM en modo creación

En estos 4 archivos, sustituye la línea del patrón D por el patrón de
`pages/single_stic_registrations.php:122-124` (extracto en "Current state"):

- `pages/single_stic_documents.php:160`
- `pages/single_stic_contacts.php:106`
- `pages/single_stic_relationships.php:82`
- `pages/single_stic_payment_commitments.php:127`

Forma exacta (idéntica en los 4):

```php
$data = null;
if (!empty($_REQUEST['id'])) {
    $data = $objSCP->getRecordDetail($_REQUEST['id'], $formSettings['moduleName'])->entry_list[0]->name_value_list;
}
```

NO toques el `getRecordDetail` de `pages/single_stic_documents.php:95` (pide la ficha del
usuario logueado, no el documento; siempre tiene id).

**Verify**: `php -l` en los 4 archivos → sin errores.
**Verify**: `grep -n "getRecordDetail(\$_REQUEST\['id'\] ?? null" pages/` → sin resultados.

### Step 6: Un solo set_entry para los flags de certificados

En `inc/stic-action.php`:

1. `comunica_upload_certificate()` (`:1171-1211`): elimina el paso 4 (el
   `set_entry('Contacts', ...)` del flag, `:1206`) y devuelve el flag junto al docId, p. ej.
   `return array('docId' => $docId, 'flag' => $meta['flag']);` (hoy devuelve `$docId` o
   `false`; nadie usa el valor de retorno — verifícalo con
   `grep -n "comunica_upload_certificate" inc/ pages/`).
2. En el bucle del llamante (`:1155-1160`), acumula los flags de las subidas que devuelvan
   truthy y haz UN `set_entry` al final:
   ```php
   $flagsToSet = array();
   foreach ($certs as $field => $meta) {
       if (isset($_FILES[$field]) && !empty($_FILES[$field]['name'])) {
           $up = comunica_upload_certificate($objSCP, $id, $field, $meta);
           if (!empty($up['flag'])) {
               $flagsToSet[$up['flag']] = '1';
           }
       }
   }
   if (!empty($flagsToSet)) {
       $objSCP->set_entry('Contacts', array_merge(array('id' => $id), $flagsToSet));
       unset($_SESSION['scp_ds_pending']); // coherente con el step 2
   }
   ```
   (Si hiciste el step 2, mueve aquí el `unset` — el flag ya no se escribe dentro del helper.)

**Verify**: `php -l inc/stic-action.php` → sin errores.
**Verify**: dentro de `comunica_upload_certificate` ya no hay `set_entry('Contacts'`:
`awk '/function comunica_upload_certificate/,/^}/' inc/stic-action.php | grep -c "set_entry('Contacts'"` → `0`.

## Test plan

- `composer test` (si hay `vendor/`): la suite existente sigue verde.
- Test nuevo NO requerido pero recomendado si `vendor/` está disponible: en `tests/`, un
  `RegistrationGuardTest.php` que cargue una versión aislada de la lógica del memo (patrón:
  `tests/SessionTest.php` stubea lo mínimo en `tests/bootstrap.php`). Si stubear
  `SugarRestApiCall`/`sticpa_gather_calendar_data` se complica, déjalo y anótalo — la
  verificación por grep + staging basta para este plan.
- En staging: (1) Eventos y ficha de evento muestran lo mismo que antes; (2) inscribirse a
  un evento sigue bloqueando el duplicado (probar doble submit); (3) crear un documento
  nuevo funciona; (4) el flujo `action=payment` llega al formulario de pago (typo).

## Done criteria

- [ ] Lint global exit 0
- [ ] `grep -rn "single_stic_payments_form" inc/ pages/` → 0 resultados
- [ ] `grep -n "getFieldDefinition" pages/single_stic_payment_form.php` → 0 resultados
- [ ] `grep -n "getRecordDetail(\$_REQUEST\['id'\] ?? null" pages/` → 0 resultados
- [ ] El handler POST de inscripciones usa `prefix_user_active_event_ids($objSCP, true)`
- [ ] `git status --porcelain` solo lista archivos in-scope (y `plans/README.md`)
- [ ] Fila 029 actualizada en `plans/README.md` (con los steps completados)

## STOP conditions

- El cuerpo de `prefix_user_active_event_ids` o de `sticpa_gather_calendar_data` no coincide
  con los extractos (quizá el plan 011 ya los refactorizó) → STOP del step 1; reconcilia.
- `comunica_upload_certificate` tiene algún llamante que use su valor de retorno de forma
  incompatible → STOP del step 6.
- En el step 4, el formulario de pago resulta tener otro flujo de rebote no descrito aquí →
  STOP de ese step (es camino de dinero).
- Cualquier verificación falla dos veces tras un intento razonable de arreglo.

## Maintenance notes

- El step 1 crea un acoplamiento deliberado: el guard de inscripción LEE la caché del
  calendario. Si algún día `sticpa_gather_calendar_data` cambia la semántica de
  `registered_events` (p. ej. incluir canceladas), el guard de las VISTAS mentiría (el del
  handler no: usa `$fresh=true`). Cualquier cambio allí debe re-mirar esto.
- Aplazado conscientemente: pasar `select_fields` explícito a los `getRecordDetail` de las
  fichas de perfil (`single_stic_comunica_perfil.php:57`, `single_stic_profile.php:83`,
  `single_stic_comunica_monitor.php:27`, `single_stic_tutor_profile.php:30`) — recorta
  payload, pero el motor de formularios puede leer campos no declarados y hace falta QA
  visual pantalla a pantalla. Candidato a plan propio si tras 027+029 las fichas siguen lentas.
- Reviewer: en el step 1, comprobar que el handler POST NUNCA lee del memo/caché
  (`$fresh=true` es la línea de seguridad anti-duplicados).
