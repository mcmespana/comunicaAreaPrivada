# Campos del CRM — resumen para agentes · MCM

<!-- formato: campos-resumen/1 · instancia: MCM (Comunica) · revisado: 31/10/2026 -->

> **Chuleta de una sola lectura** para saber QUÉ campos pedir sin explorar el CRM. Sale de
> [`CAMPOS.md`](CAMPOS.md) (el detalle, con el porqué y la historia de cada campo) y de
> [`PASAR-LISTA-CAMPOS-CRM.md`](PASAR-LISTA-CAMPOS-CRM.md). Si aquí y allí no dicen lo mismo, **manda `CAMPOS.md`**
> y este fichero se corrige.
>
> **Original aquí** (comunicaAreaPrivada). Las copias de comunicaTools y comunicaFormularios las traen sus Actions, y
> el MCP de SinergiaCRM lo sirve con la tool `crm_fields_guide` (variable `CRM_FIELDS_GUIDE_URL`).
>
> **Formato** (no lo cambies, lo lee el MCP): un `## Módulo` por módulo, con el nombre técnico entre comillas
> invertidas al principio del título; una fila por campo. Tipos cortos: `enum`, `multi` (multienum, llega
> `^a^,^b^`), `bool`, `date`, `text`, `int`, `dec`, `url`, `rel` (relate), `link` (enlace). Claves de desplegable:
> `clave` [Etiqueta]. ✅ = claves completas (leídas en Studio) · 👁 = claves vistas en datos, puede haber más ·
> ❓ = sin confirmar.

## Reglas

- **No inventes campos.** Pedir uno que no existe hace fallar la consulta ENTERA (la API da error o devuelve vacío).
  Si no está aquí: `get_module_fields(module, name_contains)` y luego apúntalo en `CAMPOS.md`.
- **No inventes claves de desplegable.** La API no valida los `enum`: una clave inventada se guarda sin error y queda
  rota. Y el MCP no devuelve las opciones de un `enum`, solo el tipo. Una clave que no esté aquí se mira en Studio.
- **Pide siempre `fields`.** Sin él llegan los ~60-80 campos del módulo.
- **La API devuelve CLAVES, no etiquetas**, y omite los campos vacíos. Mayúsculas de los `enum` poco fiables
  (`COM` y `com` conviven): compara sin distinguirlas.
- **Enlaces: usa el campo plano `…_ida`**, para leer y para escribir (dentro del propio `create/update`). Los enlaces
  anidados no llegan en esta instancia y `set_relationship` solo sirve de refuerzo (si no, `name` = «Unknown»).
  No se puede filtrar por un campo `…_ida`.
- **Un `or` sobre el MISMO campo no funciona** en `get_entry_list` (la última condición pisa a las demás).
- **Fechas al escribir: `YYYY-MM-DD HH:MM:SS` hora de Madrid**, nunca ISO con `T` (guarda AHORA sin avisar).
- **Un timeout al escribir no es un fallo**: mira si se creó antes de reintentar.
- **Importes por la API v4.1 sin «.00»** («35.00» se guarda 3.500 €): manda «35» o «35,50».
- **`assigned_user_id` = la delegación.** Todo lo que se crea va asignado a la suya; de ahí cuelga el grupo de
  seguridad. Usuario «Administrador MCM» = id `1` (= de todas). Nada interdelegacional.
- **Borrar = `deleted: 1`** (no hay tool de borrado). En SQL, siempre `deleted = 0` en cada tabla y enlace.
- **SQL**: módulo `Foo_Bar` → tabla `foo_bar`; campos `…_c` en `foo_bar_cstm` (`id_c` = `id`); cada enlace es una
  tabla `<enlace>_c` con `…_ida` / `…_idb` (nombres de +64 caracteres van recortados con hash, p. ej.
  `stic_contae394onships_idb`: mira `sql_schema`). Historial en `<tabla>_audit`.
- **Datos sensibles**: salud, IBAN, token de acceso y credenciales del portal. No se pintan enteros ni se escriben
  desde formularios.

## `Contacts` — Personas

SQL: `contacts` + `contacts_cstm`. Rol de la persona en `stic_relationship_type_c`; su grupo, en
`stic_Contacts_Relationships`; su familia, en `stic_Personal_Environment`.

| Campo | Tipo | Qué es · claves |
|---|---|---|
| `first_name`, `last_name` | text | Nombre, apellidos |
| `birthdate` | date | Nacimiento. `stic_age_c` (int) = edad, la calcula el CRM |
| `email1` | text | EL correo (el de tipo `email` es otro campo) |
| `phone_mobile` | text | **EL móvil** (llamar y WhatsApp). `phone_home`, `phone_work`, `phone_fax`: no se usan |
| `phone_other` | text | Contacto de emergencias (suele ser fijo) |
| `do_not_call` | bool | No llamar |
| `primary_address_street` / `_city` / `_postalcode` / `_state` / `_country` | text / enum | Dirección. `alt_address_*` no se usa |
| `assigned_user_id` | id | Delegación (usuario «MCM <ciudad>») |
| `stic_gender_c` | enum | Género |
| `stic_identification_type_c` / `stic_identification_number_c` | enum / text | Tipo (NIF/NIE/CIF/Pasaporte) y número |
| `stic_identification_supp_c` / `stic_identification_exp_c` | text / date | N.º de soporte y fecha del DNI (albergues). Opcionales |
| `stic_relationship_type_c` | multi 👁 | `grupo` (es miembro del MCM) · `monitor` · `participante_mic_com` (menores: SOLO esta) · `familiar_menor` (lo único que NO te hace miembro) · `coordinacion_mic_com` · `acompanamiento_mic_com`. **No existe «laico»**. Los migrados llegan `^^` |
| `stic_conduct_code_c`, `stic_confidentiality_agreement_c` | bool | Código de conducta, acuerdo de confidencialidad (solo lectura) |
| `photo` | image | Foto |
| `ajmcm_numero_persona_c` | text(12) | N.º de registro interno. ≠ `ajmcm_id_persona_c` (sin uso) |
| `ajmcm_etapa_c` | enum | `MIC` · `COM` · `LC` |
| `ajmcm_nivel_com_c` | enum ✅ | Itinerario personal: `conocimiento` [I] · `incorporacion` [II] · `crecimiento` [III] · `opcion_responsable` [IV]. ≠ segmento del grupo |
| `ajmcm_panuelo_c` | enum ✅ | `no` · `rojo` · `verde` · `azul` · `amarillo` · `cruz` · `na` [Desconocido] |
| `ajmcm_tallas_c` | enum ✅ | `4`…`14` (pares desde 8), `XS` · `S` · `M` · `L` · `XL` · `XXL` · `XXXL` |
| `ajmcm_centro_educativo_c` | text | Colegio |
| `ajmcm_grupotemp_c` | text | Grupo escrito a mano en el alta (el de verdad es la relación) |
| `ajmcm_procendencia_c` | enum ✅ | MCM local (sic, typo). `benicarlovinaros` · `burriana` · `caravaca` · `castellon` · `ciutadella` · `espinardo` · `granada` · `huetor` · `alcora` · `madird` (sic) · `nules` · `onda` · `quintanar` · `reus` · `tortosa` · `vila-real` · `villacanas` · `zaragoza` · `otros` |
| `ajmcm_acepta_lopd_c`, `ajmcm_datossalud_c`, `ajmcm_cesionimagenes_interne_c` | enum `1`/`0` | RGPD: LOPD, uso de datos de salud, cesión de imágenes |
| `ajmcm_actividadesout_c`, `ajmcm_menorwhatsapp_c`, `ajmcm_soloacasa_c`, `ajmcm_aut_participar_c` | enum `1`/`0` | Autorizaciones de menores: salidas, WhatsApp, irse solo/a, participar |
| `ajmcm_descripcion_allergies__c` | text | Alergias. **Doble guion bajo de verdad** (con uno, se ignora sin avisar). 🔒 salud |
| `ajmcm_descripcion_intoler_c`, `_tratam_c`, `_enfermed_c`, `_otros_c` | text | Intolerancias, tratamientos, enfermedades, otras. 🔒 salud |
| `ajmcm_iban_c` | text(34) | IBAN, sin espacios. 🔒 enmascarar |
| `ajmcm_iban_titular_c` | text | Titular de la cuenta (texto, no enlace) |
| `ajmcm_forma_pago_c` | enum 👁 | `cargo_cuenta` (única vista) |
| `ajmcm_asamblea_movimiento_es_c`, `ajmcm_asamblea_responsabilid_c` | text | Preguntas de asamblea |
| `ajmcm_mcm_desde_c`, `ajmcm_monitor_desde_c` | date | Año en el MCM / como monitor. Se guarda `AAAA-01-01` |
| `ajmcm_ano_incorporacion_lc_c` | int | Año de incorporación a LC |
| `ajmcm_monitor_de_c` | enum ✅ | `MIC` · `COM` · `LC` · `apoyo` · `otros` |
| `ajmcm_premonitores1_c`, `ajmcm_premonitores2_c` | enum ✅ | `no` · `en_curso` · `finalizado`. Año: `ajmcm_premonitores_year_c` (text) |
| `ajmcm_mat_c`, `ajmcm_dat_c`, `ajmcm_fa_c` | enum ✅ | `no` · `en_curso` · `practicas` · `pendiente_titulo` · `titulado` |
| `ajmcm_mat_year_c` | enum ✅ | `2013` · `2018` · `2022` · `2024` · `otra_escuela`. DAT y FA: `ajmcm_dat_year_c`, `ajmcm_fa_year_c` (text) |
| `ajmcm_mat_file_c`, `ajmcm_dat_file_c`, `ajmcm_cert_files_c`, `ajmcm_alimentos_c`, `ajmcm_form_intera_proteccion_c`, `ajmcm_eva_reconoce_c` | bool | Títulos subidos, manipulador de alimentos, formación de protección del menor… |
| `ajmcm_formacion_academica_c` | text | Formación académica |
| `ajmcm_congreso_monis_c` | multi ✅ | `2010_vlc` · `2012_cs` · `2016_cs` · `2019_godelleta` · `2022_burriana` · `2025_benicassim` · `2026_benicassim` |
| `ajmcm_aut_del_sex_c` / `ajmcm_aut_del_sex_file_c` / `ajmcm_cert_del_sex_c` | bool | Delitos sexuales: autoriza (persona) / verificado (MCM) / certificado subido |
| `ajmcm_compromiso_c`, `ajmcm_vol_acuerdo_c` | bool | Compliance, acuerdo de voluntariado. `ajmcm_vol_descripcion_c`, `ajmcm_vol_programas_c` (text) |
| `ajmcm_pa_token_c`, `stic_pa_username_c`, `stic_pa_password_c` | text | 🔒 Credenciales (enlace mágico, portal). Nunca se pintan ni se escriben desde un formulario |

No existen aquí (no los pidas): `stic_alt_address_type_c`, `stic_professional_sector_c`, `stic_employment_status_c`,
`stic_referral_agent_c`, `stic_acquisition_channel_c`, `stic_do_not_send_postal_mail_c`, `stic_*_county_c`,
`stic_alt_address_region_c`, `stic_postal_mail_return_reason_c`. Ignora `inc_*`, `sepe_*`, `jjwg_maps_*`.

## `stic_Personal_Environment` — Entorno personal (familia)

SQL: `stic_personal_environment`. Une a una persona con su familiar. **No es simétrico.**

| Campo | Tipo | Qué es · claves |
|---|---|---|
| `stic_personal_environment_contactscontacts_ida` | link | **Lado A: la persona de referencia** (el/la menor) |
| `stic_personal_environment_contacts_1contacts_ida` | link | **Lado B: el familiar** (madre, padre…) |
| `relationship_type` | enum ✅ oblig. | **Qué es B respecto de A.** `mother` · `father` · `legal` [Tutor/a] · `brother` · `sister` · `son` · `daughter` · `uncle` · `aunt` · `couple` · `friend` · `carer` · `neighbor` · `school_partner` · `work_partner` · `culture` · `sports` |
| `start_date` | date oblig. | Sin ella no se crea. `end_date` vacío = vigente |
| `authorized_signer` | bool | Firmante autorizado (Firmas). A 1 en toda relación de padre, madre o tutor/a |
| `reference_contact`, `coexistence_status`, `name`, `description` | varios | `name` = «Familiar - Etiqueta - Persona»; el CRM NO lo recalcula al cambiar lados o tipo |

Hijo/a SIEMPRE en A, una sola vez por pareja. `stic_Families` existe y no se usa.

## `ajmcm_GRUPOS` — Grupos

SQL: `ajmcm_grupos` + `ajmcm_grupos_cstm`. Los grupos del curso. ≠ grupos de actividad (`GAC_grupos`).

| Campo | Tipo | Qué es · claves |
|---|---|---|
| `code`, `name` | text | Código corto («C1») y nombre |
| `description` | text | Anotación libre del grupo |
| `level` | enum 👁 | Etapa del grupo: `mic` · `com` · `lc` · `apoyo` (minúsculas) |
| `cursos_c` | text | Curso(s) del grupo, texto libre |
| `assigned_user_id` | id | Delegación |
| `ajmcm_pasar_lista_c` | bool | Sale en Pasar Lista. Por defecto `1` en grupos nuevos |
| `ajmcm_segmento_com_c` | enum 👁 | Agrupación para coordinar/programar juntos: `com_1` · `com_2` · `com_3`. ≠ etapa y ≠ nivel personal |
| `ajmcm_n_participantes_c`, `ajmcm_n_monitores_c`, `ajmcm_monitores_c`, `ajmcm_recuento_al_c` | varios | Recuentos nocturnos (los escribe el Guardián) |

## `stic_Contacts_Relationships` — Relaciones con personas

SQL: `stic_contacts_relationships` + `_cstm`. **Una fila = una persona en un grupo en un periodo.** Mover a alguien de
grupo = cambiar el grupo de su relación.

| Campo | Tipo | Qué es · claves |
|---|---|---|
| `stic_contacts_relationships_contactscontacts_ida` | link | La persona |
| `ajmcm_grupos_stic_contacts_relationshipsajmcm_grupos_ida` | link | El grupo (tabla `ajmcm_grupos_stic_contacts_relationships_c`) |
| `relationship_type` | enum 👁 | `participante_mic_com` · `grupo` (+18 en su grupo; cuenta como participante) · `monitor` · `coordinacion_mic_com` · `acompanamiento_mic_com` · `familiar_menor` |
| `start_date` / `end_date` / `active` | date / bool | Vigencia. El curso académico (2025-2026) se DEDUCE de las fechas (corte en septiembre) |
| `ajmcm_curso_escolar_c` | enum ✅ | **Nivel escolar**, no año: `3_primaria` · `4_primaria` · `5_primaria` · `6_primaria` · `1_eso` · `2_eso` · `3_eso` · `4_eso` · `1_bachillerato` · `2_bachillerato` · `fp_gm` · `fp_gs` · `universitario` · `otros` · `na` (= no aplica, no es curso). ⚠️ En relaciones `monitor` es el curso DEL GRUPO |
| `ajmcm_etapa_relacion_c` | enum | `MIC` · `COM` · `LC` |
| `ajmcm_delegacion_c` | enum | Delegación. ⚠️ Claves distintas de `ajmcm_procendencia_c` (`vilareal` aquí, `vila-real` allí) |
| `ajmcm_clase_c` | enum ❓ | Clase |
| `end_reason`, `other_end_reasons` | enum / text | Motivo del fin. Sin uso |

## `stic_Events` — Eventos

SQL: `stic_events` + `_cstm`. Quién puede apuntarse = `assigned_user_id` + `ajmcm_ambito_c` + `ajmcm_dirigido_a_c` +
`ajmcm_filtro_edades_c`.

| Campo | Tipo | Qué es · claves |
|---|---|---|
| `name`, `description`, `timetable` | text | Nombre (oblig.), descripción, horario (texto libre: no hay campo de hora) |
| `start_date` / `end_date` | date | Solo fecha |
| `status`, `type` | enum 👁 | Vistos: `registration`; `working_day` |
| `max_attendees` | int | Aforo. `0` = sin rellenar. No existe `capacity` |
| `price` | dec | `0.00` = sin rellenar, no «gratis» |
| `assigned_user_id` | id | Delegación dueña. Id `1` o vacío = de todas |
| `ajmcm_ambito_c` | enum ✅ | `local` · `nacional`. Vacío = se deduce de `assigned_user_id` |
| `ajmcm_dirigido_a_c` | multi ❓ | Usa la lista de etapa (`MIC` · `COM` · `LC` · `lcincorporado` · `asesora` · `apoyo`), pero el código espera `grupo` · `monitor` · `participante_mic_com` · `coordinacion` · `familiar_menor`. **Contradicción abierta**: no escribas sin preguntar. Vacío = para todos |
| `ajmcm_filtro_edades_c` | multi ✅ | Cursos a los que va: MISMA lista que `ajmcm_curso_escolar_c`. Vacío = todos |
| `ajmcm_etapa_c` | multi | `MIC` · `COM` · `LC`. Para Pasar Lista (sesiones compartidas), **no** para la audiencia |
| `ajmcm_start_inscripcion_c` / `ajmcm_end_inscripcion_c` | date | Plazo de inscripción. Vacío = abierto |
| `ajmcm_lugar_c`, `ajmcm_direccion_c`, `ajmcm_mapa_c` | text / text / url | Lugar corto, dirección, enlace del mapa. No existen `location`, `city`, `address` |
| `ajmcm_ocultar_area_c` | bool | Ocultar en el área privada a quien no se ha apuntado |
| `ajmcm_fwa_url_c` | url | Enlace del formulario web avanzado. Vacío = no usa FWA |
| `ajmcm_pregunta_1_c`, `ajmcm_pregunta_2_c` | text | `¿Pregunta? \| opción 1; opción 2` (respuestas en la inscripción) |
| `ajmcm_modo_respuesta_c` | enum ✅ | `inscripcion` (por defecto, «Inscripción normal») · `si_no` («Tipo SÍ / NO»: botones sí/no en vez de inscripción; pantalla pendiente, `EVENTOS.md` §10.6) |
| `web_publicar_c` | bool | Publicar en la web `/actividades`. **Los `web_*` van SIN `ajmcm_`** |
| `web_url_c`, `web_cartel_c` | url | Botón «Inscribirme» y cartel. Vacío llega como `http://` |
| `web_cuerpo_html_c` | wysiwyg | Cuerpo de la web (HTML codificado `&lt;p&gt;`). Público aunque no esté publicado. `web_cuerpo_c`: retirado |
| `web_lema_c`, `web_slug_c` | text | Subtítulo y URL bonita |
| `stic_events_documents_1` | link | Documentos/imágenes del evento |
| `target_audience` | enum | **No se usa** (de SinergiaCRM) |

Los textos llegan con entidades HTML (`&quot;`, `&#039;`): deshazlas antes de pintar.

## `stic_Registrations` — Inscripciones

SQL: `stic_registrations` + `_cstm`. Lo de un evento es de quien lo organiza (`assigned_user_id` del evento).

| Campo | Tipo | Qué es · claves |
|---|---|---|
| `stic_registrations_contactscontacts_ida` | link | La persona inscrita. Escríbelo al crear; al LEER puede llegar vacío: usa el enlace `stic_registrations_contacts` |
| `stic_registrations_stic_eventsstic_events_ida` | link | El evento (`ajmcm_eventid_c` existe pero va vacío) |
| `status` | enum ✅ | `uninvited` · `invited` · `confirmed` · `rejected` · `maybe` · `participates` · `not_participate` (No participa: el «no» de un evento sí/no) · `dropped` (Baja: lo que escribe «cancelar») · `waiting_list`. ⚠️ **`cancelled` NO existe** en inscripciones (sí en pagos y firmas). Cancelar = `dropped`, nunca borrar |
| `participation_type` | enum 👁 | `attendant` (participante) · `organizer` (monitor/a) · `equipo` |
| `registration_date`, `attendance_percentage`, `attended_hours` | varios | Los dos últimos los calcula el CRM |
| `ajmcm_registration_amount_c` | dec | Importe |
| `ajmcm_convivencia_c` / `_fecha_c` / `_precio_c` / `_event_id_c` | enum / date / dec / text | Convivencia asociada |
| `ajmcm_respuesta_1_c`, `ajmcm_respuesta_2_c` | text | Texto de la opción elegida (preguntas del evento) |
| `ajmcm_tutor1_firstname_c`, `_lastname_c`, `_phone_c`, `_email_c`, `_dni_c` | text | Tutor/a 1 |
| `ajmcm_tutor1_relationship_c` | enum ✅ | `father` · `mother` · `legal` (≠ vocabulario del entorno personal, aunque coincidan) |
| `ajmcm_tutor1_iban_c` | text | 🔒 IBAN del tutor/a 1. Con él, el CRM crea SOLO un compromiso de pago al CREAR la inscripción |
| `ajmcm_tutor2_*` | ídem | Tutor/a 2, sin IBAN |
| `ajmcm_clase_c`, `ajmcm_curso_escolar_c` | enum ❓ | El curso NO llega en esta instancia: sácalo de la relación de participante |

## `stic_Payment_Commitments` — Compromisos de pago

SQL: `stic_payment_commitments`. Lo que hay que cobrar. Se atan por relación (no hay `…_ida` útil para escribir).

| Campo | Tipo | Qué es · claves |
|---|---|---|
| `amount` | dec oblig. | Importe |
| `payment_method` | enum oblig. 👁 | `direct_debit` (domiciliación) · `card` |
| `payment_type` | enum oblig. 👁 | `fee` (cuota) · `services` (actividad, convivencia) · `donation` (pago suelto) |
| `periodicity` | enum oblig. 👁 | `punctual` (también para la cuota anual: un compromiso por curso) |
| `first_payment_date` | date oblig. | Primer recibo |
| `bank_account` | text | **El IBAN** (no existe `iban`). `mandate`: sin uso |
| `banking_concept` | text | Concepto del extracto («Cuotas COM 26-27») |
| enlaces | link | `stic_payment_commitments_contacts`, `_stic_registrations`, `_campaigns`; pagos: `stic_payments_stic_payment_commitments` |

## `stic_Payments` — Pagos

SQL: `stic_payments`. Los genera el CRM desde el compromiso, **sin persona**: hay que ponérsela
(`stic_payments_contacts`). Comunica Tools solo cambia `status` `pending` ↔ `paid` de transferencias/Bizum.

| Campo | Tipo | Qué es · claves |
|---|---|---|
| `status` | enum 👁 | `pending` · `paid` (… otros sin documentar) |
| `payment_method` | enum 👁 | Como en el compromiso. `direct_debit` y `card` nunca se marcan a mano |
| `amount`, `payment_date` | dec / date | |

`stic_Remittances` (remesas): existe y no se usa.

## `stic_Sessions` / `stic_Attendances` — Sesiones y asistencias

| Campo | Tipo | Qué es · claves |
|---|---|---|
| `stic_Attendances.status` | enum ✅ | `yes` [Vino] · `partial` · `no_justified` [Justificada] · `no_unjustified` [No]. Vacío = sin marcar |
| `stic_Attendances.description` | text | Motivo de la falta (no hay otro campo) |
| `stic_attendances_stic_sessionsstic_sessions_ida` | link | La sesión |
| `stic_attendances_stic_registrationsstic_registrations_ida` | link | La inscripción. **Una asistencia cuelga de la inscripción**, nunca huérfana |
| `stic_Attendances.start_date` | date | No existe `end_date` (400) |

Detalle de sesiones en `PASAR-LISTA-CAMPOS-CRM.md`.

## `LIS_listas` — Pasando lista

| Campo | Tipo | Qué es · claves |
|---|---|---|
| `lis_listas_stic_sessionsstic_sessions_ida` | link | La sesión |
| `lis_listas_ajmcm_gruposajmcm_grupos_ida` | link | El grupo |
| `lis_listas_contactscontacts_ida` | link | Quién pasó la lista |
| `estado` | enum ❓ | `pasada` · `omitida` (sin registro = pendiente) |
| `pasada_el`, `n_asistieron`, `n_faltaron` | date / int | |
| `ajmcm_tipo_c` | enum ✅ | `participantes` · `monitores` |

## `AVI_avisos` — Avisos de comportamiento

| Campo | Tipo | Qué es · claves |
|---|---|---|
| `avi_avisos_contactscontacts_ida` | link | La persona del aviso |
| `fecha` (oblig.), `motivo` (oblig.) | date / text | Día y qué pasó. El nivel 1-2-3 se calcula ordenando, no se guarda |
| `contact_id_c` | id | Quién lo puso (id real del relate `ajmcm_puesto_por_c`, que es solo para pintar) |
| `stic_sessions_id_c` | id | Sesión (id real del relate `ajmcm_sesion_c`) |
| `ajmcm_notificado_familia_c` | bool | Se habló con la familia |

## `GAC_grupos` / `GAC_miembros` — Grupos de actividad de un evento

Patrullas, habitaciones… Nada que ver con `ajmcm_GRUPOS`. Nunca se borra: un grupo de más se anula (`activo = 0`).

| Campo | Tipo | Qué es · claves |
|---|---|---|
| `GAC_grupos.tipo` (oblig.) | enum ✅ | `patrulla` · `dinamica` · `habitacion` · `olimpiadas` · `limpieza` · `subgrupo` · `clan` · `casa` · `agrupacion` · `otros` |
| `GAC_grupos.agrupacion`, `plazas`, `con_monitor`, `activo`, `fecha_fin` | text / int / bool / bool / date | Tanda, plazas, lleva monitor, activo |
| `evento_id` (en los dos) | text | Id del evento: **por aquí se filtra** (no por los enlaces) |
| `GAC_miembros.papel` | enum ✅ | `participante` · `monitor` |
| `GAC_miembros.tipo`, `agrupacion` | enum / text | Copiados del grupo |
| `gac_grupos_gac_miembrosgac_grupos_ida` | link | Grupo del miembro (1:N, el único con campo plano) |
| `gac_grupos_stic_events`, `gac_miembros_contacts`, `gac_miembros_stic_registrations` | link N:M | **Sin campo `…_ida`** (pedirlo rompe la consulta): `set_relationship` + `link_name_to_fields_array` |

## `stic_Signatures` / `stic_Signers` — Firmas

Proceso de firma (`stic_Signatures`) → firmantes (`stic_Signers`, enlace `stic_signatures_stic_signers`) → registro
(`stic_Signature_Log`, enlace `stic_signers_stic_signature_log`). Firma por un menor quien tenga `authorized_signer = 1`
en su entorno personal. Detalle en comunicaTools `docs/FIRMAS.md`.

| Campo | Tipo | Qué es · claves |
|---|---|---|
| `stic_Signatures.status`, `main_module`, `on_behalf_of`, `activation_date`, `expiration_date` | varios | El proceso |
| `stic_Signers.status` | enum 👁 | `pending` · `unnecessary` [Innecesario] · (firmado: otros). Solo se toca `pending` ↔ `unnecessary` |
| `stic_Signers.contact_id_c`, `parent_type` / `parent_id`, `on_behalf_of_id` | id | Quién firma y por quién |
| `stic_Signers.email_address`, `phone`, `signature_date`, `record_type` / `record_id` | varios | |
| `stic_Signature_Log.action`, `date` | text / date | Historial (p. ej. `EMAIL_SENT`) |

## Otros módulos (existen, sin resumir)

`SEG_Seguros`, `NSOC_NumSociosLocales`, `STIC_Entorno_organizacional`, `stic_FollowUps` (seguimientos de monitores),
`stic_Messages`, `stic_Resources`, `stic_AWF_Forms`, `Campaigns` («Pagos con
tarjeta» = `00000edb-e11b-2a0f-4757-6abc23a90262`). Antes de crear un módulo o campo, mira si ya está aquí o en
`CAMPOS.md`.
