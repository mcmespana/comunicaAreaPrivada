# Pasar Lista — parte de estado

**Fecha de este parte: 27 de agosto de 2026.** Es el documento que hay que leer
para retomar el trabajo sin reconstruir el contexto. El mapa de todo lo demás
está en [`PASAR-LISTA-README.md`](PASAR-LISTA-README.md); el diseño funcional,
en [`PASAR-LISTA.md`](PASAR-LISTA.md).

Piloto: **MCM Castellón, curso 2025-2026**. Nada interdelegacional.

> **Versión web de este parte**, para leerla o compartirla sin abrir el repo:
> <https://claude.ai/code/artifact/0701d2cf-bf96-4b5d-b3c9-b5db712476a7>
> Es una copia: si cambias este documento, hay que volver a publicarla.

---

## 1. El bug abierto

### 🔴 Pasar lista todavía no pasa lista

**Sigue sin funcionar de punta a punta en producción.** Es EL bug: mientras esté
abierto, lo demás es decoración.

Lo que se ha arreglado por el camino (y está desplegado):

- La lista se buscaba por un enlace anidado que esta instancia no devuelve, así
  que **nunca encontraba la lista existente** y creaba una nueva cada vez. De
  ahí los dos registros `Omitida` con 0/0 que aparecieron en el CRM.
- El `regMap` (qué inscripción corresponde a cada persona) venía por esa misma
  vía. **Sin inscripción detrás no hay asistencia que escribir**, así que no se
  escribía nada.
- Guardar sin marcar a nadie escribía una lista falsa. Ahora no escribe nada y
  lo dice.
- La asistencia creada por el CRM no se encontraba, así que se creaba una
  segunda (`Unknown - Unknown`, sin fecha ni duración). Ahora se actualiza la
  existente — verificado contra el CRM real.

Y aun así **el usuario reporta que no se guarda**.

### Lo verificado el 27/08/2026 por la tarde (esto acota el bug)

El usuario pasó lista a mano (participantes Y monitores) hacia las **~16:00 UTC**
del 27/08 y después se comprobó el CRM por MCP:

- **Cero rastro**: ninguna `stic_Attendances` ni `LIS_listas` con
  `date_modified` posterior a las 14:00 UTC. Ni creación, ni modificación,
  ni borrado.
- Todas las escrituras de ese día en esos módulos son de **«API User MCP»**
  entre las 8:35 y las 8:53 UTC: la limpieza de la «pizarra en blanco», no la
  aplicación.

Conclusión: **el fallo está ANTES de que ninguna escritura llegue al CRM.**
O el POST no entra en la rama de guardado (nonce, marcas vacías tras el
filtrado), o TODAS las llamadas `set_entry` fallan de raíz (login del usuario
API del plugin, ACL de módulo). Y hoy eso es invisible dos veces:

- `SugarRestApiCall::set_entry()` (`inc/stic-class-6.php:344`) hace
  `$recordID = $set_entry_result->id;` **sin mirar si la respuesta es un
  error**: el mensaje real del CRM (sesión inválida, sin acceso al módulo…)
  se tira sin registrarlo.
- Un fallo al escribir la `LIS_listas` **no se cuenta en `failed`**: la
  pantalla dice «Lista guardada» aunque la lista no se haya creado.

Un precedente que hace plausible la vía ACL: al montar seguimientos, el
usuario de la API **no tenía acceso** a `stic_FollowUps` («does not have
access to this module») y hubo que dárselo. Nadie ha verificado ese acceso
para `LIS_listas` con el usuario del PLUGIN (no confundir con el usuario del
MCP, que es otro y sí escribe).

**El plan de cierre, paso a paso, está en
[`../../plans/033-guardado-visible-cerrar-el-bug.md`](../../plans/033-guardado-visible-cerrar-el-bug.md).**
Lo que hay que hacer, en este orden:

1. **Reproducirlo con datos frescos.** La razón por la que no está cerrado es
   que las pruebas anteriores se hicieron sobre sesiones ya pasadas y con pocos
   datos. Hace falta un juego de sesiones sin lista y participantes con
   inscripción para poder repetir el guardado muchas veces.
2. **Mirar el camino completo de `sticpa_pl_save()`**
   (`inc/stic-pasar-lista-crm.php`, ~línea 1573). Las tres cosas que pueden
   fallar en silencio ahí:
   - `$regMap` vacío → no hay `stic_Registrations` que enlazar y la asistencia
     queda huérfana (el CRM no la cuenta en el porcentaje).
   - `sticpa_pl_session_attendances()` devuelve vacío → cree que no hay
     asistencia previa y crea una nueva en vez de actualizar.
   - `set_entry` devuelve algo falsy y se cuenta en `failed` sin decir por qué.
3. **Que el fallo se vea.** Ahora mismo un `failed` se cuenta y no se explica.
   Antes de seguir adivinando conviene que la pantalla (o un log) diga *qué*
   llamada falló y con qué respuesta del CRM.

**No se cierra este bug hasta que un guardado real, hecho a mano en el
navegador, deje en el CRM: una `LIS_listas` con `estado = pasada` y los números
correctos, y una `stic_Attendances` por persona marcada, enlazada a su sesión y
a su inscripción.**

### 🟡 Las vigencias caducan el 31/08/2026 (anotado, no bloquea)

Verificado en el CRM el 27/08/2026: **tanto la relación de participante de
Solete como la de monitor de David Soler tienen `end_date` = 2026-08-31**. En
cuanto pase esa fecha, C1 se queda sin miembros vigentes y la pantalla volverá a
decir «0 participantes».

Eso se va a leer como «ha vuelto el bug grave» y **no lo será**: será que el
curso 2025-2026 se ha acabado en los datos.

Decisión del 27/08/2026: **no bloquea**, se sigue probando y se renuevan las
relaciones cuando toque. Pero si a partir del 1 de septiembre C1 sale vacío,
**mira esto antes de buscar el fallo en el código**.

### Otros abiertos, menores

| | Qué |
|---|---|
| 🟡 | Alguien puede ser monitor de dos grupos. Sale, pero hay que decidir cómo se enseña. |
| 🟡 | El grupo `Najar` no es de participantes MIC-COM y aparece en el árbol. Hay que decidir el filtro de qué grupos salen. |
| 🟡 | Los «sectores» (COM I = los dos primeros cursos de la ESO, etc.) se agrupan **a mano**. No hay campo en el CRM y de momento no lo va a haber. |
| 🟡 | El calentado de caché **no está configurado** (ver §5). Funciona sin él, solo que la caché la calienta el primero que entra. |
| 🟡 | El filtro plano por campo de enlace (`..._ida`) en `get_entry_list` devuelve **error 400 de base de datos** en `stic_Sessions`, `LIS_listas`, `stic_Registrations` y `stic_Contacts_Relationships`. Se puede LEER el campo, pero no FILTRAR por él. Para consultar por relación hay que ir por `get_relationships`. Ojo: no es lo mismo que la trampa de §3.1 — ahí el problema es el enlace anidado que no viene; aquí es el filtro que el CRM rechaza. |
| 🟡 | Las asistencias de 5 sesiones existen **sin `status`**: quedaron a medio marcar. Es un estado válido (una sesión sin pasar), pero conviene saber que existe. |

---

## 2. Qué SÍ funciona

- Las cinco pantallas se pintan enteras y sin avisos: portada, árbol de grupos,
  marcar, ficha y resumen.
- El grupo C1 enseña a sus participantes (fue un bug grave y está cerrado).
- «Tu grupo» detecta el grupo del monitor conectado.
- El orden de los grupos: por etapa y, dentro, por código en orden natural.
- El botón de refrescar invalida de verdad, y en las cuatro pantallas.
- Los recuentos nocturnos del Guardián se leen en la ficha del grupo.
- El modo sin conexión está construido (apagado por defecto con el filtro
  `sticpa_pl_offline_enabled`).

---

## 3. Las trampas: por qué se rompió lo que se rompió

Esta sección es la que ahorra días. Son cinco cosas que **volverán a morder** a
quien no las sepa.

### 3.1 Esta instancia NO devuelve los enlaces anidados

`get_relationships` con `related_module_link_name_to_fields_array` devuelve
**200, con registros, y sin el enlace anidado dentro**. No falla: miente por
omisión. Cinco bugs distintos venían de aquí.

Lo que sí funciona es `get_entry_list` con `link_name_to_fields_array`, que es
el 4º parámetro de `getRecordsModule()`. Está probado en producción desde años
en `pages/list_stic_job_offers.php`.

**La regla:** pide SIEMPRE el campo plano `..._ida` junto al enlace anidado y
usa el que llegue. Los campos planos existen por relación:

```
stic_contacts_relationships_contactscontacts_ida
ajmcm_grupos_stic_contacts_relationshipsajmcm_grupos_ida
stic_registrations_contactscontacts_ida
lis_listas_stic_sessionsstic_sessions_ida
lis_listas_ajmcm_gruposajmcm_grupos_ida
stic_attendances_stic_sessionsstic_sessions_ida
stic_attendances_stic_registrationsstic_registrations_ida
```

Detalle largo en [`PASAR-LISTA-CAMPOS-CRM.md`](PASAR-LISTA-CAMPOS-CRM.md) §9.

### 3.2 Un doble de test puede mentir sobre la forma de la API

Hubo **175 tests en verde con la producción rota**. El doble colgaba
`link_list` directamente del registro; la API real lo manda en
`relationship_list`, en paralelo y por posición. El plugin tenía que ensamblarlo
y no lo hacía, y el doble ocultaba justo eso.

**La regla:** un doble construye la forma **real** de la API y la pasa por el
ensamblado del propio plugin (`SugarRestApiCall::attachLinkList()`,
`flattenRelationshipFields()`). Si el doble tiene su propio ensamblado, no
prueba nada.

### 3.3 El CSS de los artboards es la especificación, no su texto

Los `.dc.html` de `design/pasar-lista/` llevan todo su CSS en línea. Leer solo
el texto produce pantallas con el contenido correcto y el aspecto equivocado:
título a 1,15rem donde el diseño dice 1,45rem, filas de 52 px donde dice
`padding: 15px 16px`, una tarjeta verde que no existe en ningún artboard.

### 3.4 Un token que no existe se ve bien en claro y mal en oscuro

`var(--surface-1, #fff)` con `--surface-1` inexistente **no falla**: se queda
con el valor de reserva, que es el del tema claro. En oscuro sale una tarjeta
blanca. Había cinco tokens inventados así.

Dos más de la misma familia:

- **`--white` no es «blanco»**: es un token de superficie y en oscuro vale
  `#16171a`, *más oscuro* que el fondo de página. Para lo que va encima de un
  relleno de color está `--pl-on-brand`, que es blanco fijo.
- **El atributo del tema es `data-stic-scheme`**, no `data-theme`. Había cuatro
  reglas escritas con `[data-theme]` que no aplicaban nunca.

`tests/TokensCssTest.php` impide que las tres vuelvan. **No pongas valores de
reserva con color en `var()`**: es lo que esconde el fallo.

### 3.5 El tema de WordPress pinta tus `<button>`

El tema estila `.entry-content button`, con más especificidad que una clase.
Todos los botones de Pasar Lista son `<button>` de verdad (accesibilidad), así
que heredaban su relleno: «Han venido todos» con letra blanca sobre verde claro,
«Sin registro» como barra ámbar sólida. Neutralizado en `css/pasar-lista.css`
§0.b, con el mismo remedio que ya usaba `.stic-pass-toggle`.

---

## 4. Rendimiento: dónde está y qué queda

El problema no era el CRM: era un bug propio (`firstLinked()` daba el primer
bloque de enlace a **todos** los campos pedidos, así que el camino de una sola
llamada fallaba en silencio y caía a respaldos 1+N).

Llamadas al CRM por pantalla:

| Pantalla | Antes | Ahora |
|---|---|---|
| Portada | 13 | 6 |
| Árbol de grupos | 10 | 6 |
| Marcar | 14 | 8 |
| Resumen | 15 | **7** |

`tests/CosteLlamadasTest.php` fija los topes. **Si añades una consulta, ese test
te lo dirá**: es el sitio donde se ve un 1+N nuevo antes de que llegue a
producción.

Cargadores de colección (uno por colección, nunca uno por fila):
`sticpa_pl_all_relationships()`, `sticpa_pl_all_listas()`,
`sticpa_pl_attendances_for_sessions()`.

### Lo siguiente, si sigue lento

Hay plan cerrado y ordenado en
[`../../plans/034-rendimiento-calentar-paralelizar-medir.md`](../../plans/034-rendimiento-calentar-paralelizar-medir.md):
medir (Server-Timing), configurar el calentado nocturno (los 3 secretos, cero
código), **paralelizar con `curl_multi` las llamadas independientes de cada
pantalla** (el coste real que queda: 6-8 llamadas EN SERIE), write-through de
caché tras guardar, y TTL de `struct` arriba.

La pregunta de la **base de datos espejo** quedó analizada y decidida el
27/08/2026 en [`DECISION-BBDD-ESPEJO.md`](DECISION-BBDD-ESPEJO.md): Neon
descartado (dato minúsculo, RTT externo nuevo, RGPD de menores, sync contra la
misma API frágil); si tras el plan 034 sigue lento, espejo de LECTURA en la
MySQL de WordPress con el diseño que ya está escrito allí.

---

## 5. La caché: cómo funciona y qué falta configurar

Dos familias, y **la familia sale del nombre de la caché**:

| Familia | Qué guarda | TTL | Se invalida |
|---|---|---|---|
| `state` | listas, asistencias, ausencias seguidas | 5 min | al guardar una lista |
| `struct` | grupos, personas, eventos, sesiones, inscripciones | 12 h | solo con el botón de refrescar |

La invalidación es por **contador de generación dentro de la clave**, no
borrando transients por nombre: hay claves que llevan un id dentro (las personas
de un grupo) y no se puede saber cuáles existen. El contador vive en
`wp_options`, no en un transient, para que perderlo no resucite datos viejos.

> **Cuidado al añadir una caché nueva:** si se llama de una forma que no está en
> la lista de `state`, cae en `struct` y el flush de después de guardar no la
> tira. Pasó con `listas` y `attrange`.

### Refrescar al momento

El botón circular de la cabecera (`?refrescar=1`) tira las dos familias de tu
delegación. Está en portada, árbol y resumen; y si un grupo sale sin
participantes, la pantalla de marcar ofrece **«Ya lo he arreglado, vuelve a
mirar»**.

### ⚠️ Calentado nocturno: falta configurarlo

El Guardián deja la caché hecha de madrugada para que el primero que entra el
sábado no la pague. Necesita tres cosas, y **sin ellas la tarea se salta y lo
dice en el informe** (no se calla):

```bash
openssl rand -hex 32
```

1. `wp-config.php` → `define('STICPA_PL_WARM_SECRET', '<eso>');`
2. Secreto del repo `AREA_PRIVADA_CALENTAR_SECRET` = **el mismo valor**
3. Secreto del repo `AREA_PRIVADA_URL` = la base del sitio, sin barra final

Detalle en [`GUARDIAN-NOCTURNO.md`](GUARDIAN-NOCTURNO.md) §5.

---

## 6. Cómo probar

```bash
composer install
vendor/bin/phpunit                                        # 243 tests
node --test .github/scripts/guardian/guardian.test.mjs    # 36 tests
```

Contra la instancia de pruebas: `https://comunica.movimientoconsolacion.com/aptest/`

Con el CRM por MCP, dos reglas que no son opcionales:

- **Siempre `fields` acotado.** Sin él la API devuelve todos los campos del
  módulo y se come el contexto.
- **Para explorar, un subagente** (Sonnet, esfuerzo bajo) que devuelva solo el
  dato mínimo, en vez de meter cientos de líneas de JSON en el hilo principal.

No hay herramienta de borrado: se borra con `update_entry` y `deleted: true`.
Y la API **no valida los desplegables**: acepta cualquier cadena, así que una
clave interna mal escrita se guarda sin protestar. Las claves buenas están en
[`CAMPOS.md`](CAMPOS.md).

---

## 7. Los datos reales del piloto (verificado 27/08/2026)

Los IDs, para no volver a buscarlos:

| Qué | ID |
|---|---|
| Delegación **MCM Castellón** (`Users`) | `00000cd2-159a-eef9-3639-68cd21b90b6a` |
| Grupo **C1** (`ajmcm_GRUPOS`) | `00000fda-af65-a98f-e69c-6a8789aca09d` |
| Evento **COM \| Sesiones semanales 2025-2026** | `00000e2a-f3e4-165f-9e5b-6a87868a5297` |
| **Solete Vilarroya** (`Contacts`) | `00000014-0ae5-3f14-28d1-6a4f389295c0` |
| Inscripción de Solete en el COM | `00000d2b-688b-264e-cbdd-6a8844c92e93` |
| **David Soler** (`Contacts`) | `00000c2f-102d-2c5b-04b9-68cec329dfe6` |
| Inscripción de David en el COM | `00000816-f8c7-f889-bc7b-6a8b8bf41458` |

Cómo está montado:

- El evento COM tiene **24 sesiones**, del 18/10/2025 al 02/05/2026, sábados
  16:30-18:00.
- C1 tiene **una** participante vigente (Solete) y **un** monitor (David).
- Hay **17 inscripciones** en el evento COM, pero solo dos son de C1: las otras
  15 son de otros grupos de la misma etapa. Todas en `uninvited` menos la de
  Solete, que está `confirmed`. **`uninvited` no molesta**: el código solo
  excluye `cancelled`.
- Existe una asistencia de Solete para cada una de las 24 sesiones.

### Pizarra en blanco (27/08/2026)

El CRM se dejó **limpio a propósito** para poder comprobar si el guardado
funciona de verdad, sin restos de pruebas anteriores que confundan:

| | Estado verificado |
|---|---|
| `LIS_listas` de la delegación | **0** |
| Asistencias de Solete | **24**, una por sesión |
| …con `status` | **0** — ninguna marcada |
| …enlazadas a su sesión y a su inscripción | **24 de 24** |
| Duplicados | ninguno (24 ids de sesión distintos) |

Lo que se borró (borrado lógico, `deleted: true`):

- Las **dos `LIS_listas`** creadas el 24/08/2026 por las pruebas: la del 25/04
  en `omitida` 0/0 y la del 02/05 en `pasada` 0/1.
- La asistencia duplicada **`Unknown - Unknown`**
  (`00000ea3-0611-9695-a832-6a8c0c33eced`), que tenía `status = yes`
  contradiciendo a la buena del mismo día. Era el rastro del bug de duplicados.

Y se vaciaron los 6 `status` que quedaban (21/02, 28/02, 11/04, 18/04, 02/05).

**Lo que NO se tocó, y no hay que tocar:** las 24 asistencias en sí. Las crea el
CRM al crear la inscripción y son el esqueleto del que cuelga todo — la
asistencia pende de la inscripción, no de la persona. Borrarlas rompería el
modelo, no lo limpiaría.

> ⚠️ **Corrección del propietario (27/08 por la tarde), sin resolver.** El
> usuario afirma que esas asistencias de Solete **las creó Claude a mano por
> MCP** (no el CRM al crear la inscripción) y que ha pedido **borrarlas** para
> empezar de cero. Contradice el párrafo de arriba y no hay que elegir a
> ciegas: **hay que verificarlo empíricamente** (crear una inscripción de
> prueba nueva y mirar si el CRM genera solas las asistencias). Importa mucho:
> si el CRM NO las crea, el camino normal del guardado es CREAR asistencias,
> no actualizarlas — y el camino de crear es justo el que está fallando.
> Además, si se borran las 24, la siguiente prueba de guardado ejercitará
> exclusivamente ese camino de creación. Está recogido en el plan 033.

> **Antes de probar, pulsa refrescar.** La caché de `struct` dura 12 h, así que
> la pantalla puede seguir enseñando el estado de antes de la limpieza. El botón
> circular de la cabecera lo arregla al momento.

**Los monitores están listos sin tocar nada.** David tiene inscripción y sale en
la pantalla de monitores; `uninvited` no le excluye porque el código solo excluye
`cancelled`. No tiene ninguna asistencia, y eso es lo interesante: al guardar por
primera vez desde `single_stic_pasar_lista_monitores` se recorre el camino de
CREAR la asistencia, y el de escribir `yes` explícito para los no marcados, que
en monitores es un dato afirmado y no un hueco.

### Qué se tiene que ver después de pasar una lista

Con la pizarra en blanco, un guardado correcto de una sesión deja **exactamente**
esto, y es el criterio para cerrar el bug:

1. **Una** `LIS_listas` nueva, con `estado = pasada`, su `lis_listas_stic_sessions`
   y su `lis_listas_ajmcm_grupos` apuntando a la sesión y a C1, y
   `n_asistieron` / `n_faltaron` cuadrando con lo marcado.
2. La asistencia de Solete de **esa** sesión con su `status` puesto —
   **actualizada, no una nueva**: siguen siendo 24 en total.
3. Nada en las otras 23 sesiones.

Si aparecen 25 asistencias, o dos listas, o una lista con 0/0, el bug sigue ahí y
ya se sabe por dónde: son los tres fallos silenciosos de §1.

### Detalle que conviene documentar en CAMPOS.md

En `LIS_listas`, el campo `lis_listas_contacts_id` apunta al **monitor que pasó
la lista**, no al participante (verificado: en las dos listas existentes apunta
a David). Y `ajmcm_tipo_c` vale `participantes` cuando la lista es de
participantes.

---

## 8. Pendiente en el CRM, no en el código

- El workflow que avisa por correo a coordinación cuando se pone un aviso nuevo.
- `phone_mobile` en `CAMPOS.md` está mal, y hay que propagar el arreglo al repo
  `comunicaFormularios`.
- Cerrar la relación de monitor del grupo `Najar`.
- ~~Borrar la asistencia basura «Unknown - Unknown»~~ — hecho el 27/08/2026,
  junto con las dos `LIS_listas` de prueba. Ver §7, «Pizarra en blanco».
