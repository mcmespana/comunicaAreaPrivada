# Plan 017: Servir la foto de perfil por endpoint con miniatura (fuera el base64 inline)

> **Executor instructions**: paso a paso, verifica, respeta STOP conditions, actualiza `plans/README.md`.
>
> **Drift check**: `git diff --stat c2d7cff..HEAD -- pages/single_stic_profile.php pages/single_stic_comunica_perfil.php inc/stic-action.php`

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED
- **Depends on**: none (coordinar con plan 003 si se ejecuta a la vez: toca el mismo archivo de handlers)
- **Category**: perf
- **Planned at**: commit `c2d7cff`, 2026-07-19

## Why this matters

La foto de perfil se incrusta HOY como data URI base64 en el HTML: el CRM devuelve la imagen
ORIGINAL (hasta 6 MB permitidos al subir) y base64 la infla +33%. Una foto de 3 MB convierte la
página de perfil en ~4 MB de HTML no cacheable que el móvil debe bajar entero antes de terminar de
parsear. Es, con diferencia, el mayor coste de red del área para quien tiene foto.

## Current state

- `pages/single_stic_comunica_perfil.php:170-171`:
  ```php
  $image = $objSCP->get_image(array('id' => $id, 'field' => 'photo'));
  $photoSrc = 'data:' . $image->image_data->mime_type . ';base64, ' . $image->image_data->data;
  ```
- `pages/single_stic_profile.php:165-175`: mismo patrón con `$_SESSION['scp_user_id']`.
- Los handlers `admin_post_*`/`admin_post_nopriv_*` del plugin viven en `inc/stic-action.php`
  (ejemplo de handler de descarga: `download_document`, línea ~750).
- El preview del cropper (`js/stic-cropper.js::markPending`) reemplaza `img.src` por un dataURL
  local ANTES de guardar: eso debe seguir funcionando (no depende del src inicial).
- La subida guarda en el CRM vía `set_entry` con el campo `photo` (no cambiar).

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint | `php -l inc/stic-action.php && php -l pages/single_stic_profile.php && php -l pages/single_stic_comunica_perfil.php` | sin errores |

## Scope

**In scope**: nuevo endpoint de imagen (admin-post o rewrite), miniatura cacheada en
`wp_upload_dir()/stic-uploads/photo-cache/`, y los dos `<img>` de perfil.
**Out of scope**: el flujo de subida/cropper, otros campos de imagen del CRM, el resto de
`inc/stic-action.php`.

## Steps

### Step 1: Endpoint `stic_profile_photo`

En `inc/stic-action.php`, registra `admin_post_stic_profile_photo` y
`admin_post_nopriv_stic_profile_photo` hacia una función que:

1. Exige sesión: `if (empty($_SESSION['scp_user_id'])) { status_header(403); exit; }`.
2. Sirve SIEMPRE la foto del usuario EN SESIÓN (`$_SESSION['scp_user_id']`) — el id NUNCA viene del
   request (misma regla que el checklist §8.9 del design system).
3. Cache de disco: clave `md5(user_id)`, ruta
   `wp_upload_dir()['basedir'] . '/stic-uploads/photo-cache/' . $hash . '.jpg'`.
   Si existe y tiene < 24h, sirve el archivo directamente.
4. Si no, pide `get_image` al CRM, decodifica el base64, redimensiona a 400×400 máx con
   `wp_get_image_editor()` (núcleo de WP: `->resize(400, 400, true)`, `->set_quality(82)`,
   `->save($ruta, 'image/jpeg')`) y sirve el resultado.
5. Cabeceras: `Content-Type: image/jpeg`, `Cache-Control: private, max-age=86400`,
   `Content-Length`. Después `readfile($ruta); exit;`.
6. Si el CRM no devuelve imagen → `status_header(404); exit;` (el `<img>` mostrará el placeholder
   vía `onerror`, ver Step 2).

### Step 2: Sustituir los data URI en las dos páginas

En ambos archivos, cuando `$data->photo->value` no esté vacío:
```php
$photoSrc = admin_url('admin-post.php?action=stic_profile_photo&v=' . rawurlencode(substr(md5((string) $data->photo->value), 0, 8)));
```
(el parámetro `v` derivado del nombre de archivo rompe la caché del navegador al cambiar la foto).
Mantén el placeholder actual para quien no tiene foto y añade
`onerror="this.onerror=null;this.src='<?php echo esc_js(plugins_url('../images/profile_picture.jpg', __FILE__)); ?>'"`.
Conserva los atributos ya presentes (`width/height/decoding`). Elimina la llamada `get_image` de las
páginas (ya no hace falta: la hace el endpoint, y solo cuando la miniatura no está cacheada).

**Verify**: `php -l` de los tres archivos → sin errores.

### Step 3: Invalidar la miniatura al subir foto nueva

En el handler de guardado de perfil (busca en `inc/stic-action.php` dónde se procesa
`$_FILES['photo']`), tras el `set_entry` con éxito: `@unlink($rutaCache)` de la clave del usuario en
sesión.

## Test plan

Manual/staging: (1) usuario con foto → la página de perfil pesa KBs, la imagen llega como
`admin-post.php?action=stic_profile_photo` con 200 y `image/jpeg`; (2) segunda carga → respuesta
servida desde el cache de disco (más rápida; añade un log temporal si hace falta); (3) subir una
foto nueva → la miniatura cambia; (4) usuario sin foto → placeholder; (5) sin sesión, el endpoint
devuelve 403; (6) el preview del cropper sigue mostrándose al elegir archivo.

## Done criteria

- [ ] `php -l` exit 0 en los tres archivos
- [ ] Ningún `data:...base64` de foto de perfil queda en `pages/` (grep `base64` en pages/ → 0 para photo)
- [ ] El HTML de perfil con foto pesa < 100 KB
- [ ] El endpoint rechaza peticiones sin sesión (403)
- [ ] Fila 017 actualizada en `plans/README.md`

## STOP conditions

- Si `wp_get_image_editor()` devuelve `WP_Error` en el hosting (falta GD/Imagick), PARA y reporta:
  la alternativa (servir el original sin redimensionar pero cacheado en disco) es aceptable, pero
  decídelo con el mantenedor.
- Si `get_image` del CRM tarda > 10s o falla intermitentemente, PARA: cachear un error rompería
  todas las fotos; hay que acordar el TTL de error primero.

## Maintenance notes

- El directorio `photo-cache/` puede crecer: son miniaturas de ~30 KB; documentar en README que se
  puede vaciar sin riesgo (se regenera).
- Si el plan 003 (endurecer descargas) se ejecuta después, debe cubrir también este endpoint con las
  mismas comprobaciones de sesión.
