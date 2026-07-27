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

## 5. Checklist al añadir UI nueva al área

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
