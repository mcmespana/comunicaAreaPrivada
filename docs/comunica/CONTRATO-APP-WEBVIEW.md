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

⚠️ **Con lo que va pegado abajo (`position: fixed` o `sticky; bottom`) el inset
no sirve**: la tab bar flotante ocupa ~73 pt desde el borde de abajo y la
píldora de atrás/adelante empieza a ~168 pt (medido en un iPhone, 27/09/2026).
La botonera de los formularios, «Inscribirme» y la barra de guardar de Pasar
Lista quedaban debajo de las dos.

**El `<head>` lleva `viewport-fit=cover`, y lo pone el plugin** (10/10/2026,
plan 042 CAR-2): `sticpa_viewport_meta()` en `inc/stic-theme.php`, solo en las
páginas del área. Antes este documento decía que lo ponía el tema, y no: el
`header.php` de Astra imprime una línea fija sin `cover`, y con ella WebKit deja
`env(safe-area-inset-*)` siempre a 0. La del plugin va dentro de `wp_head`,
después de la del tema, y con dos `meta viewport` manda la última.

**Cómo se reserva: con dos tokens de `custom-style.css` §1, y con nada más.**

| Token | Navegador | Dentro de MCM App (`body.sticpa-app-mode`) |
|---|---|---|
| `--stic-safe-bottom` | `env(safe-area-inset-bottom, 0px)` (el indicador de inicio del iPhone) | `0px` |
| `--stic-app-bottom` | `0px` | `10.5rem` (salva la tab bar y la píldora) |

```css
.lo-que-se-pega-abajo {
    position: sticky;            /* o fixed */
    bottom: var(--stic-app-bottom);
    padding-bottom: calc(0.6rem + var(--stic-safe-bottom));
}
```

En el navegador la barra crece lo que mide el indicador de inicio; en la app se
sube por encima de la tab bar y de la píldora **sin crecer**. Como cada token
vale 0 donde manda el otro, **nada se sube dos veces**: si la WebView de la app
llegara a dar un `env()` distinto de 0, no se suma (el indicador de inicio ya
cae dentro de la tab bar). `TokensCssTest` falla si aparece un
`env(safe-area-inset-bottom)` suelto o una barra `sticky` que no se pegue con
`--stic-app-bottom`.

Y una barra subida en la app necesita **el mismo hueco debajo**: si no, al final
del scroll taparía el último campo, que ya no tiene adónde subir. Lo llevan
`[data-pl-form]` (Pasar Lista) y, con margen, la `.stic-form` con botonera y la
ficha con «Inscribirme» (`custom-style.css` §65.a). La hoja de estados de Pasar
Lista (`fixed`) reserva por dentro: `calc(var(--stic-app-bottom) + 1rem)`.

Con `cover` la web llega también a los bordes **a lo ancho**. En vertical no
cambia nada (los insets laterales valen 0); en apaisado, con muesca, el área se
aparta lo que diga `env(safe-area-inset-left/right)` (`custom-style.css` §65.b).

Medido con Chromium y los insets simulados (390×844, indicador de 34 px, tab bar
de 73 y píldora de 168): en el navegador las barras crecen 34 px y siguen
pegadas al borde; en la app quedan de 602 a 676 con el `env()` a 0 y a 34, igual.
**Falta confirmarlo en un iPhone con la app** (un formulario largo: Mis datos de
monitor) y en un Android.

---

## 4. Navegación

La app pone su propia cápsula flotante **atrás/adelante** (abajo a la izquierda)
sobre el historial de la WebView, y en Android el botón atrás del sistema navega
primero por el historial de la web.

El área **sí** tiene «volver» propio en sus pantallas de detalle: la píldora
«← Eventos / Pagos / Mis inscripciones» de las fichas (`.stic-rec-back`) y la
flecha de Pasar Lista y Coordinación (`.pl-back`). Son enlaces a una URL fija,
y hasta el 10/10/2026 cada toque metía una entrada nueva en el historial: tras
usar la flecha, la cápsula «atrás» devolvía a la ficha de la que se acababa de
salir. **Ahora vuelven por el historial cuando se viene de ahí** (plan 042,
CAR-3, `bindBackLinks()` en `js/stic-ui.js`): si la página anterior es la URL
del enlace (con más parámetros o sin ellos: la fecha de la lista, la página, el
origen), es `history.back()`, y el historial de la WebView queda como lo
espera la cápsula. Si no —se entró por un enlace del correo o de WhatsApp, o
desde otra sección—, el enlace navega como siempre. Y nunca vuelve por el
historial a una página que pintó un envío (Pasar Lista guarda sin redirigir):
el navegador preguntaría si se reenvía el formulario. El servidor marca esas
páginas con `sticpa-posted` en el `<body>`, y desde ellas el enlace navega como
siempre. El overlay de carga y `sticpa:nav` `start` salen igual en los dos
casos.

### 4.a El área avisa de sus navegaciones (`sticpa:nav`) — web → app

Es uno de los dos mensajes que viajan de la web a la app (el otro es
`sticpa:barra`, §4.b); todo lo demás del contrato va en sentido contrario. Existe por rendimiento percibido: el área son recargas
completas de página, y entre el tap y el primer pintado pasa todo lo que el
servidor tarda en hablar con el CRM. El único indicador nativo de eso es la barra
de progreso de la WebView, que es fina, va arriba y queda fuera del foco visual:
la sensación es que el tap no ha hecho nada.

Cuando el área detecta que empieza una navegación (tap en un enlace interno o
envío de un formulario) manda, **si el puente existe**:

```js
window.ReactNativeWebView.postMessage(JSON.stringify({
    type: 'sticpa:nav',
    state: 'start',        // 'start' | 'end'
    href: 'https://…'      // destino ('' en formularios sin action)
}));
```

Y en cuanto la página siguiente está viva (evento `pageshow`, incluida la vuelta
desde el bfcache) manda el mismo mensaje con `state: 'end'`.

| Quién | Qué hace |
|---|---|
| **Área (web)** | Emite el mensaje. Si `window.ReactNativeWebView` no existe (navegador), no hace nada. Ya pinta además su propio overlay de carga, así que el mensaje es un extra, no un requisito |
| **App** | *Puede* usarlo para mostrar un indicador **nativo** (que se siente instantáneo porque no depende del hilo de la WebView) y, si quiere, ocultar la barra de progreso. Si lo ignora, no pasa nada |

Lado web: `bindLoadingLinks()` / `notifyApp()` en
[`js/stic-ui.js`](../../js/stic-ui.js).

> Cosas que **no** hay que romper desde aquí: `state: 'end'` puede llegar sin un
> `'start'` previo (primera carga, enlace del correo, recarga manual), así que la
> app no debe asumir que van emparejados. Y si algún día se añaden más tipos de
> mensaje, mantener el prefijo `sticpa:` para poder filtrarlos.

### 4.b La pantalla tiene barra abajo (`sticpa:barra`) — web → app

Pedido el 11/10/2026: en Pasar Lista, dentro de la app, la cápsula
atrás/adelante y la barra de guardar quedaban una encima de otra, con el tab
bar debajo y la lista asomando entre las tres.

En cada `pageshow`, el área manda, **si el puente existe**:

```js
window.ReactNativeWebView.postMessage(JSON.stringify({
    type: 'sticpa:barra',
    abajo: true            // esta pantalla tiene una barra suya pegada abajo
}));
```

Cuentan como barra la de guardar de Pasar Lista (`.pl-savebar`), la botonera
de los formularios y «Inscribirme» de la ficha de un evento (`BARRAS_ABAJO` en
`js/stic-ui.js`).

| Quién | Qué hace |
|---|---|
| **App** | Con `abajo: true`, **esconde su cápsula** atrás/adelante y, en la WebView, pone en `<html>` la clase `sticpa-app-sin-flechas` y la variable `--stic-app-tabbar` (px, lo que ocupa su tab bar desde abajo, con la zona segura y un respiro). Con `abajo: false`, vuelve a enseñar la cápsula y quita la clase. Esas pantallas tienen su propio «volver», y en Android está el botón atrás |
| **Área (web)** | Manda el mensaje. Con la clase puesta, `--stic-app-bottom` vale `--stic-app-tabbar` (si falta, 5,5 rem) en vez de 10,5 rem: la barra baja hasta el tab bar. Sin la clase, nada cambia, así que una app vieja no rompe nada |

---

## 5. Enlaces de acceso del correo que abren la app

Los correos de acceso **no enlazan al área directamente**, sino a la ruta puente
`/app/acceso?acceso_magico=…` (o `?token=…`) de este mismo dominio, declarada en
la app como universal link (iOS) y app link (Android).

| Situación | Qué pasa |
| --- | --- |
| App instalada | El sistema abre **la app**, que carga el área en su WebView con ese token. La petición web ni se hace |
| Sin app / ordenador | Llega aquí → **302** al área privada con el token intacto → login por web |

**Desde el 25/09/2026 el enlace lleva también el DESTINO** (TODO EV-8,
`docs/ACCESO.md` §4.b): `/app/acceso?acceso_magico=…&internalpage=single_stic_events&action=detail&id=…`.
El puente lo reenvía saneado. **Lado app, pendiente:** al abrir el enlace, pasar
a la WebView también `internalpage`, `action`, `id` y `from` si vienen (tal
cual; la web los vuelve a sanear). Mientras la app coja solo el token, se entra
a la portada, como antes: no se rompe nada.

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

Al pedir acceso, el campo del código sale **abierto, grande y enfocado**, en
la app y en navegador por igual: es el camino principal, y el enlace del correo
queda recordado en una frase debajo. Hasta el 02/10/2026 en navegador iba
escondido detrás de un `<details>` («¿Prefieres introducir el código?»); se
abrió porque mucha gente lee el correo en el móvil y entra en el ordenador, y
ahí solo sirve el código. Así que en esta pantalla `app=1` ya no cambia nada.

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
- [ ] ¿Se pega abajo (`fixed` o `sticky; bottom`)? Reserva con los dos tokens de
      §3 (`--stic-app-bottom` y `--stic-safe-bottom`), nunca con un `env()` suelto,
      y deja el mismo hueco debajo en la app.
- [ ] ¿Lo nuevo pulsable responde al dedo con `:active`? El destello gris de
      WebKit ya lo quita la regla común (`custom-style.css` §66.b): sin
      `:active`, el toque no tiene ninguna respuesta (plan 042, CAR-7).
- [ ] Pruébalo con `?app=1` además de en navegador.
