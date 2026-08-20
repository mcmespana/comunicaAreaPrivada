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

## 3. La decisión crítica: ¿dónde encaja el grupo en una sesión?

**El grupo no está en el evento ni en la sesión.** Sale de las relaciones. Y
"pasar lista" es exactamente "un grupo × un día". Hay tres formas de montarlo y
esta es *la* decisión que hay que cerrar.

### Opción A — Un evento por grupo y curso ⭐ recomendada

`C1 · Curso 2025-2026`, `MIC A · Curso 2025-2026`… Cada uno con sus sesiones
(su día y su hora, que **son distintos por grupo**: hay grupos de viernes a las
16:00 y de sábado a las 16:00).

- ✅ "Pasar lista" = una sesión, un grupo. Sin derivar nada.
- ✅ `validated_attendances` de la sesión responde solo por ese grupo → el
  indicador "¿he pasado lista?" es un campo, no un cálculo.
- ✅ El `%` de asistencia por niño lo calcula el CRM sin ayuda.
- ✅ El horario real de cada grupo se respeta.
- ❌ Setup anual: un evento + asistente de sesiones por grupo (~1 min/grupo).
- ❌ Cambiar a un niño de grupo a mitad de curso = cerrar una inscripción y
  abrir otra (su histórico queda partido en dos, que es honesto pero incómodo).
- ❌ El "resumen de grupos" agrega sobre N eventos.

### Opción B — Un evento global, el grupo solo en las relaciones

Un único `Actividades Semanales MIC-COM 2025-2026` (el que ya existe de prueba)
con una sesión por sábado, y el grupo se resuelve por relaciones al pintar.

- ✅ 39 sesiones en total. Setup trivial.
- ✅ Encaja con "a este evento no te inscribes, vas por ser socio".
- ✅ Cambiar de grupo a mitad de curso no rompe nada: la asistencia sigue
  colgando del niño.
- ❌ "¿He pasado lista de mi grupo hoy?" hay que **derivarlo** (contar
  asistencias marcadas de los niños de mi grupo en esa sesión vs. tamaño del
  grupo). Frágil: un niño nuevo sin marcar deja la sesión "a medias" para siempre.
- ❌ Grupos que se reúnen otro día comparten sesión con los del sábado.
- ❌ Todos los niños necesitan inscripción al evento global (se puede crear
  masivamente o al vuelo, ver 🔶3.1).

### Opción C — Un evento por etapa (MIC / COM I / COM II)

El punto medio, y el que replica la navegación de AppSheet.

- ✅ 3 eventos, horarios por etapa (que sí suelen coincidir).
- ❌ Hereda el problema de B dentro de cada etapa: varios grupos por sesión.

### Recomendación

**Opción A**, por una razón práctica: es la única en la que el estado *"esta
lista está pasada"* es un dato y no una inferencia. Todo el diseño de la
pantalla (los avisos de "te faltan listas", el skip, el selector con checks)
depende de saberlo con certeza. Y de propina el `%` por niño sale gratis.

El coste real es el setup anual, y es una tarea de coordinación de una tarde,
una vez al año, con el asistente del CRM.

> El evento global de socio (`Actividades Semanales MIC-COM 2025-2026`) **no se
> tira**: se queda para lo que es, la actividad a la que se pertenece por ser
> socio, y para convivencias/campamentos con inscripción. Pasar lista semanal
> es otra cosa y merece su propia estructura.

🔶 **Decisión 3.1** — ¿Opción A, B o C? Todo lo demás depende de esto.

🔶 **Decisión 3.2** — Si A: ¿el evento del grupo lo crea coordinación a mano
cada septiembre, o hacemos un script anual que lo genere leyendo los grupos
activos? Recomiendo script: 150 grupos a mano no los monta nadie dos años
seguidos.

---

## 4. Qué habría que crear en el CRM

Poco, y todo pequeño. En `stic_Sessions`:

| Campo | Tipo | Para qué |
|---|---|---|
| `ajmcm_lista_estado_c` | enum: `pendiente` · `pasada` · `omitida` | El indicador de la pantalla y el **skip**. `validated_attendances` no distingue "no la he pasado" de "no hubo reunión" |
| `ajmcm_lista_pasada_por_c` | relate a Contacts | Quién la pasó (útil cuando la pasa un monitor de otro grupo) |
| `ajmcm_lista_pasada_el_c` | datetime | Cuándo |

En `ajmcm_GRUPOS`, para que la app sepa qué día toca sin adivinarlo:

| Campo | Tipo | Para qué |
|---|---|---|
| `ajmcm_dia_semana_c` | enum (lunes…domingo) | Día habitual de reunión |
| `ajmcm_hora_inicio_c` | texto/hora | Hora de inicio (el aviso de "aún no ha empezado") |
| `ajmcm_hora_fin_c` | texto/hora | |

Y una limpieza que no es un campo: **poner `code` a todos los grupos**. La
pantalla se lee muchísimo mejor con "C1 · David" que con "Grupo 3: Ana María y
Quique".

🔶 **Decisión 4.1** — Los avisos de comportamiento de AppSheet (Aviso 1/2/3 +
explicación) ¿se traen? Necesitarían 4 campos en `Contacts`. Yo los dejaría
para una fase 2: no son "pasar lista".

**No hace falta ningún módulo nuevo.** El modelo de SinergiaCRM ya da para
todo esto; lo que falta son tres campos de estado.

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

Es el riesgo técnico real, no el diseño. Pintar un grupo de 14 niños con su
asistencia puede convertirse en 40 llamadas a la API si se hace ingenuamente,
y **el CRM va lento** (el proxy de monitores tiene el timeout en 120 s).

Reglas desde el minuto uno:

- **Una llamada por colección**, nunca una por fila. Los niños de un grupo son
  *una* consulta a `stic_Contacts_Relationships` con `fields` acotados.
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

**Fase 0 — datos (antes de programar nada).**
Cerrar la decisión 3.1. Poner `code` a los grupos. Asignar grupo a los
participantes y monitores que están en los grupos comodín. Crear los tres
campos de `stic_Sessions`.

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
