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

Y aun así **el usuario reporta que no se guarda**. Lo que hay que hacer, en este
orden:

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

### 🔴 Y una bomba de relojería: las vigencias caducan el 31/08/2026

Verificado en el CRM el 27/08/2026: **tanto la relación de participante de
Solete como la de monitor de David Soler tienen `end_date` = 2026-08-31**. En
cuanto pase esa fecha, C1 se queda sin miembros vigentes y la pantalla volverá a
decir «0 participantes».

Eso se va a leer como «ha vuelto el bug grave» y **no lo será**: será que el
curso 2025-2026 se ha acabado en los datos. Hay que renovar las relaciones (o
crear las del curso siguiente) antes de seguir depurando, o cada prueba a partir
del 1 de septiembre partirá de un grupo vacío.

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

1. **Medir con la caché caliente**, que es lo que verá un monitor real una vez
   configurado el calentado nocturno.
2. Subir el TTL de la familia `struct`: son grupos y personas, no cambian a
   media tarde.
3. **Solo entonces** plantear el middleware / base de datos espejo. Es mucha
   superficie nueva (sincronía, conflictos, datos rancios) y ahora mismo no está
   justificada: el 1+N ya no está.

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

### El juego de datos para probar el guardado

Preparado a propósito el 27/08/2026 vaciando el `status` de 14 asistencias de
Solete (las de 18/10, 01/11, 08/11, 15/11, 29/11, 06/12, 22/12 de 2025 y 10/01,
24/01, 31/01, 07/02, 14/02, 21/03, 28/03 de 2026). Con las 5 que ya estaban
vacías (22/11, 13/12, 17/01, 14/03, 25/04), quedan:

- **19 de las 24 sesiones «sin pasar»** — asistencia creada y enlazada a su
  sesión y a su inscripción, pero con `status` vacío. Son las que sirven para
  probar el guardado una y otra vez.
- **4 marcadas a propósito** (28/02, 11/04, 18/04 y 02/05 de 2026), para probar
  también el camino de «esta lista ya estaba pasada».

**Los monitores están listos para probar sin tocar nada más.** David tiene
inscripción en el evento COM y `uninvited` no le excluye, así que sale en la
pantalla de monitores. No tiene asistencias todavía, y eso es lo interesante: al
guardar por primera vez desde `single_stic_pasar_lista_monitores` se recorre el
camino de CREAR la asistencia (y de escribir `yes` explícito para los no
marcados, que en monitores es un dato afirmado y no un hueco).

Solo dos sesiones tienen `LIS_listas`, las dos creadas el 24/08/2026 por las
pruebas: la del 25/04 en `omitida` y la del 02/05 en `pasada` con 0/1. **No hay
histórico real de listas.**

Dos restos conocidos que **no se han borrado** a propósito, porque borrar en el
CRM lo decide el usuario:

- La asistencia duplicada `Unknown` del 02/05/2026, con `status = yes`
  contradiciendo a la otra del mismo día (`no_unjustified`). Es el rastro del bug
  de duplicados.
- Las dos `LIS_listas` de prueba de arriba.

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
- Borrar la asistencia basura `00000ea3-0611-9695-a832-6a8c0c33eced`
  («Unknown - Unknown»), que es el rastro del bug de duplicados.
