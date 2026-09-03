# Campos del CRM (SinergiaCRM) — MCM

> **Notación:** para cada campo se indica su nombre técnico (`código_interno`), la etiqueta que ve el usuario y el tipo. En los desplegables, cada valor se muestra como `valor_interno [Etiqueta mostrada]`.

---

> **Última revisión contra el CRM: 28 de agosto de 2026.** Se verificaron los
> campos que usa la ficha del monitor del área privada (`get_module_fields` de
> `Contacts`, `ajmcm_GRUPOS` y `stic_Contacts_Relationships`). De ahí salen los
> tres campos `stic_` que faltaban en §2, la corrección de `phone_mobile` y los
> avisos de §1 sobre `ajmcm_pasar_lista_c` y `ajmcm_curso_escolar_c`.
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

## 1. Campos específicos de nuestra adaptación [Módulo personas, generalmente]

### Sección MCM

- `ajmcm_numero_persona_c` — Nº Registro (uso interno)
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
- `ajmcm_descripcion_intoler_c` — Intolerancias
- `ajmcm_descripcion_tratam_c` — Tratamientos
- `ajmcm_descripcion_enfermed_c` — Enfermedades
- `ajmcm_descripcion_otros_c` — Otras patologías

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

- `ajmcm_monitor_desde_c` — Monitor/a desde… (año aproximado) — campo numérico
  - Nota: es un campo puente, va en la relación con la persona.
- `ajmcm_monitor_de_c` — Monitor/a de… (desplegable)
  - Valores: `MIC` [MIC], `COM` [COM], `LC` [LC], `apoyo` [Apoyo], `otros` [Otros]
  - Nota: es un campo puente, va en la relación con la persona.
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

Los demás campos del módulo (`code`, `name`, `level`, `cursos_c`, los recuentos
nocturnos `ajmcm_n_participantes_c` / `ajmcm_n_monitores_c` / `ajmcm_monitores_c`
/ `ajmcm_recuento_al_c`, y `ajmcm_segmento_com_c`) están en
[`PASAR-LISTA-CAMPOS-CRM.md`](PASAR-LISTA-CAMPOS-CRM.md) §3.

### Relaciones con personas (`stic_Contacts_Relationships`)

- `ajmcm_curso_escolar_c` — Curso escolar (desplegable)
  - ⚠️ **Existe y está VACÍO en todas las relaciones reales** (comprobado el
    28/08/2026). Por eso el área privada deduce el curso de `start_date` y
    `end_date` en vez de leerlo de aquí.
  - Si algún día se empieza a rellenar, **hay que mirar antes las claves
    internas de su desplegable**: si guarda `2024_2025` y el código calcula
    `2024-2025`, el histórico de cada monitor se parte en dos entradas por curso.
- `ajmcm_delegacion_c` — Delegación de la relación (desplegable)
  - ⚠️ Sus claves NO son las de `ajmcm_procendencia_c` de personas: aquí
    `vilareal`, allí `vila-real`. Dos enums de delegación con formatos distintos.
- `end_reason` / `other_end_reasons` — Motivo del fin de la relación
  - Existen, sin documentar y sin usar.

El resto de campos de este módulo (`relationship_type` y sus valores,
`start_date`, `end_date`, `active`, el vínculo con el grupo) están en
[`PASAR-LISTA-CAMPOS-CRM.md`](PASAR-LISTA-CAMPOS-CRM.md) §3.

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
  - Tipo: por confirmar. **Existe en el CRM y no se ha mirado para qué se usa.**
    Anotado el 28/08/2026 para que no se cree otro campo igual sin querer.
  - Usado por nosotros: **No, por ahora**
- `stic_total_annual_donations_c` — Donación total anual (moneda)
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
- `stic_182_exluded_c` — Excluir del Modelo 182 — No
- `title` — Puesto de trabajo — No
- `lawful_basis` — Base legal (Consentimiento / Contrato / Obligación legal / Protección del interés / Retirado / …) — No, por ahora
- `lawful_basis_source` — Fuente de la base legal (Sitio web / Teléfono / Dado al usuario / …) — No, por ahora
- `lead_source` — Toma de contacto (Campaña / Llamada en frío / Conferencia / Correo directo / Email / …) — No
- `department` — Departamento — No
- `email_opt_out` — Rehusar email — No
- `description` — Descripción (área de texto) — No
- `campaign_name` — Campaña — No
- `created_by` — Creado por
  - Tipo: relacionado. Lo rellena SinergiaCRM por defecto.
  - Usado por nosotros: Automático
- `created_by_name` — Creado por (nombre)
  - Tipo: Linkeado de forma automática 
- `current_user_only` — Mis elementos — campo de búsqueda que filtra solo los registros asignados al usuario activo — No
- `date_entered` — Fecha de creación — Automático
- `date_modified` — Fecha de modificación — Automático
- `account_name` — Organización — No
- `alt_address_city` — Dirección alternativa - Población — No
- `alt_address_country` — Dirección alternativa - País — No
- `alt_address_postalcode` — Dirección alternativa - Código postal — No
- `alt_address_state` — Dirección alternativa - Provincia — No
- `alt_address_street` — Dirección alternativa - Calle
  - Descripción: campo que recoge otra dirección alternativa.
  - Usado por nosotros: No
- `stic_alt_address_type_c` — Dirección alternativa - Tipo (Particular / Trabajo / Residencia / Otros) — No
- `stic_professional_sector_c` — Sector profesional (Legal / Administración Pública / Informática / …) — No
- `stic_professional_sector_other_c` — Otros sectores profesionales (solo aparece si en el anterior se elige "Otros") — No
- `stic_language_c` — Idioma (Castellano / Catalán) — No
- `stic_postal_mail_return_reason_c` — Motivo de devolución del correo postal (Dirección incorrecta / Desconocido / Fallecido / Rechazado / Ausente) — No
- `stic_do_not_send_postal_mail_c` — No enviar correo postal — No
- `stic_acquisition_channel_c` — Canal de adquisición (F2F / Mail / Postal / Web / Móvil / Telemarketing / Evento / Otros) — No
- `stic_preferred_contact_channel_c` — Canal de contacto favorito (Teléfono fijo / Teléfono móvil / Correo electrónico / Correo postal) — No
- `stic_alt_address_region_c` — Dirección alternativa - Comunidad autónoma — No
- `stic_primary_address_region_c` — Dirección principal - Comunidad autónoma — No
  - Valores (aplican a ambos campos anteriores): andalucia [Andalucía], aragon [Aragón], canarias [Canarias], cantabria [Cantabria], castilla_leon [Castilla y León], castilla_mancha [Castilla-La Mancha], catalunya [Cataluña], madrid [Comunidad de Madrid], navarra [Comunidad Foral de Navarra], valencia [Comunitat Valenciana], extremadura [Extremadura], galicia [Galicia], baleares [Illes Balears], rioja [La Rioja], pais_vasco [País Vasco], asturias [Principado de Asturias], murcia [Región de Murcia], ceuta [Ciudad Autónoma de Ceuta], melilla [Ciudad Autónoma de Melilla]
- `stic_alt_address_county_c` — Dirección alternativa - Comarca (Alt Camp / Alt Empordà / Alt Penedès / Alt Urgell / Alta Ribagorça / …) — No
- `stic_primary_address_county_c` — Dirección principal - Comarca (mismo listado de ejemplo que el anterior) — No
- `stic_referral_agent_c` — Agente derivador (Servicios sociales / Servicios sanitarios / Familia / Propia iniciativa) — No
- `stic_employment_status_c` — Situación profesional (Autónomo / Por cuenta ajena / Parado / Estudiante / Jubilado) — No
- `stic_primary_address_type_c` — Dirección principal - Tipo (Particular / Trabajo / Residencia / Otros) — No

---

## ⚠️ Dudas / posibles erratas a confirmar


7. Grafías con influencia catalana/valenciana en el texto original ("instància", "Província", "electrònico") las he normalizado al castellano ("instancia", "provincia", "electrónico"), ya que el resto del documento está en castellano. Dime si preferías mantener la grafía valenciana en este documento en concreto.
8. `created_by_name` tenía "Tipo de campo: LinAutomático", que era claramente un error de copy-paste — lo he separado en "Link" (tipo) y "Automático" (uso).
