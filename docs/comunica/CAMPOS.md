# CAMPOS ESPECÍFICOS DE NUESTRO CRM ADAPTADO

## SECCION MCM

### Te indico junto a cada campo entre parentesis que tipo de campo es y si es un desplegable su dominio. Entre corchetes el texto que debes mostrar el usuario y en minusculas delante el texto que se utiliza en realidad

- `ajmcm_numero_persona_c` — Nº Registro (se utiliza internamente)
- `ajmcm_centro_educativo_c` — Centro educativo (texto libre)
- `ajmcm_etapa_c` — Etapa (Desplegable: MIC [MIC], COM [COM], LC [LC])

- `ajmcm_nivel_com_c` — Nivel COM

  Desplegable:
  - conocimiento [I - Conocimiento],
  - incorporacion [II - Incorporación,
  - crecimiento [III - Crecimiento],
  - opcion_responsable [IV - Opción Responsable]

- `ajmcm_panuelo_c` — Pañuelo

  Desplegable:
  - no [No]
  - rojo [Rojo]
  - verde [Verde]
  - azul [Azul]
  - amarillo [Amarillo]
  - cruz [Cruz]
  - na [Desconocido]

- `ajmcm_tallas_c` — Talla

  Desplegable:
  - 4 [4]
  - 5 [5]
  - 6 [6]
  - 7 [7]
  - 8 [8]
  - 9 [9]
  - 10 [10]
  - 12 [12]
  - 14 [14]
  - XS [XS]
  - S [S]
  - M [M]
  - L [L]
  - XL [XL]
  - XXL [XXL]
  - XXXL [XXXL]

- `ajmcm_grupotemp_c` — Grupo MCM (Texto)
Campo de texto libre para indicar el grupo al que pertenece el miembro, después se debe vincular en sinergia

### PREGUNTAS ASAMBLEA

Campo para foto `photo`
- `ajmcm_asamblea_movimiento_es_c` — Para mi el Movimiento es.... (Texto)
- `ajmcm_asamblea_responsabilid_c` — Responsabilidades asumidas en el MCM (Texto)


## SECCIÓN RGPD (para todos, monitores y participantes)

### Son todos listas desplegabés de valores 1 (Sí) o 0 (No)

- `ajmcm_acepta_lopd_c` — Acepta LOPD
- `ajmcm_datossalud_c` — Uso de datos sobre salud
- `ajmcm_cesionimagenes_interne_c` — Acepta la cesión publicación y envío imágenes en Internet y medios de comunicación

## SECCIÓN AUTORIZACIONES (solo para participantes, menores de edad)

### Son todos listas desplegabés de valores 1 (Sí) o 0 (No)

- `ajmcm_actividadesout_c` — Autoriza a participar en actividades fuera del centro
- `ajmcm_menorwhatsapp_c` — Autoriza a incluir contacto del/a menor en grupos de Whatsapp
- `ajmcm_soloacasa_c` — Autoriza a irse solo a casa al acabar actividades
- `ajmcm_aut_participar_c` — Autorización para participar

## INFORMACIÓN SANITARIA

### Campos de texto libre opcionales

- `ajmcm_descripcion_allergies__c` — Alergias
- `ajmcm_descripcion_intoler_c` — Intolerancias
- `ajmcm_descripcion_tratam_c` — Tratamientos
- `ajmcm_descripcion_enfermed_c` — Enfermedades
- `ajmcm_descripcion_otros_c` — Otras patologías

## MONITORES

Solo los usamos para perfiles tipo monitor/a

### MONITORES - FORMACIÓN

- `ajmcm_premonitores1_c` — Premonitores I  
  Lista desplegable:
  - -vacío- [-vacío-]
  - no [No]
  - en_curso [En curso]
  - finalizado [Finalizado]

- `ajmcm_premonitores2_c` — Premonitores II  
  Lista desplegable:
  - -vacío- [-vacío-]
  - no [No]
  - en_curso [En curso]
  - finalizado [Finalizado]

- `ajmcm_premonitores_year_c` — Año Premonis (Texto)

- `ajmcm_mat_c` — MAT  
  Lista desplegable:
  - -vacío- [-vacío-]
  - no [No]
  - en_curso [En curso]
  - practicas [Prácticas]
  - pendiente_titulo [Pendiente Título]
  - titulado [Titulado]

- `ajmcm_mat_year_c` — Año MAT  
  Lista desplegable:
  - -vacío- [-vacío-]
  - 2013 [MAT Consolación 2013 - Castellón]
  - 2018 [MAT Consolación 2018 - Tortosa]
  - 2022 [MAT Consolación 2022 - El Campello]
  - 2024 [MAT Consolación 2024 - Godelleta]
  - otra_escuela [Otra escuela]

- `ajmcm_mat_file_c` — Título MAT Archivo Subido (Casilla de verificación)

- `ajmcm_dat_c` — DAT  
  Lista desplegable:
  - -vacío- [-vacío-]
  - no [No]
  - en_curso [En curso]
  - practicas [Prácticas]
  - pendiente_titulo [Pendiente Título]
  - titulado [Titulado]

- `ajmcm_dat_year_c` — DAT - Año y escuela (Texto)
- `ajmcm_dat_file_c` — Título DAT Archivo Subido (Casilla de verificación)

- `ajmcm_fa_c` — FA  
  Lista desplegable:
  - -vacío- [-vacío-]
  - no [No]
  - en_curso [En curso]
  - practicas [Prácticas]
  - pendiente_titulo [Pendiente Título]
  - titulado [Titulado]

- `ajmcm_fa_year_c` — FA - Año y escuela (Texto)
- `ajmcm_alimentos_c` — Manip Alimentos (Casilla de verificación)
- `ajmcm_cert_files_c` — Otros certificados Archivos Subidos (Casilla de verificación)
- `ajmcm_form_intera_proteccion_c` — Form. Interna Prot. Menor (Casilla de verificación)
- `ajmcm_eva_reconoce_c` — Evaluador Reconoce (Casilla de verificación)

- `ajmcm_formacion_academica_c` — Formación Académica (Texto)
- `ajmcm_congreso_monis_c` — Congresos Monis  
  Lista desplegable múltiple

### MONITORES - VARIOS
- `ajmcm_monitor_desde_c` — Monitor/a desde... (año aproximado) - Es puente, va en la relación con persona
  (Año, números)

- `ajmcm_monitor_de_c` — Monitor/a de...  - Es puente, va en la relación con persona
(Desplegable: MIC [MIC], COM [COM], LC [LC], apoyo [Apoyo], otros [Otros])


- `ajmcm_procendencia_c` — MCM Local - En principio es temporal porque está en el asignado, pero hace falta en el formulario de alta
Desplegable: 

  benicarlovinaros  [MCM Benicarló-Vinaròs]
  burriana          [MCM Burriana]
  caravaca          [MCM Caravaca]
  castellon         [MCM Castellón]
  ciutadella        [MCM Ciutadella]
  espinardo         [MCM Espinardo]
  granada           [MCM Granada]
  huetor            [MCM Huétor-Santillán]
  alcora            [MCM L'Alcora]
  madird            [MCM Madrid]
  nules             [MCM Nules]
  onda              [MCM Onda]
  quintanar         [MCM Quintanar]
  reus              [MCM Reus]
  tortosa           [MCM Tortosa]
  vila-real         [MCM Vila-real]
  villacanas        [MCM Villacañas]
  zaragoza          [MCM Zaragoza]
  otros             [Otros]

### MONITORES - LEGAL

- `ajmcm_aut_del_sex_c` — Lo marca el usuario si elige autorizarnos para obtener la entidad los Del. Sexuales plataforma Te Autorizo (Casilla de verificación)
- `ajmcm_aut_del_sex_file_c` — Lo marcamos nosotros al comprobar que efectivamente está autorizado y con vigencia en los delitos sexuales (Casilla de verificación)
- `ajmcm_cert_del_sex_c` — Cert Delitos Sexuales Archivo Subido (Casilla de verificación)
- `ajmcm_compromiso_c` — Compliance: Compromiso Archivo Subido (Casilla de verificación)
- `ajmcm_vol_acuerdo_c` — Voluntariado: Acuerdo incorporación Archivo Subido (Casilla de verificación)

- `ajmcm_vol_descripcion_c` — Voluntariado: Descripción actividad (Texto)
- `ajmcm_vol_programas_c` — Voluntariado: Programas (Texto)







# CAMPOS INCLUIDOS POR SINERGIA CRM
Se indica en 'usado por nosotros' si nosotros lo usamos o no lo usamos
Fuente: https://wiki.sinergiatic.org/index.php?title=Estructura_de_datos:_m%C3%B3dulos_y_campos#Personas


### `stic_age_c` — Edad
- **Descripción:** Se calcula automáticamente a partir de la fecha de nacimiento. Útil en filtros de búsquedas, en informes, etc.
- **Tipo de campo:** Entero
- **Usado por nosotros:** si

---

### `photo` — Fotografía
- **Descripción:** Seleccionar un archivo para subir el archivo que contiene la fotografia
- **Tipo de campo:** Imagen
- **Usado por nosotros:** si

---


### `stic_gender_c` — Género
- **Descripción:** [--/Hombre/Mujer]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** SÍ

---

### `stic_identification_type_c` — Tipo de identificación
- **Descripción:** Tipo de documento al qual se relaciona el Nº de Identificación. [NIF/NIE/CIF/Pasaporte]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** SÍ

---

### `stic_identification_number_c` — Número de identificación
- **Descripción:**
- **Tipo de campo:** Campo de Texto
- **Usado por nosotros:** si

---

### `stic_relationship_type_c` — Tipo de relaciones actuales
- **Descripción:** Tipo de relación del contacto con la entidad [Socio / Donante / Voluntario / Usuario / Trabajador / ...]
- **Tipo de campo:** Selección Múltiple
- **Usado por nosotros:** SÍ

---

### `primary_address_street` — Dirección principal - Calle
- **Descripción:**
- **Tipo de campo:** Campo de Texto
- **Usado por nosotros:** SÍ


---

### `stic_total_annual_donations_c` — Donación total anual
- **Descripción:** Tiene sentido para hacer informes o certificados de donación justo después de haber generado el Modelo M182.
- **Tipo de campo:** Moneda
- **Usado por nosotros:** POR AHORA NO



# CAMPOS POR DEFECTO DE SUITE CRM UTILIZADOS EN SINERGIA CRM
Fuente: https://wiki.sinergiatic.org/index.php?title=Estructura_de_datos:_m%C3%B3dulos_y_campos#Personas



**Módulo original:** SuiteCRM (`Contacts`)

---

### `assigned_user_id` — Asignado a
- **Descripción:** Id del usuario de la instància asignado al contacto.
- **Tipo de campo:** Relacionado
- **Usado por nosotros:** SÍ, se asigna a usaurio de cada MCM Local

---

### `* assigned_user_name` — Asignado a
- **Descripción:** Campo usado para mostrar el nombre del usuario de la instància asignado al contacto, en las vistas del módulo
- **Tipo de campo:** Link
- **Usado por nosotros:** SÍ, se asigna a usaurio de cada MCM Local

---

### `birthdate` — Fecha de nacimiento
- **Descripción:** Fecha de nacimiento del contacto [dd/mm/aaaa]
- **Tipo de campo:** Fecha
- **Usado por nosotros:** SÍ



### `date_reviewed` — Fecha de la base legal revisada
- **Descripción:** Fecha y hora de la última modificación que se ha efectuado en los campos lawful basis del contacto. SinergiaCRM rellena este campo por defecto.
- **Tipo de campo:** Fecha
- **Usado por nosotros:** Sí

---

### `deleted` — Eliminado
- **Descripción:** Marca si el campo ha sido eliminado o no. SinergiaCRM rellena este campo por defecto.
- **Tipo de campo:** Casilla de Verificación
- **Usado por nosotros:** Sí



---

### `do_not_call` — No llamar
- **Descripción:** Indicador de si se puede llamar o no al contacto
- **Tipo de campo:** Casilla de Verificación
- **Usado por nosotros:** SÍ

---

### `email1` — Correo electrónico
- **Descripción:** Correo electrònico del contacto
- **Tipo de campo:** Campo de Texto
- **Usado por nosotros:** SI

---

### `first_name` — Nombre
- **Descripción:** Nombre propio del contacto
- **Tipo de campo:** Campo de Texto
- **Usado por nosotros:** SI

---

### `last_name` — Apellidos
- **Descripción:** Apellidos del contacto
- **Tipo de campo:** Campo de Texto
- **Usado por nosotros:** SI




### `phone_other` — Tel. Alternativo
- **Descripción:** Teléfono alternativo del contacto
- **Tipo de campo:** Teléfono
- **Usado por nosotros:** Lo usamos como "Contacto de emergencias"



---

### `primary_address_city` — Dirección principal - Población
- **Descripción:** Ciudad para la dirección principal
- **Tipo de campo:** Campo de Texto
- **Usado por nosotros:** SÍ

---

### `primary_address_country` — Dirección principal - País
- **Descripción:** País para la dirección principal
- **Tipo de campo:** Campo de Texto
- **Usado por nosotros:** SÍ, automático españa

---

### `primary_address_postalcode` — Dirección principal - Código postal
- **Descripción:** Código Postal para la dirección principal
- **Tipo de campo:** Campo de Texto
- **Usado por nosotros:** SÍ

---

### `primary_address_state` — Dirección principal - Provincia
- **Descripción:** Província para la dirección principal [Álava / Albacete / Alicante / Almería / Asturias / ...]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** SÍ

---

### `primary_address_street` — Dirección principal - Calle
- **Descripción:** Dirección principal del contacto
- **Tipo de campo:** Campo de Texto
- **Usado por nosotros:** SÍ























# Campos no usados en nuestra adaptación

---

### `modified_user_id` — Modificado Por
- **Descripción:** Id del usuario que modifica el contacto. SinergiaCRM rellena este campo por defecto
- **Tipo de campo:** Relacionado
- **Usado por nosotros:** Automático

---

### `* modified_by_name` — Modificado Por Nombre
- **Descripción:** Campo usado para mostrar el nombre del usuario de la instancia que ha modificado el contacto, en las vistas del módulo
- **Tipo de campo:** Link
- **Usado por nosotros:** Automático

---

### `phone_fax` — Fax
- **Descripción:** Número de Fax del contacto
- **Tipo de campo:** Teléfono
- **Usado por nosotros:** NO USAR

---

### `phone_home` — Tel. Casa
- **Descripción:** Teléfono de casa del contacto
- **Tipo de campo:** Teléfono
- **Usado por nosotros:** NO USAR
---

### `phone_mobile` — Móvil
- **Descripción:** Teléfono móvil del contacto
- **Tipo de campo:** Teléfono
- **Usado por nosotros:** NO USAR
---

### `salutation` — Saludo
- **Descripción:** Salutación estándar para el interesado. [Sr./Srta./Sra./Dr./Prof.]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** NO

---

### `stic_182_error_c` — Error del Modelo 182
- **Descripción:** Campo para la gestión del Modelo 182
- **Tipo de campo:** Casilla de Verificación
- **Usado por nosotros:** NO

---

### `stic_182_exluded_c` — Excluir del Modelo 182
- **Descripción:** Campo para la gestión del Modelo 182
- **Tipo de campo:** Casilla de Verificación
- **Usado por nosotros:** NO

---

### `title` — Puesto de trabajo
- **Descripción:** Título del contacto
- **Tipo de campo:** Campo de Texto
- **Usado por nosotros:** NO

---

### `phone_work` — Tel. Oficina
- **Descripción:** Teléfono del trabajo del contacto
- **Tipo de campo:** Teléfono
- **Usado por nosotros:** NO USAR


---

### `lawful_basis` — Base legal
- **Descripción:** Estado del consentimiento para la gestión de datos [Consentimiento / Contrato / Obligación legal / Protección del interés / Retirado / ...]
- **Tipo de campo:** Selección múltiple
- **Usado por nosotros:** NO POR AHORA 
---

### `lawful_basis_source` — Fuente de la base legal
- **Descripción:** Fuente principal del consentimiento [Sitio Web / Teléfono / Dado al usuario / ...]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** NO POR AHORA

---

### `lead_source` — Toma de contacto
- **Descripción:** Fuente principal de contacto [Campaña / Llamada en Frío / Conferencia / Correo Directo / Email / ...]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** NO

---

### `department` — Departamento
- **Descripción:** Departamento del contacto
- **Tipo de campo:** Campo de Texto
- **Usado por nosotros:** NO

---

### `email_opt_out` — Rehusar Email
- **Descripción:** Indica si el contacto ha optado por no recibir correos electrónicos
- **Tipo de campo:** Casilla de Verificación
- **Usado por nosotros:** NO 

---

### `description` — Descripción
- **Descripción:** Texto completo
- **Tipo de campo:** Área de Texto
- **Usado por nosotros:** NO


---

### `campaign_name` — Campaña
- **Descripción:** Campo usado para mostrar el nombre de la campaña que ha generado el contacto, en las vistas del módulo
- **Tipo de campo:** Relacionado
- **Usado por nosotros:** NO

---

### `created_by` — Creado Por
- **Descripción:** Id del usuario que crea el contacto. SinergiaCRM rellena este campo por defecto
- **Tipo de campo:** Relacionado
- **Usado por nosotros:** Automático

---

### `* created_by_name` — Creado Por
- **Descripción:** Campo usado para mostrar el nombre del usuario de la instància que ha creado el contacto, en las vistas del módulo
- **Tipo de campo:** LinAutomático
- **Usado por nosotros:** Automático

---

### `current_user_only` — Mis elementos
- **Descripción:** Campo de búsqueda. Con el campo marcado, solo buscará registros asignados al usuario activo.
- **Tipo de campo:** Casilla de Verificación
- **Usado por nosotros:** NO

---

### `date_entered` — Fecha de Creación
- **Descripción:** Fecha y hora de creación del contacto en la instància. SinergiaCRM rellena este campo por defecto.
- **Tipo de campo:** Fecha y Hora
- **Usado por nosotros:** Automático

---

### `date_modified` — Fecha de Modificación
- **Descripción:** Fecha y hora de la última modificación que se ha efectuado en el contacto. SinergiaCRM rellena este campo por defecto.
- **Tipo de campo:** Fecha y Hora
- **Usado por nosotros:** Automático

---


### `account_name` — Organización
- **Descripción:** Campo usado para mostrar el nombre de la organización asociada a este contacto, en las vistas del módulo
- **Tipo de campo:** Relacionado
- **Usado por nosotros:** NO

---

### `alt_address_city` — Dirección alternativa - Población
- **Descripción:** Ciudad para la dirección alternativa
- **Tipo de campo:** Campo de Texto
- **Usado por nosotros:** NO

---

### `alt_address_country` — Dirección alternativa - País
- **Descripción:** País para la dirección alternativa
- **Tipo de campo:** Campo de Texto
- **Usado por nosotros:** NO

---

### `alt_address_postalcode` — Dirección alternativa - Código postal
- **Descripción:** Código Postal para la dirección alternativa
- **Tipo de campo:** Campo de Texto
- **Usado por nosotros:** NO

---

### `alt_address_state` — Dirección alternativa - Provincia
- **Descripción:** Província para la dirección alternativa [Álava / Albacete / Alicante / Almería / Asturias / ...]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:**NO

---

### `alt_address_street` — Dirección alternativa - Calle
- **Descripción:** Campo que recoge otra dirección alternativa
- **Tipo de campo:** Campo de Texto
- **Usado por nosotros:** NO


---

### `alt_address_street` — Dirección alternativa - Calle
- **Descripción:**
- **Tipo de campo:** Campo de Texto
- **Usado por nosotros:** NO

---

### `stic_alt_address_type_c` — Dirección alternativa - Tipo
- **Descripción:** [Particular / Trabajo / Residencia / Otros]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** no



### `stic_professional_sector_other_c` — Otros sectores profesionales
- **Descripción:** Este campo solo aparece cuando en el campo stic_professional_sector_c, se selecciona el registro 'Otros'
- **Tipo de campo:** Campo de Texto
- **Usado por nosotros:** NO

---

### `stic_professional_sector_c` — Sector profesional
- **Descripción:** [Legal / Administración Pública / Informática / ...]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** NO

---

### `stic_language_c` — Idioma
- **Descripción:** [Castellano/Catalán]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** no

---

### `stic_postal_mail_return_reason_c` — Motivo de devolución del correo postal
- **Descripción:** [Dirección incorrecta / Desconocido / Fallecido / Rechazado / Ausente]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** no

---

### `stic_do_not_send_postal_mail_c` — No enviar correo postal
- **Descripción:**
- **Tipo de campo:** Casilla de Verificación
- **Usado por nosotros:** no


### `stic_acquisition_channel_c` — Canal de adquisición
- **Descripción:** [F2F / Mail / Postal / Web / Móvil / Telemarketing / Evento / Otros]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** no

---

### `stic_preferred_contact_channel_c` — Canal de contacto favorito
- **Descripción:** [Teléfono Fijo / Teléfono Móvil / Correo Electrónico / Correo Postal]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** no

---

### `stic_alt_address_region_c` — Dirección alternativa - Comunidad autónoma
- **Descripción:**
andalucia [Andalucía]
aragon [Aragón]
canarias [Canarias]
cantabria [Cantabria]
castilla_leon [Castilla y León]
castilla_mancha [Castilla-La Mancha]
catalunya [Cataluña]
madrid [Comunidad de Madrid]
navarra [Comunidad Foral de Navarra]
valencia [Comunitat Valenciana]
extremadura [Extremadura]
galicia [Galicia]
baleares [Illes Balears]
rioja [La Rioja]
pais_vasco [País Vasco]
asturias [Principado de Asturias]
murcia [Región de Murcia]
ceuta [Ciudad Autónoma de Ceuta]
melilla [Ciudad Autónoma de Melilla]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** no




---

### `stic_primary_address_region_c` — Dirección principal - Comunidad autónoma
- **Descripción:** 
andalucia [Andalucía]
aragon [Aragón]
canarias [Canarias]
cantabria [Cantabria]
castilla_leon [Castilla y León]
castilla_mancha [Castilla-La Mancha]
catalunya [Cataluña]
madrid [Comunidad de Madrid]
navarra [Comunidad Foral de Navarra]
valencia [Comunitat Valenciana]
extremadura [Extremadura]
galicia [Galicia]
baleares [Illes Balears]
rioja [La Rioja]
pais_vasco [País Vasco]
asturias [Principado de Asturias]
murcia [Región de Murcia]
ceuta [Ciudad Autónoma de Ceuta]
melilla [Ciudad Autónoma de Melilla]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** no

---

### `stic_alt_address_county_c` — Dirección alternativa - Comarca
- **Descripción:** [Alt Camp / Alt Empordà / Alt Penedès / Alt Urgell / Alta Ribagorça / ...]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** no

---

### `stic_primary_address_county_c` — Dirección principal - Comarca
- **Descripción:** [Alt Camp / Alt Empordà / Alt Penedès / Alt Urgell / Alta Ribagorça / ...]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** no

---

### `stic_referral_agent_c` — Agente derivador
- **Descripción:** [Servicios Sociales / Servicios Sanitarios / Familia / Propia Iniciativa]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** no
---

---

### `stic_employment_status_c` — Situación profesional
- **Descripción:** [Autónomo / Por cuenta ajena / Parado / Estudiante / Jubilado]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** NO

---

### `stic_primary_address_type_c` — Dirección principal - Tipo
- **Descripción:** [Particular / Trabajo / Residencia / Otros]
- **Tipo de campo:** Desplegable
- **Usado por nosotros:** NO
