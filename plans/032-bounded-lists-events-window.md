# Plan 032: Los listados dejan de descargar el histórico entero del CRM

> **Executor instructions**: Sigue este plan paso a paso. Los steps son independientes.
> Ejecuta cada verificación antes de avanzar. ATENCIÓN: el step 1 incluye una decisión de
> producto ya tomada por el mantenedor en la ficha (ventana temporal de eventos) — léela.
> Al terminar, actualiza la fila de este plan en `plans/README.md`.
>
> **Drift check (ejecutar primero)**:
> `git diff --stat 337ec6a..HEAD -- pages/list_stic_events.php pages/single_stic_registrations.php pages/single_stic_job_applications.php inc/stic-events.php inc/stic-calendar.php`
> Ante un desajuste con "Current state", ese step es STOP.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED (cambia QUÉ registros se muestran — es visible para el usuario)
- **Depends on**: none (independiente del plan 011, que reduce el nº de llamadas; este reduce su TAMAÑO)
- **Category**: perf
- **Planned at**: commit `337ec6a`, 2026-08-09
- **Estado**: **PARCIAL** — steps 1-2 en `113255e` (2026-08-12).
  **Step 3 (techo de filas) NO aplicado**: las 4 páginas piden al CRM con `order_by`
  vacío, así que un límite mostraría los registros MÁS ANTIGUOS y esconderías los
  recientes — lo contrario de lo que se busca. Es justo el STOP que la propia ficha
  anticipaba: hace falta el `order_by` descendente correcto por módulo, validado
  contra el CRM real.
  En el step 2 la ventana se aplica al desplegable SOLO al crear: al editar una
  inscripción antigua el evento podría caer fuera de la ventana y guardar perdería la
  relación.

## Why this matters

Varias consultas al CRM piden TODO el módulo sin filtro ni límite, y el listado crece
linealmente con la antigüedad de la base de datos: el tiempo de respuesta del CRM, el tamaño
del JSON, el HTML generado y el tiempo de render en el WebView crecen con los años, no con lo
que el usuario necesita ver. Es degradación silenciosa: hoy "solo" va lento; dentro de dos
años irá el doble de lento. El caso más claro: "Eventos" descarga todos los eventos
históricos del CRM para mostrar los tres abiertos, mientras el calendario — misma entidad —
ya filtra una ventana de -3 a +12 meses.

## Current state

### Eventos sin filtro (la página y los desplegables)

- `pages/list_stic_events.php:34-36`:
  ```php
  $filterParam = '';
  $listSettings['fileName'] = basename(__FILE__, ".php");
  $getElements = $objSCP->getRecordsModule($listSettings['moduleName'], $filterParam, $fields);
  ```
  `getRecordsModule` (`inc/stic-class-6.php:411-439`) manda esa query vacía con
  `'max_results' => 0` (sin límite).
- El criterio correcto YA existe en el calendario — `inc/stic-calendar.php:367-370`:
  ```php
  // 2) Todos los eventos en la ventana (-3 meses … +12 meses). ...
  $filter = "(stic_events.start_date BETWEEN DATE_ADD(curdate(), INTERVAL -3 MONTH) AND DATE_ADD(curdate(), INTERVAL 12 MONTH))";
  $allEvents = $objSCP->getRecordsModule('stic_Events', $filter, array('id', 'name', 'type', 'start_date', 'end_date'));
  ```
- Desplegable de eventos del formulario de inscripción —
  `pages/single_stic_registrations.php:254-262`:
  ```php
  function getRelatedRecord($objSCP, $relatedModule) {
      $events = $objSCP->getRecordsModule($relatedModule);
      ...
  ```
  (sin query: módulo completo para rellenar un `<select>`). Mismo patrón en
  `pages/single_stic_job_applications.php:151-156` (ofertas de empleo).

### Decisión de producto (tomada por el mantenedor al aprobar este plan)

> **ACTUALIZADA el 2026-08-13**: la ventana hacia atrás es de **14 meses**, no de 3.
> Las actividades del MCM son anuales y se repiten, así que la edición del año
> anterior sigue siendo la referencia útil; con 3 meses desaparecía justo lo que la
> gente busca. 14 = ciclo anual + 2 meses de margen. Aplica al listado Y al
> calendario (allí los eventos pasados caen en su fecha, así que no molestan y
> permiten mirar atrás el curso anterior). Ajustable con
> `sticpa_events_window_months_back` / `_ahead`.

**"Eventos" muestra la misma ventana temporal que el calendario (-3 a +12 meses).** Los
eventos pasados siguen visibles en "Inscripciones" (las inscripciones del usuario no se
filtran por este plan). Si al ejecutar encuentras un requisito que contradiga esto (p. ej.
un texto de UI que prometa "histórico completo de eventos"), es STOP.

### Listados relacionados sin límite

- Todas las páginas de listado pasan `"limit" => 0` a `getRelatedElementsForLoggedUser`:
  `pages/list_stic_registrations.php:64`, `pages/list_stic_documents.php:64`,
  `pages/list_stic_payments.php:85,100,116,131`, `pages/list_stic_attendances.php:70,89`.
- Los 11 listados usan DataTables con `'paging' => false` (todas las filas van al HTML).
- La opción `sticpa_scp_case_per_page` está registrada
  (`sinergiacrm-private-area.php:193`) pero **no se usa en ningún sitio** (verificado por
  grep): la paginación se previó y nunca se implementó.

### Convenciones

- Helpers `sticpa_*` en `inc/`; los de eventos viven en `inc/stic-events.php`.
- Comentarios en español con el porqué. Mensajes de UI con `__('…', 'sticpa')`.

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint global | `find . -name "*.php" -not -path "./vendor/*" -not -path "./.agents/*" -not -path "./.claude/*" -print0 \| xargs -0 -n1 -P4 php -l >/dev/null` | exit 0 |
| Tests (con vendor/) | `composer test` | verdes |

## Scope

**In scope**:
- `inc/stic-events.php` (helper nuevo de ventana temporal)
- `inc/stic-calendar.php` (SOLO sustituir el filtro literal por el helper)
- `pages/list_stic_events.php`
- `pages/single_stic_registrations.php` y `pages/single_stic_job_applications.php`
  (solo su `getRelatedRecord`)
- `pages/list_stic_payments.php`, `pages/list_stic_attendances.php`,
  `pages/list_stic_registrations.php`, `pages/list_stic_documents.php` (solo el `limit`)

**Out of scope** (NO tocar):
- Los N+1 (llamadas por fila) de esas páginas — plan 011.
- `inc/stic-class-6.php` — no cambies los defaults de `getRecordsModule` (otros llamantes
  dependen de "sin límite", p. ej. herramientas de admin).
- Paginación completa con offset/navegación — deliberadamente aplazada (ver Maintenance).
- El chrome de DataTables en los listados — plan 031 step 4.

## Git workflow

- Rama: la del operador; si no, `advisor/032-bounded-lists`.
- Un commit por step, estilo repo: `perf(eventos): el listado usa la misma ventana temporal que el calendario`.
- NO push/PR salvo instrucción.

## Steps

### Step 1: Helper compartido de ventana temporal + aplicarlo al listado de Eventos

1. En `inc/stic-events.php`, añade (junto a los demás helpers `sticpa_event_*`):
   ```php
   /**
    * Filtro SQL de la ventana temporal de eventos "vivos" (-3 … +12 meses).
    * FUENTE ÚNICA: la usan el calendario y el listado de Eventos. Los eventos
    * pasados no desaparecen del área: siguen en "Inscripciones".
    * Ajustable sin tocar código con los filtros sticpa_events_window_*.
    */
   function sticpa_events_window_filter()
   {
       $back  = (int) apply_filters('sticpa_events_window_months_back', 3);
       $ahead = (int) apply_filters('sticpa_events_window_months_ahead', 12);
       return "(stic_events.start_date BETWEEN DATE_ADD(curdate(), INTERVAL -{$back} MONTH) AND DATE_ADD(curdate(), INTERVAL {$ahead} MONTH))";
   }
   ```
2. En `inc/stic-calendar.php:369`, sustituye el literal `$filter = "(stic_events...)"` por
   `$filter = sticpa_events_window_filter();` (mismo resultado por defecto — verifica que la
   cadena generada es idéntica a la actual).
3. En `pages/list_stic_events.php:34`, cambia `$filterParam = '';` por
   `$filterParam = sticpa_events_window_filter();`.

**Verify**: `php -l inc/stic-events.php inc/stic-calendar.php pages/list_stic_events.php`
(uno a uno) → sin errores.
**Verify**: `grep -c "sticpa_events_window_filter" inc/ pages/ -r` → 3 (definición + 2 usos).
**Verify**: `grep -n "INTERVAL -3 MONTH" inc/stic-calendar.php` → 0 resultados (el literal ya no está).

### Step 2: Los desplegables de eventos/ofertas no descargan el módulo entero

En `pages/single_stic_registrations.php` (`getRelatedRecord`, `:254`): el `<select>` de
eventos del formulario. Pasa la ventana y limita campos:

```php
function getRelatedRecord($objSCP, $relatedModule) {
    // Solo eventos de la ventana viva: el desplegable es para INSCRIBIRSE,
    // no para arqueología (los pasados están en "Inscripciones").
    $query = ($relatedModule === 'stic_Events' && function_exists('sticpa_events_window_filter'))
        ? sticpa_events_window_filter() : '';
    $events = $objSCP->getRecordsModule($relatedModule, $query, array('id', 'name'));
    ...
```

En `pages/single_stic_job_applications.php` (`getRelatedRecord`, `:151`): mismo cambio de
firma (`$query = ''` — las ofertas NO llevan ventana de eventos; solo añade el tercer
argumento `array('id', 'name')` para no pedir todos los campos). OJO: son dos funciones con
el MISMO nombre en archivos distintos — solo una se carga por petición (páginas distintas);
no las unifiques en este plan.

**Verify**: `php -l` en ambos → sin errores.
**Verify**: `grep -n "getRecordsModule(\$relatedModule)" pages/` → 0 resultados (todas las
llamadas llevan ya query y campos explícitos).

### Step 3: Techo de filas en los listados relacionados

En las páginas de listado, sustituye `"limit" => 0` por un techo con la opción ya registrada
(y hasta hoy sin uso) `sticpa_scp_case_per_page`:

1. En `inc/stic-events.php` NO — este helper es genérico, ponlo en
   `inc/stic-listController.php` (arriba, junto a `makeList`):
   ```php
   /**
    * Techo de filas de los listados. La opción sticpa_scp_case_per_page estaba
    * registrada desde el origen del plugin y sin usar; ahora es el techo global.
    * 0 o vacío = comportamiento antiguo (sin límite).
    */
   function sticpa_list_row_cap()
   {
       $cap = (int) get_option('sticpa_scp_case_per_page');
       return $cap > 0 ? $cap : (int) apply_filters('sticpa_list_row_cap_default', 200);
   }
   ```
2. Sustituye `"limit" => 0,` por `"limit" => sticpa_list_row_cap(),` en:
   - `pages/list_stic_registrations.php:64`
   - `pages/list_stic_documents.php:64`
   - `pages/list_stic_attendances.php:70` (solo el PRIMERO; el `:89` está dentro del bucle
     N+1 que arregla el plan 011 — no lo toques)
   - `pages/list_stic_payments.php:85` y `:116` (los principales; los `:100` y `:131`
     anidados son del plan 011 — no los toques)
3. En `inc/stic-listController.php`, `makeList()`: tras el bucle de filas, si el nº de filas
   pintadas es EXACTAMENTE `sticpa_list_row_cap()`, añade al final de la tabla un aviso
   honesto (patrón visual del estado vacío, `:159-168`):
   ```php
   $html .= "<p class='stic-empty-sub'>" . sprintf(__('Se muestran los %d registros más recientes.', 'sticpa'), sticpa_list_row_cap()) . "</p>";
   ```
   IMPORTANTE sobre "más recientes": las llamadas llevan `order_by` (cada página el suyo).
   Comprueba en cada página tocada que el `order_by` pone lo más relevante PRIMERO
   (p. ej. `list_stic_payments.php` ordena por fecha descendente). Si alguna ordena
   ascendente o no ordena, añade el `order_by` descendente por el campo de fecha del módulo
   ANTES de aplicar el límite — si no sabes qué campo es, STOP para esa página.

**Verify**: `php -l` en todos los tocados → sin errores.
**Verify**: `grep -n '"limit" => 0' pages/list_stic_registrations.php pages/list_stic_documents.php` → 0 resultados.
**Verify**: `grep -c "sticpa_list_row_cap" inc/stic-listController.php` → ≥ 2.

## Test plan

- `composer test` (con vendor/) → verde.
- Staging (operador):
  1. "Eventos" muestra los mismos eventos futuros/recientes que el calendario, y ya no los
     de hace años. Un evento pasado con inscripción sigue visible en "Inscripciones".
  2. El desplegable del formulario de inscripción ofrece solo eventos de la ventana.
  3. Un usuario con más de 200 pagos ve 200 + el aviso "Se muestran los 200 más recientes".
  4. Ajustar la opción en el admin de WP (SinergiaCRM Private Area → case per page) cambia el techo.

## Done criteria

- [ ] Lint global exit 0
- [ ] `grep -rn "INTERVAL -3 MONTH" inc/stic-calendar.php` → 0 (usa el helper)
- [ ] `pages/list_stic_events.php` pasa `sticpa_events_window_filter()` como query
- [ ] Ningún `getRecordsModule($relatedModule)` sin query/campos en `pages/`
- [ ] Los `limit => 0` principales de los 4 listados sustituidos por el cap
- [ ] `git status --porcelain` solo lista archivos in-scope (y `plans/README.md`)
- [ ] Fila 032 actualizada en `plans/README.md`

## STOP conditions

- El literal de la ventana en `inc/stic-calendar.php:369` no coincide (deriva o el plan 011
  ya reescribió el gather) → STOP del step 1.2 (el resto del step sigue).
- No puedes determinar el `order_by` correcto de una página en el step 3 → STOP para esa
  página, aplica el resto.
- Cualquier señal de que "Eventos" debe mostrar histórico completo (texto de UI, doc de
  producto) → STOP del step 1.3 y consulta al mantenedor.
- El plan 011 está ejecutándose a la vez sobre las mismas páginas → coordina: este plan va
  DESPUÉS en esas páginas.

## Maintenance notes

- La ventana (-3/+12) y el techo (200) son filtrables sin tocar código
  (`sticpa_events_window_months_*`, `sticpa_list_row_cap_default`) y el techo es además
  opción de admin. Documentado aquí como referencia.
- Paginación real (offset + "ver más") queda aplazada: exige propagar el offset por
  `buildActionsColumn` y los enlaces de acción. Si el techo de 200 se queda corto para
  alguien, ese es el siguiente paso — no subir el techo a lo loco.
- Reviewer: la trampa de este plan es el ORDEN — un límite sin `order_by` descendente
  muestra los 200 más VIEJOS. Verificar página a página.
- Si el plan 011 colapsa las llamadas anidadas de pagos/asistencias, los `limit` internos
  que este plan no tocó desaparecen con ellas.
