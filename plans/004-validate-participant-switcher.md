# Plan 004: El selector de participante solo acepta contactos del propio familiar

> **Executor instructions**: paso a paso, verifica, respeta STOP conditions, actualiza `plans/README.md`.
>
> **Drift check**: `git diff --stat bc3c436..HEAD -- inc/stic-action.php menu.php pages/single_stic_profile_selection.php`

## Status

- **Priority**: P0
- **Effort**: M
- **Risk**: MED
- **Depends on**: plans/001-auth-gate-state-changing-handlers.md
- **Category**: security
- **Planned at**: commit `bc3c436`, 2026-07-19

## Why this matters

El cambio de participante escribe `$_SESSION['scp_user_id']` con el `profile_selected_id` del
request **sin comprobar** que ese contacto sea realmente uno de los participantes a cargo del
familiar. Como `scp_user_id` es lo que leen TODAS las páginas para leer y escribir datos, un
usuario logado puede fijarlo a cualquier GUID del CRM y a partir de ahí ver/editar la ficha de
cualquier contacto por la UI normal (escalada horizontal de privilegios).

## Current state

- Handler — `prefix_admin_single_stic_profile_selection` (`inc/stic-action.php:12-48`). Tras la
  guarda de sesión (plan 001), asigna directamente:
  ```php
  $requestUserId = sanitize_text_field($_REQUEST['profile_selected_id']);
  ...
  $_SESSION['scp_user_id'] = $requestUserId;              // ← sin validar pertenencia
  $_SESSION['scp_user_contact_name'] = $requestUserName;
  ```
- La lista legítima de participantes del familiar se cachea en
  `$_SESSION['scp_available_profiles']` (array de `{id, name}`) — ver `docs/design-system.md` §6
  ("Modelo de sesión"). Hoy el handler **no la consulta**.
- Cómo se rellena `scp_available_profiles`: lo produce la pantalla de selección
  (`pages/single_stic_profile_selection.php`) y/o el filtro `sticpa_familia_participants`. Con el
  CRM de Comunica aún sin las relaciones `stic_Personal_Environment`, en pruebas se usa
  `?familia_demo=1` (ver §6). El validador debe funcionar con ambos: sesión real y demo.

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint PHP | `php -l inc/stic-action.php` | `No syntax errors detected` |

## Scope

**In scope**: `inc/stic-action.php` (solo el handler del selector).
**Out of scope**: cómo se rellena `scp_available_profiles` (eso es del plan FAM-01 / §6); el
rendimiento del switcher (plan 011).

## Steps

### Step 1: Validar el id contra la lista de participantes disponibles

En `prefix_admin_single_stic_profile_selection`, antes de asignar `scp_user_id`, comprueba que el
id solicitado esté en `scp_available_profiles` (o sea el propio familiar):

```php
$requestUserId = sanitize_text_field($_REQUEST['profile_selected_id']);

$allowed = array();
if (!empty($_SESSION['scp_available_profiles']) && is_array($_SESSION['scp_available_profiles'])) {
    foreach ($_SESSION['scp_available_profiles'] as $p) {
        if (!empty($p['id'])) { $allowed[] = $p['id']; }
    }
}
// El familiar siempre puede elegirse a sí mismo.
if (!empty($_SESSION['scp_tutor_user_id'])) { $allowed[] = $_SESSION['scp_tutor_user_id']; }

if (!in_array($requestUserId, $allowed, true)) {
    // Id no autorizado: no cambiamos de participante.
    wp_redirect(home_url());
    exit;
}
```
Deja el resto del handler igual (fijar tutor la primera vez, `scp_tutor_is_user`, redirect).

**Verify**: `php -l inc/stic-action.php` → sin errores.

### Step 2: Derivar el nombre del servidor, no del request

En vez de guardar `scp_user_contact_name` desde `$_REQUEST['profile_selected_name']`, tómalo del
elemento correspondiente de `scp_available_profiles` (mismo id) para que el nombre mostrado no sea
manipulable:
```php
$requestUserName = $requestUserId;
foreach (($_SESSION['scp_available_profiles'] ?? array()) as $p) {
    if (($p['id'] ?? null) === $requestUserId) { $requestUserName = $p['name'] ?? $requestUserId; break; }
}
```
(Si el id es el propio familiar, usa `scp_tutor_user_contact_name`.)

**Verify**: `grep -n "profile_selected_name" inc/stic-action.php` — ya no debe usarse para fijar el
nombre de sesión directamente.

## Test plan

Manual (sin suite; ver 013):
- Con `?familia_demo=1`, cambiar entre los participantes de ejemplo funciona.
- POST con `profile_selected_id=<GUID ajeno>` que no esté en `scp_available_profiles` → redirige a
  home, `scp_user_id` no cambia.
- El familiar puede volver a verse a sí mismo.

## Done criteria

- [ ] `php -l inc/stic-action.php` exit 0
- [ ] El handler rechaza ids fuera de `scp_available_profiles` (+ el propio tutor)
- [ ] `scp_user_contact_name` se deriva de la lista de sesión, no del request
- [ ] Fila 004 actualizada en `plans/README.md`

## STOP conditions

- `scp_available_profiles` está vacío en un flujo legítimo (p. ej. porque aún no se rellena con el
  CRM real) y la validación bloquea al usuario que debería poder cambiar → para y reporta: la
  validación debe convivir con el estado real de FAM-01 (quizá permitir solo el propio tutor hasta
  que existan las relaciones).
- Los excerpts no coinciden con el código vivo.

## Maintenance notes

- Cuando FAM-01 conecte las relaciones reales del CRM, `scp_available_profiles` se poblará desde
  ahí; este validador seguirá siendo correcto sin cambios.
- Reviewer: confirmar que no queda ninguna ruta que escriba `scp_user_id` desde el request sin pasar
  por esta validación.
