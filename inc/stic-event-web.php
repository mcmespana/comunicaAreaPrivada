<?php
/**
 * EVENTOS — la información de la web, también dentro del área privada.
 * ----------------------------------------------------------------------------
 * Un evento del CRM tiene dos capas de texto, y hasta ahora el área privada
 * solo enseñaba una:
 *
 *   · `description`  la descripción de siempre (la que salía en «Sobre esta
 *                    actividad»).
 *   · `web_*_c`      lo que se escribe para la página pública de la actividad
 *                    (`/actividades/?e=…`, repo comunicaFormularios): el
 *                    cartel, el lema, el cuerpo largo con sus secciones y los
 *                    documentos subidos al evento.
 *
 * La segunda es la buena —la que se escribe pensando en quien la va a leer— y
 * vivía solo fuera. Ahora la ficha del evento la pinta ENTERA aquí dentro, con
 * el mismo renderizador que la página pública (`inc/eventos-cuerpo.php`), así
 * que una actividad se lee igual en los dos sitios y se escribe una sola vez.
 *
 * Se pinta aunque el evento NO esté publicado en la web: `web_publicar_c`
 * decide si hay página pública, no si la información existe. (Es la misma
 * decisión que ya tomó el modal de la convivencia de los formularios.) Y la
 * audiencia la sigue filtrando el área como con todo lo demás.
 *
 * ── Los documentos ─────────────────────────────────────────────────────────
 *
 * Los que se suben al evento en el CRM (relación `stic_events_documents_1`)
 * salen como el cartel, una galería y botones de descarga. Se sirven por un
 * endpoint PROPIO (`sticpa_evento_archivo`), no por `/archivo.php` de la web
 * —que solo sirve los de eventos publicados— y no por la descarga genérica de
 * Documentos. Este comprueba tres cosas antes de soltar un byte: que hay una
 * sesión abierta, que el documento cuelga DE ESE evento y que el evento es
 * para quien lo pide (la misma audiencia que decide si puede apuntarse).
 * Ver docs/comunica/EVENTOS.md §9.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Los campos de la web. Se piden solo si existen en el CRM, como todo. */
function sticpa_event_web_fields()
{
    return (array) apply_filters('sticpa_event_web_fields', array(
        'web_cuerpo_c', 'web_lema_c', 'web_cartel_c', 'web_publicar_c', 'web_slug_c',
    ));
}

/** La relación técnica evento ↔ documentos (Studio, 20/09/2026). */
function sticpa_event_docs_link()
{
    return (string) apply_filters('sticpa_event_docs_link', 'stic_events_documents_1');
}

/** Dónde vive la página pública de las actividades. */
function sticpa_event_public_base()
{
    return (string) apply_filters('sticpa_event_public_base', 'https://comunica.movimientoconsolacion.com/actividades/');
}

/**
 * El tipo de un fichero por su extensión, con lista blanca. Sin SVG: puede
 * llevar JavaScript dentro y lo estaríamos sirviendo desde nuestro dominio.
 * Misma lista que `/archivo.php` de la web.
 */
function sticpa_event_file_type($filename)
{
    $tipos = array(
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    );
    $ext = strtolower((string) pathinfo((string) $filename, PATHINFO_EXTENSION));
    return $tipos[$ext] ?? '';
}

/**
 * Los documentos que cuelgan de un evento, cacheados diez minutos.
 *
 * Una llamada por evento y por cada diez minutos, la vean cien personas o una.
 * Lo que no tenga un tipo permitido no se anuncia (tampoco se serviría).
 *
 * @return array|null Lista de ['id','titulo','fichero','tipo','imagen'], o null
 *   si el CRM no ha contestado (que NO es lo mismo que «no hay documentos»).
 */
function sticpa_event_documents($objSCP, $eventId)
{
    $eventId = trim((string) $eventId);
    if ($eventId === '' || $objSCP === null) {
        return array();
    }
    $key = 'sticpa_evdocs_' . md5($eventId);
    $cached = get_transient($key);
    if (is_array($cached)) {
        return $cached;
    }

    $rows = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'stic_Events',
        'module_id' => $eventId,
        'link_field_name' => sticpa_event_docs_link(),
        'related_module_query' => '',
        'related_fields' => array('id', 'document_name', 'filename'),
        'related_module_link_name_to_fields_array' => array(),
        'deleted' => 0,
        'order_by' => '',
        'offset' => 0,
        'limit' => 50,
    ));
    if (!is_array($rows)) {
        return null;
    }

    $docs = array();
    foreach ($rows as $row) {
        $nvl = $row->name_value_list ?? null;
        $id = trim((string) ($nvl->id->value ?? ''));
        $fichero = (string) ($nvl->filename->value ?? '');
        $tipo = sticpa_event_file_type($fichero);
        if ($id === '' || $tipo === '') {
            continue;
        }
        $titulo = trim(html_entity_decode((string) ($nvl->document_name->value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $docs[] = array(
            'id' => $id,
            'titulo' => $titulo !== '' ? $titulo : $fichero,
            'fichero' => $fichero,
            'tipo' => $tipo,
            'imagen' => strpos($tipo, 'image/') === 0,
        );
    }
    set_transient($key, $docs, 10 * MINUTE_IN_SECONDS);
    return $docs;
}

/** El enlace por el que el área sirve un documento de un evento. */
function sticpa_event_file_url($eventId, $docId)
{
    $base = function_exists('admin_url') ? admin_url('admin-post.php') : '/wp-admin/admin-post.php';
    return $base . '?action=sticpa_evento_archivo&e=' . rawurlencode((string) $eventId) . '&d=' . rawurlencode((string) $docId);
}

/**
 * Lo de la web de un evento, listo para pintar en la ficha.
 *
 * El reparto es el MISMO que el de la página pública, para que no se
 * contradigan nunca:
 *   cartel   → `web_cartel_c`; si no, la imagen con la que empieza el cuerpo;
 *              si no, la primera imagen subida al evento.
 *   galería  → el resto de imágenes subidas.
 *   adjuntos → lo que no es imagen (PDF), con el nombre que se le puso en el CRM.
 *
 * @param object $objSCP  Cliente del CRM (para los documentos).
 * @param string $eventId Id del evento.
 * @param object $nvl     name_value_list del evento, pedido con los campos web.
 * @return array cartel, lema, cuerpo_html, galeria, adjuntos, pagina. Todo
 *   vacío si el evento no tiene nada de esto.
 */
function sticpa_event_web_view($objSCP, $eventId, $nvl)
{
    $val = function ($campo) use ($nvl) {
        return isset($nvl->$campo->value) ? (string) $nvl->$campo->value : '';
    };
    $vista = array('cartel' => '', 'lema' => '', 'cuerpo_html' => '', 'galeria' => array(), 'adjuntos' => array(), 'pagina' => '');
    if (!function_exists('mcm_cuerpo_bloques')) {
        return $vista;
    }

    $docs = sticpa_event_documents($objSCP, $eventId);
    $docs = is_array($docs) ? $docs : array();

    // Un `/archivo.php?d=<id>` escrito a mano en el cuerpo, si ese documento
    // cuelga de ESTE evento, se sirve por el endpoint del área (con permisos):
    // `/archivo.php` solo sirve los de eventos publicados y aquí no lo están
    // todos.
    $ids = array();
    foreach ($docs as $d) {
        $ids[strtolower($d['id'])] = true;
    }
    $reescribe = function ($url) use ($ids, $eventId) {
        if (preg_match('#^/archivo\.php\?d=([a-f0-9-]{10,64})#i', (string) $url, $m) && isset($ids[strtolower($m[1])])) {
            return sticpa_event_file_url($eventId, $m[1]);
        }
        return $url;
    };

    $bloques = mcm_cuerpo_bloques($val('web_cuerpo_c'));

    $cartel = mcm_cuerpo_url(mcm_cuerpo_normalizar($val('web_cartel_c')), false);
    if ($cartel === '') {
        $cartel = mcm_cuerpo_sacar_cartel($bloques);
    } else {
        // Si el cuerpo empieza por el mismo cartel, que no salga dos veces.
        mcm_cuerpo_sacar_cartel($bloques, $cartel);
    }
    foreach ($docs as $d) {
        $url = sticpa_event_file_url($eventId, $d['id']);
        if ($d['imagen']) {
            if ($cartel === '') {
                $cartel = $url;
                continue;
            }
            $vista['galeria'][] = array('url' => $url, 'titulo' => $d['titulo']);
            continue;
        }
        $vista['adjuntos'][] = array('url' => $url, 'titulo' => $d['titulo'], 'tipo' => $d['tipo']);
    }

    $vista['cartel'] = $cartel !== '' ? $reescribe($cartel) : '';
    $vista['lema'] = mcm_cuerpo_una_linea(mcm_cuerpo_normalizar($val('web_lema_c')));
    $vista['cuerpo_html'] = mcm_cuerpo_html($bloques, array(
        // El título de la ficha es un <h3> y el de la sección un <h4>: los del
        // cuerpo van por debajo para que el orden de títulos no se rompa.
        'titulo_base' => 5,
        'url' => $reescribe,
        'clases' => array(
            'boton' => 'stic-rec-btn stic-rec-btn--ghost stic-evweb-btn',
        ),
    ));

    // La página pública, solo si existe: sin `web_publicar_c` no hay página y
    // el enlace llevaría a un «no encontrado».
    if ($val('web_publicar_c') === '1') {
        $slug = trim(mcm_cuerpo_normalizar($val('web_slug_c')));
        $vista['pagina'] = sticpa_event_public_base() . '?e=' . rawurlencode($slug !== '' ? $slug : $eventId);
    }
    return $vista;
}

/** ¿Hay algo de la web que enseñar? */
function sticpa_event_web_has_content($vista)
{
    return !empty($vista['cartel']) || trim((string) ($vista['cuerpo_html'] ?? '')) !== ''
        || !empty($vista['adjuntos']) || !empty($vista['galeria']);
}

/**
 * La información de la web, pintada: la sección que va en la ficha.
 *
 * El cartel va ARRIBA (lo pone la ficha, fuera de esta sección); aquí va el
 * cuerpo, la galería y los documentos.
 */
function sticpa_event_web_section_html($vista)
{
    $html = '';
    if (trim((string) $vista['cuerpo_html']) !== '') {
        $html .= "<div class='stic-evweb-cuerpo'>" . $vista['cuerpo_html'] . "</div>";
    }
    if (!empty($vista['galeria'])) {
        $html .= "<div class='stic-evweb-galeria'>";
        foreach ($vista['galeria'] as $img) {
            $html .= "<img src='" . esc_url($img['url']) . "' alt='" . esc_attr($img['titulo']) . "' loading='lazy'>";
        }
        $html .= "</div>";
    }
    if (!empty($vista['adjuntos'])) {
        $html .= "<ul class='stic-evweb-docs'>";
        foreach ($vista['adjuntos'] as $doc) {
            $html .= "<li><a class='stic-evweb-doc' href='" . esc_url($doc['url']) . "' target='_blank' rel='noopener'>"
                . "<span class='stic-evweb-doc-ico'>" . sticpa_record_icon('download') . "</span>"
                . "<span class='stic-evweb-doc-name'>" . esc_html($doc['titulo']) . "</span>"
                . "<span class='stic-evweb-doc-kind'>" . esc_html($doc['tipo'] === 'application/pdf' ? 'PDF' : '') . "</span>"
                . "</a></li>";
        }
        $html .= "</ul>";
    }
    if (!empty($vista['pagina'])) {
        $html .= "<p class='stic-evweb-pagina'><a href='" . esc_url($vista['pagina']) . "' target='_blank' rel='noopener'>"
            . esc_html__('Ver la página pública de la actividad', 'sticpa') . sticpa_record_icon('go') . "</a>"
            . "<span>" . esc_html__('Es la que se puede compartir por WhatsApp.', 'sticpa') . "</span></p>";
    }
    return $html;
}

/* ── El endpoint de los documentos ─────────────────────────────────────── */

add_action('admin_post_sticpa_evento_archivo', 'sticpa_event_file_endpoint');
add_action('admin_post_nopriv_sticpa_evento_archivo', 'sticpa_event_file_endpoint');

/**
 * Sirve un documento de un evento. Solo si:
 *   1. hay una sesión del área abierta;
 *   2. el documento cuelga DE ESE evento en el CRM;
 *   3. el evento es para quien lo pide (misma audiencia que la inscripción).
 * Un 404 para todo lo demás: quien pruebe ids ajenos no tiene por qué saber si
 * existen.
 */
function sticpa_event_file_endpoint()
{
    $fuera = function ($code) {
        status_header($code);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $code === 404 ? 'No encontrado' : 'No disponible';
        exit;
    };

    $eventId = isset($_GET['e']) ? (string) $_GET['e'] : '';
    $docId = isset($_GET['d']) ? (string) $_GET['d'] : '';
    if (!preg_match('/^[a-f0-9-]{10,64}$/i', $eventId) || !preg_match('/^[a-f0-9-]{10,64}$/i', $docId)) {
        $fuera(404);
    }
    if (empty($_SESSION['scp_user_id'])) {
        $fuera(404);
    }

    $objSCP = SugarRestApiCall::getObjSCP();
    $docs = sticpa_event_documents($objSCP, $eventId);
    if ($docs === null) {
        $fuera(503);
    }
    $doc = null;
    foreach ($docs as $d) {
        if (strcasecmp($d['id'], $docId) === 0) {
            $doc = $d;
            break;
        }
    }
    if ($doc === null) {
        $fuera(404);
    }

    if (function_exists('sticpa_event_audience_check')) {
        $verdict = sticpa_event_audience_check($objSCP, $eventId);
        if (empty($verdict['ok'])) {
            $fuera(404);
        }
    }

    // A partir de aquí no se escribe en la sesión: se suelta el candado para
    // que una descarga lenta no deje en cola el resto de pantallas.
    if (session_id()) {
        session_write_close();
    }

    $detail = $objSCP->getRecordDetail($doc['id'], 'Documents', array('document_revision_id'));
    $revId = $detail->entry_list[0]->name_value_list->document_revision_id->value ?? '';
    if ($revId === '') {
        $fuera(404);
    }
    $rev = $objSCP->getDocumentRevision($revId);
    $datos = base64_decode((string) ($rev->document_revision->file ?? ''), true);
    if ($datos === false || $datos === '') {
        $fuera(404);
    }

    while (ob_get_level()) {
        ob_end_clean();
    }
    $nombre = preg_replace('/[^\w.\- ]+/u', '_', (string) ($doc['fichero'] ?: 'documento'));
    header('Content-Type: ' . $doc['tipo']);
    header('Content-Length: ' . strlen($datos));
    header('X-Content-Type-Options: nosniff');
    // Privado: depende de quién seas. Una hora en tu navegador y en ningún
    // intermediario.
    header('Cache-Control: private, max-age=3600');
    header('Content-Disposition: inline; filename="' . $nombre . '"');
    echo $datos;
    exit;
}
