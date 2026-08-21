# Pasar Lista — diseño funcional

Sustituir la app de AppSheet (sobre Google Sheets) por una pantalla del área
privada. Documento de trabajo: **todavía no se desarrolla nada**, aquí se cierra
qué hace, cómo se guarda y cómo se ve.

Estado: **borrador para decidir**. Las decisiones abiertas están marcadas con
🔶 y son las que hay que cerrar antes de tocar código.

---

## 1. Qué tiene que pasar

Un monitor, un sábado por la tarde, con el móvil en la mano y niños alrededor:

1. Entra al área privada.
2. Ve **su grupo** y **la sesión de hoy** sin buscar nada.
3. Marca quién ha venido en menos de un minuto.
4. Guarda.

Y de vez en cuando:

- Pasar lista de **otro grupo** porque falta su monitor.
- Recuperar **la semana pasada** porque se le olvidó.
- Abrir la **ficha de un niño** y llamar a su familia en dos toques.
- (Coordinación) ver el **resumen de todos los grupos**: monitores, cuántos
  niños, recuentos por etapa.

El listón de calidad es ese: que apetezca. Si pasar lista cuesta más que en
AppSheet, no lo van a usar.

---

## 2. Lo que ya existe en el CRM

Cuatro módulos encadenados. Todo esto **ya funciona**, comprobado contra la
instancia real:

```
ajmcm_GRUPOS ─┐
              ├── stic_Contacts_Relationships ── Contacts ── stic_Personal_Environment
              │      (participante / monitor)      (niño)        (madre, padre…)
              │
stic_Events ──┴── stic_Sessions ── stic_Attendances ── stic_Registrations
  (el curso)       (cada día)        (cruce)             (niño ↔ evento)
```

### 2.1 Grupos — `ajmcm_GRUPOS`

| Campo | Uso |
|---|---|
| `name` | "C1", "GRUPO MIC A", "Grupo 3: Ana María y Quique"… (sin convención) |
| `code` | Código corto ("C1", "M4", "CMON"). **Muy pocos grupos lo tienen puesto** |
| `level` | Etapa: `mic` · `com` · `lc` · `apoyo` |
| `cursos_c` | Texto libre: "1º ESO", "5º Primaria", "4º-6º EP", "Jovenes" |
| `ajmcm_grupos_accounts_*` | Delegación (Account: MCM Castellón, MCM Vila-real…) |

Hay del orden de **100-150 grupos** en toda la organización, de todas las
delegaciones. Cualquier pantalla que los liste tiene que filtrar por delegación
y por etapa desde el primer momento.

### 2.2 La pieza clave — `stic_Contacts_Relationships`

Aquí es donde vive **la pertenencia a un grupo**, tanto de niños como de
monitores. Es una tabla de relación con vigencia:

| Campo | Uso |
|---|---|
| `relationship_type` | `participante_mic_com` · `monitor` · `grupo` · `familiar_menor` |
| `stic_contacts_relationships_contacts_*` | La persona |
| `ajmcm_grupos_stic_contacts_relationships_*` | El grupo (**puede estar vacío**) |
| `ajmcm_etapa_relacion_c` | `MIC` · `COM` · `LC` · `apoyo` · `asesora` |
| `ajmcm_curso_escolar_c` | `1_eso`, … |
| `ajmcm_delegacion_c` | `castellon`, `vila-real`, `onda`, `madird` (sic), … |
| `start_date` / `end_date` / `active` | Vigencia |

De modo que:

- **Niños de un grupo** = relaciones `participante_mic_com` activas y vigentes
  cuyo grupo sea ese.
- **Monitores de un grupo** = relaciones `monitor` activas y vigentes con ese grupo.
- **Mis grupos** (como monitor logueado) = mis relaciones `monitor` vigentes.

> ⚠️ **Higiene de datos.** Muchísimas relaciones apuntan a los grupos comodín
> `⚠️ Grupo monitoreado - POR DEFINIR!` y `⚠️ Grupo COM-LC - POR DEFINIR!`, y
> muchos `participante_mic_com` no tienen grupo ninguno. La pantalla será tan
> buena como estén estos datos: **antes de desplegar hay que hacer una pasada
> de asignación de grupos**. Conviene una vista de "personas sin grupo" para
> que coordinación lo limpie (§9).

### 2.3 Eventos, sesiones y asistencias

- **`stic_Events`** — el contenedor del curso. `total_hours` = suma de las
  duraciones de sus sesiones (calculado).
- **`stic_Sessions`** — cada día concreto. Obligatorio evento + inicio + fin;
  el nombre, el día de la semana y la duración los pone el CRM. Tiene
  `responsible`, `activity_type`, `color`, `total_attendances`,
  `validated_attendances`. **En la interfaz del CRM hay un asistente de
  sesiones periódicas** (frecuencia, intervalo, fecha fin o nº de sesiones):
  crear un curso entero es un formulario, no 39.
- **`stic_Registrations`** — niño ↔ evento. Aquí viven `attended_hours` y
  `attendance_percentage`, **calculados por el CRM**. También `disabled_weekdays`
  (excluir días de la semana en la generación automática).
- **`stic_Attendances`** — el cruce inscripción × sesión. `status` con cuatro
  valores: **Sí / Parcial / No, justificada / No, sin justificar**; solo *Sí* y
  *Parcial* cuentan. Tiene `start_date`, `duration`, `payment_exception`,
  `amount`.

**Automatismo importante y ya verificado:** al crear una inscripción en estado
*Confirmado*, el CRM genera él solo las asistencias en blanco de todas las
sesiones pasadas del evento. Hay además una tarea diaria que las va creando.
No hay que crear asistencias a mano: **la pantalla rellena el `status` de
asistencias que ya existen.**

### 2.4 Familia — `stic_Personal_Environment`

Contacto ↔ contacto con `relationship_type` (`mother`, `sister`…),
`reference_contact` y `authorized_signer`. De aquí salen los teléfonos para el
botón de llamar/WhatsApp. El móvil está en el contacto del familiar
(`phone_mobile`).

---

## 3. Dónde encaja el grupo (corregido)

> **Corrección de la versión anterior de este documento.** Recomendaba un
> evento por grupo y decía que el evento único era frágil. Estaba equivocado:
> multiplicado por delegaciones son 150 eventos y un jaleo de mantener, y las
> consultas que yo daba por costosas son una llamada cada una. Lo que sigue es
> el modelo bueno.

### 3.1 Por qué no era difícil

Las dos consultas que sostienen toda la pantalla ya funcionan contra la
instancia real, comprobadas:

| Lo que necesito | Cómo | Coste |
|---|---|---|
| Niños **y** monitores de un grupo, con etapa, curso y vigencia | `get_relationships(ajmcm_GRUPOS, id, 'ajmcm_grupos_stic_contacts_relationships')` | **1 llamada** |
| Inscripciones del evento con el id de contacto de cada una | `get_relationships(stic_Events, id, 'stic_registrations_stic_events')` | 1 llamada / 50 |
| Asistencias de una sesión con su inscripción y su estado | `get_relationships(stic_Sessions, id, 'stic_attendances_stic_sessions')` | 1 llamada / 50 |

Con eso la pantalla de marcado se monta uniendo en PHP: contacto → inscripción
→ asistencia. **Tres consultas para un grupo entero**, no una por niño. Para
una etapa de 126 niños son 3 páginas de 50, y todo eso se cachea por sesión.

Lo único que **de verdad** faltaba era una cosa pequeña, y es esta: si una
sesión la comparten varios grupos, *no hay sitio donde anotar «esta lista está
pasada»* ni «este grupo no se reunió». Eso no es un problema de arquitectura,
es una tabla que falta. Y dijiste que módulos podemos crear.

### 3.2 El modelo

```
Delegación ─┬─ Evento (etapa · curso) ── Sesiones ─┬─ Asistencias
            │        │                             │      │
            │        └── Inscripciones ────────────┘      │
            │                                             │
            └─ Grupos ── Relaciones con personas ── Personas
                  └──────────── Listas ────────────────────┘
                         (sesión × grupo × estado)
```

**Evento = delegación × etapa × curso.** `MIC · Castellón · 2025-2026`,
`COM · Castellón · 2025-2026`. Del orden de **20 eventos al año** en toda la
organización, no 150.

El criterio no es "una etapa" por dogma, es este: **un evento agrupa a quien
comparte calendario.** Porque lo que se firma al pasar lista es una *sesión*, y
una sesión tiene un día y una hora concretos. Los datos reales dan la razón a
separar: en Onda el MIC se reúne los viernes de 17:00 a 18:15 y el COM los
viernes de 15:45 a 17:00; en Vila-real hay grupos de sábado. Si metes ambos en
el mismo evento, el selector de sesiones de un monitor de COM le enseña también
los viernes del MIC.

> Si en una delegación MIC y COM coinciden en día y hora (pasa: en un grupo de
> Castellón los dos son viernes de 16:00 a 17:30), pueden compartir evento sin
> problema. Y si dentro de un evento hubiera calendarios mezclados, existe la
> salida nativa: `disabled_weekdays` en la inscripción excluye días de la
> semana en la generación automática de asistencias. Es justo para lo que
> SinergiaCRM lo puso.

**El grupo sigue viniendo de las relaciones.** No se duplica en el evento ni en
la sesión.

**Módulo nuevo: `Listas`** (sesión × grupo). Es la pieza que faltaba:

| Campo | Tipo |
|---|---|
| `sesion` | relate a `stic_Sessions` |
| `grupo` | relate a `ajmcm_GRUPOS` |
| `estado` | enum: `pasada` · `omitida` (no hay registro = pendiente) |
| `pasada_por` | relate a `Contacts` — quién la pasó |
| `pasada_el` | datetime |
| `n_asistieron` / `n_faltaron` | int — para el resumen sin recontar |

Un registro por grupo y por semana: ~23 grupos × 39 semanas ≈ **900 al año**.
Nada. Y a cambio resuelve de golpe el indicador de "te faltan listas", el
*skip* de "no hubo reunión", quién la pasó (que importa cuando cubres a otro
monitor) y el resumen de coordinación con una sola consulta.

Es además lo que uno haría en cualquier esquema relacional: el **acto** de
pasar lista es una entidad distinta de los **hechos** de asistencia.

### 3.3 Lo que esto cuesta montar

- **Una vez, en septiembre:** crear los ~20 eventos y sus sesiones (con el
  asistente de sesiones periódicas del CRM, un formulario por evento) y dar de
  alta las inscripciones de los participantes activos. Las inscripciones sí
  conviene scriptarlas: leer los grupos de la delegación+etapa, sacar sus
  miembros y crear la inscripción. El CRM genera solas las asistencias.
- **Durante el curso:** nada. Un niño nuevo necesita su relación de grupo (que
  ya se hace) y su inscripción al evento de su etapa.

🔶 **Decisión 3.1** — ¿Confirmamos delegación × etapa? ¿O empezamos con
Castellón solo, un evento MIC y otro COM, y lo extendemos?

🔶 **Decisión 3.2** — El alta masiva de inscripciones: ¿script contra la API o
importación CSV desde el CRM? El CSV es menos código pero hay que casar los ids
a mano.

### 3.4 Sobre el campo `grupo` en Contacts

Lo propusiste como alternativa. Mi opinión, ahora que sé lo que cuestan las
consultas: **para pasar lista no hace falta.** Sacar los miembros de un grupo ya
es una llamada; añadir un campo denormalizado no la mejora.

Donde sí ayudaría es al revés: en pantallas centradas en la persona (buscar un
niño por nombre y ver su grupo en la propia tarjeta, sin ir a sus relaciones).
Eso hoy costaría una llamada por niño, que es exactamente el patrón que hay que
evitar en un listado.

Si se hace, dos condiciones:

1. **Es una caché, no la verdad.** La verdad sigue en las relaciones. Cualquier
   duda, se resuelve mirando la relación.
2. **Se recalcula al guardar la relación, no una vez al año.** Un flujo de
   trabajo anual se queda obsoleto el primer día que alguien cambia de grupo a
   mitad de curso, y entonces tienes dos respuestas distintas a la misma
   pregunta, que es peor que no tener el campo. Si el flujo dispara en el
   `after_save` de `stic_Contacts_Relationships`, no puede desviarse.

🔶 **Decisión 3.4** — ¿Lo dejamos para cuando haya una pantalla que lo pida
(buscador de niños), con recálculo al guardar?

---

## 4. Qué hay que crear en el CRM

### 4.1 Módulo `Listas`

El de §3.2. Es lo único nuevo de verdad.

### 4.2 Campos

En `ajmcm_GRUPOS`, para que la app sepa qué día toca sin adivinarlo:

| Campo | Tipo | Para qué |
|---|---|---|
| `ajmcm_dia_semana_c` | enum (lunes…domingo) | Día habitual de reunión |
| `ajmcm_hora_inicio_c` | hora | El aviso de "aún no ha empezado" |
| `ajmcm_hora_fin_c` | hora | |

**Segmento dentro de COM** (anotado para más adelante, tal cual pediste):

| Campo | Tipo | Notas |
|---|---|---|
| `ajmcm_segmento_c` | enum: `com_1` · `com_2` · `com_3` | En `ajmcm_GRUPOS` y también en `stic_Contacts_Relationships`, igual que `ajmcm_etapa_relacion_c` |

Importa más de lo que parece para esta pantalla: la app de AppSheet **ya navega
por MIC / COM I / COM II**, no por MIC / COM. Sin el campo, ese primer nivel del
árbol hay que deducirlo del curso escolar, que es texto libre ("1º ESO", "1-2
ESO", "Jovenes") y no se puede agrupar de forma fiable. Mientras no exista, la
pantalla agrupará por `level` (MIC / COM / LC) y el segmento se queda fuera.

🔶 **Decisión 4.2** — ¿Los segmentos de COM comparten calendario entre sí? Si
COM I se reúne el sábado y COM II el viernes, el evento pasa a ser
delegación × segmento en vez de delegación × etapa.

### 4.3 Limpieza (no es un campo, pero bloquea igual)

- **`code` en todos los grupos.** "C1 · David" se lee; "Grupo 3: Ana María y
  Quique" no cabe.
- **Grupo asignado** a los participantes y monitores que hoy cuelgan de
  `⚠️ Grupo monitoreado - POR DEFINIR!` y `⚠️ Grupo COM-LC - POR DEFINIR!`.

🔶 **Decisión 4.4** — Los avisos de comportamiento de AppSheet (Aviso 1/2/3 +
explicación) necesitan 4 campos en `Contacts`. Yo los dejaría para fase 3: no
son "pasar lista".

---

## 5. Qué sesión propone la app (la regla)

La regla que pediste, escrita para poder programarla. Sea *D* el día de reunión
del grupo (sábado en C1) y *H* su hora de inicio (16:00):

| Momento | Sesión propuesta | Aviso |
|---|---|---|
| Día *D*, antes de *H* | La de **hoy** | 🟡 "Esta sesión empieza a las 16:00. ¿Seguro que quieres pasar lista ya?" — **avisa, no bloquea** |
| Día *D*, desde *H* | La de **hoy** | — |
| *D+1 … D+6* | La del **último *D*** (la semana pasada) | 🟡 "Estás pasando la lista del sábado 18" si aún estaba pendiente |
| No hay sesión reciente | Ninguna | Estado vacío: "No hay sesiones pendientes" |

Encima siempre, **un selector de sesión** desplegable con las últimas ~8
sesiones y las 2 próximas, cada una con su marca:

```
✅ sáb 15 nov · pasada (12/14)
✅ sáb 8 nov  · pasada (14/14)
⚪ sáb 1 nov  · sin pasar          ← se ofrece recuperar
⏭️ sáb 25 oct · omitida (no hubo)
🔒 sáb 22 nov · próxima
```

🔶 **Decisión 5.1** — ¿Hasta cuándo se puede editar una lista ya pasada?
Propongo: **libremente 14 días**, después solo coordinación. Sin ventana, el
histórico deja de ser fiable; con ventana muy corta, los despistes no se
arreglan.

---

## 6. Pantallas

Cuatro. La navegación imita a AppSheet (Etapa → Grupo → Niños) porque funciona
y la gente ya la conoce, pero **con un atajo delante**: el 90% de las veces el
monitor va a su propio grupo y a la sesión de hoy, y eso tiene que ser un toque.

### 6.1 Home de Pasar Lista — el atajo

```
┌────────────────────────────────────────────┐
│  Pasar lista                               │
│                                            │
│  ┌──────────────────────────────────────┐  │
│  │  HOY · sábado 15 de noviembre        │  │  ← tarjeta grande
│  │                                      │  │    degradado de marca
│  │  C1 · 1º ESO           14 niños      │  │
│  │  17:00 – 19:00                       │  │
│  │                                      │  │
│  │        [ PASAR LISTA →  ]            │  │
│  └──────────────────────────────────────┘  │
│                                            │
│  ⚠️ Te faltan 2 listas                     │  ← solo si las hay
│     sáb 1 nov  · C1     [ Recuperar ]      │
│     sáb 25 oct · C1     [ Recuperar ]      │
│                                            │
│  ── Pasar lista de otro grupo ───────────  │
│                                            │
│  🔴 MIC      93   >                        │  ← igual que AppSheet
│  🟢 COM I    75   >                        │
│  🔵 COM II   51   >                        │
│                                            │
│  [ 📊 Resumen de grupos ]                  │
└────────────────────────────────────────────┘
```

- Si el monitor lleva **varios grupos**, arriba salen varias tarjetas (o una
  con selector). Si no lleva **ninguno** (coordinación), se entra directo al
  árbol de etapas.
- El bloque de "te faltan" es el que empuja. Es lo que hoy no existe y hace que
  se pierdan semanas enteras.

### 6.2 Árbol de grupos (Etapa → Grupo)

Calcado de AppSheet, que está bien resuelto: lista de etapas con recuento,
dentro lista de grupos con `code · monitor [curso]` y recuento. Se le añade:

- **Buscador** arriba (por grupo, por monitor o por nombre de niño → salta
  directo a su ficha).
- **Chip de estado de la lista de hoy** en cada grupo: `✅ pasada` /
  `⚪ pendiente` / `⏭️ omitida`. De un vistazo, coordinación ve qué grupos
  faltan.

```
┌────────────────────────────────────────────┐
│  ‹  COM I                        🔍        │
│                                            │
│  C1 · David Soler        [1º ESO]  14  ✅  │
│  C2 · Mercedes           [1º ESO]  11  ⚪  │
│  C3 · Teresa             [1º ESO]  15  ⚪  │
│  C4 · Sara               [2º ESO]  12  ⏭️  │
└────────────────────────────────────────────┘
```

### 6.3 Pasar lista (la pantalla que importa)

```
┌────────────────────────────────────────────┐
│  ‹  C1 · sáb 15 nov          [ Cambiar ▾ ] │  ← selector de sesión
│  ────────────────────────────────────────  │
│  ⚡ Empieza a las 17:00 (aún no ha empezado)│  ← aviso ámbar, condicional
│                                            │
│  [ ✓ Han venido todos ]   [ ⏭️ No hubo ]   │
│                                            │
│  ┌──────────────────────────────────────┐  │
│  │ ⬤  Solete Vilarroya      79%    ●○   │  │  ← toggle grande
│  │ SV  1º ESO                           │  │
│  ├──────────────────────────────────────┤  │
│  │ ⬤  Jaume Pascual         92%    ●○   │  │
│  │ JP  1º ESO                           │  │
│  ├──────────────────────────────────────┤  │
│  │ ⬤  Lydia Godoy           41%    ○●   │  │  ← ausente (rojo)
│  │ LG  1º ESO           ⚠️ 3 faltas     │  │
│  └──────────────────────────────────────┘  │
│                                            │
│         12 vinieron · 2 faltas             │  ← contador vivo
│  ┌──────────────────────────────────────┐  │
│  │           GUARDAR LISTA              │  │  ← barra fija abajo
│  └──────────────────────────────────────┘  │
└────────────────────────────────────────────┘
```

Decisiones de interacción:

- **Todos empiezan sin marcar.** Nada de "todos presentes" por defecto: sería
  meter datos que nadie ha mirado. Pero el botón **"Han venido todos"** está
  arriba del todo, y desmarcar dos ausentes es más rápido que marcar doce.
- **Tocar la fila entera** alterna vino/no vino. El target es la fila completa,
  no un checkbox de 20px.
- **Parcial y justificada** no ensucian el gesto principal: salen al mantener
  pulsado (o en un `…` de la fila) con las cuatro opciones reales del CRM.
- **El nombre es un enlace** a la ficha (§6.4). Tocar nombre ≠ tocar fila:
  🔶 **Decisión 6.1** — ¿o mejor una flecha `›` al final para la ficha y toda
  la fila para marcar? Me inclino por la flecha: marcar es lo frecuente.
- **Guardar es explícito**, con la barra fija abajo y el contador encima. Nada
  de autoguardado silencioso: el monitor tiene que poder repasar antes.
- **Si no hay conexión** (patio, sótano, campamento) la lista se guarda en el
  navegador y se sincroniza sola. 🔶 **Decisión 6.2**: ¿fase 1 o fase 2? Es
  bastante trabajo; propongo fase 2 pero **diseñando la pantalla para poder
  añadirlo** (una sola llamada de guardado con todo el lote).
- **Skip / "No hubo"** marca la sesión como `omitida` y no crea asistencias:
  no cuenta como falta de nadie ni como lista pendiente.

### 6.4 Ficha del niño

Todo lo que sabemos, y sobre todo **el teléfono de la familia a un toque**.

```
┌────────────────────────────────────────────┐
│  ‹  Solete Vilarroya Messguer              │
│     C1 · COM · 1º ESO · 13 años            │
│                                            │
│  ┌────────────┐  ┌────────────┐            │
│  │ 💬 WhatsApp│  │ 📞 Llamar  │            │  ← acciones primero
│  │   familia  │  │   familia  │            │
│  └────────────┘  └────────────┘            │
│                                            │
│  ── Asistencia ──────────────────────────  │
│  ████████████████░░░░  79%                 │
│  31 de 39 sesiones · 3 faltas seguidas ⚠️  │
│  [ ver detalle por sesión ]                │
│                                            │
│  ── Familia ─────────────────────────────  │
│  Sol Meseguer · Madre · ⭐ referencia      │
│  📞 6XX XXX XXX          [ 💬 ] [ 📞 ]     │
│                                            │
│  ── Salud y avisos ──────────────────────  │
│  Alergias: —                                │
│  Puede irse solo a casa: Sí                │
│                                            │
│  ── Datos ───────────────────────────────  │
│  Nacimiento 30/7/2013 · Castellón          │
└────────────────────────────────────────────┘
```

El bloque de asistencia reutiliza `attendance_percentage` y `attended_hours` de
la inscripción, y el detalle por sesión ya existe: es el **calendario de
actividades** (`inc/stic-calendar.php`), que ya colorea las sesiones por
asistencia. No hay que inventarlo, hay que enlazarlo.

🔶 **Decisión 6.3** — ¿La ficha es de **solo lectura** o el monitor puede
editar (alergias, "puede irse solo")? En AppSheet edita. Editar desde aquí
significa escribir en `Contacts` desde el área privada con el rol monitor:
hay que decidir qué campos exactamente.

### 6.5 Resumen de grupos (coordinación)

```
┌──────────────────────────────────────────────────┐
│  Resumen de grupos · MCM Castellón               │
│                                                  │
│  🔴 MIC  93 niños · 8 grupos · 12 monitores      │
│  🟢 COM  75 niños · 6 grupos · 9 monitores       │
│  🔵 LC   51        · 4 grupos · 5 monitores      │
│  ────────────────────────────────────────────    │
│  Grupo    Monitores      Niños  Asist.  Últ.lista│
│  C1       David Soler      14    82%    sáb 15 ✅│
│  C2       Mercedes         11    77%    sáb 8  ⚠️│
│  C3       Teresa, Jaime    15    91%    sáb 15 ✅│
│  ⚠️ Sin grupo asignado      7      —        —    │
└──────────────────────────────────────────────────┘
```

La fila "Sin grupo asignado" es deliberada: es el gancho para que se limpien
los datos de §2.2.

---

## 7. Permisos

| Rol | Puede |
|---|---|
| **Monitor** | Pasar lista de **cualquier grupo de su delegación** (el caso real: falta un monitor y le cubre otro). Ver la ficha de los niños de esos grupos. |
| **Coordinación** | Lo anterior + resumen + editar listas fuera de la ventana de 14 días |
| **Familia / participante** | Nada de esto. Ve su propia asistencia en el calendario, que ya existe |

El rol ya se detecta hoy en `inc/stic-comunica-roles.php` (`monitor` / `laico`)
leyendo `stic_relationship_type_c` del contacto.

🔶 **Decisión 7.1** — ¿De verdad cualquier grupo de la delegación, o solo de su
etapa? Y ¿queda registrado quién la pasó? (para eso es `ajmcm_lista_pasada_por_c`).

---

## 8. Rendimiento

Sigue siendo lo que hay que cuidar, pero ya está medido (§3.1): la pantalla de
marcado son **tres consultas**, no una por niño. El riesgo no es el volumen,
es escribir el código de forma ingenua — y **el CRM va lento** (el proxy de
monitores tiene el timeout en 120 s).

Reglas desde el minuto uno:

- **Una llamada por colección**, nunca una por fila. Los miembros de un grupo
  salen de `get_relationships` sobre el grupo, no de una consulta por persona.
- **Guardado en lote**: una sola petición con las 14 asistencias.
- **Cachear** en sesión lo que no cambia en una tarde: árbol de grupos,
  monitores, sesiones del curso.
- Ya hay precedente en el repo: `plans/011-kill-n-plus-1-listings.md` y
  `sticpa_cached_field_definition()`.

🔶 **Decisión 8.1** — ¿El guardado en lote va contra la API v4.1 (`set_entries`)
o hace falta un endpoint propio en el proxy? Hay que probarlo antes de
comprometerse con la pantalla.

---

## 9. Fases

**Fase 0 — CRM y datos (antes de programar nada).**
Cerrar la decisión 3.1. Crear el módulo `Listas` y los tres campos de día y
hora en `ajmcm_GRUPOS`. Crear los eventos de la delegación piloto con sus
sesiones y dar de alta las inscripciones. Poner `code` a los grupos y asignar
grupo a los participantes y monitores que hoy cuelgan de los grupos comodín.

Se puede hacer entero desde el CRM y la API, sin tocar el área privada, y al
terminar ya se puede comprobar en el calendario de actividades que las
asistencias salen bien. Es el hito que decide si el modelo aguanta.

**Fase 1 — pasar lista.**
Home con atajo + árbol de grupos + pantalla de marcado + guardado en lote.
Sin offline, sin edición de ficha. Esto ya sustituye a AppSheet.

**Fase 2 — la ficha y la familia.**
Ficha del niño, contactos de familia con llamada/WhatsApp, enlace al detalle de
asistencia.

**Fase 3 — coordinación.**
Resumen de grupos, recuentos por etapa, "sin grupo asignado", avisos de
comportamiento si se decide traerlos.

**Fase 4 — offline.**
Guardado local y sincronización.

---

## 10. Datos de prueba montados (agosto 2026)

Para poder ver esto funcionando ya:

| Qué | Id / detalle |
|---|---|
| Evento `Actividades Semanales MIC-COM 2025-2026` | `00000e2a-f3e4-165f-9e5b-6a87868a5297` · 39 sesiones (78 h), sábados 17:00-19:00 |
| Grupo `C1` | `00000fda-af65-a98f-e69c-6a8789aca09d` · code C1 · nivel com · 1º ESO · MCM Castellón |
| Monitor de C1 | David Soler Balado, relación `monitor`, etapa COM, 1/9/2025 – 31/8/2026 |
| Participantes de C1 | Solete Vilarroya Messguer (13 años) y Sol Meseguer |
| Inscripción de Solete | 79,49 % (62 h de 78), 31 sesiones marcadas *Sí* |

Sesiones excluidas del calendario: julio y agosto, 7 y 14 de marzo (Magdalena)
y 4 de abril (Sábado Santo). **Navidad no está excluida** (falta decidirlo).

⚠️ Las claves internas de los estados de ausencia (*No justificada* / *No sin
justificar*) **no están confirmadas**: la API acepta cualquier cadena sin
validar, así que las 3 faltas de Solete están sin marcar en vez de con un valor
inventado. Hay que mirarlas en el desplegable del CRM y anotarlas aquí antes de
programar el marcado.
