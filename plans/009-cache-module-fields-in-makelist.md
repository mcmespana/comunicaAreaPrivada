# Plan 009: `makeList` cachea las definiciones de campo como ya hace `makeForm`

> **Executor instructions**: paso a paso, verifica, respeta STOP conditions, actualiza `plans/README.md`.
>
> **Drift check**: `git diff --stat bc3c436..HEAD -- inc/stic-listController.php inc/stic-formController.php`

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `bc3c436`, 2026-07-19

## Why this matters

El arreglo de rendimiento PERF-01 cacheó `get_module_fields` en `makeForm` (transient 6h), pero
`makeList` quedó fuera: cada una de las ~13 páginas de listado hace todavía una llamada síncrona al
CRM (~0,5-2s) solo para las etiquetas/tipos de columna, que cambian rarísimo. Reutilizar el mismo
patrón elimina esa llamada por listado.

## Current state

- **Sin cache** — `inc/stic-listController.php:8`:
  ```php
  $fieldsDefinitionResults = $objSCP->getFieldDefinition($listSettings['moduleName'], $fields);
  $fieldsDefinitionResultsArray = json_decode(json_encode($fieldsDefinitionResults), true);
  $fieldsDefinition = $fieldsDefinitionResultsArray['module_fields'] ?? null;
  ```
- **Exemplar con cache** — `inc/stic-formController.php:65-74`:
  ```php
  $cacheKey = 'sticpa_fdef_' . md5($formSettings['moduleName'] . '|' . implode(',', $fields));
  $fieldsDefinition = isset($_GET['refresh_fields']) ? false : get_transient($cacheKey);
  if ($fieldsDefinition === false || !is_array($fieldsDefinition)) {
      $fieldsDefinitionResults = $objSCP->getFieldDefinition($formSettings['moduleName'], $fields);
      $fieldsDefinitionResultsArray = json_decode(json_encode($fieldsDefinitionResults), true);
      $fieldsDefinition = $fieldsDefinitionResultsArray['module_fields'] ?? array();
      if (!empty($fieldsDefinition)) {
          set_transient($cacheKey, $fieldsDefinition, 6 * HOUR_IN_SECONDS);
      }
  }
  ```
  El bypass `?refresh_fields=1` y el TTL de 6h ya están aceptados por el proyecto.

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint | `php -l inc/stic-listController.php && php -l inc/stic-formController.php` | sin errores |

## Scope

**In scope**: `inc/stic-listController.php`; opcionalmente extraer un helper compartido en
`inc/stic-formController.php` (o un archivo común ya incluido).
**Out of scope**: cambiar el TTL, invalidación por evento, u otras cachés (plan 011/PERF-08).

## Steps

### Step 1: Extraer un helper compartido de definición cacheada (recomendado)

Para no duplicar el patrón, crea una función reutilizable (en `inc/stic-formController.php`, que ya
se incluye antes que el list controller, o donde el proyecto cargue utilidades comunes):
```php
/**
 * Definición de campos de un módulo, cacheada 6h (bypass con ?refresh_fields=1).
 */
function sticpa_cached_field_definition($objSCP, $moduleName, $fields) {
    $cacheKey = 'sticpa_fdef_' . md5($moduleName . '|' . implode(',', $fields));
    $def = isset($_GET['refresh_fields']) ? false : get_transient($cacheKey);
    if ($def === false || !is_array($def)) {
        $res = $objSCP->getFieldDefinition($moduleName, $fields);
        $arr = json_decode(json_encode($res), true);
        $def = $arr['module_fields'] ?? array();
        if (!empty($def)) { set_transient($cacheKey, $def, 6 * HOUR_IN_SECONDS); }
    }
    return $def;
}
```
Luego haz que `makeForm` (65-74) llame a este helper en lugar de su bloque inline (mantén el mismo
comportamiento y clave — la clave `sticpa_fdef_` + `md5(module|fields)` es idéntica, así que las
cachés existentes siguen válidas).

**Verify**: `php -l inc/stic-formController.php` → sin errores.

### Step 2: Usar el helper en `makeList`

`inc/stic-listController.php:8` →
```php
$fieldsDefinition = sticpa_cached_field_definition($objSCP, $listSettings['moduleName'], $fields);
```
Elimina las dos líneas de `json_decode(...)`/`module_fields` que quedan redundantes (el helper ya
devuelve el array de `module_fields`).

**Verify**: `php -l inc/stic-listController.php` → sin errores.
**Verify**: `grep -n "getFieldDefinition" inc/stic-listController.php` → 0 (ya solo dentro del helper).

## Test plan

Manual / staging (sin suite; ver 013):
- Abrir un listado dos veces: la segunda no debe hacer la llamada `get_module_fields` (comprobar por
  logs del CRM o tiempo de carga).
- `?refresh_fields=1` vuelve a pedir la definición.
- Las columnas se siguen mostrando con sus etiquetas/tipos correctos.

## Done criteria

- [ ] `php -l` de ambos archivos exit 0
- [ ] `makeList` obtiene la definición vía la caché (helper o inline con la misma clave)
- [ ] La clave de transient sigue siendo `sticpa_fdef_` + `md5(module|fields)` (no invalida la de forms)
- [ ] Fila 009 actualizada en `plans/README.md`

## STOP conditions

- La forma del array devuelto por `getFieldDefinition` difiere entre form y list (estructura distinta
  de `module_fields`) → confírmalo antes de compartir el helper; si difiere, cachea en cada sitio con
  su propia normalización pero misma estrategia.

## Maintenance notes

- Si en el futuro se invalida la caché al tocar Studio, hazlo en un solo sitio (el helper).
- Reviewer: confirmar que la clave no cambió (para no descachear los formularios en producción).
