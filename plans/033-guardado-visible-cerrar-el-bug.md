# 033 — Que el guardado se vea, y cerrar EL bug

> ## ✅ HECHO el 27/08/2026 — falta confirmarlo con un guardado real
>
> **La causa era el JS: `saveBtn.disabled = true` dentro del manejador de
> `submit`.** Un control deshabilitado no se serializa, así que `pl_action` no
> llegaba al servidor y PHP se saltaba el guardado entero sin decir nada. Estaba
> ahí desde el primer commit de la fase 1 (`88511ec`), y por eso «pasar lista»
> no ha pasado lista nunca. Ningún test lo veía porque un POST de PHPUnit trae
> `pl_action` puesto a mano.
>
> Arreglado por dos vías (campo oculto + no deshabilitar hasta el tic
> siguiente), y con todo lo de la fase 1 de este plan implementado para que no
> vuelva a fallar en silencio. El relato completo, en
> [`../docs/comunica/PASAR-LISTA-ESTADO.md`](../docs/comunica/PASAR-LISTA-ESTADO.md) §1.
>
> Lo que queda: **confirmarlo en producción** con el criterio de cierre de la
> fase 3 (abajo), y decidir si la lista de monitores debe escribir su propia
> `LIS_listas` (ver §5 de este plan).

**Prioridad: P0. Es EL bug: mientras esté abierto, lo demás es decoración.**
Esfuerzo: M. Depende de: nada. Bloquea: 034 (medir con un guardado que funcione),
037 (UX del guardado).

> Contexto imprescindible antes de tocar nada:
> [`../docs/comunica/PASAR-LISTA-ESTADO.md`](../docs/comunica/PASAR-LISTA-ESTADO.md)
> (§1 el bug, §3 las cinco trampas, §7 los datos del piloto) y
> [`../docs/comunica/CAMPOS.md`](../docs/comunica/CAMPOS.md) para cualquier
> nombre de campo. No inventes nombres de campo. No borres asistencias.

---

## 1. Lo que se sabe (verificado 27/08/2026)

1. El CRM se dejó limpio por la mañana (pizarra en blanco): 0 `LIS_listas`,
   asistencias de Solete sin `status`.
2. El usuario pasó lista a mano hacia las ~16:00 UTC — pantalla de
   participantes Y pantalla de monitores — y **no quedó NINGÚN rastro en el
   CRM**: cero registros con `date_modified` posterior a las 14:00 UTC en
   `stic_Attendances` y `LIS_listas`. Verificado por MCP con `modified_by`:
   todo lo tocado ese día lo tocó «API User MCP» (la limpieza), nada el
   usuario API del plugin.
3. Por tanto el fallo está **antes de que ninguna escritura llegue al CRM**.
   Quedan exactamente dos familias de causa:
   - **(A) El POST no entra en la rama de guardado**: nonce que no verifica,
     `pl_marks` vacío al llegar (JS roto o borrador perdido), o el filtrado
     `array_intersect_key` contra `$people['participants']` que vacía las
     marcas (ids de la caché ≠ ids del POST).
   - **(B) Entra, pero TODAS las `set_entry` fallan**: login del CRM fallando
     (credenciales en `wp_options`), sesión inválida no recuperada, o **ACL**:
     el usuario API del plugin sin permiso de edición sobre `stic_Attendances`
     y/o `LIS_listas` (precedente real: no tenía acceso a `stic_FollowUps` y
     hubo que dárselo).
4. Hoy ambas familias son (parcialmente) **invisibles**:
   - `SugarRestApiCall::set_entry()` (`inc/stic-class-6.php:344`) hace
     `$recordID = $set_entry_result->id;` sin comprobar que la respuesta no
     sea un objeto de error de SuiteCRM (`{number, name, description}`). El
     motivo real del fallo se descarta.
   - `set_relationship()` devuelve `{created, failed}` y **nadie lo mira**:
     una relación fallida deja la asistencia/lista huérfana en silencio.
   - En `sticpa_pl_save()` (`inc/stic-pasar-lista-crm.php:1573`), el fallo al
     escribir la `LIS_listas` **no incrementa `failed`**: la pantalla dice
     «Lista guardada» aunque la lista no exista.
   - Los avisos de nonce caducado y de «no has marcado a nadie» SÍ se pintan,
     pero nadie ha confirmado cuál vio el usuario.
5. ⚠️ Contradicción abierta sobre el modelo: el parte decía «las asistencias
   las crea el CRM al crear la inscripción»; el propietario dice que las de
   Solete **las creó Claude a mano** y ha pedido borrarlas. Si el CRM no las
   crea solas, el camino normal es CREAR (no actualizar), y es el camino menos
   probado. La fase 3 lo verifica empíricamente antes de asumir nada.

## 2. Fase 1 — Instrumentar (primero, y se despliega YA)

Nada de adivinar: primero se hace que el fallo hable. Todo esto es pequeño,
sin riesgo funcional, y vale para siempre.

### 2.1 El transporte deja de tragarse los errores

En `inc/stic-class-6.php`:

- `call()`: si la respuesta decodificada trae `->number` o `->name` y NO es el
  reintento de sesión caducada ya contemplado (número 11), `error_log()` con
  prefijo `[sticpa]`, el método, el módulo si se conoce y
  `name`/`number`/`description` del error. Devolver la respuesta tal cual
  (los consumidores ya deciden).
- `set_entry()`: comprobar `isset($set_entry_result->id)`. Si no está:
  `error_log()` con el módulo y un `json_encode` recortado (~500 caracteres)
  de la respuesta, y devolver `null` explícito. Guardar además el último error
  en una propiedad pública (`$this->lastError`) para que la capa de arriba
  pueda enseñarlo.
- `set_relationship()`: mirar `->failed`; si `failed > 0` o la respuesta es un
  error, `error_log()` igual y devolver algo que el llamador pueda distinguir
  (p. ej. `false` en fallo, la respuesta en éxito). Revisar los llamadores
  existentes para no romper contratos (hoy casi nadie mira el retorno).

### 2.2 `sticpa_pl_save()` y `sticpa_pl_save_monitors()` cuentan la verdad

- Añadir a `$result` una clave `errors`: lista de
  `array('paso' => 'attendance_update|attendance_create|rel_session|rel_registration|lista', 'id' => …, 'error' => $objSCP->lastError)`.
- El fallo de la `LIS_listas` (set_entry falsy) **cuenta**: `failed++` y entra
  en `errors`. Lo mismo con cada `set_relationship` fallido.
- Registro persistente de intentos: guardar en `wp_options` un ring de los
  últimos ~20 intentos de guardado (`sticpa_pl_save_log`): timestamp, pantalla
  (participantes/monitores), grupo, sesión, nº de marcas recibidas en el POST,
  nº tras el filtrado, `saved/failed`, `lista_id`, y los `errors`. Con esto,
  el próximo «no se guarda» se diagnostica sin reproducirlo.
- **También cuando NO se guarda**: si el nonce no verifica o las marcas quedan
  vacías tras el filtrado, se anota en el mismo ring (`motivo: nonce|sin_marcas`),
  con el tamaño de `pl_marks` crudo recibido. Es la única forma de distinguir
  la familia (A) de la (B) a posteriori.

### 2.3 La pantalla no miente

En `pages/single_stic_pasar_lista_marcar.php` y
`pages/single_stic_pasar_lista_monitores.php`:

- «Lista guardada» SOLO si `failed === 0` **y** `lista_id !== ''` (en marcar).
- Si hay `errors`, enseñarlos: para todos, un aviso claro («No se ha podido
  guardar: el CRM ha respondido “…”. Avisa a coordinación.»); el detalle
  técnico completo, solo para coordinación (`sticpa_pl_is_coordinator()`) o
  bajo un query arg `?pl_debug=1` protegido por ese mismo rol.
- Si el guardado falla, **las marcas no se pierden**: hoy el JS borra el
  borrador (`lsDel(draftKey)`) en el `submit`, antes de saber el resultado
  (`js/stic-pasar-lista.js`, manejador de submit). Moverlo: el servidor pinta
  un flag de éxito (p. ej. `data-pl-saved-ok`) y el JS solo borra el borrador
  al verlo. En fallo, el borrador repuebla las filas y no se ha perdido nada.

### 2.4 Tests

Siguiendo la trampa §3.2 del parte (los dobles construyen la forma REAL de la
API): tests de que (1) un `set_entry` que devuelve un error de SuiteCRM produce
`failed`+`errors` y NO «Lista guardada»; (2) el fallo de `LIS_listas` cuenta;
(3) un `set_relationship` con `failed>0` cuenta; (4) el ring de `wp_options` se
escribe también en la rama de nonce y en la de sin-marcas.

## 3. Fase 2 — Diagnosticar con la instrumentación puesta

Desplegada la fase 1, reproducir UNA VEZ en el navegador (pilotaje real:
grupo C1) y leer `sticpa_pl_save_log` + `error_log`. Árbol de decisión:

| Lo que dice el ring | Familia | Siguiente paso |
|---|---|---|
| No hay entrada nueva | El POST no llega al handler | Mirar caché de página/CDN o redirección: un POST servido de caché no ejecuta PHP. Comprobar con las herramientas de red del navegador qué respondió el POST (código, cabeceras de caché). |
| `motivo: nonce` | (A) | Nonce caducado por HTML cacheado >12/24h o sesión PHP perdida. Ver 028 (lock de sesión) y la política de caché de página del hosting para `?internalpage=…`. |
| `motivo: sin_marcas`, `pl_marks` crudo vacío | (A) JS | El JS no rellenó el campo: mirar errores JS en la webview (eruda/inspector remoto) y si `js/stic-pasar-lista.js` cargó. |
| `motivo: sin_marcas`, `pl_marks` crudo CON datos | (A) filtrado | Los ids del POST no están en `$people['participants']` (caché con ids viejos). Comparar ids; probablemente falta invalidar `struct` o hay doble fuente de ids. |
| `errors` con «Invalid Session ID» o login | (B) credenciales | `wp_options`: `sticpa_scp_rest_url/username/password`. Probar login a mano (curl) con esas credenciales. |
| `errors` con «not authorized» / «no access» | (B) ACL | En SuiteCRM admin: rol del usuario API del plugin → permisos de `stic_Attendances` y `LIS_listas` (list/edit) y **permisos por campo** (`status`, `estado` no pueden estar Read-Only). Precedente: `stic_FollowUps`. |
| `saved > 0` y el CRM sigue vacío | (B) raro | El id devuelto es de OTRO registro o va contra otra instancia (¿`rest_url` apunta a aptest?). Comparar `rest_url` con la instancia que se está mirando por MCP. |

> ⚠️ Nota para quien diagnostique: hay DOS usuarios API distintos — el del
> plugin (opciones `sticpa_scp_*`) y el del MCP («API User MCP»). Que el MCP
> escriba bien no prueba nada del plugin. Y hay DOS instancias (producción y
> `/aptest/`): verificar contra cuál apunta `sticpa_scp_rest_url` ANTES de
> concluir que «no se escribió nada».

## 4. Fase 3 — Arreglar y endurecer

Según la rama que confirme la fase 2, más esto, que vale para todas:

1. **Verificar el modelo de creación de asistencias** (la contradicción del
   parte §7): crear por MCP una inscripción de prueba en el evento COM y mirar
   si el CRM genera solas las asistencias de las 24 sesiones. Documentar el
   resultado en `PASAR-LISTA-ESTADO.md` y en `CAMPOS.md` si toca. Si NO las
   genera, el camino de crear es el normal y hay que tratarlo como tal (no
   como excepción).
2. **Camino de crear endurecido** (en `sticpa_pl_save` y `save_monitors`):
   crear → comprobar id → relacionar sesión → relacionar inscripción →
   **verificar** los retornos de las dos relaciones; si una falla, contarlo en
   `errors` (la asistencia huérfana no cuenta en el porcentaje del CRM, que es
   el síntoma histórico). Valorar escribir también `name` y `start_date`(los
   de la sesión) en la asistencia creada — las creadas por el camino viejo
   salían «Unknown - Unknown, sin fecha ni duración»; confirmar nombres en
   `CAMPOS.md` antes.
3. **Cerrar contra el criterio del parte §7**: un guardado real a mano deja
   exactamente 1 `LIS_listas` `pasada` enlazada a sesión y grupo con números
   correctos; el `status` de la persona marcada puesto en la asistencia de ESA
   sesión (actualizada si existía, creada Y enlazada si no); nada en las otras
   sesiones; sin duplicados. Verificarlo por MCP (subagente, `fields`
   acotados) y pegar la verificación en el PR.

## 5. STOP conditions

- **STOP** si para «arreglar» hace falta borrar asistencias o listas en el CRM
  de producción: eso se pide al propietario, no se hace.
- **STOP** si la causa resulta ser ACL o credenciales: el cambio es de
  configuración del CRM/WordPress, no de código. Documentar exactamente qué
  cambiar y quién, y no parchear el código para «rodearlo».
- **STOP** si `pl_debug` fuese a enseñar datos personales a quien no es
  coordinación.
- No tocar el contrato de la cola offline (`sticpa_pl_queue`): un reenvío es
  un envío normal a la misma URL. Si se cambia algo del POST, la cola vieja
  tiene que seguir valiendo.

## Estado

| Fecha | Qué | Quién |
|---|---|---|
| 2026-08-27 | Plan escrito tras verificar por MCP que el intento real no deja rastro | revisión |
| 2026-08-27 | Fase 1 completa (transporte, contabilidad de fallos, diario, panel `?pl_diag=1`, borrador y cola que no mienten) | `eb5eac5` |
| 2026-08-27 | **Causa encontrada y arreglada**: el botón deshabilitado se comía `pl_action`. Verificado por MCP que el payload y las relaciones son válidos, así que no era ni ACL ni forma del dato | `eb5eac5` |
| 2026-08-27 | El doble de test ahora modela escribir-y-releer, y puede rechazar escrituras. 256 tests en verde | `eb5eac5` |
| | **Pendiente**: confirmar con un guardado real en producción | — |
