# Cómo se entra al área privada

Quien entra aquí **no es un usuario de WordPress**: es una persona del CRM. No
hay registro, no hay «recuperar contraseña» y casi nadie tiene contraseña. Este
documento cuenta las puertas que hay, por qué son esas y qué pasa cuando alguien
se queda fuera.

Código: [`inc/stic-otp.php`](../inc/stic-otp.php) (las piezas),
[`inc/stic-magic-login.php`](../inc/stic-magic-login.php) (el enlace firmado) y
los handlers `sticpa_handle_send_access*` de
[`inc/stic-action.php`](../inc/stic-action.php).

---

## 1. Las puertas

| Puerta | Para quién | Qué hace |
|---|---|---|
| **Por correo** (la de siempre) | Todo el mundo | Escribes tu correo y te llega UN mensaje con **enlace mágico + código de 6 cifras** |
| **Contraseña** | Quien se la ha puesto en «Usuario y contraseña» | Usuario = `stic_pa_username_c` o, si no tiene, su DNI |
| **Por documento** (10/09/2026) | Quien no sabe con qué correo se dio de alta | Te busca por el DNI y manda el acceso al correo que ya teníamos |

El correo lleva las dos formas —enlace y código— a propósito: dentro de la app
MCM el enlace falla más, y el código es la red. Está explicado en la cabecera de
`inc/stic-otp.php`.

Desde el 02/10/2026 las dos van **a la vista**: el correo pinta el código
grande, una «o» y el botón, y la pantalla de después enseña el campo del código
abierto y grande, con el botón del correo recordado debajo. El código va además **en el asunto** («123 456 es tu código
de acceso a…»), para leerlo en la notificación sin abrir el correo; se ve en la
pantalla bloqueada, como en casi todos los servicios, y caduca en minutos.

**Las altas no se hacen aquí.** Se hacen en la web pública, con el formulario que
toca en cada caso. La URL está en `sticpa_signup_url()` (opción
`sticpa_signup_url`, por defecto `comunica.movimientoconsolacion.com`). Hasta el
10/09/2026 el login enlazaba a `?internalpage=single_stic_signup`, una página que
**no existe**: era un enlace a un div vacío.

---

### «Usuario y contraseña» (28/09/2026)

La pantalla (`single_stic_password_change`) **enseña el usuario**, porque quien
entra por el correo no lo ha visto nunca. Si la ficha no tiene
`stic_pa_username_c`, el usuario es el DNI (`stic_identification_number_c`, en
mayúsculas y sin guiones), se enseña sin poder editarlo y **se guarda como
usuario** la primera vez que se pone contraseña; si no, la contraseña no
serviría para entrar. Si ese DNI ya es el usuario de otra ficha, no se pisa
(error 5). Sin usuario ni DNI no hay formulario. La contraseña actual solo se
pide si ya existe: la sesión es la prueba de quién es. Mínimo 6 caracteres.
Tests: `tests/PasswordChangeTest.php`.

## 2. La regla que no se toca: nunca decimos si un correo existe

Pidas el acceso con un correo registrado o con uno inventado, ves **exactamente
la misma pantalla**. Y el cupo de envíos se apunta en los dos casos. Si no fuera
así, probar direcciones sería una forma de leerse la base de datos del CRM.

Eso tiene un precio, y es real: quien escribe mal su correo se queda esperando
un correo que no va a llegar, sin nada que se lo diga. Es lo que pasaba, y
terminaba en una llamada a la oficina técnica.

---

## 3. El rescate: «¿necesitas ayuda?»

La solución **no** ha sido romper la regla de arriba, sino dar las salidas que
tiene, en orden de probabilidad, dentro de un desplegable cerrado al final de la
pantalla del código (`sticpa_access_rescue_html`):

1. Mirar la carpeta de spam.
2. Comprobar que el correo esté bien escrito: se enseña **a cuál se mandó**,
   tapado (`da•••@movimientoconsolacion.com`), en el subtítulo de la pantalla.
3. **Buscarse por el DNI** (§4). Es la que resuelve de verdad el caso «me di de
   alta con otro correo».
4. Escribir a la oficina técnica (`sticpa_support_email()`, por defecto
   `comunica@movimientoconsolacion.com`).

### Una pantalla, una cosa (03/10/2026)

Las dos pantallas del acceso siguen el patrón de cualquier servicio: **campo,
botón y la ayuda plegada**. Una sola ayuda por pantalla
(`sticpa_auth_help_details()`):

| Pantalla | Lo que se ve | Lo plegado |
|---|---|---|
| Login | Correo · «Enviarme el acceso» | «¿Qué correo pongo?»: familias / miembros y el DNI |
| Código | Código · «Entrar» · «O entra con el botón del correo» · «Reenviar código · Usar otro correo» | «¿Necesitas ayuda?»: lo de arriba |

Antes el login tenía dos desplegables (uno metido entre el campo y el botón) y
la pantalla del código seis bloques de texto para una sola acción. La ayuda del
login se abre sola cuando se vuelve de un error del DNI, para tener el
formulario a mano.

---

## 4. Entrar por el documento (DNI/NIE)

Escribes tu DNI, te buscamos y te mandamos el acceso **al correo que ya tenemos
guardado**, diciéndote cuál es tapado para que lo reconozcas («ah, era el del
trabajo»).

### Las tres reglas

1. **No abre sesión.** Un DNI no es un secreto —está en cualquier formulario que
   hayas firmado—, así que esta vía solo sirve para MANDAR el correo a donde ya
   estaba. Quien no tenga acceso a ese buzón, no entra. La sesión la abre el
   enlace o el código, como siempre.
2. **La dirección completa no viaja al navegador.** Se guarda en la sesión de
   PHP; la pantalla del código la usa desde ahí y **no** pinta el campo oculto
   con el correo (marca `sticpa_otp_via = dni`). Si lo pintara, bastaría con
   mirar el código fuente para ver entera la dirección que estamos tapando.
3. **Mismo tope que la vía del correo**, por documento y por IP, y el intento se
   apunta acierte o falle.

### Aquí sí se contesta con la verdad

Por documento sí decimos «no encontramos a nadie con ese documento», y es una
decisión, no un descuido:

- Para probar un DNI hay que **tener** ese DNI, que ya es un dato de alguien
  concreto: no se puede barrer como se barren correos.
- Y sin respuesta clara esta puerta no sirve para lo único que existe, que es
  sacar a alguien de un atasco.

Los cuatro casos, cada uno con su mensaje (`sticpa_dni_error_message`):
documento mal escrito · demasiados intentos · **te encontramos pero no tenemos
correo tuyo** (a la oficina técnica) · no existe.

### Qué se busca en el CRM

`getContactByDocument()` mira `stic_identification_number_c` (el documento de la
ficha) y, si ahí no hay nada, `stic_pa_username_c` (el usuario del área privada,
que en esta entidad **es** el DNI). No valida la letra: en el CRM hay pasaportes
y documentos de otros países, y exigir el molde español dejaría fuera justo a
quien más lío tiene para entrar.

> ⚠️ **Sin verificar contra el CRM.** La consulta va como SQL y el MCP de
> SinergiaCRM **no admite SQL** (solo filtros estructurados), así que no se ha
> podido probar desde aquí. Por eso se copia la forma exacta de
> `getUserInformationByUsername()`, que es la del login por contraseña y por
> tanto sabemos que funciona en producción. Si algún día falla, el primer
> sospechoso es el JOIN con `contacts_cstm`: se fuerza pidiendo campos custom en
> `select_fields`.

---

## 4.b Se entra a donde se iba (25/09/2026, TODO EV-8)

Un enlace a una página del área —la ficha de un evento que llega por WhatsApp,
el botón «Entrar al área privada» de la página pública de una actividad— sin
sesión pinta el login. Hasta el 25/09/2026, al entrar se acababa **siempre en la
portada** (salvo con contraseña): el código llevaba al área a secas, el enlace
del correo se firmaba contra el área a secas y la pantalla del código tiraba la
query.

Ahora el **destino** viaja por la URL en cada paso y al entrar se aterriza en él:

| Puerta | Cómo lleva el destino |
|---|---|
| Contraseña | Ya lo hacía: el formulario se manda a la misma URL |
| Código de 6 cifras | La pantalla del código lo lleva en su URL y el verificador redirige a él |
| Enlace del correo | El enlace se firma contra `área + destino`; el puente `/app/acceso` lo reenvía |
| Enlace del correo abierto por la **app** | Depende de la app: si solo coge el token, entra a la portada (como antes). Para que llegue, la app tiene que pasar también `internalpage`, `action`, `id` y `from` (ver `CONTRATO-APP-WEBVIEW.md` §5) |

**No es un redirector.** Del destino solo viajan `internalpage` (tiene que ser
una página de `pages/`) y tres parámetros con su forma exacta: `action`
(`detail`/`create`/`edit`), `id` (un UUID) y `from`. Nada de URLs ni de hosts.
Y la página de destino hace sus comprobaciones de siempre con la sesión recién
abierta: el destino dice a dónde ir, no qué se puede ver. Código:
`sticpa_login_destination_args()` y `sticpa_url_with_destination()` en
`inc/stic-magic-login.php`; pruebas en `tests/LoginDestinationTest.php`.

Por la URL y no por la sesión, a propósito: el enlace del correo se abre muchas
veces en otro navegador —o en la app— que el que lo pidió.

> Una familia con hijos que entra por el enlace de un evento lo ve **como ella
> misma** (igual que si ya tuviera la sesión abierta): para apuntar a un hijo
> tiene que cambiar de participante antes. No es nuevo, pero ahora se llegará
> más a menudo por aquí.

---

## 5. Cosas que estaban y ya no

- **`prefix_admin_single_stic_signup`** (borrado el 10/09/2026): un
  `admin_post_nopriv` que cogía TODO el `$_REQUEST` y lo metía en el CRM con
  `set_entry()`, sin nonce y sin sesión. O sea: cualquiera con la URL podía
  crear registros con los campos que quisiera. No lo usaba nadie —su formulario
  vivía en una página que no existe—.
- **`getAllEmail()`**: se traía el correo de TODOS los contactos del CRM en una
  llamada sin límite, para comprobar si uno estaba repetido. Solo la usaba el
  alta de arriba.
