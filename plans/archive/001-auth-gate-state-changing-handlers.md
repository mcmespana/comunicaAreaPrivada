# Plan 001: Los handlers que mutan datos rechazan peticiones sin sesión

> **Executor instructions**: Sigue el plan paso a paso. Ejecuta cada verificación y confirma
> el resultado esperado antes de pasar al siguiente. Si ocurre algo de "STOP conditions", para
> y reporta — no improvises. Al terminar, actualiza la fila de este plan en `plans/README.md`.
>
> **Drift check (primero)**: `git diff --stat bc3c436..HEAD -- inc/stic-action.php`
> Si `inc/stic-action.php` cambió desde `bc3c436`, compara los excerpts de "Current state" con
> el código vivo antes de seguir; si no coinciden, es una STOP condition.

## Status

- **Priority**: P0
- **Effort**: S
- **Risk**: MED
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `bc3c436`, 2026-07-19

## Why this matters

Los usuarios del área privada son contactos del CRM, no usuarios de WordPress. Para WordPress,
toda visita es anónima, así que cada handler `admin_post_*` se registra también como
`admin_post_nopriv_*`. La ÚNICA autenticación posible es que el handler compruebe
`$_SESSION['scp_user_id']`. Hoy varios handlers de creación/edición/borrado y de descarga de
documentos **no lo comprueban**: cualquiera en internet puede invocar `admin-post.php` con
`action=single_stic_payments` (etc.), un `id` arbitrario y `stic-action=delete` para borrar o
sobrescribir registros del CRM sin login. Este plan cierra la puerta: sin sesión, no se toca nada.

## Current state

- `inc/stic-action.php` — controladores POST del front. Dos patrones coexisten:
  - **Ya endurecido** (exemplar a imitar) — `prefix_admin_single_stic_tutor_profile` (líneas 90-101):
    ```php
    $tutorId = $_SESSION['scp_tutor_user_id'] ?? ($_SESSION['scp_user_id'] ?? '');
    if (!$tutorId) {
        wp_redirect(home_url());
        exit;
    }
    ```
    Y `prefix_admin_single_stic_profile_selection` (líneas 16-19) hace lo mismo con
    `if (!isset($_SESSION['scp_user_id'])) { wp_redirect(home_url()); exit; }`.
  - **Sin comprobación de sesión** (los que hay que arreglar): cada uno empieza a trabajar
    (loop de `$_REQUEST`, `set_entry`, `download_document`) sin ninguna guarda:
    - `prefix_admin_single_stic_profile` — línea 57
    - `prefix_admin_single_stic_documents` — línea 144 (incluye `download_document($_REQUEST['id'])` en la 147)
    - `prefix_admin_single_stic_relationships` — ~línea 233
    - `prefix_admin_single_stic_payment_commitments` — ~línea 279
    - `prefix_admin_single_stic_payments` — ~línea 317
    - `prefix_admin_single_stic_registrations` — ~línea 355
    - `prefix_admin_single_stic_job_applications` — ~línea 473
    - `prefix_admin_single_stic_contacts` — ~línea 962

- **NO tocar la puerta de estos dos** (necesitan acceso anónimo legítimo): el signup
  (`prefix_admin_single_stic_signup`) y "he olvidado mi contraseña" / enlace mágico
  (`prefix_admin_stic_forgot_password`). Un usuario sin sesión debe poder usarlos.

- Convención del repo: los redirects terminan SIEMPRE en `wp_redirect(...); exit;` (ver
  `docs/design-system.md` §8.9). El id sobre el que se escribe sale de `$_SESSION`, nunca del request.

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint PHP | `php -l inc/stic-action.php` | `No syntax errors detected` |

(No hay suite de tests todavía — ver plan 013. Verifica por lint + lectura.)

## Scope

**In scope**:
- `inc/stic-action.php`

**Out of scope**:
- La lógica interna de cada handler (mass-assignment e IDOR se arreglan en el plan 002/003; aquí
  SOLO se añade la guarda de sesión al principio).
- `prefix_admin_single_stic_signup` y `prefix_admin_stic_forgot_password` (deben seguir `nopriv`).
- Los handlers del admin (`inc/stic-magic-login.php`): ya usan `current_user_can` + nonces.

## Git workflow

- Rama: `advisor/001-auth-gate` (o la convención del repo si difiere).
- Un commit lógico; estilo de mensaje como el repo (conventional commits, ver `git log`).
- No hagas push ni PR salvo que te lo pidan.

## Steps

### Step 1: Añadir un helper de guarda de sesión

Al principio de `inc/stic-action.php` (tras el `<?php` y los comentarios de cabecera), define:

```php
/**
 * Aborta la petición si no hay sesión de área privada activa.
 * Los handlers que mutan datos o sirven ficheros privados deben llamarlo lo PRIMERO.
 */
function sticpa_require_session() {
    if (empty($_SESSION['scp_user_id'])) {
        wp_redirect(home_url());
        exit;
    }
}
```

**Verify**: `php -l inc/stic-action.php` → `No syntax errors detected`.

### Step 2: Llamar a la guarda al inicio de cada handler no público

Como PRIMERA sentencia dentro de cada uno de estos handlers (justo tras `{`), añade
`sticpa_require_session();`:

- `prefix_admin_single_stic_profile` (línea 57)
- `prefix_admin_single_stic_documents` (línea 144) — antes del `if (isset($_REQUEST['download'])`
- `prefix_admin_single_stic_relationships`
- `prefix_admin_single_stic_payment_commitments`
- `prefix_admin_single_stic_payments`
- `prefix_admin_single_stic_registrations`
- `prefix_admin_single_stic_job_applications`
- `prefix_admin_single_stic_contacts`

NO lo añadas a `prefix_admin_single_stic_signup` ni a `prefix_admin_stic_forgot_password`.
En `prefix_admin_single_stic_profile_selection` y `prefix_admin_single_stic_tutor_profile` ya
existe una guarda equivalente: puedes dejarla o sustituirla por la llamada al helper (opcional).

**Verify**: `php -l inc/stic-action.php` → `No syntax errors detected`.
**Verify**: `grep -n "sticpa_require_session();" inc/stic-action.php` → al menos 8 apariciones.

### Step 3: Confirmar que signup y forgot-password siguen abiertos

**Verify**: en `prefix_admin_single_stic_signup` y `prefix_admin_stic_forgot_password` NO aparece
`sticpa_require_session();` (búscalo leyendo esas dos funciones).

## Test plan

Sin suite automática todavía (plan 013). Verificación manual documentada:
1. Estando deslogueado, un POST a `admin-post.php` con `action=single_stic_payments` debe
   redirigir a `home_url()` sin tocar el CRM (comprobar por logs del CRM o entorno de staging).
2. Logueado, guardar un pago/documento sigue funcionando igual que antes.
3. Signup y "enviar enlace de acceso" siguen funcionando sin sesión.

## Done criteria

- [ ] `php -l inc/stic-action.php` exit 0
- [ ] `grep -n "sticpa_require_session" inc/stic-action.php` muestra la definición + ≥8 llamadas
- [ ] Signup y forgot-password NO llaman a la guarda
- [ ] `git status` no muestra archivos fuera de `inc/stic-action.php`
- [ ] Fila de 001 actualizada en `plans/README.md`

## STOP conditions

- El código de los handlers no coincide con los excerpts (el archivo ha derivado). 
- Descubres un handler que muta datos y que SÍ necesita acceso anónimo distinto de signup/forgot
  (no lo asumas: repórtalo).
- Al añadir la guarda, un flujo legítimo (p. ej. el familiar editando a un participante) empieza a
  redirigir a home — indica que ese flujo depende de un estado de sesión distinto; para y reporta.

## Maintenance notes

- Este plan solo cierra el acceso anónimo. La **propiedad** de los registros (que un usuario logado
  no vea/edite los de otro) se arregla en 002 y 003 — no lo des por hecho aquí.
- Al combinar con CSRF (SEC-05, futuro), la guarda de sesión y el nonce son complementarios: la
  sesión evita el acceso anónimo, el nonce evita el CSRF de un usuario logado.
- Revisor: comprobar que ningún handler nuevo se añade sin la guarda; considerar un test que
  recorra los `add_action('admin_post_nopriv_*')` y falle si el handler no llama a la guarda.
