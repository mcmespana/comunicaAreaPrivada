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

## 2. Si el correo no está, se dice (31/10/2026)

Hasta el 31/10/2026 la regla era la contraria —«nunca decimos si un correo
existe»—: con un correo registrado o con uno inventado se veía **exactamente la
misma pantalla**. Tenía un precio real: quien escribía mal su correo, o usaba
uno que no era el del alta, se quedaba esperando un mensaje que no iba a llegar,
sin nada que se lo dijera, y acababa llamando a la oficina técnica.

**Decisión del propietario:** si el correo no existe, se contesta la verdad, y
en la misma pantalla se ofrece la salida (el DNI, §4, que ya contestaba con la
verdad).

| Qué pasa | A dónde va | Qué se ve |
|---|---|---|
| El correo existe | Pantalla del código | «Mira tu correo», como siempre |
| **No existe** (`email_error=nohay`) | Login | Aviso rojo con el correo tecleado, el campo **relleno** para corregir la errata y debajo la **tarjeta del DNI abierta** («¿Te diste de alta con otro correo?», botón en contorno «Buscarme por mi DNI») |
| No parece un correo (`formato`) | Login | Aviso corto; no se pregunta al CRM |
| El CRM no contesta (`crm`) | Login | «Ahora mismo no podemos comprobar tu correo». **No** se dice que no existe: sin esto una caída del CRM mandaría a todo el mundo al DNI |
| Demasiadas direcciones seguidas (`throttled`) | Login | «Espera unos minutos o escríbenos» |
| Tope de envíos a ese correo agotado | Pantalla del código | Aviso de esperar (quien tiene un código en camino lo ve ahí) |

El correo tecleado viaja en la **sesión** (`sticpa_email_miss`), no en la URL,
que acaba en los logs del servidor. Se pinta escapado.

### El freno que sustituye al silencio

Decirlo regala una forma de comprobar si una persona concreta está en el CRM
—de un movimiento católico, que en el RGPD es dato de categoría especial— y de
barrer direcciones. Por eso, en lugar del silencio, hay un tope:

- Cada correo que **no existe** se apunta **por IP** (`sticpa_otp_note_miss`):
  **8 cada 30 minutos**, filtrable (`sticpa_otp_miss_max`,
  `sticpa_otp_miss_window`). Pasado el tope **ni se mira en el CRM**, ni siquiera
  un correo que sí existe: la respuesta no puede depender de la dirección.
- Los aciertos y las caídas del CRM **no** cuentan.
- Se mantienen los topes de envíos de siempre (por correo y por IP).
- Equivocarse una o dos veces no lo nota nadie; 8 en media hora es un colegio
  entero con una sola IP o un barrido.

Si algún día hay que volver al silencio, es este bloque y el handler
`sticpa_handle_send_access`; los tests de `tests/AccesoCorreoTest.php` dicen qué
se rompe.

---

## 3. El rescate: «¿necesitas ayuda?»

Antes de que el login dijera cuándo un correo no existía (§2), esto era la única
ayuda: las salidas, en orden de probabilidad, dentro de un desplegable cerrado al
final de la pantalla del código (`sticpa_access_rescue_html`). Sigue ahí, para
quien llega con un correo que sí existe pero no ve el mensaje:

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
| Login | Correo · «Enviarme el acceso» | «¿Qué correo pongo?»: familias / miembros y el DNI. Tras un «ese correo no está», en su lugar, la tarjeta del DNI **abierta** (§2) |
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

### Aquí también se contesta con la verdad

Por documento decimos «no encontramos a nadie con ese documento» (y desde el
31/10/2026 por correo también, §2). Aquí es una decisión de siempre, no un
descuido:

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

### Una familia con varios hijos que entra por un enlace (09/10/2026, plan 042 FAM-a1)

Hasta el 09/10/2026 esta nota decía que la familia veía el evento «como ella
misma». Era peor: una madre que **solo es familiar** y tiene **dos o más
hijos** quedaba con `scp_user_id` = ella pero tratada como «participante» (menú
entero, «Datos de Marta») y **«Inscribirme» la apuntaba A ELLA**, o le decía
que su ficha no tenía el perfil del evento. El camino más corriente de una
familia nueva.

Ahora, mientras no haya elegido a quién ve (`scp_tutor_is_user` sin poner), la
página pedida se cambia por **«¿A quién quieres apuntar?»** (o «¿A quién
quieres ver?» si el destino no es un evento) y, al elegir, se va **al destino**
y no a la portada. El destino viaja en la tarjeta como un solo parámetro `dest`
y el handler lo pasa por la **misma lista blanca del login**
(`sticpa_family_deep_link_destination()`, que usa
`sticpa_login_destination_args()`). La selección se pinta en el sitio, sin
redirigir, y una vez elegido (un hijo o ella misma) los enlaces pasan: no hay
bucle. Con un hijo (se elige solo), sin hijos o siendo además miembro del MCM,
todo sigue como antes. Código: `sticpa_family_must_choose_first()` en
`inc/stic-family.php`; pruebas en `tests/FamilyContextTest.php`.

### Cambiar de hijo sin volver a la portada (11/10/2026, plan 043 FAM-a8)

Cambiar de hijo en el selector de la barra llevaba SIEMPRE a la portada, y
apuntar a dos hermanos a la misma convivencia costaba siete toques. Ahora el
selector manda la página en la que se está en un parámetro `volver` (como
`dest`, una query en un solo parámetro) y el handler vuelve a ella **solo si no
es de nadie**: la lista de eventos, la ficha de un evento (`action=detail` y su
`id`) y el calendario. Pasa por `sticpa_login_destination_args()` y luego por
una lista más corta, `sticpa_family_switch_destination()`
(`inc/stic-family.php`), que además mira que esa sección se le enseñe a quien
se ve ahora: quien se elige a sí misma y solo es familiar no tiene Eventos ni
Calendario, así que va a la portada. Cualquier otra página (un pago, una
inscripción, sus datos) o algo raro, a la portada como antes. `dest` (FAM-a1)
manda sobre `volver`, y los dos solo se siguen con una elección válida.

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
