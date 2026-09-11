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
| **Contraseña** | Quien se la ha puesto desde su perfil | Usuario = su DNI |
| **Por documento** (10/09/2026) | Quien no sabe con qué correo se dio de alta | Te busca por el DNI y manda el acceso al correo que ya teníamos |

El correo lleva las dos formas —enlace y código— a propósito: dentro de la app
MCM el enlace falla más, y el código es la red. Está explicado en la cabecera de
`inc/stic-otp.php`.

**Las altas no se hacen aquí.** Se hacen en la web pública, con el formulario que
toca en cada caso. La URL está en `sticpa_signup_url()` (opción
`sticpa_signup_url`, por defecto `comunica.movimientoconsolacion.com`). Hasta el
10/09/2026 el login enlazaba a `?internalpage=single_stic_signup`, una página que
**no existe**: era un enlace a un div vacío.

---

## 2. La regla que no se toca: nunca decimos si un correo existe

Pidas el acceso con un correo registrado o con uno inventado, ves **exactamente
la misma pantalla**. Y el cupo de envíos se apunta en los dos casos. Si no fuera
así, probar direcciones sería una forma de leerse la base de datos del CRM.

Eso tiene un precio, y es real: quien escribe mal su correo se queda esperando
un correo que no va a llegar, sin nada que se lo diga. Es lo que pasaba, y
terminaba en una llamada a la oficina técnica.

---

## 3. El rescate: «¿no te llega nada?»

La solución **no** ha sido romper la regla de arriba, sino dar las cuatro cosas
que puede hacer, en orden de probabilidad, al final de la pantalla del código
(`sticpa_access_rescue_html`):

1. Mirar la carpeta de spam.
2. Comprobar que el correo esté bien escrito — y se enseña **a cuál se mandó**,
   tapado (`da•••@movimientoconsolacion.com`).
3. **Buscarse por el DNI** (§4). Es la que resuelve de verdad el caso «me di de
   alta con otro correo».
4. Escribir a la oficina técnica (`sticpa_support_email()`, por defecto
   `comunica@movimientoconsolacion.com`).

Es discreto a propósito: la mayoría abre el correo y no llega a leerlo.

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

## 5. Cosas que estaban y ya no

- **`prefix_admin_single_stic_signup`** (borrado el 10/09/2026): un
  `admin_post_nopriv` que cogía TODO el `$_REQUEST` y lo metía en el CRM con
  `set_entry()`, sin nonce y sin sesión. O sea: cualquiera con la URL podía
  crear registros con los campos que quisiera. No lo usaba nadie —su formulario
  vivía en una página que no existe—.
- **`getAllEmail()`**: se traía el correo de TODOS los contactos del CRM en una
  llamada sin límite, para comprobar si uno estaba repetido. Solo la usaba el
  alta de arriba.
