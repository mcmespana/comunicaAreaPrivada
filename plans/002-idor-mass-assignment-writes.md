# Plan 002: Las escrituras al CRM solo tocan el registro del usuario y solo campos permitidos

> **Executor instructions**: Sigue el plan paso a paso; verifica cada paso. Ante una STOP
> condition, para y reporta. Al terminar actualiza la fila en `plans/README.md`.
>
> **Drift check (primero)**: `git diff --stat bc3c436..HEAD -- inc/stic-action.php`
> Compara los excerpts antes de editar; si no coinciden, STOP.

## Status

- **Priority**: P0
- **Effort**: M
- **Risk**: MED
- **Depends on**: plans/001-auth-gate-state-changing-handlers.md
- **Category**: security
- **Planned at**: commit `bc3c436`, 2026-07-19

## Why this matters

Los handlers de escritura toman el `id` del registro desde `$_REQUEST` y vuelcan **todo**
`$_REQUEST` a `set_entry`. Consecuencia doble: (1) IDOR — pasando `id=<otro contacto>` se
sobrescribe la ficha de cualquiera; (2) mass-assignment — se pueden fijar campos que el formulario
nunca muestra, incluidos `stic_pa_password_c` (contraseña del área privada) y `ajmcm_pa_token_c`
(token de acceso permanente): **toma de control de cualquier cuenta del portal**. El arreglo ya
existe en el mismo archivo (el handler del familiar); hay que replicarlo.

## Current state

- **Handler vulnerable** — `prefix_admin_single_stic_profile` (`inc/stic-action.php:57-83`):
  ```php
  foreach ($_REQUEST as $key => $value) {
      $moduleData[$key] = is_array($value) ? '^' . implode('^,^', stripslashes_deep($value)) . '^' : stripslashes_deep($value);
  }
  $id = $_REQUEST['id'] ?? $_SESSION['scp_user_id'];   // ← id desde el request
  $moduleData['id'] = $id;
  $isUpdate = $objSCP->set_entry($moduleName, $moduleData);
  ```
- **Exemplar ya correcto** — `prefix_admin_single_stic_tutor_profile` (`inc/stic-action.php:97-118`):
  ```php
  $tutorId = $_SESSION['scp_tutor_user_id'] ?? ($_SESSION['scp_user_id'] ?? '');
  if (!$tutorId) { wp_redirect(home_url()); exit; }
  $skip = array('action', 'scp_current_url', 'stic-action', 'save', 'back', 'id', 'stic_year_only_fields');
  $moduleData = array();
  foreach ($_REQUEST as $key => $value) {
      if (in_array($key, $skip, true)) { continue; }
      $moduleData[$key] = is_array($value) ? '^' . implode('^,^', stripslashes_deep($value)) . '^' : stripslashes_deep($value);
  }
  $moduleData['id'] = $tutorId;   // ← id desde la sesión
  ```
- **Otros handlers con el mismo defecto** (id del request + volcado completo):
  documents (~156-175), relationships (~252-261), payment_commitments (~291-300),
  payments (~329-338), registrations (~427-451), job_applications (~485-493), contacts (~974-981).
- **Campos que NUNCA deben aceptarse del cliente**: `stic_pa_password_c`, `ajmcm_pa_token_c`,
  `assigned_user_id`, `deleted` (salvo la ruta de borrado controlada del handler de documentos),
  `id` (se fija desde la sesión).
- Convención: para signup (crear) el `id` NO se fija (es alta nueva); ese handler necesita otro
  tratamiento — ver Step 4.

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint PHP | `php -l inc/stic-action.php` | `No syntax errors detected` |

## Scope

**In scope**: `inc/stic-action.php`.
**Out of scope**: `inc/stic-class-6.php` (la API del CRM); la guarda de sesión (plan 001, ya hecho);
la comprobación de propiedad en las páginas de detalle (plan 003).

## Steps

### Step 1: Un helper compartido de sanitizado + allow-list negativa

Añade cerca de la cabecera de `inc/stic-action.php`:

```php
/**
 * Convierte $_REQUEST en datos para set_entry descartando claves que no son campos
 * del CRM y campos sensibles que el cliente nunca debe fijar. Mantén el formato
 * multi-valor del CRM (^a^,^b^).
 */
function sticpa_request_to_module_data($extraSkip = array()) {
    $skip = array_merge(array(
        'action', 'scp_current_url', 'stic-action', 'save', 'back', 'id',
        'stic_year_only_fields', 'stic_pa_password_c', 'ajmcm_pa_token_c',
        'assigned_user_id', 'deleted',
    ), $extraSkip);
    $data = array();
    foreach ($_REQUEST as $key => $value) {
        if (in_array($key, $skip, true)) { continue; }
        $data[$key] = is_array($value)
            ? '^' . implode('^,^', stripslashes_deep($value)) . '^'
            : stripslashes_deep($value);
    }
    return $data;
}
```

**Verify**: `php -l inc/stic-action.php` → sin errores.

### Step 2: Migrar `prefix_admin_single_stic_profile` a id-desde-sesión + helper

Sustituye el loop + `$id = $_REQUEST['id'] ?? …` por:
```php
$moduleData = sticpa_request_to_module_data();
$moduleData['id'] = $_SESSION['scp_user_id'];   // el usuario solo edita SU ficha
$id = $moduleData['id'];
```
Deja el resto (upload de foto, redirect) igual, pero usa `$id` de la sesión.

**Verify**: `grep -n "\$_REQUEST\['id'\]" inc/stic-action.php` no debe listar la línea de este
handler (líneas ~57-83).

### Step 3: Migrar los demás handlers de auto-servicio

Para relationships, payment_commitments, payments, registrations, job_applications, contacts,
documents: sustituye su `foreach ($_REQUEST …)` por `$moduleData = sticpa_request_to_module_data();`.
Reglas por handler:
- Donde el registro pertenece al usuario actual y `id` identifica un registro RELACIONADO (no el
  contacto), **no basta con la sesión**: la comprobación de propiedad del `id` es del plan 003.
  Aquí, como mínimo, deja de aceptar los campos sensibles (ya excluidos por el helper) y no confíes
  en `deleted` del request (usa la ruta de borrado explícita ya existente en documents:162).
- En documents, la ruta de borrado (`$action === 'delete'`) fija `$moduleData['deleted'] = 1` de
  forma controlada tras leerlo — mantenla; el helper ya excluye `deleted` del volcado ciego.

**Verify**: `php -l inc/stic-action.php` → sin errores.
**Verify**: `grep -n "foreach (\$_REQUEST" inc/stic-action.php` no debe quedar en ninguno de los
handlers migrados (solo podría quedar en signup, ver Step 4).

### Step 4: Signup — bloquear los campos sensibles sin fijar id

`prefix_admin_single_stic_signup` crea un contacto nuevo (no fija `id`). Cámbialo para construir
sus datos con `sticpa_request_to_module_data()` (que ya excluye `stic_pa_password_c`,
`ajmcm_pa_token_c`, `assigned_user_id`, `id`, `deleted`) y añade explícitamente SOLO los campos que
el formulario de alta debe poder escribir (username y password del área privada se fijan por lógica
de servidor, no copiando el request). Si el signup necesita fijar `stic_pa_password_c`, hazlo con
una asignación explícita del valor saneado del formulario de alta, no vía el volcado.

**Verify**: pasar `stic_pa_password_c` o `ajmcm_pa_token_c` en un signup NO debe llegar al CRM
(revisar por lectura que esos campos solo se fijan por asignación explícita, nunca por el loop).

## Test plan

Manual (sin suite; ver 013):
- Logueado como A, POST a `single_stic_profile` con `id=<contacto B>` → debe editar A, no B.
- POST a cualquier handler con `stic_pa_password_c=x` o `ajmcm_pa_token_c=x` → no debe cambiar esos
  campos en el CRM.
- Guardar perfil/pago/documento normal sigue funcionando.

## Done criteria

- [ ] `php -l inc/stic-action.php` exit 0
- [ ] `grep -n "sticpa_request_to_module_data" inc/stic-action.php` → definición + ≥7 usos
- [ ] Ningún handler de auto-servicio toma `id` de `$_REQUEST` para `set_entry` (grep vacío en esas líneas)
- [ ] El helper excluye `stic_pa_password_c`, `ajmcm_pa_token_c`, `assigned_user_id`, `id`, `deleted`
- [ ] Fila 002 actualizada en `plans/README.md`

## STOP conditions

- Un handler necesita legítimamente escribir un campo de la lista de excluidos → para y reporta
  (probablemente hay que gestionarlo con asignación explícita, no relajar la lista).
- El flujo del familiar editando a un participante deja de guardar (el `id` correcto en ese caso es
  `scp_user_id`, el participante activo — no `scp_tutor_user_id`). Confírmalo con `docs/design-system.md` §6.
- Los excerpts no coinciden con el código vivo.

## Maintenance notes

- Reviewer: verificar que ningún handler futuro vuelva a `foreach ($_REQUEST …)` sin el helper.
- Este es el candidato natural para el "motor CRUD de escritura" (ver dirección en README): cuando
  se construya, este helper es su núcleo de sanitizado.
- La comprobación de propiedad del `id` de registros relacionados (documentos, pagos…) se completa
  en el plan 003; sin él, un usuario logado aún podría tocar registros de otro por `id`.
