# Pantallas archivadas

Lo que hay en esta carpeta **no se ejecuta**. Está guardado por si algún día
hace falta, no porque haga falta hoy.

**Archivado el 19/09/2026**, por decisión del propietario: *«no lo vamos a usar,
déjalo deshabilitado o archivado por si algún día lo recuperamos»*.

---

## Qué hay aquí

Tres secciones que venían del **plugin original de SinergiaCRM** —no las hicimos
nosotros— y que el MCM no usa:

| Sección | Archivos |
|---|---|
| Relaciones con la organización | `list_stic_relationships.php`, `single_stic_relationships.php` |
| Contactos de la organización | `list_stic_contacts.php`, `single_stic_contacts.php` |
| Organizaciones miembro | `list_stic_member_organizations.php` |

Sus dos handlers de guardado están en
[`inc/archivo/stic-action-modulos-retirados.php`](../../inc/archivo/stic-action-modulos-retirados.php),
que tampoco se incluye.

## Por qué se archivaron, y no se dejaron donde estaban

Estaban **ni vivas ni muertas**: fuera del menú (sus líneas llevaban años
comentadas) pero todavía alcanzables —la ficha de perfil tenía dos botones
«Volver» que llevaban a «Organizaciones miembro»—, y pintadas con el volcado
genérico antiguo (`makeList()`, filas «ETIQUETA: valor») que se retiró de los
otros ocho módulos. Quien llegara por uno de esos botones se encontraba el
diseño de antes.

Y había una razón menos estética: sus dos handlers estaban registrados como
`admin_post_nopriv_*` —accesibles **sin haber iniciado sesión**—, volcaban todo
el `$_REQUEST` en `set_entry()` y aceptaban `stic-action=delete`. O sea que
cualquiera con la URL podía crear, sobrescribir o borrar registros del CRM: el
de relaciones toca `stic_Contacts_Relationships`, que es **de donde salen los
grupos de cada persona en Pasar Lista**, y el de contactos toca `Contacts`, que
son las personas. Archivarlos cierra eso.

## Por qué aquí no llega nadie

El enrutador (`sticpa_resolve_page_file()`) solo acepta nombres de
`[a-z0-9_]+` y solo mira en `pages/`. Una URL como
`?internalpage=archivo/list_stic_contacts` **no pasa el filtro** (tiene una
barra), y `?internalpage=list_stic_contacts` ya no encuentra archivo. No hace
falta ninguna comprobación extra, y hay un test que lo fija
(`tests/ArchivoTest.php`).

Tampoco se suben al hosting: el workflow de despliegue excluye `**/archivo/**`.

## Cómo se recupera una

1. Mueve el archivo de `pages/archivo/` a `pages/`.
2. Vuelve a poner su entrada en `getSticMenuElements()` (`menu.php`) y su icono
   y descripción en `sticpa_section_meta()` (archivo principal del plugin).
3. **Migra la pantalla a la ficha de registro** (`inc/stic-record-view.php`,
   §4.1 de `docs/design-system.md`). No la dejes con `makeList()`: es el único
   sitio donde quedaría ese lenguaje, y volvería a desentonar con todo lo demás.
4. Si necesita guardar, recupera su handler **arreglándolo** primero. Las cuatro
   cosas que le faltan están escritas en la cabecera del archivo de `inc/archivo/`.
5. Quita del test `tests/ArchivoTest.php` la pantalla que hayas recuperado.

## Lo que NO se archivó

`inc/stic-listController.php` **se queda entero**, y por dos razones:

- `renderDeleteMessage()`, que está ahí dentro, la usan **siete listados vivos**
  (Eventos, Inscripciones, Pagos, Compromisos, Documentos, Sesiones y
  Asistencias).
- `makeList()` ya no lo usa nadie vivo —solo las tres pantallas de esta
  carpeta—, pero borrarlo obligaría a reescribirlas enteras el día que se
  recuperen, que es justo lo que este archivo intenta evitar.

Si algún día se decide que no vuelve ninguna, ese es el momento de tirar también
`makeList()` y su CSS (§22). Hasta entonces es la red, no deuda.
