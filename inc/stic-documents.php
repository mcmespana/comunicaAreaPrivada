<?php
/**
 * DOCUMENTOS — el listado.
 * ----------------------------------------------------------------------------
 * La acción de un documento es DESCARGARLO. Parece obvio y era lo único que no
 * se podía hacer: el listado tenía un solo botón, "Abrir", que llevaba a un
 * formulario de edición desde el que —tres toques más abajo— había otro botón
 * de descarga. Para bajarse una autorización firmada había que pasar por una
 * pantalla de editar metadatos.
 *
 * Ahora la tarjeta lleva "Descargar" como acción principal, con el enlace
 * directo al endpoint que ya existía, y "Ver ficha" como secundaria para quien
 * quiera cambiar el nombre o borrarlo.
 *
 * Y se enseñan dos cosas que el listado tenía pero no explicaba:
 *   · El TIPO de archivo, sacado de la extensión, dentro de la cápsula: un PDF
 *     y una foto no se abren igual y conviene saberlo antes de tocar.
 *   · Si el documento está CADUCADO (`exp_date` pasada). Antes era una columna
 *     de fecha más; ahora es un chip y la tarjeta se apaga, porque un permiso
 *     caducado es justo el que hay que renovar.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Campos que se piden al CRM para el listado de documentos. */
function sticpa_document_list_fields()
{
    return array(
        'id',
        'document_name',
        'filename',
        'status_id',
        'active_date',
        'exp_date',
        'category_id',
    );
}

/**
 * URL de descarga directa de un documento.
 *
 * Reutiliza el endpoint que ya existía (`admin_post_single_stic_documents` con
 * `download=true`), que acepta GET. No se abre ninguna puerta nueva: el mismo
 * enlace lo generaba ya el botón de la pantalla de detalle; lo único que cambia
 * es que ahora está a un toque en vez de a tres.
 */
function sticpa_document_download_url($documentId)
{
    $documentId = (string) $documentId;
    if ($documentId === '') {
        return '';
    }
    $base = function_exists('admin_url') ? admin_url('admin-post.php') : '/wp-admin/admin-post.php';
    return $base . '?action=single_stic_documents&download=true&id=' . rawurlencode($documentId);
}

/**
 * El tipo de archivo, en tres o cuatro letras, sacado de la extensión.
 *
 * Se enseña dentro de la cápsula de la tarjeta, en el hueco donde otros módulos
 * ponen el mes. Un PDF, una foto y una hoja de cálculo no se abren igual y en
 * un móvil conviene saberlo ANTES de tocar y esperar la descarga.
 *
 * Sin extensión reconocible devuelve '' y la cápsula cae a su icono de archivo:
 * antes inventar un tipo que enseñar la palabra "archivo" en un recuadro.
 */
function sticpa_document_kind($filename)
{
    $filename = trim((string) $filename);
    if ($filename === '' || strpos($filename, '.') === false) {
        return '';
    }
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === '' || strlen($ext) > 4 || !preg_match('/^[a-z0-9]+$/', $ext)) {
        return '';
    }
    // Familias que se dicen mejor con otra palabra que con su extensión.
    $familias = array(
        'jpg' => 'JPG', 'jpeg' => 'JPG', 'png' => 'PNG', 'heic' => 'HEIC', 'webp' => 'WEBP',
        'doc' => 'DOC', 'docx' => 'DOC', 'odt' => 'ODT',
        'xls' => 'XLS', 'xlsx' => 'XLS', 'ods' => 'ODS',
        'ppt' => 'PPT', 'pptx' => 'PPT',
    );
    return $familias[$ext] ?? strtoupper($ext);
}

/**
 * Normaliza un documento del CRM.
 *
 * @return array|null null si la fila no tiene nombre.
 */
function sticpa_document_view_model($nvl)
{
    $val = function ($field) use ($nvl) {
        return isset($nvl->$field->value) ? trim((string) $nvl->$field->value) : '';
    };

    $name = $val('document_name');
    $filename = $val('filename');
    if ($name === '' && $filename === '') {
        return null;
    }

    $expStr = $val('exp_date');
    $expTs = $expStr !== '' ? strtotime($expStr) : null;

    return array(
        'id'        => $val('id'),
        'name'      => $name !== '' ? $name : $filename,
        'filename'  => $filename,
        'kind'      => sticpa_document_kind($filename),
        'status'    => $val('status_id'),
        'active_ts' => $val('active_date') !== '' ? strtotime($val('active_date')) : null,
        'exp_ts'    => $expTs,
        // Un permiso caducado es justo el que hay que renovar: se marca.
        'caducado'  => ($expTs !== null && $expTs < strtotime('today')),
    );
}

/**
 * LISTADO de documentos como tarjetas.
 *
 * @param array $rows       Registros del CRM.
 * @param array $definition Definición cacheada del módulo (etiquetas de enums).
 */
function sticpa_documents_list_html($rows, $definition = array())
{
    $models = array();
    foreach ((array) $rows as $row) {
        $nvl = $row->name_value_list ?? null;
        if (!$nvl) {
            continue;
        }
        $model = sticpa_document_view_model($nvl);
        if ($model) {
            $models[] = $model;
        }
    }

    if (empty($models)) {
        return sticpa_record_empty_html(
            'file',
            __('Todavía no tienes documentos', 'sticpa'),
            __('Aquí guardas y descargas lo tuyo: autorizaciones, certificados, justificantes.', 'sticpa'),
            array('label' => __('Subir un documento', 'sticpa'), 'url' => '?internalpage=single_stic_documents&action=create', 'primary' => true)
        );
    }

    // Lo caducado al final; el resto, lo más reciente arriba.
    usort($models, function ($a, $b) {
        if ($a['caducado'] !== $b['caducado']) {
            return $a['caducado'] ? 1 : -1;
        }
        return ($b['active_ts'] ?? 0) <=> ($a['active_ts'] ?? 0);
    });

    $cards = array();
    foreach ($models as $doc) {
        // SIN el nombre del archivo. Estaba, y se cayó al mirar la captura:
        // "autorizacion-imagen-2026.pdf" debajo de "Autorización de imagen
        // firmada" y al lado de una cápsula que ya pone PDF es decir lo mismo
        // tres veces, y con el botón de descarga quitando 44px de ancho, esa
        // línea se partía en dos. El nombre técnico del fichero sigue en la
        // ficha, que es donde importa.
        $lines = array();
        if ($doc['active_ts']) {
            $lines[] = array(
                'icon' => 'clock',
                /* translators: %s = fecha en que se subió el documento */
                'text' => sprintf(__('Del %s', 'sticpa'), sticpa_record_date_line($doc['active_ts'])),
            );
        }

        $chips = array();
        if ($doc['caducado']) {
            $chips[] = array(
                'label' => __('Caducado', 'sticpa'),
                'tone'  => 'danger',
            );
        } else {
            $statusLabel = sticpa_record_enum_label($definition, 'status_id', $doc['status']);
            if ($statusLabel !== '') {
                $chips[] = array('label' => $statusLabel, 'tone' => sticpa_record_status_tone($doc['status']));
            }
        }

        // La descarga, como botón redondo a la derecha y no como barra de
        // acciones: con cuatro documentos en pantalla eran cuatro botones de
        // marca, y el degradado que sale cuatro veces deja de firmar nada.
        // Se decidió mirando la captura.
        //
        // El overlay de carga NO hace falta desactivarlo a mano: la URL lleva
        // `download=` y js/stic-ui.js ya se salta esas (una descarga no navega,
        // así que el overlay se quedaría puesto para siempre). Si algún día
        // cambia el nombre del parámetro, hay que tocar también aquella guarda.
        //
        // Sin archivo no hay descarga que ofrecer: un documento del CRM puede
        // ser una ficha a la que todavía no se le ha subido nada, y un botón
        // que baja un fichero vacío es peor que no tener botón.
        $quick = null;
        if ($doc['filename'] !== '') {
            $downloadUrl = sticpa_document_download_url($doc['id']);
            if ($downloadUrl !== '') {
                $quick = array(
                    /* translators: %s = nombre del documento */
                    'label' => sprintf(__('Descargar %s', 'sticpa'), $doc['name']),
                    'url'   => $downloadUrl,
                    'icon'  => 'download',
                );
            }
        }

        $cards[] = array(
            // La tarjeta entera abre la FICHA, no la descarga: tocar sin
            // querer y que empiece a bajar un archivo es de las cosas que más
            // molestan en móvil. Se descarga desde su botón, a propósito.
            'url'     => '?internalpage=single_stic_documents&action=edit&id=' . rawurlencode($doc['id']),
            'ts'      => null,
            'icon'    => 'file',
            'kind'    => $doc['kind'],
            'name'    => $doc['name'],
            'lines'   => $lines,
            'chips'   => $chips,
            'is_past' => $doc['caducado'],
            'quick'   => $quick,
        );
    }

    return sticpa_record_list_html($cards);
}
