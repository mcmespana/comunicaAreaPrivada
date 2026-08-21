# Pasar Lista — diseño funcional

Sustituir la app de AppSheet (sobre Google Sheets) por una pantalla del área
privada. Este documento es **el diseño de lo que se construye**: qué hace, cómo
se guarda y cómo se ve.

- Campos y módulos del CRM → [`PASAR-LISTA-CAMPOS-CRM.md`](PASAR-LISTA-CAMPOS-CRM.md)
- Fases, estado del piloto y melones futuros → [`PASAR-LISTA-ROADMAP.md`](PASAR-LISTA-ROADMAP.md)

---

## 1. Qué tiene que pasar

Un monitor, un sábado por la tarde, con el móvil en la mano y niños alrededor:

1. Entra al área privada (normalmente desde MCM App).
2. Ve **su grupo** y **la sesión de hoy** sin buscar nada.
3. Marca quién ha venido en menos de un minuto.
4. Guarda.

Y de vez en cuando:

- Pasar lista de **otro grupo** porque falta su monitor.
- Recuperar una semana **atrasada**, sin límite de tiempo.
- Abrir la **ficha de un niño** y llamar a su familia en dos toques.
- (Coordinación) ver el **resumen de todos los grupos**.

El listón es que apetezca. Si cuesta más que en AppSheet, no lo usarán.

---

## 2. El modelo

```
Delegación ─┬─ Evento (etapa · curso) ── Sesiones ─┬─ Asistencias
            │        │                             │      │
            │        └── Inscripciones ────────────┘      │
            │                                            │
            └─ Grupos ── Relaciones con personas ── Personas
                  └──────────── LIS_listas ───────────────┘
                         (sesión × grupo × estado)
```

**Un evento por delegación, etapa y curso.** `COM | Sesiones semanales
2025-2026`, `MIC | Sesiones semanales 2025-2026`. Unos 20 al año en toda la
organización. El nombre no lleva la delegación delante porque **lo ven las
familias** y la delegación ya está en el «asignado a».

El criterio: **un evento agrupa a quien comparte calendario.** Lo que se firma
al pasar lista es una *sesión*, con su día y su hora. Los segmentos del COM
comparten calendario entre sí, así que van juntos en el evento del COM; MIC y
COM se separan porque pueden tener horarios distintos.

**El grupo sale de las relaciones con personas**, no se duplica en ningún sitio.

**`LIS_listas`** guarda el *acto* de pasar lista: un registro por grupo y sesión,
con estado, quién y cuándo. Es lo que hace posible el aviso de listas
pendientes, el «sin registro» y el resumen de coordinación sin recontar
asistencias.

### 2.1 Las consultas (medidas contra la instancia real)

| Lo que necesito | Cómo | Coste |
|---|---|---|
| Niños **y** monitores de un grupo, con etapa y vigencia | `get_relationships(ajmcm_GRUPOS, id, 'ajmcm_grupos_stic_contacts_relationships')` | **1 llamada** |
| Inscripciones del evento con el id de contacto | `get_relationships(stic_Events, id, 'stic_registrations_stic_events')` | 1 / 50 |
| Asistencias de una sesión con su estado | `get_relationships(stic_Sessions, id, 'stic_attendances_stic_sessions')` | 1 / 50 |

La pantalla de marcado se monta uniendo en PHP: contacto → inscripción →
asistencia. **Tres consultas para un grupo entero**, no una por niño.

⚠️ **No se puede filtrar por campos de tipo link.** `get_entry_list` con un
filtro sobre `..._ida` devuelve error de base de datos. Hay que ir por
`get_relationships` desde el registro padre.

⚠️ **La API no valida los desplegables**: acepta cualquier cadena sin avisar. Las
claves internas se sacan de `CAMPOS.md`, nunca a ojo.

⚠️ **El `name` de las sesiones que autogenera el CRM tiene la hora mal** (usa un
desfase fijo, así que en horario de invierno sale una hora antes). **La pantalla
formatea `start_date`/`end_date` y no usa nunca el `name`.**

---

## 3. Volumen real

Lo que de verdad va a usar esto:

| | Grupos | Niños por grupo |
|---|---|---|
| 3-4 delegaciones grandes | ~10 MIC + ~10 COM | 10, máximo 12 |
| Las otras ~8 delegaciones | ~25 % de eso | igual |

Son números pequeños. El diseño no tiene que escalar a miles: tiene que ser
rápido de usar y no machacar el CRM, que va lento.

**Nada interdelegacional.** Un monitor ve lo de su delegación, la de su
«asignado a». No hay excepciones.

---

## 4. Qué sesión propone la app

Sea *D* el día de la sesión y *H* su hora de inicio (sábados 16:30 en Castellón):

| Momento | Sesión propuesta | Aviso |
|---|---|---|
| Día *D*, antes de *H* | La de **hoy** | 🟡 «Empieza a las 16:30 — aún no han llegado». Avisa, no bloquea |
| Día *D*, desde *H* | La de **hoy** | — |
| *D+1 … D+6* | La del **último *D*** | 🟡 «Estás pasando la del sábado 18» si seguía pendiente |
| Sin sesión reciente | Ninguna | Estado vacío |

**Sin ventana de edición.** Un monitor puede pasar o corregir cualquier sesión
pasada cuando quiera. El histórico vale más completo y tarde que incompleto.

Encima, siempre, un **selector de sesión** con las últimas ~8 y las 2 próximas,
cada una con su marca: pasada (con el recuento), pendiente, sin registro o
próxima.

---

## 5. Caché y refresco

Esto importa porque el CRM va lento y porque se entra desde MCM App, que es una
webview del sitio web: cada apertura es una carga de página real.

Hay dos tipos de dato con vidas muy distintas y **hay que cachearlos distinto**:

### 5.1 Estructura — cambia una vez al año

Árbol de grupos de la delegación, sus códigos, monitores, miembros y las
sesiones del curso. Una vez montado el curso, esto **no se mueve**.

- **Transient de WordPress por delegación y curso**, no por usuario: todos los
  monitores de Castellón comparten el mismo árbol, así que no tiene sentido
  pedirlo una vez por persona. Clave del tipo
  `sticpa_pl_estructura_{delegacion}_{curso}`.
- **TTL largo: 12-24 h.**
- Se invalida a mano con un botón de refresco (§6) y automáticamente al guardar
  una lista de un grupo cuya composición haya cambiado.

> Ojo con la clave: **jamás una clave compartida entre delegaciones.** El grupo
> de seguridad no protege un transient de WordPress; si la clave no lleva la
> delegación, un monitor podría ver datos de otra.

### 5.2 Estado — cambia cada sábado

Qué listas están pasadas, las asistencias de una sesión.

- **TTL corto (5 min) o nada**, y sobre todo: **invalidación al escribir.** Al
  guardar una lista se borra el transient de esa sesión y de esa semana.
- Es lo que el monitor va a mirar para saber si le falta algo: si esto va
  desfasado, la pantalla miente y se pierde la confianza.

### 5.3 Botón de refresco visible

La app de AppSheet tiene su icono de sincronizar y los monitores están
acostumbrados. Ponerlo:

- Da control cuando algo se ve raro, en vez de tener que cerrar y abrir la app.
- Es la vía de escape cuando la caché de estructura se queda vieja porque
  coordinación acaba de mover a alguien de grupo.

### 5.4 Pensando ya en el offline (fase 4)

Sin construirlo, el diseño de la fase 1 no debe cerrarse la puerta:

- **El guardado es un solo lote** con toda la lista. Una cola offline es
  entonces trivial: guardar el lote y reintentar.
- **Los datos de una pantalla de marcado caben en un objeto pequeño** (grupo,
  sesión, 12 niños con su estado). Eso se guarda en el navegador sin problema.
- Ya existe precedente en el repo: `plans/011-kill-n-plus-1-listings.md` y
  `sticpa_cached_field_definition()`.

---

## 6. Pantallas

Cuatro. La navegación imita a AppSheet (etapa → grupo → niños) porque funciona y
la gente ya la conoce, **con un atajo delante**: el 90 % de las veces el monitor
va a su grupo y a la sesión de hoy, y eso son dos toques.

AppSheet no es la referencia a igualar, es el suelo: donde se pueda mejorar, se
mejora.

### 6.1 Home de Pasar Lista

Arriba, grande, la tarjeta de **hoy** con el grupo del monitor y un solo botón.
Debajo, el bloque de **listas que faltan** (solo si las hay) — esto es lo que hoy
no existe y hace que se pierdan semanas. Después, el acceso a **otro grupo** por
etapa, y al final el resumen.

Si el monitor lleva varios grupos, salen varias tarjetas. Si no lleva ninguno
(coordinación), se entra directo al árbol.

### 6.2 Cómo se nombra un grupo en pantalla

Regla única para todas las pantallas:

```
C1 · Los Peques                    ← código en negrita + nombre si aporta algo
David Soler · 1º ESO · 14 niños    ← línea secundaria, gris, más pequeña
```

- **El código va primero y en negrita.** Es el identificador que usa la gente.
- **El nombre solo si añade información.** Si `code` y `name` son iguales (o el
  nombre es un simple «C1»), se pinta solo uno. Nunca «C1 · C1».
- Si el grupo **no tiene código**, se usa el nombre como identificador principal.
- Monitor, curso y número de niños van juntos en la línea secundaria, separados
  por `·`. Integrado y sutil, sin etiquetas tipo «Monitor:».

### 6.3 Marcar asistencia

La pantalla que importa. Decisiones:

Los cuatro estados son los del CRM, con sus claves reales:
`yes` (Vino), `partial` (Parcial), `no_justified` (Justificada),
`no_unjustified` (No). Sin valor = **sin marcar**, que es un quinto estado de
pantalla y no una falta.

- **Todos empiezan sin marcar**, no todos en «no» como hace el AppSheet. Dos
  razones: una falta falsa es peor que una marca que falta (la falsa se cuenta
  en el porcentaje que ve la familia, la que falta solo se nota), y con «Han
  venido todos» arriba el caso normal son 3 toques —el botón y los dos que no
  vinieron— frente a los 10 de ir uno por uno. El AppSheet arrancaba en «no»
  porque no tenía ese botón; aquí no hace falta.
- **«Han venido todos»** está arriba del todo: desmarcar dos ausentes es más
  rápido que marcar diez.
- **Tocar la fila entera** alterna sin marcar → vino → falta. El target es la
  fila completa.
- **La flecha del final abre la ficha**, con 44 px de área táctil. Marcar es lo
  frecuente y se queda con el área grande; la ficha es lo raro y tiene su botón
  propio. *(Esto cierra la duda 6.1: fila para marcar, flecha para la ficha.)*
- **El estado se pinta como un círculo de color con su glifo**, no como una
  pastilla con texto. Sin marcar es un círculo **vacío con borde punteado**, que
  se lee como un control que espera un toque; los cuatro marcados son círculos
  llenos con check (`yes`), medio disco (`partial`), guion (`no_justified`) y
  cruz (`no_unjustified`). La etiqueta no hace falta en cada fila: **una leyenda
  debajo de la lista** enseña los cuatro una vez.
- **Un color por estado**: verde `#2f9e44` vino, verde azulado `#0d9488`
  parcial, ámbar `#f59e0b` justificada, rojo `#dc2626` no vino. Parcial sale del
  verde puro a propósito: cuenta como asistencia igual que «vino», pero tiene
  que distinguirse de un vistazo.
- **Parcial y falta justificada** no ensucian el gesto principal: salen al
  mantener pulsado, en una hoja inferior con los cuatro valores y un motivo
  opcional (artboard `Estados`). El toque simple solo recorre
  sin marcar → `yes` → `no_unjustified`, que es el 95 % de los casos.
- **El mantener pulsado funciona en web**, y es lo que usaremos: `pointerdown`
  con un temporizador de ~500 ms, cancelado por `pointerup`, `pointercancel` o
  un `pointermove` de más de unos píxeles (para no confundirlo con un scroll),
  más `contextmenu` anulado y `touch-action: manipulation` en la fila para que
  el navegador no se lleve el gesto. En escritorio el clic derecho abre la misma
  hoja. Es la misma técnica que usan las apps nativas, y no depende de la
  webview de MCM App.
- **El aviso de faltas seguidas solo si son consecutivas.** Tres faltas
  repartidas en el curso no dicen nada; tres seguidas sí. El umbral es
  `seguidas >= 3`.
- **Sin porcentajes por niño en esta pantalla.** Era ruido mientras marcas; su
  sitio es la ficha. Se queda solo el aviso de faltas seguidas, que sí es señal.
- **Guardar es explícito**, con la barra fija abajo y el contador vivo encima.
- **«Sin registro»** al final, discreto: marca la sesión de ese grupo como
  omitida y **deja de avisar**. Cubre tanto «no hubo reunión» como «se me olvidó
  y ya no me acuerdo» — no hace falta distinguirlos, y un monitor honesto
  necesita poder cerrar el aviso sin inventarse datos.

### 6.4 Ficha del participante

Primero **las dos acciones de contacto**, no los datos: es lo que se necesita con
prisa. Luego asistencia, familia, salud y datos.

- **Teléfono del propio chaval** en el COM (menos habitual, pero lo tienen).
  El botón de WhatsApp respeta `ajmcm_menorwhatsapp_c`: si no autoriza, no se
  pinta.
- **Pañuelo editable con confirmación** — es un dato importante y el monitor es
  quien lo sabe. Editar pide confirmar antes de escribir. Va **abajo, después de
  Permisos**: no es un dato de urgencia como el teléfono, y arriba competía con
  las acciones de contacto, que son el motivo real por el que se abre la ficha.
- **Edad** visible (viene calculada en `stic_age_c`).
- **Sexo no**, no aporta nada aquí.
- **Salud en una sola tarjeta**, no cuatro campos sueltos: alergias,
  intolerancias, tratamientos, enfermedades y otros se pintan seguidos y **solo
  los que tienen contenido**. Una ficha sin nada de salud no enseña la tarjeta.
- **Permisos** en su bloque: solo a casa, cesión de imágenes
  (`ajmcm_cesionimagenes_interne_c`) y WhatsApp del menor. La cesión de imágenes
  hace falta aquí porque se decide en el momento de hacer la foto.
- **Avisos de comportamiento** con fecha, quién lo puso y motivo, y el contador
  «2 de 3». El front se construye ya; los campos detrás son fase 3 y van en un
  módulo, no en casillas (ver `PASAR-LISTA-CAMPOS-CRM.md` §6).
- El detalle de asistencia por sesión **ya existe**: es el calendario de
  actividades (`inc/stic-calendar.php`), que colorea las sesiones por asistencia.
  Se enlaza, no se reinventa.

### 6.5 Resumen de grupos (coordinación)

Recuentos por etapa y segmento (MIC / COM I / COM II / COM III), estado de las
listas y «datos por revisar».

**El historial de listas por grupo.** Esta era la duda de verdad: cómo se ve, de
un vistazo, quién ha pasado la última lista y a quién le faltan listas de otros
días. La respuesta es una **tira de marcas bajo cada grupo, una por sesión ya
celebrada, la más reciente a la derecha**:

| Marca | Significa |
|---|---|
| Verde lleno | Lista pasada |
| Ámbar hueco | **Falta por pasar** |
| Gris | Sin registro (se saltó a propósito) |

Un grupo al día es una tira verde limpia. Un grupo dejado se ve como un tramo
de huecos ámbar, y se ve *dónde* empezó a dejarse. Al lado, el número: «4 sin
pasar». La pastilla de la derecha dice el estado de la **última** sesión, que es
la pregunta frecuente; la tira contesta la de fondo. Eso hace innecesaria una
pantalla aparte de «listas pendientes» por grupo.

**El denominador se dice siempre.** Si estamos en febrero, el curso no lleva
36,5 horas ni 24 sesiones: lleva las que han pasado. Así que:

- El porcentaje se calcula **sobre las sesiones ya celebradas**, nunca sobre el
  total del curso. Un 82 % en noviembre y un 82 % en mayo no son lo mismo, y sin
  denominador parecen iguales.
- Se escribe **«82 % de 12 sesiones»**, no «82 %» a secas.
- `attendance_percentage` y `attended_hours` de la inscripción los calcula el
  CRM sobre el evento completo, así que **no sirven para esto**: en mitad de
  curso dan un número bajo que no significa nada malo. Se usan para el histórico
  cerrado; el porcentaje vivo lo calcula la pantalla contando asistencias sobre
  sesiones pasadas.
- Igual con las horas: se enseñan «22 h de 24 h hasta hoy», no «22 h de 36,5 h».

**Datos por revisar.** El bloque ámbar del final lista los problemas clásicos
que en el CRM cuestan de ver y aquí están a un toque: participantes sin grupo
asignado, monitores en «grupo por definir», participantes sin fecha de
nacimiento, grupos sin código corto. Cada línea es un contador y **se abre**:
toca «7 participantes sin grupo asignado» y sale la lista de esos siete.

- **Coordinación edita, un monitor solo mira.** En la lista de participantes sin
  grupo, un coordinador tiene el desplegable de grupo en cada fila y lo asigna
  ahí mismo; un monitor ve la lista y a quién le falta, pero sin control de
  edición. Es lo estándar y evita el clásico de que alguien arregle a mano lo
  que no le toca.
- No necesita pantalla propia de diseño: es la misma lista de participantes de
  siempre con una fila de acción. Se construye en la fase 3.

### 6.6 Buscar un participante

En AppSheet esto era «todos los grupos» o una pantalla de «datos». Con el
buscador arriba del árbol basta: escribes un nombre, te dice **en qué etapa y
grupo está**, y entras a su ficha. No hace falta una pantalla de datos generales
aparte; si algún día se echa en falta, se añade.

---

## 7. Permisos

| Rol | Puede |
|---|---|
| **Monitor** | Pasar lista de cualquier grupo **de su delegación** (el caso real: falta un monitor y le cubre otro). Ver la ficha de esos niños. Marcar «sin registro». Sin límite de antigüedad |
| **Coordinación** | Lo anterior + resumen + datos por revisar |
| **Familia / participante** | Nada de esto. Ve su propia asistencia en el calendario, que ya existe |

Queda registrado **quién** pasó cada lista (`lis_listas_contacts`), que es lo que
importa cuando cubres a otro. El rol ya se detecta hoy en
`inc/stic-comunica-roles.php`.

---

## 8. Higiene de datos

La pantalla será tan buena como estén los datos. Hoy:

- Muchas relaciones cuelgan de los grupos comodín `⚠️ Grupo monitoreado - POR
  DEFINIR!` y `⚠️ Grupo COM-LC - POR DEFINIR!`.
- Muchos `participante_mic_com` no tienen grupo.
- Muchos grupos no tienen `code`.

Como el sistema **aún no está en uso real**, podemos imponer convenciones en vez
de adaptarnos al desorden (ver el final del roadmap). Antes de desplegar en una
delegación hay que hacer la pasada de limpieza de sus grupos.
