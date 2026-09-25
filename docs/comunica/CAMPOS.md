# Campos del CRM (SinergiaCRM) — MCM

> **Notación:** para cada campo se indica su nombre técnico (`código_interno`), la etiqueta que ve el usuario y el tipo. En los desplegables, cada valor se muestra como `valor_interno [Etiqueta mostrada]`.

---

> **⏳ HAY UNA LISTA DE PENDIENTES JUSTO DEBAJO** (§ Lo que queda por revisar).
> Por crear no queda nada; lo que falta es **leer en Studio las opciones de dos
> desplegables de eventos**, porque si no casan con lo que espera el código el
> filtro falla EN SILENCIO. Míralo antes de tocar la audiencia de eventos.
>
> **Última revisión contra el CRM: 25 de septiembre de 2026 — la primera
> COMPLETA por MCP.** Se comparó el documento entero con `get_module_fields` de
> cada módulo y con el código. Lo que salió:
>
> - **Erratas y tipos corregidos**: `stic_182_excluded_c` (aquí ponía
>   `exluded`), `ajmcm_monitor_desde_c` es `date` y vive en `Contacts` (no en la
>   relación), `lawful_basis` es `multienum`, y otros cuatro tipos. El doble
>   guion bajo de `ajmcm_descripcion_allergies__c` es real (errata del CRM, se
>   queda así).
> - **`ajmcm_dirigido_a_c` ya es `multienum`**: el pendiente 3 queda cerrado.
> - **11 campos `stic_*` de la §4 no existen** en esta instancia; marcados.
> - **Campos reales que faltaban** (inscripciones, avisos, token de acceso…):
>   sección nueva al final de la §1.
> - **Módulos sin documentar**: lista en el pendiente 7.
> - **El entorno personal ya no está vacío**: la migración trajo ~400
>   relaciones de familia. Ver §1 → Entorno personal.
> - **Ningún campo que use el código activo apunta a un campo inexistente.**
>
> **Revisión anterior: 20 de septiembre de 2026.** Los eventos ya
> están rellenos, así que por fin se pueden confirmar claves **con datos** en
> vez de con Studio: `ajmcm_ambito_c = local` (en seis de los siete eventos) y
> `ajmcm_dirigido_a_c = ^participante_mic_com^` son **las claves exactas que
> espera el código**. El eje local y el del perfil funcionan; sigue sin
> confirmar la clave de «nacional», que no usa ningún evento todavía. Los dos
> pendientes de abajo bajan de riesgo alto a medio y dicen cómo esquivarlo.
>
> **Revisión anterior: 15 de septiembre de 2026.** Entra la
> **campaña de renovaciones 2026-2027**, y con ella cuatro cosas:
>
> - Los **tres campos de pago** de §1 (`ajmcm_iban_c`, `ajmcm_iban_titular_c`,
>   `ajmcm_forma_pago_c`), creados ese día y verificados uno a uno por MCP.
>   Están en la PERSONA a propósito y de forma provisional; la ficha explica por qué.
> - El módulo de **entorno personal** (`stic_Personal_Environment`), que hasta
>   hoy no estaba documentado en ninguna parte pese a que el área privada ya lo
>   lee. Tiene sección propia en §1.
> - **Los compromisos de pago** (`stic_Payment_Commitments`), otro módulo que
>   nadie había documentado y que tenía UN registro. Con la sorpresa de que los
>   formularios de alta **no los crean** aunque lo parezca: su `defParams` lleva
>   `include_payment_commitment = 0` desde el export original.
> - **Lo que la migración de Berrly NO trajo**, que es la razón de ser del
>   formulario de renovación: ni un consentimiento RGPD ni una autorización.
>   Está en §1 → «Qué trajo la migración de septiembre de 2026».
>
> **Revisión anterior: 10 de septiembre de 2026.** Los cinco campos
> de `stic_Events` que pedía la audiencia de eventos **están creados**, con el
> nombre exacto y verificados uno a uno por MCP: `ajmcm_lugar_c`,
> `ajmcm_direccion_c`, `ajmcm_mapa_c`, `ajmcm_dirigido_a_c` y `ajmcm_ambito_c`.
> Los cinco vacíos todavía en los cinco eventos, así que aún no restringen nada.
> **No queda ningún campo por crear en todo el proyecto.**
>
> También se apuntó el dominio COMPLETO de `ajmcm_curso_escolar_c_list` (leído
> en Studio, esta vez exhaustivo), que es **la misma lista** que usa
> `stic_Events.ajmcm_filtro_edades_c` — por eso el filtro de cursos no puede
> divergir— y se cerró la duda del segmento COM: **no tiene ninguna
> correspondencia con el nivel personal.**
>
> **Revisión anterior: 9 de septiembre de 2026.** Se verificaron
> por MCP `stic_Events` (59 campos), `stic_Contacts_Relationships` (40) y
> `ajmcm_GRUPOS` (31), más los valores reales de sus desplegables. De ahí sale
> la sección nueva de **Eventos** en §1 —con los tres ejes de quién puede
> apuntarse— y **la corrección de `ajmcm_curso_escolar_c`**, que este documento
> daba por vacío y por «año académico» y es ni una cosa ni la otra.
>
> ⚠️ **El MCP no devuelve las opciones de los desplegables**, solo el tipo. Los
> valores que se listan como «vistos» salen de datos reales, así que son «lo
> que se usa», no necesariamente el dominio completo. Si necesitas una clave
> que no esté, míralas en el CRM y apúntalas aquí.
>
> **Revisión anterior: 28 de agosto de 2026.** Se verificaron los
> campos que usa la ficha del monitor del área privada (`get_module_fields` de
> `Contacts`, `ajmcm_GRUPOS` y `stic_Contacts_Relationships`). De ahí salen los
> tres campos `stic_` que faltaban en §2 y la corrección de `phone_mobile`.
>
> ## Este fichero es EL ORIGINAL. No hay otro.
>
> `comunicaFormularios/CAMPOS.md` es una **copia generada** de este fichero: la
> sincroniza sola una GitHub Action de aquel repo, que abre un PR cuando esto
> cambia. No la edites allí — se pisa en la siguiente sincronización.
>
> **Todo cambio de campos del CRM se hace AQUÍ**, y llega al otro repo solo.
> (Antes había que acordarse a mano, y por eso las dos copias llevaban meses
> divergiendo: los tres campos `stic_`, `phone_mobile`, `do_not_call` y los dos
> módulos nuevos existían solo en esta.)

## ⏳ Lo que queda por revisar (anotado el 19/09/2026)

**Por crear no queda nada.** Lo de aquí abajo son campos que YA EXISTEN y de los
que este documento no puede jurar el contenido, porque nunca se ha leído.

### Por qué esto es una lista y no una nota al pie

Dos cosas del CRM se juntan mal:

- **El MCP no devuelve las opciones de un desplegable**, solo el tipo. Así que
  de un `enum` sabemos que existe, pero no qué claves tiene dentro — salvo que
  alguien las mire en Studio y las apunte aquí.
- **La API no valida los desplegables**: acepta cualquier cadena.

O sea que una clave que no casa **no da ningún error**. No se rompe nada, no
salta nada en los tests: simplemente el filtro deja de acertar y nadie se entera.
Es el peor modo de fallo que tiene este proyecto, y ya nos ha mordido dos veces
(el rol «laico» que buscaba tres cadenas inexistentes y no se disparó jamás; el
`na` de los cursos, que habría excluido a gente en silencio).

### 1. `stic_Events.ajmcm_dirigido_a_c` — una clave CONFIRMADA, el resto no ⚠️ RIESGO MEDIO

El campo existe y es **`multienum`** (verificado el 25/09/2026; el 10/09 era
`enum` simple, ver punto 3). Las opciones de Studio siguen sin leerse, pero se
leyeron **los datos**, que es la otra forma de confirmarlas: el 25/09/2026 hay
**5 de 10 eventos** con el campo relleno y **los cinco llevan
`^participante_mic_com^`**, o sea **la clave exacta que espera el código**. Dos
cosas que eso cierra:

- El vocabulario **es** el de `relationship_type`, como se pidió. No hay dos
  listas.
- El valor llega **envuelto en circunflejos**, como todo multienum. El
  troceador lo aguanta (`sticpa_event_audience_multi()`).

Las otras cuatro claves siguen sin confirmar, porque ningún evento las usa
todavía. El código compara clave a clave contra
`sticpa_event_audience_perfil_map()`, y espera exactamente estas:

| Clave que espera el código | Significado |
|---|---|
| `grupo` | Miembros del MCM (con grupo) |
| `monitor` | Monitores/as |
| `participante_mic_com` | Participantes de MIC y COM |
| `coordinacion` | Equipo de coordinación (agrupa `coordinacion_mic_com` y `acompanamiento_mic_com`) |
| `familiar_menor` | Familias |

**Qué pasa si no casan:** una clave desconocida casa consigo misma, así que un
evento marcado `monitores` (en plural, por ejemplo) buscaría a gente con el papel
`monitores`, que no existe → **el evento se escondería a TODO EL MUNDO**, sin
error y sin aviso.

**Cómo se arregla si no casan:** o se corrigen las claves en Studio, o se ajusta
el mapa con `add_filter('sticpa_event_audience_perfil_map', …)`. No hay que
rehacer nada.

### 2. `stic_Events.ajmcm_ambito_c` — `local` CONFIRMADO, `nacional` no ⚠️ RIESGO MEDIO

Existe (`enum(100)`) y las opciones de Studio siguen sin leerse, pero los datos
del 20/09/2026 confirman la mitad que importa: **seis de los siete eventos
llevan `ajmcm_ambito_c = local`**, la clave literal que espera
`sticpa_event_audience_scope()`. El eje local funciona.

Lo que **sigue sin confirmar es la clave de «para todas las delegaciones»**,
porque ningún evento la usa aún. El código acepta tres sinónimos —`nacional`,
`todas` e `interdelegacional`— y **cualquier otra cosa la entiende como local**.

**Qué pasa si no casa:** el primer evento nacional que se cree marcado `estatal`
o `todas_las_delegaciones` se trataría como **local** y solo lo vería su
delegación. En silencio, como siempre.

**Cómo no pisar la mina, mientras no se lean las opciones en Studio:** un evento
para todas las delegaciones se puede decir sin tocar este campo — se deja
`ajmcm_ambito_c` **vacío** y se asigna al «Administrador MCM» (id `1`) o a
nadie, y el ámbito se deduce como nacional. Es el camino que ya está probado.

### 3. ✅ CERRADO — `stic_Events.ajmcm_dirigido_a_c` ya es MÚLTIPLE

Se pidió de selección múltiple y se creó simple (10/09/2026). El 25/09/2026 el
CRM ya lo devuelve como **`multienum`**: alguien cambió el tipo en Studio. Ya se
puede marcar «monitores Y coordinación» en el mismo evento. El código no se
tocó: el troceador aguantaba las dos formas.

### 4. `stic_personal_environment_relationship_type_list` — «Tutor/a legal» PENDIENTE (lo hace el propietario)

Los parentescos del entorno personal. En datos (25/09/2026) se ven `son`,
`mother`, `father` y **`legal`** (1 registro). `legal` es la candidata obvia a
«Tutor/a legal» —y el código ya la busca (`RELATIONSHIP_TUTOR_TYPES`, junto a
`carer`)—, pero **el propietario del CRM ha dicho que la clave de tutor/a legal
la cierra él y nos avisa**. Hasta entonces: no se escribe `legal` ni `carer`
desde el área, y la lista entera de Studio sigue sin apuntarse aquí.

### 5. `ajmcm_GRUPOS.ajmcm_segmento_com_c` — valores observados, no leídos

`com_1`, `com_2` y `com_3` salen de **mirar los datos**, no el desplegable. El
25/09/2026 se volvió a contar: 156 grupos, `com_1` 8, `com_2` 3, `com_3` 4 y el
resto vacío. Ningún valor más. Podría haber opciones sin usar en Studio; es
menos grave porque aquí no hay comparación cruzada.

### 6. `Contacts.stic_time_availability_c` — existe y nadie lo usa

`varchar(255)`, **vacío en todos los contactos** (25/09/2026). No hay nada que
perder si algún día se le da uso; anotado para que nadie cree otro igual.

### 7. Módulos del CRM que este documento no recoge — PENDIENTE (lo hace el propietario)

`get_available_modules` (25/09/2026) devuelve 38 módulos. Estos existen y
**aquí no aparecen**; el propietario ha dicho que se documentarán más adelante.
Mientras tanto, antes de crear un módulo o un campo para algo de esto, **mira si
ya está aquí**:

| Módulo | Etiqueta | Nota |
|---|---|---|
| `SEG_Seguros` | Seguros | Custom de MCM |
| `NSOC_NumSociosLocales` | Número Socios Locales | Custom de MCM |
| `STIC_Entorno_organizacional` | Equipos y responsabilidades | |
| `stic_Sessions` | Sesiones | Pasar Lista las usa (`PASAR-LISTA-CAMPOS-CRM.md`) |
| `stic_Attendances` | Asistencias | Pasar Lista las usa |
| `stic_FollowUps` | Seguimientos | Seguimientos de monitores (`PASAR-LISTA-SEGUIMIENTOS.md`) |
| `stic_Payments` | Pagos | El área los lista (`inc/stic-payments.php`) |
| `stic_Registrations` | Inscripciones | Sus campos `ajmcm_*` sí están, en §1 |
| `stic_Messages` | Mensajes | |
| `stic_Resources` | Recursos | |
| `stic_AWF_Forms` | Formularios Web Avanzados | |
| `stic_Signatures`, `stic_Signers`, `stic_Signature_Log` | Firmas | Firma electrónica de SinergiaCRM |

`AVI_avisos` y `LIS_listas` sí están documentados, en los papeles de Pasar Lista;
`stic_Remittances` (24 campos, ninguno custom) tiene su nota en §1.

---

## 1. Campos específicos de nuestra adaptación [Módulo personas, generalmente]

### Sección MCM

- `ajmcm_numero_persona_c` — Nº Registro (uso interno) (`varchar`, 12)
- `ajmcm_centro_educativo_c` — Centro educativo (texto libre)
- `ajmcm_etapa_c` — Etapa (desplegable)
  - Valores: `MIC` [MIC], `COM` [COM], `LC` [LC]
- `ajmcm_nivel_com_c` — Nivel COM (desplegable)
  - Valores: `conocimiento` [I - Conocimiento], `incorporacion` [II - Incorporación], `crecimiento` [III - Crecimiento], `opcion_responsable` [IV - Opción Responsable]
- `ajmcm_panuelo_c` — Pañuelo (desplegable)
  - Valores: `no` [No], `rojo` [Rojo], `verde` [Verde], `azul` [Azul], `amarillo` [Amarillo], `cruz` [Cruz], `na` [Desconocido]
- `ajmcm_tallas_c` — Talla (desplegable)
  - Valores: 4, 5, 6, 7, 8, 9, 10, 12, 14, XS, S, M, L, XL, XXL, XXXL (mismo valor interno y etiqueta)
- `ajmcm_grupotemp_c` — Grupo MCM (texto libre)
  - Nota: indica el grupo al que pertenece el miembro; después hay que vincularlo manualmente en Sinergia.

**Preguntas asamblea**

- `ajmcm_asamblea_movimiento_es_c` — "Para mí el Movimiento es…" (texto libre)
- `ajmcm_asamblea_responsabilid_c` — Responsabilidades asumidas en el MCM (texto libre)
- `photo` — Fotografía asociada (ver ficha completa en el apartado 2)

### Sección RGPD (todos: monitores y participantes)

*Todos son desplegables con valores 1 (Sí) / 0 (No).*

- `ajmcm_acepta_lopd_c` — Acepta LOPD
- `ajmcm_datossalud_c` — Uso de datos sobre salud
- `ajmcm_cesionimagenes_interne_c` — Acepta la cesión, publicación y envío de imágenes en internet y medios de comunicación

### Sección Autorizaciones (solo participantes menores de edad)

*Todos son desplegables con valores 1 (Sí) / 0 (No).*

- `ajmcm_actividadesout_c` — Autoriza a participar en actividades fuera del centro
- `ajmcm_menorwhatsapp_c` — Autoriza a incluir el contacto del/de la menor en grupos de WhatsApp
- `ajmcm_soloacasa_c` — Autoriza a irse solo/a a casa al acabar las actividades
- `ajmcm_aut_participar_c` — Autorización para participar

### Información sanitaria

*Campos de texto libre, todos opcionales.*

- `ajmcm_descripcion_allergies__c` — Alergias
  - ⚠️ **El doble guion bajo es de verdad**: el campo se creó así por error y así
    se queda (confirmado contra el CRM el 25/09/2026 y por el propietario). Se
    escribe con `__`; con uno solo, la API lo ignora sin avisar.
- `ajmcm_descripcion_intoler_c` — Intolerancias
- `ajmcm_descripcion_tratam_c` — Tratamientos
- `ajmcm_descripcion_enfermed_c` — Enfermedades
- `ajmcm_descripcion_otros_c` — Otras patologías

### Pago y domiciliación

*Creados el 15/09/2026 y verificados por MCP ese mismo día. **Viven en la
PERSONA a propósito y de forma provisional**: conceptualmente esto es un
compromiso de pago (`stic_Payment_Commitments`), pero la migración de Berrly
traía el IBAN pegado a cada participante y se decidió no perderlo por el camino.
Cuando los compromisos de pago estén montados de verdad, de aquí salen; hasta
entonces, este es el sitio.*

- `ajmcm_iban_c` — IBAN (`varchar`, 34)
  - 34 es el largo del IBAN más largo que existe (Malta), así que cabe cualquiera.
  - Se guarda **sin espacios** (así llegó la migración: `ES9300494898982516218644`).
    Quien lo enseñe que lo agrupe de cuatro en cuatro al pintarlo, no al guardarlo.
  - ⚠️ **No se pinta entero en pantalla.** El área privada lo enmascara a cuatro
    y cuatro (`sticpa_payment_mask_account()`); los formularios públicos hacen lo
    mismo cuando lo devuelven ya relleno. Es un dato bancario.
- `ajmcm_iban_titular_c` — Titular de la cuenta (`varchar`, 255)
  - El nombre de quien firma el recibo, que **no** suele ser el participante: en
    la migración vino relleno con el del padre, madre o tutor/a.
  - No es un enlace a otra ficha, es texto. Si algún día hace falta saber *quién*
    es, se resuelve por el entorno personal, no por este campo.
- `ajmcm_forma_pago_c` — Forma de pago (`enum`, 100)
  - ⚠️ **Clave OBSERVADA, no el desplegable entero:** `cargo_cuenta`, que es la
    que trajo la migración. El MCP de esta instancia no devuelve las opciones de
    los `enum`, solo el tipo. Si necesitas otra (efectivo, transferencia…),
    **míralas en Studio y apúntalas aquí — no te las inventes**, que la API las
    acepta todas sin rechistar y un valor inventado no falla: se queda guardado
    y roto.

### Monitores

*Solo se usan para perfiles de tipo monitor/a.*

**Formación**

- `ajmcm_premonitores1_c` — Premonitores I (desplegable)
  - Valores: `-vacío-`, `no` [No], `en_curso` [En curso], `finalizado` [Finalizado]
- `ajmcm_premonitores2_c` — Premonitores II (desplegable, mismos valores que Premonitores I)
- `ajmcm_premonitores_year_c` — Año Premonitores (texto)
- `ajmcm_mat_c` — MAT (desplegable)
  - Valores: `-vacío-`, `no` [No], `en_curso` [En curso], `practicas` [Prácticas], `pendiente_titulo` [Pendiente Título], `titulado` [Titulado]
- `ajmcm_mat_year_c` — Año MAT (desplegable)
  - Valores: `-vacío-`, `2013` [MAT Consolación 2013 - Castellón], `2018` [MAT Consolación 2018 - Tortosa], `2022` [MAT Consolación 2022 - El Campello], `2024` [MAT Consolación 2024 - Godelleta], `otra_escuela` [Otra escuela]
- `ajmcm_mat_file_c` — Título MAT (archivo subido) — casilla de verificación
- `ajmcm_dat_c` — DAT (desplegable, mismos valores que MAT)
- `ajmcm_dat_year_c` — DAT - Año y escuela (texto)
- `ajmcm_dat_file_c` — Título DAT (archivo subido) — casilla de verificación
- `ajmcm_fa_c` — FA (desplegable, mismos valores que MAT)
- `ajmcm_fa_year_c` — FA - Año y escuela (texto)
- `ajmcm_alimentos_c` — Manipulador de alimentos — casilla de verificación
- `ajmcm_cert_files_c` — Otros certificados (archivos subidos) — casilla de verificación
- `ajmcm_form_intera_proteccion_c` — Formación interna de protección del menor — casilla de verificación
- `ajmcm_eva_reconoce_c` — Evaluador reconoce — casilla de verificación
- `ajmcm_formacion_academica_c` — Formación académica (texto)
- `ajmcm_congreso_monis_c` — Congresos monitores (desplegable múltiple)
    `2010_vlc` [2010 - Valencia]
    `2012_cs` [2012 - Castellón]
    `2016_cs` [2016 - Castellón]
    `2019_godelleta` [2019 - Godelleta]
    `2022_burriana` [2022 - Burriana]
    `2025_benicassim` [2025 - Benicàssim]
    `2026_benicassim` [2026-27 - Benicàssim]


**Varios**

- `ajmcm_monitor_desde_c` — Monitor/a desde… (año aproximado) — **`date`** (no número)
  - Vive en `Contacts`, no en la relación (verificado el 25/09/2026: en
    `stic_Contacts_Relationships` no hay ningún campo `monitor`).
  - Solo interesa el año: el área privada guarda `AAAA-01-01` (`yearOnly` del
    motor de formularios) y enseña solo el año.
- `ajmcm_monitor_de_c` — Monitor/a de… (desplegable)
  - Valores: `MIC` [MIC], `COM` [COM], `LC` [LC], `apoyo` [Apoyo], `otros` [Otros]
  - Vive en `Contacts`, no en la relación (verificado el 25/09/2026).
- `ajmcm_procendencia_c` — MCM Local (desplegable)
    - Nota: por ahora es un campo temporal (ya cubierto por "Asignado a"), pero hace falta en el formulario de alta. Hay un typo en el nombre del campo y en Madrid. Se deja así por las risas xd
    - Valores:
     `benicarlovinaros` [MCM Benicarló-Vinaròs]
     `burriana` [MCM Burriana]
     `caravaca` [MCM Caravaca]
     `castellon` [MCM Castellón]
     `ciutadella` [MCM Ciutadella]
     `espinardo` [MCM Espinardo]
     `granada` [MCM Granada]
     `huetor` [MCM Huétor-Santillán]
     `alcora` [MCM L'Alcora]
     `madird` [MCM Madrid]
     `nules` [MCM Nules]
     `onda` [MCM Onda]
     `quintanar` [MCM Quintanar]
     `reus` [MCM Reus]
     `tortosa` [MCM Tortosa]
     `vila-real` [MCM Vila-real]
     `villacanas` [MCM Villacañas]
     `zaragoza` [MCM Zaragoza]
    `otros` [Otros]

**Legal**

- `ajmcm_aut_del_sex_c` — Autorización Delitos Sexuales, marcada por el usuario — casilla de verificación
  - Nota: la marca el propio usuario si autoriza a la entidad a obtener su certificado de Delitos Sexuales a través de la plataforma "Te Autorizo".
- `ajmcm_aut_del_sex_file_c` — Autorización Delitos Sexuales, verificada por MCM — casilla de verificación
  - Nota: la marca la entidad al comprobar que la persona está efectivamente autorizada y con vigencia.
- `ajmcm_cert_del_sex_c` — Certificado de Delitos Sexuales (archivo subido) — casilla de verificación
- `ajmcm_compromiso_c` — Compliance: Compromiso (archivo subido) — casilla de verificación
- `ajmcm_vol_acuerdo_c` — Voluntariado: Acuerdo de incorporación (archivo subido) — casilla de verificación
- `ajmcm_vol_descripcion_c` — Voluntariado: Descripción de la actividad (texto)
- `ajmcm_vol_programas_c` — Voluntariado: Programas (texto)

### Entorno personal — la familia (`stic_Personal_Environment`)

El módulo que ata a un participante con su padre, madre, tutor/a o hermano/a.
**El área privada lleva meses leyéndolo** (`inc/stic-family.php`, de ahí sale la
lista de «tus participantes» de una madre) y no estaba documentado aquí.
Verificado por MCP el 15/09/2026.

**Tiene 35 campos y solo importan seis.** El resto son los de auditoría de
SuiteCRM más una relación con `stic_Families` (módulo que existe y está a **0
registros**: no lo usamos, y no hay que empezar a usarlo sin decidirlo antes).

| Campo | Tipo | Para qué |
|---|---|---|
| `relationship_type` | `enum` **obligatorio** | Qué es la persona del lado B respecto a la del lado A |
| `start_date` | `date` **obligatorio** | Desde cuándo. Sin esto el registro no se crea |
| `end_date` | `date` | Hasta cuándo. Vacío = sigue viva |
| `name` | nombre | Se compone «Familiar - Etiqueta - Persona de referencia» |
| `description` | texto | Libre |
| `reference_contact`, `authorized_signer`, `coexistence_status` | varios | Existen, **sin usar y sin mirar**. Anotados para que nadie cree otro campo igual |

#### ⚠️ La dirección de la relación, que es lo que se equivoca

Son dos enlaces a `Contacts` y **no son simétricos**:

```
stic_personal_environment_contacts     → lado A → LA PERSONA DE REFERENCIA (el/la participante)
   campo plano: stic_personal_environment_contactscontacts_ida

stic_personal_environment_contacts_1   → lado B → EL FAMILIAR (la madre, el padre, la hermana)
   campo plano: stic_personal_environment_contacts_1contacts_ida
```

**`relationship_type` describe al lado B respecto del lado A.** Un registro con
`mother` significa «el del lado B es la madre del lado A», nunca al revés.
Comprobado sobre los dos registros reales que hay en el CRM: el que lleva
`mother` se llama «Sol Meseguer - Madre - Solete Vilarroya Messguer», y Sol
—la madre— está en `contacts_1`.

Ponerlo al revés no da error: crea una relación que dice que la niña es la
madre de su madre, y el área privada le enseña a la niña la ficha de su madre.

**Regla de la casa al leerlo** (la misma de Pasar Lista): esta instancia no
devuelve enlaces anidados, así que se pide siempre el campo plano `..._ida` y se
usa el que llegue. Nunca se confía en el objeto de la relación.

#### Las claves de `relationship_type`

Vistas en datos el 25/09/2026, sobre ~360 registros:

| Clave | Qué dice | Nota |
|---|---|---|
| `mother` | El lado B es la madre del lado A | La que lee el área privada |
| `father` | El lado B es el padre del lado A | La que lee el área privada |
| `son` | El lado B es el hijo del lado A | La trajo la migración de 2025, **mal puesta** (ver abajo). Quedan 14 |
| `legal` | ¿Tutor/a legal? | 1 registro. **Pendiente: la clave de tutor/a legal la cierra el propietario del CRM** (pendiente 4 de arriba) |

El código busca `father`, `mother`, `legal` y `carer`
(`RELATIONSHIP_TUTOR_TYPES`). La lista completa de Studio
(`stic_personal_environment_relationship_type_list`) sigue sin apuntarse; no se
inventa ninguna clave.

Quien escriba en este módulo, que lo haga **sin bloquear el resto del guardado**:
la API acepta cualquier cadena en un `enum` sin rechistar, así que una clave
inventada no falla — se queda guardada y mal.

> **Ojo, que son dos vocabularios distintos y se parecen mucho:**
> `stic_Registrations.ajmcm_tutorN_relationship_c` (los tutores que viajan
> pegados a una inscripción) usa `father` / `mother` / `legal`, y esos sí están
> confirmados porque los escribe el formulario de altas desde hace un año. **No
> son las claves de este módulo** aunque dos coincidan. Un campo, un vocabulario.

#### Estado real: montado, y arreglado el 25/09/2026

Lo que había hasta el 15/09/2026 eran 2 registros. El 25/09/2026 había **360**,
de dos cargas distintas, y **las dos estaban mal**, cada una a su manera:

- **Carga de 2025** (216 registros, `start_date` 2025-09-01): los lados bien
  —hijo/a en A, familiar en B— pero con el tipo **`son`** («el familiar es hijo
  del niño»). El área privada no la veía, porque solo busca `mother`/`father`.
- **Carga del 24/09/2026** (~140 registros, creados por el usuario «API User»):
  el tipo bien (`mother`/`father`) pero, en unos 90, **los lados al revés** —el
  familiar en A y el hijo/a en B—. Y muchas parejas **dos veces**, una en cada
  sentido.

O sea: ningún familiar migrado veía a sus hijos en el área privada.

**Qué se hizo el 25/09/2026, por MCP y con permiso del propietario del CRM:**

- Los **91** que estaban al revés, **se les dio la vuelta** (hijo/a a A,
  familiar a B).
- Los 144 `son`, a **`mother` o `father`**. Cómo se decidió: el género del
  familiar si lo tenía (2) y, si no, **los apellidos** —el primer apellido del
  hijo/a es el del padre, el segundo el de la madre—. Validado contra los
  registros de 2026 que ya traían el tipo: acertaba 114 de 118. Y repasados a
  mano uno a uno contra el nombre de pila: ninguno chocaba.
- Los **82 duplicados** (la misma pareja dos veces, o un `son` que repetía un
  `mother`/`father` ya bueno), **borrado lógico**. De cada pareja queda uno.
- El `name` se reescribió en todos los tocados: **el CRM no lo recalcula** al
  cambiar lados o tipo por la API. Formato: «Familiar - Madre|Padre - Hijo/a».

**Lo que se dejó sin tocar, para que lo mire una persona** (19 registros):

- 14 `son` en los que los apellidos no deciden (apellidos compuestos, un solo
  apellido…) y el familiar no tiene género.
- 3 `mother` y el único `legal`, que apuntan a contactos que no existen.
- 2 registros que, arreglados, dejaban al niño con dos madres o dos padres
  (`00000290-408d-98a7-e5cb-6ab567a2f1b8` y
  `000004a1-b68f-0c8f-e65c-6ab508f35231`). Siguen al revés a propósito.

**Y 5 que probablemente son HERMANOS, no padres** (tipo puesto por la carga de
2026, no por nosotros): el «padre» o la «madre» lleva exactamente los mismos
dos apellidos que el hijo/a: `00000344-1b9e-c76c-9f37-6ab373f78ad5`,
`00000570-6e67-04c9-88f1-6ab3fac4a3c8`, `00000a4c-8e05-2bd1-ebf8-6ab508b64eb6`,
`00000e55-b23e-8abb-2a18-6ab4eff344d9` y `00000ed5-575d-f82f-b8db-6ab40337939d`
(este último puede ser padre de verdad: son apellidos rumanos, que no siguen la
regla). El tercero explica el segundo caso de arriba: el padre de verdad está
en el otro registro, y este, con el mismo nombre de pila y los apellidos del
niño, es casi seguro su hermano. Hay que mirarlos con la familia delante.

**Resultado, verificado releyendo el módulo entero el 25/09/2026:** 278
registros —200 `mother`, 63 `father`, 14 `son`, 1 `legal`—, ninguna pareja
repetida, y de los 263 `mother`/`father`, 258 con el hijo/a en A (el resto son
los casos de arriba).

⚠️ **Quien vuelva a cargar familias por la API, que respete la dirección**:
hijo/a en `stic_personal_environment_contactscontacts_ida` (A), familiar en
`stic_personal_environment_contacts_1contacts_ida` (B), tipo = lo que es el
familiar. Y una sola vez por pareja. La carga del 24/09 se hizo al revés en la
mitad de los casos y por duplicado; si la herramienta que la hizo se vuelve a
usar, repetirá el error.

### Compromisos de pago (`stic_Payment_Commitments`)

Lo que hay que cobrar. Verificado por MCP el 15/09/2026, cuando el módulo tenía
**UN registro en todo el CRM** —un pago con tarjeta por Redsys, asignado al
Administrador MCM— y ningún formulario lo estaba usando.

Tiene 78 campos. Los que importan:

| Campo | Tipo | ¿Obligatorio? | Para qué |
|---|---|---|---|
| `name` | nombre | no | Cómo se lee en el CRM |
| `amount` | decimal | **sí** | El importe |
| `payment_method` | `enum` | **sí** | Cómo se cobra |
| `payment_type` | `enum` | **sí** | Qué clase de cobro es |
| `periodicity` | `enum` | **sí** | Cada cuánto |
| `first_payment_date` | fecha | **sí** | **Cuándo se pasa el primer recibo** |
| `banking_concept` | texto | no | Lo que la familia ve en su extracto |
| `bank_account` | `varchar(255)` | no | **El IBAN.** No existe un campo `iban` |
| `mandate` | `varchar(255)` | no | El mandato SEPA. Existe, sin usar |
| `end_date`, `signature_date`, `active`, `description` | varios | no | |

**Enlaces:** `stic_payment_commitments_contacts` (→ Personas),
`stic_payment_commitments_stic_registrations` (→ Inscripciones),
`stic_payment_commitments_campaigns` (→ Campañas) y
`stic_payments_stic_payment_commitments` (→ Pagos ya cobrados). **No hay campos
planos `_ida` que sirvan para escribir**: los dos lados se atan por relación.

#### Las claves de los desplegables, y de dónde salen

El MCP no devuelve las opciones de un `enum`, así que estas **no** están
observadas en datos (solo había un registro, con `card` y `punctual`). Salen del
**export original de SinergiaCRM** que hay en
`comunicaFormularios/participantes/entrega_sinergia/`, donde el propio
constructor de formularios del CRM las dejó escritas como campos ocultos:

| Campo | Clave | Qué es |
|---|---|---|
| `payment_method` | `direct_debit` | Domiciliación bancaria |
| `payment_method` | `card` | Tarjeta (visto en el único registro real) |
| `payment_type` | `fee` | Cuota |
| `payment_type` | `services` | Servicios. **Observada en datos** (MCP, 25/09/2026): la pone el propio CRM a los compromisos que crea para las convivencias. La usa el área para el pago de una actividad (`EVENTOS.md` §10.2) |
| `periodicity` | `punctual` | Pago único |

> **El área privada no escribe claves de `payment_method` a ciegas**: al
> inscribirse ofrece solo las que el desplegable del CRM trae de verdad (la API
> v4.1 sí devuelve las opciones; el MCP no). Ver `EVENTOS.md` §10.2.

Es la misma fuente que usa el CRM para sus propios formularios, así que valen.
**Cualquier otra clave hay que mirarla en Studio**, no deducirla.

> **`punctual` es lo correcto para una cuota anual, aunque suene raro.** Cada
> curso lleva su propio compromiso («Cuotas COM 26-27»), que se cobra una vez.
> Un `annual` haría que el mismo compromiso se repitiera solo cada año, que es
> justo lo que no se quiere: el importe y la gente cambian de un curso a otro.

#### ⚠️ El CRM crea compromisos SOLO al guardar una inscripción (25/09/2026)

Mirado por MCP sobre ~60 inscripciones: **toda inscripción con
`ajmcm_tutor1_iban_c` relleno tiene un compromiso** (nombre «Tutor -
Participante - Evento - Domiciliación - importe»), y ninguna sin él lo tiene.
Toma el importe de `ajmcm_registration_amount_c` (tipo `fee`, primer pago el
día de la inscripción) o, si no, de `ajmcm_convivencia_precio_c` (tipo
`services`, primer pago en la fecha de la convivencia); siempre
`direct_debit`, `punctual`, y el `assigned_user_id` de la inscripción. **El
precio del evento no lo usa.**

**No es un workflow**: en `AOW_WorkFlow` no hay ninguno de `stic_Registrations`
ni de `stic_Payment_Commitments`. Es código del CRM (un logic hook), así que
**no se sabe si la condición es «hay IBAN» o «hay importe»**: en los datos van
siempre juntos. Quien cree inscripciones por API, que mire si ya tienen
compromiso antes de crear otro (la renovación cobró dos veces el 22/09/2026;
el área privada lo hace así, `EVENTOS.md` §10.2).

**Solo al CREAR, no al modificar** (mirado el 25/09/2026 sobre las 150
inscripciones con IBAN): las que se tocaron después conservan un único
compromiso, fechado en el alta y no en la modificación. Así que cambiar o
cancelar una inscripción desde el área no genera cobros nuevos. ⚠️ Una de ellas
(la de Solete, «COM | Curso 2026-2027 · CS», con IBAN e importes) **no tiene
ningún compromiso**, ni del alta ni de la modificación posterior: ver
`TODO.md` → `CRM-05`.

#### ⚠️ Los formularios de alta NO crean compromisos de pago

Y parece que sí. Los formularios de participantes llevan cuatro campos
`stic_Payment_Commitments___*` (`amount`, `payment_method`, `payment_type`,
`periodicity`) que **el CRM ignora**, porque su `defParams` lleva
`include_payment_commitment = 0` — y lo lleva **desde el export original**, no
es algo que se rompiera por el camino. Son restos del constructor.

Resultado: hasta el 15/09/2026 el IBAN de una familia se guardaba como TEXTO en
`Contacts.ajmcm_iban_c` y en `stic_Registrations.ajmcm_tutor1_iban_c`, y no había
nada que cobrar en ninguna parte.

**Quien los crea ahora es el formulario de renovación**, por API
(`crm_proxy.php` → `renovCrearCompromiso()`): uno por participante y curso, con
`banking_concept` = «Cuotas MIC 26-27» / «Cuotas COM 26-27», `assigned_user_id`
de su delegación —para que cada MCM Local remese lo suyo— y atado a la persona y
a su inscripción. El formulario de ALTAS sigue sin crearlos: su motor se va de
la página al enviar y no hay dónde enganchar la llamada.

#### Remesas (`stic_Remittances`)

El módulo **existe y está a 0 registros** (15/09/2026). No lo usa nadie todavía.
Anotado para que no se cree otro mecanismo de remesas sin mirar este antes.

### Qué trajo la migración de septiembre de 2026 (y qué no)

El 15/09/2026 se importaron desde Berrly los participantes y los familiares de
**MCM Castellón** (~119 contactos con `assigned_user_id` de Castellón), en dos
campañas: `[Importación] Personas - Participantes - Sept 2026` y
`[Importación] Personas - Familiares - Sept 2026`. Las fichas importadas llevan
`description` = «Importación sept 2026; Participante» (o «Familiar»).

**Esto es un inventario de lo que hay, no de lo que debería haber.** Se apunta
aquí porque decide qué tiene que pedir el formulario de renovación
(`comunicaFormularios/participantes/participantes-renovacion-castellon.html`), y
porque a simple vista en el CRM una ficha migrada parece completa y no lo está.

Comprobado campo a campo sobre una ficha real (Fiamma Canepa,
`b350917e-7749-42f4-9e3c-36287a236c0f`), el 15/09/2026:

| Llegó relleno | Llegó VACÍO |
|---|---|
| `first_name`, `last_name`, `birthdate` | `email1` |
| `stic_identification_type_c` / `_number_c` | `ajmcm_tallas_c` |
| `stic_gender_c` | `ajmcm_grupotemp_c` |
| `phone_mobile`, `phone_other` | `ajmcm_acepta_lopd_c` |
| Dirección principal completa | `ajmcm_datossalud_c` |
| `ajmcm_numero_persona_c`, `ajmcm_centro_educativo_c` | `ajmcm_cesionimagenes_interne_c` |
| `ajmcm_etapa_c`, `ajmcm_nivel_com_c`, `ajmcm_panuelo_c` | `ajmcm_actividadesout_c` |
| `ajmcm_procendencia_c` | `ajmcm_soloacasa_c` |
| `ajmcm_iban_c`, `ajmcm_iban_titular_c`, `ajmcm_forma_pago_c` | `ajmcm_menorwhatsapp_c` |
| | Los cinco `ajmcm_descripcion_*` (info sanitaria) |
| | `stic_relationship_type_c` |

Tres consecuencias que hay que tener presentes:

1. ⚠️ **NO HAY NI UN CONSENTIMIENTO NI UNA AUTORIZACIÓN.** Los tres campos de
   RGPD y los cuatro de autorizaciones están vacíos en las fichas migradas. No
   es un detalle de calidad de dato: **son los que dicen que la familia ha
   consentido**, y sin ellos no hay base para tratar los datos de salud ni para
   publicar una foto. Recogerlos es la razón principal del formulario de
   renovación, por encima de «no perder gente por el camino».
2. ⚠️ **`stic_relationship_type_c` llega literalmente `^^`** (multienum vacío),
   no `^participante_mic_com^`. Quien mire ese campo para saber si alguien es
   participante **no encontrará a ninguno de los migrados**. Y afecta al área
   privada: `sticpa_detect_role_from_relationship()` y
   `sticpa_es_miembro_por_tipo_de_relacion()` se apoyan en él (§2). El
   formulario de renovación lo escribe (`^participante_mic_com^`) al guardar.
3. ~~El entorno personal está SIN MONTAR.~~ Ya no (25/09/2026): hay ~260
   parejas familiar-hijo/a, arregladas ese día. Ver §1 → Entorno personal. Aun
   así, el formulario de renovación hace bien en no depender solo de ella: hay
   niños sin familia enlazada.

---

### Grupos (`ajmcm_GRUPOS`)

- `ajmcm_pasar_lista_c` — Este grupo entra en Pasar Lista — casilla de verificación
  - Creado el 27/08/2026. En el CRM hay ~150 grupos y la mayoría son históricos:
    esta casilla decide cuáles salen en el árbol, en el buscador y en el alcance
    de coordinación.
  - Regla de seguridad, y está en el código: **mientras no haya NINGUNA marcada,
    no se esconde nada.** Sin eso, el día que se creó el campo Pasar Lista se
    habría quedado sin un solo grupo y habría parecido que estaba roto.
  - ⚠️ **Su `default` en el CRM es `1`**, aunque se pidió que naciera
    desmarcada. Los grupos que ya existían nacieron sin valor —por eso el filtro
    funciona hoy—, pero **un grupo creado a partir de ahora entrará solo en
    Pasar Lista**. Probablemente es lo que se quiere; queda escrito para que
    nadie lo descubra por sorpresa. (Comprobado el 28/08/2026.)

- `ajmcm_segmento_com_c` — Segmento COM — desplegable (`enum`, custom, len 100)
  - **Qué es, y no es lo que parece por el nombre.** Es una **agrupación
    organizativa interna**: se juntan varios grupos —a veces todos los del MIC
    en uno, a veces media etapa del COM en uno y la otra media en otro— para
    **ponerles un coordinador y que esa gente programe junta las actividades**.
    Nada más. (Explicado por el propietario el 10/09/2026.)
  - **No es la etapa** (eso es `level`, MIC/COM/LC) **y no es el nivel personal**
    (`ajmcm_nivel_com_c` en Personas, el itinerario de cada chaval: I
    Conocimiento, II Incorporación, III Crecimiento, IV Opción Responsable). Son
    tres ejes distintos y confundirlos es fácil:

    ```
    level (grupo)      → de qué etapa es el grupo: MIC / COM / LC
    segmento (grupo)   → con quién se coordina y programa ese grupo
    nivel (persona)    → por dónde va cada chaval en su itinerario
    ```
  - **Valores EN USO** (leídos del CRM el 10/09/2026, sobre 105 grupos):
    `com_1` (7 grupos) · `com_2` (3) · `com_3` (4) · **sin valor: 91**. Que la
    inmensa mayoría esté vacío es normal: solo se rellena donde hay una
    agrupación montada.
  - ⚠️ **Estas son las claves OBSERVADAS, no el desplegable entero**: el MCP de
    este CRM no devuelve las opciones de los `enum` (`get_module_fields` da solo
    `name`, `type`, `len`, `custom`). Si hace falta una clave que no esté aquí,
    míralas en el CRM y apúntalas — no te las inventes. Las **etiquetas**
    legibles tampoco se pueden leer por API; el área privada enseña la clave con
    los guiones bajos en espacios (`com_2` → «COM 2»), así que si se quiere que
    se lea «COM II» hay que documentar aquí la etiqueta real.
  - Lo usa Pasar Lista para el **alcance de coordinación**: quien coordina con
    segmento ve solo los grupos de su segmento; sin segmento, toda su etapa o
    toda la delegación (`sticpa_pl_coord_scope()`, y la frase que lo dice en
    pantalla sale de `sticpa_pl_coord_scope_label()`).
  - El nombre lleva `_com_` porque nació pensado para el COM, pero la idea vale
    para cualquier etapa. Si algún día se agrupan grupos del MIC, **el campo
    sirve igual** (es texto libre por dentro): no se crea otro.

Los demás campos del módulo (`code`, `name`, `level`, `cursos_c` y los recuentos
nocturnos `ajmcm_n_participantes_c` / `ajmcm_n_monitores_c` / `ajmcm_monitores_c`
/ `ajmcm_recuento_al_c`) están en
[`PASAR-LISTA-CAMPOS-CRM.md`](PASAR-LISTA-CAMPOS-CRM.md) §3.

### Relaciones con personas (`stic_Contacts_Relationships`)

- `ajmcm_curso_escolar_c` — Curso escolar (desplegable)
  - ⚠️ **CORRECCIÓN DEL 09/09/2026, y es importante.** Este documento decía que
    el campo estaba «VACÍO en todas las relaciones reales» (28/08/2026) y que
    guardaría el curso escolar tipo `2024_2025`. Las dos cosas eran falsas:
    - **Ya está relleno** en muchas relaciones (comprobado por MCP el
      09/09/2026).
    - **No guarda el año académico, guarda el NIVEL ESCOLAR.**
  - **Desplegable `ajmcm_curso_escolar_c_list`, DOMINIO COMPLETO** (leído en
    Studio el 10/09/2026, no observado en datos — esta lista sí es exhaustiva):

    | Clave | Etiqueta |
    |---|---|
    | *(vacío)* | -vacío- |
    | `3_primaria` | 3º |
    | `4_primaria` | 4º |
    | `5_primaria` | 5º |
    | `6_primaria` | 6º |
    | `1_eso` | 1º ESO |
    | `2_eso` | 2º ESO |
    | `3_eso` | 3º ESO |
    | `4_eso` | 4º ESO |
    | `1_bachillerato` | 1º Bach |
    | `2_bachillerato` | 2º Bach |
    | `fp_gm` | FP GM |
    | `fp_gs` | FP GS |
    | `universitario` | Universitario |
    | `otros` | Otros |
    | `na` | NA |

  - ⚠️ **`na` NO es un curso: es «no aplica».** Para el filtro de audiencia de
    los eventos cuenta como *no tener curso*, no como un curso que no casa con
    ninguno — si contara como curso, una persona marcada `na` quedaría fuera de
    cualquier evento que restrinja cursos. `otros` sí es un curso (uno que no
    está en la lista), y casa solo con `otros`.
  - **No hay bug que arreglar en Pasar Lista**: lo que allí se deduce de
    `start_date`/`end_date` es el AÑO académico (`2025-2026`), que es otra cosa
    y sigue sin vivir en ningún campo. Son dos ejes:
    ```
    Año académico  →  2025-2026  ·  se deduce de las fechas de la relación
    Nivel escolar  →  1_eso      ·  ajmcm_curso_escolar_c
    ```
  - ⚠️ **TRAMPA CON LAS RELACIONES DE MONITOR.** En una relación de tipo
    `monitor` este campo lleva el curso **del grupo que lleva**, no el de la
    persona: hay monitoras adultas con `5_primaria`. Quien lea este campo como
    «el curso de esta persona» tiene que **quedarse solo con las relaciones de
    participante** (`participante_mic_com` y `grupo`). Lo hace
    `sticpa_viewer_audience()` (`inc/stic-event-audience.php`).
  - ⚠️ **`stic_Events.ajmcm_filtro_edades_c` USA ESTE MISMO DESPLEGABLE**
    (`ajmcm_curso_escolar_c_list`), no una copia. Por eso el filtro de «a qué
    cursos va dirigido este evento» compara clave con clave y **no pueden
    divergir nunca**: quien añada un curso aquí lo añade en los dos sitios a la
    vez. Es la razón de que no haya nada que sincronizar. Ver §1 → Eventos.
- `ajmcm_delegacion_c` — Delegación de la relación (desplegable)
  - ⚠️ Sus claves NO son las de `ajmcm_procendencia_c` de personas: aquí
    `vilareal`, allí `vila-real`. Dos enums de delegación con formatos distintos.
- `end_reason` / `other_end_reasons` — Motivo del fin de la relación
  - Existen, sin documentar y sin usar.

El resto de campos de este módulo (`relationship_type` y sus valores,
`start_date`, `end_date`, `active`, el vínculo con el grupo) están en
[`PASAR-LISTA-CAMPOS-CRM.md`](PASAR-LISTA-CAMPOS-CRM.md) §3.

Claves de `relationship_type` observadas en el CRM (09/09/2026):
`participante_mic_com`, `grupo`, `monitor`, `coordinacion_mic_com`,
`acompanamiento_mic_com`, `familiar_menor`. Son las mismas que las de
`Contacts.stic_relationship_type_c` (inventario contado en §2), pero **son dos
campos distintos**: aquí describen UNA relación con un grupo; allí, a la
persona entera.

### Eventos (`stic_Events`)

El módulo tiene 59 campos. Aquí van los que usa el área privada y, sobre todo,
**los que deciden QUIÉN PUEDE APUNTARSE**, que es la parte que se malinterpreta:
los grupos de seguridad del CRM **no** filtran nada de lo que se ve en el área
privada, porque el plugin se conecta con un usuario técnico y no con la persona
que ha entrado. Todo lo que no filtre el plugin, se ve. La lógica está en
[`inc/stic-event-audience.php`](../../inc/stic-event-audience.php) y el diseño
funcional en [`EVENTOS.md`](EVENTOS.md) §5.

**Los tres ejes de la audiencia**

- `assigned_user_id` — Asignado a → **la delegación dueña del evento**
  - Es lo que separa el evento local del de otra delegación. Un evento asignado
    al «Administrador MCM» (id `1`) o sin asignar se entiende como de todas.
- `ajmcm_ambito_c` — Ámbito ✅ **creado** (`enum`, verificado el 10/09/2026; ficha en `EVENTOS.md` §4.2)
  - ⚠️ **SUS CLAVES NO ESTÁN CONFIRMADAS.** El campo existe, pero nadie ha
    leído sus opciones en Studio. Lo de abajo es **lo que espera el código**,
    no lo que se ha comprobado que hay. Ver §⏳ punto 2: si no casan, el evento
    se trata como local **sin dar ningún error**.
  - Desplegable que espera el código: `local` [Solo su delegación] ·
    `nacional` [Todas las delegaciones]. También se aceptan `todas` e
    `interdelegacional` como sinónimos de `nacional`.
  - Si está vacío, el ámbito se deduce de `assigned_user_id` (con delegación =
    local; sin delegación = nacional), así que el campo es opcional: sirve para
    decir «este evento es de Castellón y AUN ASÍ es para todas».
- `ajmcm_dirigido_a_c` — Dirigido a ✅ **creado** (ficha en `EVENTOS.md` §4.1)
  - **Selección múltiple (`multienum`)**, verificado el 25/09/2026 (se creó
    simple el 10/09 y se cambió después en Studio). Un evento puede ser de
    monitores Y de coordinación a la vez.
  - ⚠️ **SUS CLAVES NO ESTÁN CONFIRMADAS**, igual que en `ajmcm_ambito_c`: la
    tabla de abajo es **lo que espera el código**, no lo leído en Studio. Ver
    §⏳ punto 1 — si no casan, el evento **se esconde a todo el mundo** y no
    salta ningún error.
  - **Las claves deberían ser literalmente las de `relationship_type`** (ver el
    inventario de §2), para no mantener dos vocabularios que dicen lo mismo:

    | Clave que espera el código | Etiqueta |
    |---|---|
    | `grupo` | Miembros del MCM (con grupo) |
    | `monitor` | Monitores/as |
    | `participante_mic_com` | Participantes de MIC y COM |
    | `coordinacion` | Equipo de coordinación |
    | `familiar_menor` | Familias |

  - Vacío = **para todos los perfiles**. Es el eje de «congreso solo para
    monitores» y de «evento para miembros COM-LC» — que se dice con `grupo`,
    porque **no existe una clave «laico»** (§2). `coordinacion` es el único
    agrupador: cubre `coordinacion_mic_com` y `acompanamiento_mic_com`. El mapa
    vive en `sticpa_event_audience_perfil_map()`.
- `ajmcm_filtro_edades_c` — Cursos a los que va dirigido ✅ **YA EXISTE, NADA QUE TOCAR**
  - Selección **múltiple**, y usa **el mismo desplegable
    `ajmcm_curso_escolar_c_list` que el campo de la persona** (§1 → Relaciones
    con Personas). No es una copia: es la misma lista, así que los dos lados
    del filtro **no pueden divergir nunca** y no hay nada que sincronizar.
    Dominio completo, allí.
  - **No había que crearlo ni ampliarlo**: estaba creado y relleno (las
    sesiones semanales del MIC llevan `^4_primaria^,^5_primaria^,^6_primaria^`
    y las del COM de 1.º de la ESO a 2.º de bachillerato). Lo único que
    faltaba era que el área privada lo mirara.
  - Vacío = para todos los cursos. Y **solo estrecha entre participantes**:
    a quien no tiene curso propio (un monitor, una madre, o alguien marcado
    `na`) no se le aplica, o los monitores del MIC se quedarían fuera de las
    sesiones del MIC.

**El lugar — tres campos ✅ CREADOS** el 10/09/2026 (fichas completas en
[`EVENTOS.md`](EVENTOS.md) §5.3). No existen `location`, `city` ni `address`:

- `ajmcm_lugar_c` — Lugar (`varchar(255)`)
  - El nombre corto del sitio: «Casa de Espiritualidad, Benigànim». Sale en la
    **tarjeta del listado** y en la ficha, así que tiene que caber en una línea
    de móvil. Es el que hay que rellenar siempre.
- `ajmcm_direccion_c` — Dirección (`varchar(255)`)
  - La dirección completa. Solo en la ficha, y es lo que se le manda al mapa
    (más preciso que el nombre).
- `ajmcm_mapa_c` — Enlace del mapa (tipo `url`, 255) — **opcional de verdad**
  - **El botón del mapa no lo necesita**: con `ajmcm_lugar_c` ya se arma una
    búsqueda de Google Maps y el botón funciona desde el primer día. Este campo
    es el arreglo para cuando la búsqueda no acierta (de «Casa de
    Espiritualidad» hay unas cuantas) o cuando ya se tiene el enlace bueno.
  - ⚠️ **Solo `http` y `https`.** Lo rellena una persona en el CRM, así que un
    `javascript:…` acabaría en un enlace que pulsa una familia. Un esquema que
    no valga se descarta y se cae a la búsqueda.

**La web del evento — los campos `web_*_c`** (creados en Studio el
20/09/2026; tipos verificados por MCP el 24/09/2026). ⚠️ **Van SIN el prefijo
`ajmcm_`**, a diferencia del resto de campos propios del módulo: así se crearon
y así se quedan. Los lee la página pública (`/actividades`, repo
comunicaFormularios), el modal de la convivencia de los formularios y, desde el
24/09/2026, la ficha del evento del área privada. Las etiquetas de Studio no
las devuelve la API: si las necesitas, míralas allí.

| Campo | Tipo | Para qué |
|---|---|---|
| `web_publicar_c` | casilla (por defecto `0`) | El interruptor de la **página pública**. Apagada, el evento no tiene página en `/actividades`; su texto sí se ve en el área privada y en el modal de la convivencia |
| `web_url_c` | URL | A dónde lleva «Inscribirme» en la página pública. Vacío → «Entrar al área privada». ⚠️ Vacío llega como `http://` (SuiteCRM): se trata como vacío |
| `web_cuerpo_html_c` | **WYSIWYG** (editor TinyMCE) — ✅ creado; verificado por MCP el 25/09/2026 (tipo `wysiwyg`, relleno en tres eventos) | **El cuerpo**: HTML del editor. Se lee con lista blanca (`inc/eventos-cuerpo.php`); estilos y formato de Word se ignoran. Lo guarda CODIFICADO (`&lt;p&gt;`). **No es sitio para borradores**: es público aunque el evento no esté publicado. El nombre vive en `mcm_cuerpo_campo()` |
| `web_cuerpo_c` | texto largo | ⚠️ **RETIRADO** (Markdown). Nadie lo lee desde el cutover; se borra en Studio cuando esté mezclado el cambio |
| `web_cartel_c` | URL | La imagen de cabecera, si no se sube al evento. Vacío = `http://` (ver arriba) |
| `web_lema_c` | texto (255) | El subtítulo bajo el título («Sin Rodeos: Soy Consolación») |
| `web_slug_c` | texto (255) | La URL bonita (`?e=convivencia26-cs-com`). Vacío → se saca del nombre |

**Preguntas simples — ⏳ PROPUESTOS, NO CREADOS** (25/09/2026, TODO EV-6). Para
que un evento sí/no con una o dos preguntas no necesite un formulario web
avanzado. **Hasta que se creen, el área no los pide** (cruza cada nombre con la
definición del CRM). Si se crean con otro nombre, apúntalo aquí y cámbialo en
`sticpa_event_question_fields()`. Formato y comportamiento en `EVENTOS.md` §10.1.

| Módulo | Campo propuesto | Tipo | Para qué |
|---|---|---|---|
| `stic_Events` | `ajmcm_pregunta_1_c` | texto (255) | «Pregunta simple 1»: `¿Pregunta? \| opción 1; opción 2`. La pregunta (antes de la `\|`) es opcional; opciones separadas por `;`, dos como mínimo |
| `stic_Events` | `ajmcm_pregunta_2_c` | texto (255) | «Pregunta simple 2», igual |
| `stic_Registrations` | `ajmcm_respuesta_1_c` | texto (255) | La respuesta a la 1: el TEXTO de la opción elegida (no un número), para leerla en el CRM sin ir al evento |
| `stic_Registrations` | `ajmcm_respuesta_2_c` | texto (255) | La respuesta a la 2 |

Van por parejas y en campos separados (no todo junto en un texto largo) para que
en el CRM se pueda filtrar y contar («¿cuántos van en autobús?»).

Los **documentos** del evento cuelgan de la relación `stic_events_documents_1`
(nombre técnico; la API rechaza la etiqueta «Documents»). La primera imagen es
el cartel si no hay otro, las demás van a galería y los PDF a descargar. Solo
PDF, JPG, PNG, GIF y WEBP (sin SVG).

⚠️ **Los textos llegan con entidades HTML** (`&quot;`, `&#039;`, `&gt;`): así los
guarda SuiteCRM. Quien los pinte tiene que deshacerlas antes de escapar, o sale
«&quot;» en pantalla (`mcm_cuerpo_normalizar()`).

⚠️ **NO se filtra por `ajmcm_etapa_c` del evento** (multienum `MIC`/`COM`/`LC`,
obligatorio) aunque parezca el candidato natural. Ese campo dice a qué etapas
**sirve el evento en Pasar Lista** —un sábado marcado `^MIC^,^COM^` comparte
sesiones—, no a quién se le ofrece: un congreso de monitores marcado `^COM^`
dejaría fuera a los monitores del MIC. Un campo, un significado.

⚠️ **`target_audience` existe y NO lo usamos.** Es un enum de SinergiaCRM, de
selección simple y con su propio dominio, y está vacío en todos los eventos.
Se deja quieto: no se le mete nuestro vocabulario a un campo ajeno, y con uno
solo no se puede decir «monitores Y coordinación». Anotado para que nadie cree
otro campo pensando que este estaba libre.

**Lo demás del módulo que ya existe** (y que corrige lo que `EVENTOS.md` pedía
crear: varios estaban creados con otro nombre). Comprobado el 09/09/2026:

| Campo | Tipo | Notas |
|---|---|---|
| `name` | texto | Obligatorio |
| `start_date` / `end_date` | fecha | Solo fecha, sin hora |
| `status` | desplegable | Valor visto: `registration` |
| `type` | desplegable | Valor visto: `working_day` |
| `description` | texto largo | |
| `max_attendees` | entero | **Es el «aforo»**; no existe `capacity`. ⚠️ Vale `0` en los cinco eventos: es el valor por defecto de SuiteCRM, no «cero plazas». El área no lo enseña cuando es 0 |
| `price` | decimal | ⚠️ Igual: `0.00` en los cinco. Un 0 no se enseña — no es «gratis», es «sin rellenar» |
| `ajmcm_start_inscripcion_c` / `ajmcm_end_inscripcion_c` | fecha | **La ventana de inscripción**; no existe `registration_end`. Fuera de plazo el área NO deja apuntarse (`EVENTOS.md` §5.2). Vacío = abierta |
| `timetable` | texto | El horario, en texto libre; no hay campo de hora |
| `stic_events_fp_event_locations` | relación | El módulo de ubicaciones del CRM. **Decidido NO usarlo**: para los eventos que hay, mantener un catálogo de sitios es más trabajo del que ahorra. El lugar va en los tres campos de texto de abajo |
| `ajmcm_etapa_c` | selección múltiple | `MIC` · `COM` · `LC`. Para Pasar Lista, **no** para la audiencia |
| `attendees`, `total_hours`, `budget`, `actual_cost`… | varios | Gestión, no se usan en el área |
| `stic_events_fp_event_locations` | relación | **El lugar es una relación a un módulo de ubicaciones**, no un texto: no existen `location`, `city` ni `address` |

### Campos que existían y no estaban aquí (añadidos el 25/09/2026)

Salen de comparar este documento con el CRM por MCP (`get_module_fields`) y con
el código. **Todos existen y casi todos los usa ya el área privada**; faltaban
aquí, no en el CRM. Los tipos son los que devuelve el CRM. Las claves de los
`enum` siguen sin leerse (el MCP no las devuelve): donde no se dice nada, **no
se conocen** — no te las inventes.

**Personas (`Contacts`)**

| Campo | Tipo | Qué es |
|---|---|---|
| `ajmcm_mcm_desde_c` | `date` | En el MCM desde. Como `ajmcm_monitor_desde_c`: solo cuenta el año (`yearOnly`, se guarda `AAAA-01-01`) |
| `ajmcm_ano_incorporacion_lc_c` | `int` | Año de incorporación a LC |
| `ajmcm_pa_token_c` | `varchar(255)` | El token del enlace mágico de acceso (`inc/stic-magic-login.php`, `docs/ACCESO.md`). ⚠️ Es una credencial: nunca se pinta ni se escribe desde un formulario (`sticpa_request_to_module_data()` lo bloquea) |
| `ajmcm_pa_portal_url_c` | `url` | URL del portal. Sin usar en el código |
| `stic_pa_username_c`, `stic_pa_password_c` | `varchar(255)` | Usuario y contraseña del portal de SinergiaCRM. Credenciales: mismas reglas que el token |
| `stic_pa_enable_c` | `bool` | Portal activado. Sin usar en el código |
| `ajmcm_id_persona_c` | `varchar(255)` | Sin usar en el código. No confundir con `ajmcm_numero_persona_c` (Nº Registro) |
| `ajmcm_acompanante_c` | `varchar(100)` | Sin usar en el código |
| `actualizado_c` | `bool` | Sin usar en el código |
| `stic_tax_name_c` | `varchar(255)` | De SinergiaCRM. Sin usar |
| `stic_occupational_safety_c` | `bool` | De SinergiaCRM. Sin usar |
| `stic_incorpora_locations_id_c` | `id` | Integración Incorpora. Sin usar |

Y **unos 40 campos de integraciones que no usamos**, anotados solo para que
nadie cree uno igual: `inc_*` (34, programa Incorpora / SEPE), `sepe_*` (4) y
`jjwg_maps_*` (4, geocodificación de direcciones).

**Inscripciones (`stic_Registrations`)** — los lee `inc/stic-registrations.php`

> `status`: el área escribe `confirmed` (al inscribirse) y `cancelled` (al
> cancelar desde la ficha, `EVENTOS.md` §10.3), y trata `cancelled` como «no
> cuenta» en todas partes. **Sin confirmar en Studio**; por eso `cancelled`
> solo se escribe si aparece en las opciones que devuelve la definición del
> CRM. En datos se ha visto también `uninvited` (inscripciones de prueba).

| Campo | Tipo | Qué es |
|---|---|---|
| `ajmcm_clase_c` | `enum` | Clase del/de la participante en la inscripción |
| `ajmcm_curso_escolar_c` | `enum` | Curso escolar (misma lista que en las relaciones, ver abajo) |
| `ajmcm_registration_amount_c` | `decimal(6)` | Importe de la inscripción |
| `ajmcm_convivencia_c` | `enum` | Si va a la convivencia |
| `ajmcm_convivencia_fecha_c` | `date` | Fecha de la convivencia |
| `ajmcm_convivencia_precio_c` | `decimal(6)` | Precio de la convivencia |
| `ajmcm_convivencia_event_id_c` | `varchar(48)` | Id del evento de la convivencia |
| `ajmcm_eventid_c` | `varchar(48)` | Id del evento. ⚠️ **Vacío en los registros reales**: el que vale es el `_ida` del enlace con el evento |
| `ajmcm_tutor1_firstname_c` / `_lastname_c` | `varchar(60)` / `varchar(140)` | Nombre y apellidos del tutor/a 1 |
| `ajmcm_tutor1_relationship_c` | `enum` | Parentesco: `father` / `mother` / `legal` (confirmadas, las escribe el formulario de altas). **No es el vocabulario del entorno personal** |
| `ajmcm_tutor1_phone_c` | `varchar(10)` | Teléfono |
| `ajmcm_tutor1_email_c` | `varchar(48)` | Correo |
| `ajmcm_tutor1_dni_c` | `varchar(14)` | DNI |
| `ajmcm_tutor1_iban_c` | `varchar(40)` | IBAN (dato bancario: se enmascara al pintarlo) |
| `ajmcm_tutor2_*` | ídem | Lo mismo para el tutor/a 2, **sin IBAN** |

**Relaciones con personas (`stic_Contacts_Relationships`)** — la etapa también
está en [`PASAR-LISTA-CAMPOS-CRM.md`](PASAR-LISTA-CAMPOS-CRM.md)

| Campo | Tipo | Qué es |
|---|---|---|
| `ajmcm_clase_c` | `enum` | Clase de esa relación |
| `ajmcm_etapa_relacion_c` | `enum` | Etapa de esa relación (`MIC` / `COM` / `LC` en el código) |

**Avisos (`AVI_avisos`)** — los escribe Pasar Lista; ficha en
[`PASAR-LISTA-CAMPOS-CRM.md`](PASAR-LISTA-CAMPOS-CRM.md)

| Campo | Tipo | Qué es |
|---|---|---|
| `ajmcm_notificado_el_c` | `date` | Cuándo se notificó |
| `ajmcm_notificado_familia_c` | `bool` | Si se notificó a la familia |
| `ajmcm_puesto_por_c` | `relate` | Quién puso el aviso |
| `ajmcm_sesion_c` | `relate` | Sesión del aviso (su id plano, `stic_sessions_id_c`) |
| `contact_id_c` | `id` | Id plano de la persona (se repite igual en `stic_Sessions` y `stic_FollowUps`) |

---

## 2. Campos incluidos por SinergiaCRM

*Se indica en "Usado por nosotros" si lo utilizamos o no.*
Fuente: https://wiki.sinergiatic.org/index.php?title=Estructura_de_datos:_m%C3%B3dulos_y_campos#Personas

- `stic_age_c` — Edad
  - Tipo: entero. Se calcula automáticamente a partir de la fecha de nacimiento; útil en filtros e informes.
  - Usado por nosotros: **Sí**
- `photo` — Fotografía
  - Tipo: imagen (subida de archivo)
  - Usado por nosotros: **Sí**
- `stic_gender_c` — Género (desplegable: — / Hombre / Mujer)
  - Usado por nosotros: **Sí**
- `stic_identification_type_c` — Tipo de identificación (desplegable: NIF / NIE / CIF / Pasaporte)
  - Usado por nosotros: **Sí**
- `stic_identification_number_c` — Número de identificación (texto)
  - Usado por nosotros: **Sí**
- `stic_relationship_type_c` — Tipo de relación actual (selección múltiple: Socio / Donante / Voluntario / Usuario / Trabajador / …)
  - Usado por nosotros: **Sí**
  - **Claves internas vistas en el CRM real** (08/09/2026, por MCP). El campo es
    multiselección y llega en formato SuiteCRM, `^clave^,^clave^`:

    **Inventario COMPLETO**, contado sobre los 256 contactos del CRM el
    09/09/2026 (la API no devuelve las etiquetas del desplegable, solo las
    claves, así que esto es lo que hay):

    | Clave | Personas | Qué significa |
    |---|---|---|
    | `grupo` | 236 | Tiene grupo: **es miembro del MCM**. Sea del COM o laico, da igual: la ficha es la misma |
    | `monitor` | 150 | Además es monitor/a, y añade sus campos de formación |
    | `familiar_menor` | 1 | Familiar de un participante menor. **Es la única marca que, ella sola, NO te hace miembro del MCM** |
    | `participante_mic_com` | 1 | Participante de MIC/COM. Los participantes menores llevan SOLO esta, sin `grupo` |
    | `acompanamiento_mic_com` | 1 | Acompañamiento del equipo |
    | `coordinacion_mic_com` | 1 | Coordinación |

    (8 contactos tienen el campo vacío.)

    **NO EXISTE UN TIPO «LAICO».** Ser del MCM es tener `grupo`; encima puedes
    ser monitor o no. Esto importa porque el área tuvo durante meses un rol
    'laico' que buscaba `com-lc`, `laic` y `grupo com` — tres cadenas que no
    existen aquí — y que por tanto **no se disparaba jamás**.
  - **La API devuelve CLAVES, no etiquetas.** Llega `^grupo^,^monitor^`, no
    `^Monitor/a^,^Grupo COM-LC^`. El mapa de roles se escribió contra etiquetas
    y por eso 'monitor' acertaba de casualidad (la clave contiene la palabra) y
    'laico' fallaba siempre. `sticpa_detect_role_from_relationship()` compara
    ahora por **clave exacta**, y solo cae a la subcadena si el valor parece una
    etiqueta antigua (lleva espacios o barras).
  - **Solo hay UN rol: `monitor`.** Todo el que tiene `grupo` es miembro y
    rellena la misma ficha; el monitor añade la suya. **Ser MIEMBRO no es un
    rol**: lo resuelve `sticpa_es_miembro_por_tipo_de_relacion()`
    (`inc/stic-family.php`), que pregunta si hay algo aparte de las marcas de
    «solo familiar». Confundir las dos cosas dejó a una madre con
    `^familiar_menor^,^grupo^` sin poder ver a su hija (08/09/2026), y tuvo a 86
    miembros sin ver su propia sección de MCM (09/09/2026).
- `stic_conduct_code_c` — Código de conducta
  - Tipo: casilla. Verificado contra el CRM el 28/08/2026: **existe y estaba sin
    documentar aquí**. El área privada lo enseña en el bloque «En regla» de la
    ficha del monitor, como una de las obligaciones que hay que tener firmadas.
  - Usado por nosotros: **Sí** (solo lectura)
- `stic_confidentiality_agreement_c` — Acuerdo de confidencialidad
  - Tipo: casilla. Igual que el anterior: existe, estaba sin documentar, y se
    enseña en «En regla».
  - Usado por nosotros: **Sí** (solo lectura)
- `stic_time_availability_c` — Disponibilidad horaria
  - Tipo: `varchar(255)`. **Vacío en TODOS los contactos** (25/09/2026). Anotado
    el 28/08/2026 para que no se cree otro campo igual sin querer.
  - Usado por nosotros: **No, por ahora**
- `stic_total_annual_donations_c` — Donación total anual (`decimal(26)`)
  - Nota: útil para informes o certificados de donación tras generar el Modelo 182.
  - Usado por nosotros: **Por ahora no**

---

## 3. Campos por defecto de SuiteCRM utilizados en Sinergia CRM

Fuente: https://wiki.sinergiatic.org/index.php?title=Estructura_de_datos:_m%C3%B3dulos_y_campos#Personas
*(Módulo original: SuiteCRM → `Contacts`)*

- `assigned_user_id` — Asignado a
  - Tipo: relacionado. Id del usuario de la instancia asignado al contacto.
  - Usado por nosotros: **Sí**, se asigna al usuario de cada MCM Local.
- `assigned_user_name` — Asignado a (nombre)
  - Tipo: link. Muestra el nombre del usuario asignado en las vistas del módulo.
  - Usado por nosotros: **Sí**, se asigna al usuario de cada MCM Local.
- `birthdate` — Fecha de nacimiento (dd/mm/aaaa)
  - Usado por nosotros: **Sí**
- `phone_mobile` — Móvil
  - Tipo: teléfono. **Es EL móvil de la persona** (participante, familiar o
    monitor/a): de aquí salen los botones de llamar y de WhatsApp del área
    privada. Verificado contra el CRM el 27/08/2026 y otra vez el 28/08/2026.
  - Usado por nosotros: **Sí**. Estuvo listado como «no usar» en la §4 y por eso
    la ficha del participante salió meses sin teléfonos.
- `phone_other` — Otro teléfono
  - Tipo: teléfono. En la práctica es el **contacto de emergencias** y suele ser
    un fijo, así que el área privada le ofrece llamar pero no WhatsApp.
  - Usado por nosotros: **Sí**
- `do_not_call` — No llamar
  - Tipo: casilla. Si está marcada, el área privada lo dice arriba en la ficha,
    junto a los botones de llamar: es lo único que cambia lo que se puede hacer
    con ellos.
  - Usado por nosotros: **Sí** (solo lectura, no lo escribe el área)
- `date_reviewed` — Fecha de la base legal revisada
  - Tipo: fecha. Se actualiza automáticamente al modificar los campos de "lawful basis" del contacto.
  - Usado por nosotros: **Sí**
- `deleted` — Eliminado
  - Tipo: casilla de verificación. Marca si el contacto ha sido eliminado o no; lo gestiona SinergiaCRM automáticamente.
  - Usado por nosotros: **Sí**
- `do_not_call` — No llamar
  - Tipo: casilla de verificación. Indica si se puede llamar o no al contacto.
  - Usado por nosotros: **Sí**
- `email1` — Correo electrónico
  - Tipo: `varchar` (la API lo devuelve así; el de tipo `email` es otro campo, `email`).
  - Usado por nosotros: **Sí**
- `first_name` — Nombre
  - Usado por nosotros: **Sí**
- `last_name` — Apellidos
  - Usado por nosotros: **Sí**
- `phone_other` — Tel. alternativo
  - Usado por nosotros: **Sí**, como "Contacto de emergencias"
- `primary_address_city` — Dirección principal - Población
  - Usado por nosotros: **Sí**
- `primary_address_country` — Dirección principal - País
  - Usado por nosotros: **Sí**, automático (España)
- `primary_address_postalcode` — Dirección principal - Código postal
  - Usado por nosotros: **Sí**
- `primary_address_state` — Dirección principal - Provincia (desplegable: Álava / Albacete / Alicante / Almería / Asturias / …)
  - Usado por nosotros: **Sí**
- `primary_address_street` — Dirección principal - Calle
  - Usado por nosotros: **Sí**

---

## 4. Campos no usados en nuestra adaptación

- `modified_user_id` — Modificado por
  - Tipo: relacionado. Id del usuario que modifica el contacto; lo rellena SinergiaCRM por defecto.
  - Usado por nosotros: Automático
- `modified_by_name` — Modificado por (nombre)
  - Tipo: link. Muestra el nombre del usuario que ha modificado el contacto.
  - Usado por nosotros: Automático
- `phone_fax` — Fax — No usar
- `phone_home` — Tel. casa — No usar
- `phone_work` — Tel. oficina — No usar
  - ⚠️ Ojo: `phone_mobile` **sí se usa** y ha subido a la §3. Estaba aquí como
    «no usar» y es el móvil de verdad de participantes, familiares y monitores:
    de ahí salen los botones de llamar y de WhatsApp del área privada.
- `salutation` — Saludo (desplegable: Sr. / Srta. / Sra. / Dr. / Prof.) — No
- `stic_182_error_c` — Error del Modelo 182 — No
- `stic_182_excluded_c` — Excluir del Modelo 182 — No (antes aquí ponía `exluded`, errata del documento; el CRM lo escribe bien)
- `title` — Puesto de trabajo — No
- `lawful_basis` — Base legal, **selección múltiple** (`multienum`, por defecto `^consent^`) (Consentimiento / Contrato / Obligación legal / Protección del interés / Retirado / …) — No, por ahora
- `lawful_basis_source` — Fuente de la base legal (Sitio web / Teléfono / Dado al usuario / …) — No, por ahora
- `lead_source` — Toma de contacto (Campaña / Llamada en frío / Conferencia / Correo directo / Email / …) — No
- `department` — Departamento — No
- `email_opt_out` — Rehusar email — No
- `description` — Descripción (área de texto) — No
- `campaign_name` — Campaña (`relate`, no texto) — No
- `created_by` — Creado por
  - Tipo: relacionado. Lo rellena SinergiaCRM por defecto.
  - Usado por nosotros: Automático
- `created_by_name` — Creado por (nombre)
  - Tipo: Linkeado de forma automática 
- `current_user_only` — Mis elementos — campo de búsqueda que filtra solo los registros asignados al usuario activo — No
  - No es un campo guardado: solo existe en el buscador de SuiteCRM, la API no lo devuelve.
- `date_entered` — Fecha de creación — Automático
- `date_modified` — Fecha de modificación — Automático
- `account_name` — Organización — No
- `alt_address_city` — Dirección alternativa - Población — No
- `alt_address_country` — Dirección alternativa - País — No
- `alt_address_postalcode` — Dirección alternativa - Código postal — No
- `alt_address_state` — Dirección alternativa - Provincia (`enum`, no texto) — No
- `alt_address_street` — Dirección alternativa - Calle
  - Descripción: campo que recoge otra dirección alternativa.
  - Usado por nosotros: No
- `stic_alt_address_type_c` — Dirección alternativa - Tipo (Particular / Trabajo / Residencia / Otros) — ⚠️ **NO EXISTE en esta instancia** (verificado el 25/09/2026)
- `stic_professional_sector_c` — Sector profesional (Legal / Administración Pública / Informática / …) — ⚠️ **NO EXISTE en esta instancia** (verificado el 25/09/2026)
- `stic_professional_sector_other_c` — Otros sectores profesionales (solo aparece si en el anterior se elige "Otros") — ⚠️ **NO EXISTE en esta instancia** (verificado el 25/09/2026)
- `stic_language_c` — Idioma (Castellano / Catalán) — No
- `stic_postal_mail_return_reason_c` — Motivo de devolución del correo postal (Dirección incorrecta / Desconocido / Fallecido / Rechazado / Ausente) — ⚠️ **NO EXISTE en esta instancia** (verificado el 25/09/2026)
- `stic_do_not_send_postal_mail_c` — No enviar correo postal — ⚠️ **NO EXISTE en esta instancia** (verificado el 25/09/2026)
- `stic_acquisition_channel_c` — Canal de adquisición (F2F / Mail / Postal / Web / Móvil / Telemarketing / Evento / Otros) — ⚠️ **NO EXISTE en esta instancia** (verificado el 25/09/2026)
- `stic_preferred_contact_channel_c` — Canal de contacto favorito (Teléfono fijo / Teléfono móvil / Correo electrónico / Correo postal) — No
- `stic_alt_address_region_c` — Dirección alternativa - Comunidad autónoma — ⚠️ **NO EXISTE en esta instancia** (verificado el 25/09/2026)
- `stic_primary_address_region_c` — Dirección principal - Comunidad autónoma — No
  - Valores (aplican a ambos campos anteriores): andalucia [Andalucía], aragon [Aragón], canarias [Canarias], cantabria [Cantabria], castilla_leon [Castilla y León], castilla_mancha [Castilla-La Mancha], catalunya [Cataluña], madrid [Comunidad de Madrid], navarra [Comunidad Foral de Navarra], valencia [Comunitat Valenciana], extremadura [Extremadura], galicia [Galicia], baleares [Illes Balears], rioja [La Rioja], pais_vasco [País Vasco], asturias [Principado de Asturias], murcia [Región de Murcia], ceuta [Ciudad Autónoma de Ceuta], melilla [Ciudad Autónoma de Melilla]
- `stic_alt_address_county_c` — Dirección alternativa - Comarca (Alt Camp / Alt Empordà / Alt Penedès / Alt Urgell / Alta Ribagorça / …) — ⚠️ **NO EXISTE en esta instancia** (verificado el 25/09/2026)
- `stic_primary_address_county_c` — Dirección principal - Comarca (mismo listado de ejemplo que el anterior) — ⚠️ **NO EXISTE en esta instancia** (verificado el 25/09/2026)
- `stic_referral_agent_c` — Agente derivador (Servicios sociales / Servicios sanitarios / Familia / Propia iniciativa) — ⚠️ **NO EXISTE en esta instancia** (verificado el 25/09/2026)
- `stic_employment_status_c` — Situación profesional (Autónomo / Por cuenta ajena / Parado / Estudiante / Jubilado) — ⚠️ **NO EXISTE en esta instancia** (verificado el 25/09/2026)
- `stic_primary_address_type_c` — Dirección principal - Tipo (Particular / Trabajo / Residencia / Otros) — No

---

## ⚠️ Dudas / posibles erratas a confirmar


7. Grafías con influencia catalana/valenciana en el texto original ("instància", "Província", "electrònico") las he normalizado al castellano ("instancia", "provincia", "electrónico"), ya que el resto del documento está en castellano. Dime si preferías mantener la grafía valenciana en este documento en concreto.
8. `created_by_name` tenía "Tipo de campo: LinAutomático", que era claramente un error de copy-paste — lo he separado en "Link" (tipo) y "Automático" (uso).
