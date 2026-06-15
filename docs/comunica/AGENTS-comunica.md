El repositorio incluye formularios en HTML/CSS/JS o scripts en PHP para introducir datos en el CRM "Comunica MCM" que es una instancia de SinergiaCRM (versión deslucrativizada de SuiteCRM)


La URL de la API es $CRM_URL = 'https://movimientoconsolacion.sinergiacrm.org/custom/service/v4_1_SticCustom/rest.php';


En el archivo CAMPOS.md se muestran todos los campos disonibles en el CRM


En la carpeta del repositorio `ejemplos_api_sinergiaCRM` encontrarás ejemplos de como usar la API de sinergia CRM 
En este enlace está tambien documentado https://docs.suitecrm.com/developer/api/api-v4.1-methods/

Si tienes algo más raro que hacer que actualizar un registro (por ejemplo archivos, imagenes u otras cosas) revisa siempre la documentación primero porque tiene algo de distinto.

## Notas técnicas (resumen completo de los cambios tratados en esta conversación)

- El snippet de edición `monitores/edicion_monitores_2026_v2.html` es un html que se inserta en un post de wordpress para editar los datos de los monitores en Comunica
- El proxy `monitores/crm_proxy.php` gestiona las acciones `lookup`, `update`, `upload_photo` y `upload_documents` para el formulario de monitores.
- `upload_documents` acepta ficheros con claves `ds_file`, `mat_file`, `dat_file`, `form_file` (y compatibilidad `archivo_1`/`archivo_2`) junto con `dni` y `meta` para tipificar documentos. Cada fichero se crea como documento en CRM, se vincula al contacto y se sube su revisión.
- El CRM necesita `set_document_revision` usando el campo `id` del documento (no `document_id`), y se usa `date_input` al crear el documento.
- Se marca el estado en el contacto al subir documentos: `ajmcm_cert_del_sex_c` (DS), `ajmcm_mat_file_c` (MAT), `ajmcm_dat_file_c` (DAT), `ajmcm_cert_files_c` (otros).
- Se crea un log local `monitores/update_wordpress/crm_proxy.log` con eventos y errores de subida (inicio, creación, revisión, relación y fallo).

- El timeout de cURL del proxy está en 120 segundos para cargas pesadas porque el servidor va lentísimo

- Usa tres inputs para la fecha de nacimiento (día/mes/año) y normaliza a `YYYY-MM-DD` antes de buscar.

- La sección "Certificado Delitos Sexuales" incluye opciones Manual/Automático ligadas al campo `ajmcm_aut_del_sex_c` y muestra/oculta el bloque de subida o el enlace de tutorial.
- La sección "Archivos" muestra el estado de documentos (voluntariado, MAT, DAT, otros) y permite subir MAT/DAT/otros; el estado se recalcula con los datos del CRM y en tiempo real con los valores actuales del formulario.
- La subida de documentos se lanza en paralelo desde el navegador (una petición por archivo) tras guardar los cambios de datos.

## Integración Google Forms → SinergiaCRM

El subdirectorio `integracionComunicaGForms/` contiene un sistema reutilizable basado en dos templates:

- **Form template** con un script launcher (`form-launcher/FormLauncher.gs`) que copia el spreadsheet template y lo vincula como destino de respuestas. Se pega una vez, no necesita mantenimiento.
- **Spreadsheet template** con el script CLASP (`src/`) que contiene toda la lógica: trigger onFormSubmit, cliente SinergiaCRM (login + lookup por DNI), y escritura de datos en la hoja.
- La configuración de columnas y campos CRM vive en una **hoja oculta `_config`** del spreadsheet, editable sin tocar código.
- Las credenciales del CRM se almacenan en Script Properties del spreadsheet template.
- Para desplegar un form nuevo: copiar form → Inicializar → Instalar trigger. 100% navegador.
- CI/CD via `clasp push` cuando se tocan ficheros en `src/`.
- Documentación completa: `integracionComunicaGForms/README.md`