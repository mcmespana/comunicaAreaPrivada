# Contrato App MCM ↔ Área privada (WebView)

> **Lee esto antes de tocar cualquier cosa de presentación del área privada.**
>
> En móvil, el área privada **casi nunca se ve en un navegador**: se ve dentro de
> la **app MCM** (Expo / React Native), en una WebView a pantalla completa. Es el
> canal principal. Cualquier decisión de UI (tema, barras fijas, botones de
> volver, altos de viewport) hay que pensarla primero para ese caso.
>
> Copia de trabajo del contrato que mantiene el repo de la app
> (`mcmespana/mcmapp`, `docs/contratos/COMUNICA_WEBVIEW.md`). Si cambia allí,
> actualiza aquí. Lado app: `mcm-app/app/screens/ComunicaScreen.tsx`.
> Lado web: este repo (`inc/stic-theme.php`, `css/custom-style.css` §44).

---

## 1. Detectar que estamos dentro de la app

La app carga siempre la URL con `?app=1`:

```
https://comunica.movimientoconsolacion.com/aptest/?app=1&theme=dark
```

En este plugin lo resuelve **`sticpa_is_app_mode()`** (`inc/stic-theme.php`): el
`?app=1` se recuerda en la cookie `sticpa_app` (30 días), porque los enlaces
internos del área no arrastran el parámetro. `?app=0` lo desactiva.

Se usa para:

- ocultar el header/footer/admin-bar del tema de WordPress
  (`sticpa_app_mode_css()`, clase `body.sticpa-app-mode`) — la app ya pone su
  barra y su tab bar;
- **no** pintar el control de apariencia del área (ver §2).

> ⚠️ `app=1` **no es autenticación**: es una pista de presentación. Cualquiera
> puede añadirlo en un navegador. No lo uses para dar acceso a nada.

---

## 2. Tema claro / oscuro

La app tiene **su propio selector** (Sistema / Claro / Oscuro), así que
`prefers-color-scheme` **no es fiable dentro de la app**: refleja la apariencia
del sistema operativo, no lo que el usuario haya elegido en la app. Por eso la
app manda el tema por **tres vías**, todas con el mismo valor (`light` o `dark`):

| Vía | Dónde | Cuándo llega | Quién la lee aquí |
| --- | ----- | ------------ | ----------------- |
| `?theme=` | Query string de la URL inicial | Solo en la **primera** petición | `sticpa_theme_pref()` |
| Cookie `mcm_theme` | Cookie (`path=/`, 1 año, `SameSite=Lax`) | En **todas** las peticiones a partir de la segunda | `sticpa_theme_pref()` |
| `<html>` | `data-mcm-theme="dark"`, clase `.dark`/`.light`, `style="color-scheme:dark"` | Tras cargar cada página **y al cambiar el tema en caliente** | el script de `sticpa_theme_boot_js()` |

### Cómo lo resuelve el área

**Dos atributos, no los confundas** (los estampa `sticpa_theme_attr()` en los
contenedores y el filtro `language_attributes` en `<html>`):

- `data-stic-theme` → **preferencia**: `auto` \| `light` \| `dark`.
- `data-stic-scheme` → **esquema resuelto**: `light` \| `dark`. **Es el único que
  mira el CSS** (`css/custom-style.css` §44).

Prioridades (`sticpa_theme_pref()`):

1. **Dentro de la app manda la app.** `?theme=` y, si no, la cookie `mcm_theme`.
   Si la app no manda nada (está en "Sistema"), queda `auto`.
2. **En navegador manda la cookie propia `sticpa_theme`** (`light`/`dark`), que
   escribe el control discreto de *Apariencia* del pie del área.
3. **Si nadie manda, `auto`**: decide el dispositivo (`prefers-color-scheme`).

El caso `auto` lo resuelve un **script inline en `<head>`**
(`sticpa_theme_boot_js`) con `matchMedia`, **antes del primer pintado** → sin
flash. Ese mismo script escucha:

- el `change` de `prefers-color-scheme` (el dispositivo cambia de apariencia), y
- un `MutationObserver` sobre `data-mcm-theme` de `<html>` — porque la app
  **no recarga** al cambiar de tema (perdería lo escrito en un formulario):
  reinyecta el atributo y el área tiene que repintarse sola.

Con preferencia explícita, PHP ya vuelca `data-stic-scheme` en el servidor, así
que tampoco hay parpadeo en la primera carga.

### En la app NO se pinta el control de apariencia

`sticpa_theme_switch_enabled()` devuelve `false` en modo app. Dos interruptores
compitiendo (el de la app y el de la web) es peor que uno: el de la app manda y
el del área desaparece. En navegador sí se pinta, discreto, al final del área.

### Saneado

`?theme=` y las cookies son **entrada de usuario**: `sticpa_theme_pref()` solo
acepta los literales `'light'` y `'dark'` y descarta todo lo demás (incluido
`DARK`, `' dark '` o `"><script>`). Cubierto por `tests/ThemeTest.php`.

---

## 3. Zona segura (notch y tab bar)

La app dibuja la web **a pantalla completa**, por detrás de su barra superior
translúcida y de su tab bar inferior, y compensa con `contentInset`. Eso funciona
para contenido que scrollea normal.

⚠️ **Con elementos `position: fixed` el inset no sirve** — una barra fija abajo
(tipo «Guardar» pegado al viewport) queda tapada por el tab bar. Los elementos
fijos del área reservan el hueco ellos mismos:

```css
padding-bottom: calc(12px + env(safe-area-inset-bottom));
```

Ya aplicado en la botonera sticky de los formularios y en el control de
apariencia de la pantalla de login. Para que `env(safe-area-inset-*)` no valga
siempre 0 hace falta que el `<head>` lleve:

```html
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
```

(lo pone el tema de WordPress; si algún día deja de ponerlo, se nota aquí).

---

## 4. Navegación

La app pone su propia cápsula flotante **atrás/adelante** (abajo a la izquierda)
sobre el historial de la WebView, y en Android el botón atrás del sistema navega
primero por el historial de la web. El área **no** añade botones de volver
propios.

---

## 5. Enlaces de acceso del correo que abren la app

Los correos de acceso **no enlazan al área directamente**, sino a la ruta puente
`/app/acceso?acceso_magico=…` (o `?token=…`) de este mismo dominio, declarada en
la app como universal link (iOS) y app link (Android).

| Situación | Qué pasa |
| --- | --- |
| App instalada | El sistema abre **la app**, que carga el área en su WebView con ese token. La petición web ni se hace |
| Sin app / ordenador | Llega aquí → **302** al área privada con el token intacto → login por web |

### 5.a Y cuando el enlace no llega a la app: el código de 6 cifras

La fila de arriba tiene una tercera situación que no se ve en la tabla y que en
la práctica pasa a menudo: **el cliente de correo envuelve el enlace en un
redirector** (Gmail en iOS, SafeLinks de Outlook…). Entonces el universal link
se pierde, la petición sí llega aquí, y la sesión acaba **en el navegador, no en
la WebView**. Desde fuera se lee como "la app no funciona": la app sigue
pidiendo acceso porque su cookie de sesión es otra.

Por eso el mismo correo lleva **un código de 6 cifras** además del enlace
([`inc/stic-otp.php`](../../inc/stic-otp.php)). Es lo único que sobrevive a
cualquier cliente de correo, porque lo transporta la persona.

- **Dentro de la app** (`sticpa_is_app_mode()`), al pedir acceso el campo del
  código sale **abierto y enfocado**: es el camino principal.
- **En navegador** manda el enlace y el código queda detrás de un `<details>`
  pequeño ("¿Prefieres introducir el código?").

⚠️ Esto es lo único de todo el contrato en lo que `app=1` cambia algo más que
presentación pura, así que conviene decirlo claro: cambia **qué se ve primero**,
nunca **qué se puede hacer**. Las dos vías están siempre disponibles en las dos
partes, porque `app=1` es una cookie de 30 días y no es una señal fiable (§1).

Como la sesión de la app dura un año deslizante, esto se hace **una vez** y
luego se olvida — que es justo por lo que merece la pena que ese primer día
salga bien.

Lado web: [`inc/stic-app-links.php`](../../inc/stic-app-links.php) (sirve los
`/.well-known/…`, atiende el puente y expone `sticpa_app_link_url()`).
Lado app: `app/+native-intent.ts` + `utils/pendingComunicaLink.ts`.
Detalle completo y pasos pendientes en el README §8.6.

> Cosas que **no** hay que romper desde aquí: si se cambia la URL del área en
> ajustes, el puente la sigue sola. Pero si algún día se cambia la ruta
> `/app/acceso`, hay que cambiarla **también en `app.json` de la app**, y eso
> exige build de tienda.

---

## 6. Checklist al añadir UI nueva al área

- [ ] ¿Usa **tokens** (`var(--…)`) para todos los colores? Si sí, el tema oscuro
      le sale gratis. Si necesitas un color que no existe, crea el token en
      `custom-style.css` §1 y dale su valor oscuro en §44 — **nunca** un hex a
      pelo en la regla, y **nunca** un color en un atributo `style=` (un inline
      gana a cualquier regla y no hay forma de tematizarlo).
- [ ] ¿Se ve bien en las **cuatro** combinaciones? {SO claro, SO oscuro} ×
      {apariencia auto, apariencia forzada}.
- [ ] ¿Va anclado a `<body>` (overlay, tooltip, modal, desplegable)? Entonces
      añádelo a la lista de anfitriones de tokens de §44.a, o no heredará el tema.
- [ ] ¿Tiene elementos `position: fixed`? Reserva `env(safe-area-inset-bottom)`.
- [ ] Pruébalo con `?app=1` además de en navegador.
