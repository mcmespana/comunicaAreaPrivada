# Plan 030: Cada tap responde al instante — feedback inmediato, prefetch del menú y aviso a la app

> **Executor instructions**: Sigue este plan paso a paso. Los steps 1-2 son el núcleo;
> 3 y 4 son independientes (un STOP en uno no bloquea los demás). Ejecuta cada
> verificación antes de avanzar. Al terminar, actualiza la fila de este plan en
> `plans/README.md`.
>
> **Drift check (ejecutar primero)**:
> `git diff --stat 337ec6a..HEAD -- js/stic-ui.js sinergiacrm-private-area.php inc/stic-magic-login.php docs/comunica/CONTRATO-APP-WEBVIEW.md`
> Ante un desajuste con los extractos de "Current state", ese step es STOP.

## Status

- **Priority**: P1
- **Effort**: S-M
- **Risk**: LOW-MED
- **Depends on**: none (compone con 027/029: ellos acortan la espera, este la hace visible y la solapa)
- **Category**: perf (velocidad percibida)
- **Planned at**: commit `337ec6a`, 2026-08-09

## Why this matters

El área privada es una web clásica de recargas completas dentro de una WebView (app MCM).
Entre el tap y el primer píxel de la página siguiente pasan el TTFB completo (las llamadas al
CRM del servidor) sin NINGUNA señal en pantalla: el único indicador es la barrita fina del
WebView, arriba, fuera del foco visual. La percepción del usuario es "no ha hecho nada" →
segundo tap → doble navegación. Hoy solo 4 formularios y el selector de participante muestran
un overlay de carga; **ningún enlace** (menú, tarjetas de la home, botones de fila de los
listados) da feedback.

Este plan: (1) feedback inmediato en todos los enlaces internos, (2) prefetch especulativo de
las secciones del menú (en Chrome/Android WebView la navegación siguiente empieza a cargarse
ANTES del tap), (3) un `postMessage` opcional para que la app pueda pintar su propio spinner
nativo, y (4) quitarle a la pantalla puente del enlace mágico sus 5 animaciones infinitas
(jank garantizado en gama media, justo en el primer contacto con el área).

## Current state

### El overlay existe pero solo lo usan formularios

- `js/stic-ui.js:40-47` — `showOverlay(text, sub)`: construye/activa un overlay a pantalla
  completa (clase `is-active`). Ya está listo para reutilizar.
- `js/stic-ui.js:50-67` — `bindLoadingForms()`: solo engancha `form.stic-loading-form`
  (4 formularios en todo el repo: login ×2, código OTP, y todos los del motor vía
  `inc/stic-formController.php:176`).
- `js/stic-ui.js:~519-525` — único ENLACE con overlay, el selector de participante:
  ```js
  document.addEventListener('click', function (e) {
      var link = e.target.closest ? e.target.closest('[data-part-switch-to]') : null;
      if (link) {
          showOverlay(link.getAttribute('data-part-switch-to') || 'Cambiando…', '');
      }
  });
  ```
- `js/stic-ui.js:~700-716` — bloque de init en `DOMContentLoaded` que llama a todos los
  `bind*()`; el nuevo binding se registra ahí, siguiendo el patrón.
- `js/stic-ui.js` se encola SIEMPRE en el área, sin dependencias
  (`sinergiacrm-private-area.php:143-144`), con versión por `filemtime`.

### Enlaces internos del área (a cubrir)

- Menú: `a.stic-nav-link` con `href='?internalpage=...'` (`menu.php:330-335`); logout
  `?logout=true` con clase `stic-nav-logout` (`menu.php:295-298`) y `.stic-logout`
  (`menu.php:307`).
- Tarjetas/botones: `.stic-rowbtn` en listados (`inc/stic-listController.php:191-198`),
  tarjetas de la home, enlaces de eventos (`inc/stic-events.php`). Todos son `<a href>` de
  mismo origen con query strings relativas (`?internalpage=...&id=...`).
- Descargas: los documentos se descargan vía enlaces que acaban sirviendo
  `application/octet-stream` — un overlay que no se quita solo. De ahí las exclusiones y el
  temporizador del step 1.

### No existe ninguna precarga ni puente hacia la app

- `grep` de `prefetch|prerender|speculationrules|postMessage|ReactNativeWebView` en el código
  propio: **0 resultados**.
- Contrato con la app: `docs/comunica/CONTRATO-APP-WEBVIEW.md` — hoy es unidireccional
  (app → web: tema, modo app). La app pinta su cápsula de navegación sobre el historial del
  WebView. No hay canal web → app.

### La pantalla puente del enlace mágico (inc/stic-magic-login.php:300-395)

HTML standalone (no pasa por el tema) con `<style>` inline que incluye:

```css
body { ...
    background:
        radial-gradient(40% 50% at 18% 22%, rgba(28,111,179,.28), transparent 60%),
        radial-gradient(45% 55% at 85% 18%, rgba(157,30,116,.26), transparent 60%),
        radial-gradient(50% 60% at 70% 90%, rgba(108,75,158,.24), transparent 62%),
        linear-gradient(135deg,#eef5fc 0%,#f4eef9 50%,#fbeef5 100%);
    background-size:200% 200%;
    animation:mesh 16s ease-in-out infinite;
}
.card { ... backdrop-filter:blur(18px) saturate(1.4); ... }
.logo { ... animation:float 4.5s ease-in-out infinite; }
.spinner { ... animation:spin .9s linear infinite; }
.dots span { ... animation:blink 1.4s ease-in-out infinite; }
```

Cinco animaciones infinitas simultáneas, una de ellas (`mesh`) repinta el fondo del viewport
completo en cada frame, con un `backdrop-filter` encima. Sin `prefers-reduced-motion`. Todo
para una pantalla cuyo único trabajo es esperar un redirect.

### Convenciones

- JS propio: IIFE sin dependencias, funciones `bindX()` registradas en el bloque
  `DOMContentLoaded` final (mira `js/stic-ui.js` entero antes de tocar).
- El tema oscuro se engancha a `data-stic-scheme`/`data-stic-theme` — no lo toques.
- PHP: hooks de WordPress, comentarios en español con el porqué.

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint PHP | `php -l sinergiacrm-private-area.php && php -l inc/stic-magic-login.php` | sin errores |
| Sintaxis JS | `node --check js/stic-ui.js` | exit 0 (node disponible en CI; si no hay node local, revisa a mano) |
| Tests (con vendor/) | `composer test` | verdes (ThemeTest cubre la pantalla puente: no debe romperse) |

## Scope

**In scope**:
- `js/stic-ui.js`
- `sinergiacrm-private-area.php` (solo para emitir el `<script type="speculationrules">`)
- `inc/stic-magic-login.php` (solo el bloque `<style>` de la pantalla puente)
- `docs/comunica/CONTRATO-APP-WEBVIEW.md` (documentar el postMessage del step 3)

**Out of scope** (NO tocar):
- `css/custom-style.css` / `css/stic-base.css` (el overlay ya tiene estilos).
- Cualquier intercambio parcial de contenido, fetch de formularios, View Transitions —
  descartado deliberadamente hasta medir el efecto de 027-030 (ver plans/README.md).
- El lado de la app MCM (repo distinto): el step 3 debe ser inofensivo sin app.

## Git workflow

- Rama: la del operador; si no, `advisor/030-tap-feedback`.
- Un commit por step. Ej.: `feat(ux): overlay de carga también al navegar entre secciones`.
- NO push/PR salvo instrucción.

## Steps

### Step 1: Feedback inmediato en todos los enlaces internos

En `js/stic-ui.js`, añade una función `bindLoadingLinks()` (junto a `bindLoadingForms`) y
regístrala en el bloque `DOMContentLoaded` final:

```js
/* -------- Navegación con feedback: overlay al tocar enlaces internos -------- */
function bindLoadingLinks() {
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
        var link = e.target.closest ? e.target.closest('a[href]') : null;
        if (!link) { return; }
        // Solo navegaciones internas de documento completo.
        if (link.target && link.target !== '_self') { return; }
        if (link.hasAttribute('download')) { return; }
        var href = link.getAttribute('href') || '';
        if (href === '' || href.charAt(0) === '#') { return; }
        if (/^(mailto:|tel:|javascript:)/i.test(href)) { return; }
        var url;
        try { url = new URL(link.href, window.location.href); } catch (err) { return; }
        if (url.origin !== window.location.origin) { return; }
        // Las descargas de documentos responden un fichero, no una página:
        // el overlay no se quitaría solo. Se excluyen por su acción.
        if (/action=download|[?&]download=/.test(url.search)) { return; }
        // El selector de participante ya tiene su propio overlay con texto.
        if (e.target.closest('[data-part-switch-to]')) { return; }
        showOverlay(link.getAttribute('data-loading-text') || 'Cargando…', '');
        // Red de seguridad: si la navegación se cancela (o el fichero era una
        // descarga no detectada), el overlay se retira solo.
        setTimeout(hideOverlayIfStuck, 12000);
    });
    // Volver con bfcache (atrás de la app): la página revive con el overlay
    // puesto; pageshow con persisted lo limpia.
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) { hideOverlayIfStuck(); }
    });
}
function hideOverlayIfStuck() {
    var el = document.querySelector('.stic-loading-overlay');
    if (el) { el.classList.remove('is-active'); }
}
```

ANTES de escribirlo: abre `js/stic-ui.js` y localiza (a) el nombre real de la clase del
overlay que construye `buildOverlay()` (ajusta el selector de `hideOverlayIfStuck`), y (b) el
bloque init final — registra `bindLoadingLinks();` junto a `bindLoadingForms();`.

Consideración de producto incluida: el overlay de formularios usa textos específicos
("Guardando tus cambios…"); para enlaces el texto genérico "Cargando…" basta. Si un enlace
concreto quiere texto propio, ya queda soportado vía `data-loading-text`.

**Verify**: `node --check js/stic-ui.js` → exit 0.
**Verify**: `grep -c "bindLoadingLinks" js/stic-ui.js` → 2 (definición + registro).
**Verify**: `grep -n "pageshow" js/stic-ui.js` → ≥ 1 resultado.

### Step 2: Prefetch especulativo de las secciones del menú

En `sinergiacrm-private-area.php`, añade tras `sugar_crm_portal_style_and_script()` un hook
que emita las reglas SOLO cuando hay shortcode y sesión iniciada (sin sesión, el menú no
existe):

```php
/**
 * Speculation Rules (Chrome/Android WebView): al apoyar el dedo en un item del
 * menú, el navegador ya va pidiendo la página. En iOS/WKWebView se ignora sin
 * ruido. Solo prefetch (no prerender): cada página dispara llamadas al CRM y
 * prerender las multiplicaría. Se excluyen logout y descargas (efectos de lado).
 */
add_action('wp_footer', 'sticpa_speculation_rules');
function sticpa_speculation_rules()
{
    global $post;
    if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'sinergiacrm-private-area')) {
        return;
    }
    if (empty($_SESSION['scp_user_id'])) {
        return;
    }
    $rules = array(
        'prefetch' => array(
            array(
                'where' => array(
                    'and' => array(
                        array('selector_matches' => "a.stic-nav-link[href^='?internalpage=']"),
                        array('not' => array('selector_matches' => 'a.stic-nav-logout')),
                    ),
                ),
                'eagerness' => 'moderate',
            ),
        ),
    );
    echo '<script type="speculationrules">' . wp_json_encode($rules) . '</script>';
}
```

Notas para el ejecutor:
- `eagerness: moderate` = el prefetch se dispara en hover / touchstart (~el propio gesto del
  tap), no en masa al cargar: coste extra sobre el CRM ≈ 0 peticiones desperdiciadas.
- Los `?internalpage=` son GET idempotentes (solo lectura); `?logout=true` NO lo es y por eso
  se excluye por selector. No amplíes el selector a otros enlaces sin comprobar idempotencia.
- El HTML prefetcheado lleva `Set-Cookie` de sesión (ver plan 028); prefetch same-origin lo
  gestiona bien el navegador. No usar `prerender`.

**Verify**: `php -l sinergiacrm-private-area.php` → sin errores.
**Verify**: `grep -n "speculationrules" sinergiacrm-private-area.php` → ≥ 1.

### Step 3: Aviso a la app MCM al navegar (postMessage, inofensivo sin app)

En la función `bindLoadingLinks()` del step 1, justo después de `showOverlay(...)`:

```js
        if (window.ReactNativeWebView && window.ReactNativeWebView.postMessage) {
            try { window.ReactNativeWebView.postMessage(JSON.stringify({ type: 'sticpa:nav', state: 'start', href: url.href })); } catch (err) {}
        }
```

Y en el listener de `pageshow` (misma función), el cierre:

```js
        if (window.ReactNativeWebView && window.ReactNativeWebView.postMessage) {
            try { window.ReactNativeWebView.postMessage(JSON.stringify({ type: 'sticpa:nav', state: 'end' })); } catch (err) {}
        }
```

Documéntalo en `docs/comunica/CONTRATO-APP-WEBVIEW.md` como sección nueva (sigue el estilo de
las existentes): qué mensaje se emite, cuándo, y que la app PUEDE usarlo para mostrar un
indicador nativo — sin obligación (el contrato sigue funcionando si lo ignora).

**Verify**: `grep -c "ReactNativeWebView" js/stic-ui.js` → 2.
**Verify**: `grep -n "sticpa:nav" docs/comunica/CONTRATO-APP-WEBVIEW.md` → ≥ 1.

### Step 4: Dieta de animaciones de la pantalla puente del enlace mágico

En `inc/stic-magic-login.php`, bloque `<style>` de la pantalla puente (`:322-395` aprox.):

1. `body`: elimina `background-size:200% 200%;` y `animation:mesh 16s ...;` y el
   `@keyframes mesh`. El fondo (gradientes estáticos) se queda tal cual — es bonito quieto.
   Haz lo mismo en las variantes oscuras del final del bloque (buscan `background-size:200% 200%`).
2. `.card`: elimina `backdrop-filter` y `-webkit-backdrop-filter` (con fondo estático ya no
   aportan; sube la opacidad del fondo de `.74` a `.92` para compensar legibilidad). Conserva
   la animación `pop` (es de entrada, una sola vez — barata).
3. `.logo`: elimina `animation:float ...` y `@keyframes float`.
4. `.dots span`: elimina `animation:blink ...` y `@keyframes blink` (los puntos quedan
   estáticos con `opacity:.35`).
5. El `.spinner` SE QUEDA (es el indicador de que algo pasa), pero envuélvelo en:
   ```css
   @media (prefers-reduced-motion: reduce) {
       .spinner { animation: none; }
   }
   ```

**Verify**: `php -l inc/stic-magic-login.php` → sin errores.
**Verify**: `grep -c "infinite" inc/stic-magic-login.php` → 1 (solo el spinner).
**Verify**: `grep -n "prefers-reduced-motion" inc/stic-magic-login.php` → 1 resultado.
**Verify**: `composer test` (si hay vendor/) → `ThemeTest` sigue verde (la lógica de tema de
la pantalla no se toca, solo el CSS).

## Test plan

- `node --check js/stic-ui.js` y `php -l` (arriba).
- Suite existente verde (`composer test` con vendor/).
- Manual/staging (operador):
  1. Tocar un item del menú → overlay inmediato → la página siguiente lo reemplaza.
  2. Volver atrás (gesto/cápsula de la app o botón del navegador) → NO queda overlay pegado.
  3. Descargar un documento → NO aparece overlay (o desaparece a los 12 s como mucho).
  4. En Chrome de escritorio con DevTools (Application → Speculative loads): al pasar el
     ratón por el menú aparecen prefetches.
  5. Abrir un enlace mágico del correo → la pantalla puente se ve igual pero quieta (solo
     gira el spinner).

## Done criteria

- [ ] `node --check js/stic-ui.js` exit 0 y `php -l` exit 0 en los 2 PHP
- [ ] `grep -c "bindLoadingLinks" js/stic-ui.js` → 2
- [ ] `grep -n "speculationrules" sinergiacrm-private-area.php` → ≥ 1
- [ ] `grep -c "infinite" inc/stic-magic-login.php` → 1
- [ ] `docs/comunica/CONTRATO-APP-WEBVIEW.md` documenta `sticpa:nav`
- [ ] `git status --porcelain` solo lista archivos in-scope (y `plans/README.md`)
- [ ] Fila 030 actualizada en `plans/README.md`

## STOP conditions

- `buildOverlay`/`showOverlay` en `js/stic-ui.js` no coinciden con lo descrito (deriva) →
  STOP del step 1.
- El init de `js/stic-ui.js` ya no es un único bloque `DOMContentLoaded` → STOP del step 1.
- La pantalla puente ya no está en `inc/stic-magic-login.php:300-395` aprox. → STOP del step 4.
- Cualquier tentación de convertir enlaces en `fetch`/SPA → STOP: fuera de alcance explícito.

## Maintenance notes

- Si algún día se añade un enlace GET con efectos de lado (como `?logout`), hay que añadirlo
  a las exclusiones del overlay Y del selector de speculation rules. Regla simple: los GET
  del área deben ser idempotentes; lo que mute, por POST.
- Si el plan 031 parte o renombra `js/stic-ui.js`, `bindLoadingLinks` debe seguir cargándose
  en TODAS las páginas del área (es el feedback universal).
- Cuando la app MCM implemente el consumo de `sticpa:nav` (repo `mcmapp`), puede ocultar la
  barra de progreso del WebView y usar su propio indicador; el contrato queda documentado.
- Métrica de éxito real (operador): el "doble tap por impaciencia" desaparece de las quejas.
