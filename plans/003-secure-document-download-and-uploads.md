# Plan 003: Ver/descargar registros exige propiedad y las subidas validan tipo/tamaño

> **Executor instructions**: paso a paso, verifica cada uno, respeta STOP conditions, actualiza
> `plans/README.md` al final.
>
> **Drift check**: `git diff --stat bc3c436..HEAD -- inc/stic-action.php pages/`

## Status

- **Priority**: P0
- **Effort**: M
- **Risk**: MED
- **Depends on**: plans/001-auth-gate-state-changing-handlers.md
- **Category**: security
- **Planned at**: commit `bc3c436`, 2026-07-19

## Why this matters

`download_document($_REQUEST['id'])` sirve el contenido de cualquier Documento del CRM a quien
pase/enumere su GUID, sin comprobar que pertenezca al usuario — filtra CVs, DNIs, certificados de
delitos sexuales. Las páginas `single_*` leen el detalle de cualquier registro por `id` sin
comprobar pertenencia (IDOR). Y las subidas de documentos no validan tipo ni tamaño (se lee el
fichero entero en memoria) y el `filename` va sin sanear a la cabecera `Content-Disposition`.

## Current state

- **Descarga sin auth ni propiedad** — `inc/stic-action.php:146-147`:
  ```php
  if (isset($_REQUEST['download']) && $_REQUEST['download'] == "true") {
      download_document($_REQUEST['id']);
  }
  ```
  `download_document` (`inc/stic-action.php:750-775`) hace `getRecordDetail` del DocumentRevision
  y lo vuelca con `filename` del CRM en `Content-Disposition` sin sanear (línea ~773).
- **Detalle por id sin propiedad** — cada `pages/single_*.php` hace
  `getRecordDetail($_REQUEST['id'], $module)`: documents:160, contacts:106, payments:58,
  payment_commitments:127, relationships:82, attendances:105, events:64, job_offers:59,
  job_applications:125, sessions:98, registrations:124. (Los listados sí filtran por
  `getRelatedElementsForLoggedUser` con `module_id = scp_user_id` — el problema es solo el detalle.)
- **Subida sin validar** — documentos (`inc/stic-action.php:204-214`) hace `file_get_contents` +
  `base64_encode` sin comprobar MIME/extensión/tamaño; certificado (`:916-956`) valida 6 MB pero no
  tipo. **Exemplar correcto**: `upload_file_to_record` (`inc/stic-action.php:785-821`) sí valida
  6 MB y una allow-list de imágenes — imítalo.
- Convención: el id del usuario/participante activo vive en `$_SESSION['scp_user_id']`.

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint PHP | `php -l inc/stic-action.php` | `No syntax errors detected` |
| Lint páginas | `for f in pages/single_*.php; do php -l "$f"; done` | todas sin errores |

## Scope

**In scope**: `inc/stic-action.php`, `pages/single_*.php` (solo la línea de `getRecordDetail`).
**Out of scope**: el motor de listados; cambiar el formato de las respuestas del CRM.

## Steps

### Step 1: Helper de comprobación de propiedad

En `inc/stic-action.php`, añade un helper que confirme que un `id` de un módulo pertenece al
contacto logado, reutilizando la relación que ya usan los listados:

```php
/**
 * ¿El registro $id del módulo relacionado pertenece al usuario logado?
 * Reutiliza la misma relación que los listados (getRelatedElementsForLoggedUser).
 * Devuelve true/false.
 */
function sticpa_user_owns_record($relatedModule, $id) {
    if (empty($_SESSION['scp_user_id']) || empty($id)) { return false; }
    $objSCP = SugarRestApiCall::getObjSCP();
    $related = $objSCP->getRelatedElementsForLoggedUser($relatedModule /* + args que ya use el listado */);
    // Recorre $related y devuelve true si alguno tiene ese id.
    // (Copia la forma exacta de recorrer/extraer id del listado del módulo correspondiente.)
    // ...
    return false;
}
```
Abre un `pages/list_*.php` (p. ej. `list_stic_documents.php`) para copiar la firma EXACTA de
`getRelatedElementsForLoggedUser` y cómo se extrae el id de cada elemento. No inventes argumentos.

**Verify**: `php -l inc/stic-action.php` → sin errores.

### Step 2: Exigir propiedad antes de descargar

En `prefix_admin_single_stic_documents` (tras la guarda de sesión del plan 001), antes de
`download_document`:
```php
if (isset($_REQUEST['download']) && $_REQUEST['download'] == "true") {
    if (!sticpa_user_owns_record('Documents', $_REQUEST['id'] ?? '')) {
        wp_redirect(home_url()); exit;
    }
    download_document($_REQUEST['id']);
}
```

**Verify**: leyendo, `download_document` solo se alcanza tras `sticpa_user_owns_record`.

### Step 3: Sanear el filename de la cabecera de descarga

En `download_document` (~línea 773), envuelve el nombre antes de meterlo en la cabecera:
```php
$safeName = sanitize_file_name($filename);
header('Content-Disposition: attachment; filename="' . $safeName . '"');
```
(Elimina CR/LF y caracteres peligrosos; evita header injection.)

**Verify**: `grep -n "Content-Disposition" inc/stic-action.php` → usa `sanitize_file_name`.

### Step 4: Validar tipo y tamaño en la subida de documentos y certificado

En la subida de documentos (`~204-214`) y `comunica_upload_certificate` (`~916-956`), antes de leer
el fichero, aplica el patrón de `upload_file_to_record` (785-821): tope de tamaño (6 MB) y allow-list
de extensiones/MIME (para documentos: pdf/jpg/jpeg/png/gif/doc/docx según lo que el CRM acepte; para
el certificado, al menos pdf/jpg/jpeg/png). Si no pasa, redirige con `&msg=error` y `exit`.

**Verify**: `php -l inc/stic-action.php` → sin errores. Leer que ambos puntos rechazan un `.php`/`.exe`.

### Step 5: Propiedad en las páginas de detalle

En cada `pages/single_*.php` listada, envuelve el `getRecordDetail($_REQUEST['id'], …)`: si
`!sticpa_user_owns_record('<Módulo>', $_REQUEST['id'])`, no cargues el detalle (muestra mensaje o
redirige a su listado). Usa el módulo correcto por página (Documents, Payments, etc.). Deja intacto
el caso en que la propia página edita el contacto del usuario (perfil), que ya usa la sesión.

**Verify**: `for f in pages/single_*.php; do php -l "$f"; done` → todas sin errores.

## Test plan

Manual (sin suite; ver 013):
- Logueado como A, `?...&download=true&id=<doc de B>` → redirige, no descarga.
- Logueado como A, `single_stic_payments?id=<pago de B>` → no muestra el registro de B.
- Subir un `.php` como documento/certificado → rechazado.
- Descargar un documento propio → sigue funcionando; el nombre del fichero es correcto.

## Done criteria

- [ ] `php -l inc/stic-action.php` exit 0 y todas las `pages/single_*.php` exit 0
- [ ] `download_document` solo se alcanza tras `sticpa_user_owns_record`
- [ ] `Content-Disposition` usa `sanitize_file_name`
- [ ] Subida de documentos y certificado validan tamaño y tipo
- [ ] Cada `pages/single_*` con `getRecordDetail($_REQUEST['id'])` comprueba propiedad
- [ ] Fila 003 actualizada en `plans/README.md`

## STOP conditions

- No consigues determinar la firma exacta de `getRelatedElementsForLoggedUser` desde los listados
  (no la inventes) → para y reporta.
- La comprobación de propiedad añade demasiadas llamadas al CRM y rompe el rendimiento de forma
  evidente → repórtalo; puede convenir combinarlo con el plan 011 (traer los ids relacionados de una).
- Un detalle legítimo deja de verse (falso negativo de propiedad) → para y reporta.

## Maintenance notes

- La comprobación de propiedad hace 1 llamada CRM extra por vista de detalle; el plan 011 puede
  cachear/compartir esa consulta.
- Reviewer: confirmar que TODOS los `single_*` con `getRecordDetail($_REQUEST['id'])` quedan cubiertos.
