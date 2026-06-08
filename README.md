# SinergiaCRM Private Area — Guía técnica y funcional

> Plugin de **WordPress** que crea un "Área Privada" en tu web donde tus contactos
> (socios, beneficiarios, usuarios…) pueden iniciar sesión y consultar/editar datos que
> **viven dentro de SinergiaCRM** (un CRM basado en SuiteCRM/SugarCRM), sin entrar nunca
> al CRM directamente.

Este documento está pensado para que **cualquier persona o agente de IA** entienda en 10
minutos cómo funciona esta movida, dónde tocar las cosas, y cómo extenderla. Escrito en
plan "explícamelo como si tuviera 5 años, pero sin mentirme".

---

## 1. La idea en una frase

WordPress **no guarda usuarios ni contraseñas del área privada**. Es solo una **fachada**:
cada vez que alguien hace login o consulta algo, WordPress **llama por API REST a
SinergiaCRM**, que es quien tiene los datos de verdad. WordPress actúa como un "cliente"
del CRM.

```
  Navegador del socio
        │  (rellena login / formularios)
        ▼
  WordPress + este plugin  ─────API REST (JSON sobre HTTPS)────►  SinergiaCRM (SuiteCRM)
        ▲                                                              │
        └───────────────  respuesta JSON con los datos  ◄─────────────┘
```

---

## 2. ¿Cómo se conecta WordPress a Sinergia? (la conexión)

### 2.1 Vía API REST v4.1 de SuiteCRM/SugarCRM

La conexión NO es a base de datos directa. Va por la **API REST clásica de SugarCRM/SuiteCRM**
(la `v4_1`). Todo pasa por una sola clase:

- **Archivo clave:** [`inc/stic-class-6.php`](inc/stic-class-6.php) → clase `SugarRestApiCall`.

Esa clase hace peticiones `cURL` por **POST** a una URL del CRM, enviando siempre 4 campos:

```php
$post = array(
    "method"        => $method,        // p.ej. "login", "get_entry_list", "set_entry"
    "input_type"    => "JSON",
    "response_type" => "JSON",
    "rest_data"     => json_encode($parameters),
);
```

El CRM responde con JSON, que se decodifica y se usa en las páginas.

### 2.2 ¿Qué usuario y contraseña usa para conectarse al CRM?

Hay que distinguir **DOS niveles de credenciales**. Esto es lo que más confunde:

| Nivel | Quién | Dónde se guarda | Para qué sirve |
|-------|-------|-----------------|----------------|
| **1. Usuario "técnico" / de servicio** | Un usuario del CRM (admin/API) | En la config del plugin de WP (`wp_options`) | Para que WordPress pueda hablar con el CRM. Es **uno solo**, fijo. |
| **2. Usuario del área privada** | Cada socio/contacto | En el propio CRM, en campos del Contacto/Cuenta | Para que cada persona entre a su área privada. |

**Nivel 1 (la cuenta de servicio):** Se configura en el panel de WordPress, en el menú
*"SinergiaCRM Private Area"* (ver [`sinergiacrm-private-area.php`](sinergiacrm-private-area.php),
función `sugar_crm_portal_settings_page`). Los ajustes se guardan como opciones de WordPress:

- `sticpa_scp_host_url` → URL del CRM (ej. `https://ejemplo.sinergiacrm.org`)
- `sticpa_scp_rest_url` → endpoint de la API
  (ej. `https://ejemplo.sinergiacrm.org/custom/service/v4_1_SticCustom/rest.php`)
- `sticpa_scp_username` → **usuario del CRM** que usa el plugin para conectarse
- `sticpa_scp_password` → **contraseña** de ese usuario
- `sticpa_scp_module` → si los usuarios del área privada son `Contacts`, `Accounts` o `Any`

Con esas credenciales, el método `login()` (en `stic-class-6.php`) autentica contra el CRM.
Ojo: la API espera la contraseña en **MD5**, por eso ves `md5($this->password)`:

```php
"user_auth" => array(
    "user_name" => $this->username,
    "password"  => md5($this->password),   // <- la API v4.1 usa MD5
),
```

Si el login es correcto, el CRM devuelve un `session_id` que se guarda en
`$_SESSION['api_session_id']` y se reutiliza en las siguientes llamadas. Si una llamada
devuelve el error `number == 11` (sesión caducada), la clase vuelve a hacer login sola y
reintenta (ver método `call()`).

### 2.3 ¿Cómo entran los socios? (el login del área privada — Nivel 2)

Esto es **independiente** del login técnico. Cuando un socio rellena el formulario de login
del área privada (su usuario y su contraseña), el plugin **NO** usa la API de `login`. Lo que
hace es **buscar un registro de Contacto/Cuenta en el CRM cuyos campos coincidan**:

- **Archivo:** `sinergiacrm-private-area.php` → `sugar_crm_portal_check_user_and_login()`
- Llama a `PortalLogin()` en `stic-class-6.php`, que hace un `get_entry_list` con esta query:

```php
'query' => "stic_pa_username_c = '{$username}' AND stic_pa_password_c = '{$password}'",
```

Es decir: **el "usuario" y la "contraseña" del área privada son dos campos personalizados
del módulo Contactos/Cuentas en el CRM**:

- `stic_pa_username_c` → nombre de usuario del área privada
- `stic_pa_password_c` → contraseña del área privada

Si la consulta devuelve un registro, el login es válido y se guardan datos en `$_SESSION`
(`scp_user_id`, `scp_user_contact_name`, etc.).

### 2.4 ⚠️ Las contraseñas del área privada están en TEXTO PLANO

Esto es importante que lo sepas (y es un riesgo de seguridad heredado del diseño):

- La contraseña del socio se guarda **tal cual, sin cifrar ni hashear**, en el campo
  `stic_pa_password_c` del CRM.
- El login compara directamente texto contra texto (`stic_pa_password_c = '{password}'`).
- La función **"He olvidado mi contraseña"** literalmente **lee la contraseña del CRM y la
  envía por email en claro** (ver `prefix_admin_stic_forgot_password` en `inc/stic-action.php`):

  ```php
  $body .= __('Your private area password is: ', 'sticpa') . ': ' . $password;
  ```

- El cambio de contraseña (`prefix_admin_single_stic_password_change`) también guarda la nueva
  en claro.

> 💡 **Recomendación de mejora:** este es el punto más flojo del plugin. Idealmente las
> contraseñas deberían hashearse (p.ej. `password_hash`/`password_verify` de PHP) y "olvidé mi
> contraseña" debería enviar un enlace de reseteo en vez de la contraseña. Pero cambiarlo
> implica tocar también cómo se valida el login y cómo lo gestiona SinergiaCRM, así que es un
> cambio de calado, no un parche de una línea. Además hay **inyección SQL/SuiteQL** potencial
> en la query del login (el usuario/contraseña se concatenan sin escapar): otra cosa a
> endurecer si se mete mano.

### 2.5 Resumen del flujo de login

```
Socio mete usuario+contraseña
        │
        ▼
WordPress (con la cuenta de SERVICIO) se autentica en el CRM  →  session_id
        │
        ▼
WordPress pregunta al CRM: "¿hay un Contacto con
   stic_pa_username_c = X  Y  stic_pa_password_c = Y?"
        │
   ┌────┴─────┐
   ▼          ▼
  SÍ          NO
   │          │
guarda datos  muestra "usuario/contraseña incorrectos"
en $_SESSION
y muestra el
área privada
```

---

## 3. ¿De dónde salen los usuarios y en qué campos están?

- Los **usuarios del área privada son registros del módulo `Contacts` (o `Accounts`)** dentro
  de SinergiaCRM. **No hay tabla de usuarios en WordPress.**
- Los dos campos personalizados (custom fields, sufijo `_c`) que habilitan el acceso son:
  - `stic_pa_username_c` (usuario)
  - `stic_pa_password_c` (contraseña, en claro — ver aviso arriba)
- Para que alguien pueda entrar al área privada, basta con que su Contacto/Cuenta en el CRM
  tenga esos dos campos rellenos. El **registro ("signup")** desde la web simplemente crea un
  Contacto/Cuenta nuevo con esos campos (ver `pages/single_stic_signup.php` +
  `prefix_admin_single_stic_signup`).
- El parámetro `Module` de la config decide si el área privada trabaja con `Contacts`,
  `Accounts`, o deja elegir (`Any`). Lo resuelve `getDestinationModule()`.

---

## 4. Arquitectura del plugin (mapa de archivos)

| Archivo / carpeta | Qué hace |
|-------------------|----------|
| `sinergiacrm-private-area.php` | **Punto de entrada.** Define el shortcode `[sinergiacrm-private-area]`, el menú de ajustes en el admin de WP, el formulario de login, la sesión y el logout. |
| `inc/stic-class-6.php` | **Cliente de la API REST del CRM** (`SugarRestApiCall`): login, `get_entry_list`, `set_entry`, relaciones, documentos, imágenes… Todo el diálogo con Sinergia pasa por aquí. |
| `inc/stic-action.php` | **Controladores de acciones POST** (`admin_post_*`): procesan el envío de formularios (perfil, documentos, pagos, inscripciones, cambio de contraseña, signup, etc.). Reciben datos del navegador y llaman a `set_entry` para guardarlos en el CRM. |
| `inc/stic-formController.php` | **Motor de formularios** (`makeForm` / `renderField`): convierte una definición de campos PHP en HTML, mezclando con la definición de campos que da el CRM. |
| `inc/stic-listController.php` | Motor de listados (tablas de registros). |
| `inc/stic-formatter.php` | Formateadores de valores (fechas, moneda…). |
| `inc/stic-script-vars.php` | Variables que se pasan al JavaScript del front. |
| `menu.php` | **Define el menú del área privada** (qué secciones ve el socio). Se activan/desactivan descomentando líneas en `getSticMenuElements()`. |
| `pages/` | **Una vista por pantalla.** `list_*` = listados, `single_*` = formularios de un registro. Cada archivo declara qué campos mostrar. |
| `css/` | Estilos (ver sección 6). |
| `js/` | Librerías front: FullCalendar (calendario), Selectize (multiselects), DataTables (tablas), utilidades propias. |
| `languages/` | Traducciones (text domain `sticpa`): catalán, español. |

### Cómo se monta una página en pantalla

1. Pones el shortcode `[sinergiacrm-private-area]` en una página de WordPress.
2. Si **no** hay sesión → se muestra el login (`sugar_crm_portal_check_user_and_login`).
3. Si **hay** sesión → `sugar_crm_portal_index()` pinta el menú (`menu.php`) y hace
   `include` del archivo de `pages/` correspondiente a `?internalpage=...`.
4. Cada página de `pages/` define una lista de campos y llama a `makeForm()` (formularios) o
   al list controller (listados), que a su vez piden datos al CRM vía `SugarRestApiCall`.

---

## 5. Cómo incorporar campos personalizados (custom fields) que tengas en Sinergia

Buena noticia: el sistema está pensado justo para esto. Si ya tienes el campo creado en
SinergiaCRM (Studio → módulo → campo, normalmente con sufijo `_c`), añadirlo a una pantalla es
casi trivial.

### 5.1 Receta rápida

1. **Crea el campo en SinergiaCRM** (si no existe) desde *Studio*. Apunta su **nombre exacto**
   (ej. `mi_campo_c`).
2. Abre la página de `pages/` donde quieras que aparezca. Por ejemplo, para añadirlo al
   registro de socios edita [`pages/single_stic_signup.php`](pages/single_stic_signup.php), o
   para el perfil [`pages/single_stic_profile.php`](pages/single_stic_profile.php).
3. Añade una entrada al array `$fieldList`. **En el caso más simple, solo el `name`:**

   ```php
   $fieldList[] = array('name' => 'mi_campo_c');
   ```

   El plugin pedirá al CRM la definición del campo (`get_module_fields`) y rellenará solo el
   tipo, la etiqueta, si es obligatorio y, en los desplegables, las opciones.

4. Si quieres personalizar algo, amplía el array:

   ```php
   $fieldList[] = array(
       'name'     => 'mi_campo_c',
       'label'    => __('Mi etiqueta bonita', 'sticpa'),  // sobrescribe la del CRM
       'type'     => 'select',        // text, textarea, select, password, date, bool, radio...
       'required' => true,
       'defaultValue' => '',
       'attributes'   => array('disabled' => 'disabled'),  // opcional
       'selectValues' => array(       // solo para select/radio si no quieres las del CRM
           ''       => ' ',
           'opcion1' => __('Opción 1', 'sticpa'),
           'opcion2' => __('Opción 2', 'sticpa'),
       ),
   );
   ```

5. **¡Ya está!** Como `stic-action.php` recorre **todo** el `$_REQUEST` y se lo pasa a
   `set_entry`, el valor del campo se guardará automáticamente en el CRM sin tocar el
   controlador. (Es decir: si el `name` del input coincide con el nombre del campo en el CRM,
   se guarda solo.)

### 5.2 Tipos de campo soportados

El motor (`getFieldHtml` en `inc/stic-formController.php`) entiende, entre otros:
`text`, `textarea`, `varchar`, `name`, `email`, `phone`, `date`, `datetime`, `datetimecombo`,
`number`/`decimal`/`integer`/`float`, `password`, `select`/`enum`/`dynamicenum`,
`multienum`/`selectMultiple` (multiselect), `bool` (checkbox), `radio`, `hidden`,
`header`/`subheader` (separadores), `readOnly`, `info`, `html` (HTML libre tuyo).

### 5.3 Avisos al añadir campos

- El `name` debe ser **idéntico** al nombre del campo en el CRM (sensible a mayúsculas).
- Para multiselect, el plugin envía los valores con el formato SuiteCRM `^val1^,^val2^`. Eso ya
  lo gestionan los controladores; no tienes que hacer nada especial.
- Por la bug conocida de la API de SuiteCRM, el flag `required` que devuelve el CRM no siempre es
  fiable: si necesitas que un campo sea obligatorio, ponlo explícito con `'required' => true`.

---

## 6. ¿Qué lenguaje se usa y cómo hacer los estilos MUY modernos?

### 6.1 Stack tecnológico

- **Backend / plantillas:** **PHP** (estilo procedural, mezclando lógica y HTML; es un plugin
  WordPress clásico, sin framework). API de WordPress (`add_action`, `add_shortcode`,
  `get_option`, `wp_redirect`, `__()` para traducciones…).
- **Frontend:** **HTML** generado desde PHP + **CSS** + **JavaScript/jQuery**. Librerías
  incluidas: FullCalendar, DataTables, Selectize.
- **Datos:** **API REST JSON** de SuiteCRM/SugarCRM vía cURL.
- **i18n:** ficheros `.po`/`.mo` en `languages/` (gettext), text domain `sticpa`.

### 6.2 Dónde están los estilos

Se cargan **solo** en las páginas que tienen el shortcode (para no ensuciar el resto de la web),
en `sugar_crm_portal_style_and_script()`:

```php
wp_enqueue_style('stic-style',        'css/stic-style.css');        // base (657 líneas)
wp_enqueue_style('stic-multiselect',  'css/selectize.css');         // librería selectize
wp_enqueue_style('custom-style',      'css/custom-style.css');      // TUYO, vacío ahora
wp_enqueue_style('stic-modern-style', 'css/stic-modern-style.css'); // "moderno" (1355 líneas)
wp_enqueue_style('fullcalendar',      'js/fullcalendar/lib/main.css');
```

Orden de carga = orden de prioridad (lo último pisa a lo anterior). Tienes:

- `css/stic-style.css` → estilos base históricos.
- `css/stic-modern-style.css` → una capa de modernización ya empezada.
- **`css/custom-style.css` → está VACÍO y es TU sitio para personalizar.** Como se carga
  después de `stic-style`, puedes sobreescribir lo que quieras aquí sin tocar los ficheros del
  plugin (así no pierdes los cambios al actualizar).

### 6.3 Cómo modernizarlos MUCHO (recomendación práctica)

Para hacerlos modernos de verdad sin reescribir el plugin, trabaja en `css/custom-style.css`:

1. **Define un sistema de design tokens con variables CSS** al principio del archivo:

   ```css
   :root {
     --pa-primary: #4f46e5;        /* color principal de marca */
     --pa-primary-700: #4338ca;
     --pa-bg: #f7f8fc;
     --pa-surface: #ffffff;
     --pa-text: #1f2937;
     --pa-muted: #6b7280;
     --pa-border: #e5e7eb;
     --pa-radius: 14px;
     --pa-shadow: 0 10px 30px rgba(2, 6, 23, .08);
     --pa-font: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
   }
   ```

2. **Tipografía moderna** (carga una Google Font tipo *Inter*, *Manrope* o *Poppins* desde el
   `<head>` del tema o con `wp_enqueue_style`) y aplícala al contenedor del área privada.

3. **Tarjetas y profundidad:** envuelve formularios/listados en superficies con
   `border-radius`, sombras suaves y espaciado generoso:

   ```css
   .stic-form {
     background: var(--pa-surface);
     border: 1px solid var(--pa-border);
     border-radius: var(--pa-radius);
     box-shadow: var(--pa-shadow);
     padding: 28px;
   }
   ```

4. **Inputs modernos** (los inputs usan la clase `.input-text`):

   ```css
   .stic-form .input-text {
     border: 1px solid var(--pa-border);
     border-radius: 10px;
     padding: 12px 14px;
     transition: border-color .15s ease, box-shadow .15s ease;
   }
   .stic-form .input-text:focus {
     border-color: var(--pa-primary);
     box-shadow: 0 0 0 3px color-mix(in srgb, var(--pa-primary) 20%, transparent);
     outline: none;
   }
   ```

5. **Botones con gradiente / estados hover** (clase `.stic-button`):

   ```css
   .stic-button {
     background: linear-gradient(135deg, var(--pa-primary), var(--pa-primary-700));
     color: #fff; border: 0; border-radius: 12px;
     padding: 12px 22px; font-weight: 600; cursor: pointer;
     transition: transform .08s ease, filter .15s ease;
   }
   .stic-button:hover { filter: brightness(1.07); }
   .stic-button:active { transform: translateY(1px); }
   ```

6. **Responsive / mobile-first:** usa CSS Grid/Flexbox y `@media` para que el formulario de dos
   columnas (`.stic-form-two-col`) colapse a una sola en móvil. El layout actual se apoya en
   `<ul><li>`, así que un `display:grid` sobre el `<ul>` te da columnas modernas sin tocar PHP.

7. **Detalles que dan el toque "MUY moderno":** micro-animaciones (`transition`), modo oscuro
   con `@media (prefers-color-scheme: dark)` reusando las variables, iconos SVG, y estados de
   foco accesibles.

> Clases/ganchos útiles que ya genera el HTML y puedes estilar: `.stic-form`,
> `.stic-form-two-col`, `.stic-login-form`, `.input-text`, `.stic-button`, `.stic-send`,
> `.stic-msg`, `.error`, `.success`, `.input_login`, `.actions_login`, `.stic-check-group`,
> y el contenedor del menú generado en `menu.php`.

---

## 7. Configuración paso a paso (instalación)

1. Copia el plugin a `wp-content/plugins/` y actívalo en WordPress.
2. Ve al menú **"SinergiaCRM Private Area"** del admin de WP.
3. Rellena: **Host URL**, **REST URL**, **Username**, **Password** (la cuenta de servicio del
   CRM) y elige **Module** (`Contacts`, `Accounts` o `Any`). Al guardar, el panel te dirá
   *"Successful connection"* si conecta bien.
4. Crea una página de WordPress y mete el shortcode: `[sinergiacrm-private-area]`.
5. En SinergiaCRM, asegúrate de que los Contactos/Cuentas que deben tener acceso tienen
   rellenos `stic_pa_username_c` y `stic_pa_password_c`.
6. Personaliza el menú en `menu.php` y los estilos en `css/custom-style.css`.

---

## 8. Glosario rápido para humanos despistados (y agentes de IA)

- **Shortcode:** etiqueta `[...]` de WordPress que inserta funcionalidad en una página.
- **`set_entry`:** método de la API del CRM para **crear o actualizar** un registro.
- **`get_entry_list`:** método de la API para **buscar/listar** registros con una query.
- **Campo `_c`:** campo personalizado ("custom") creado en el CRM con Studio.
- **`$_SESSION['scp_*']`:** datos del socio logueado, guardados en la sesión de PHP.
- **Cuenta de servicio:** el único usuario del CRM que usa WordPress para conectarse (Nivel 1).
- **Usuario de área privada:** cada socio, identificado por `stic_pa_username_c` (Nivel 2).
- **`internalpage`:** parámetro de URL que decide qué archivo de `pages/` se muestra.

---

## 9. Documentación oficial

- Wiki SinergiaCRM (ES/CA):
  https://wikisuite.sinergiacrm.org/index.php?title=Plugin_Wordpress_para_gesti%C3%B3n_de_%C3%81rea_Privada
- Repositorio SinergiaCRM/SuiteCRM: https://github.com/SinergiaTIC/SinergiaCRM-SuiteCRM
</content>
</invoke>
