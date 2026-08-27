<?php
/**
 * PASAR LISTA — diagnóstico: qué ha pasado de verdad al guardar.
 * ----------------------------------------------------------------------------
 * POR QUÉ EXISTE. «Paso lista y no se guarda» costó semanas porque no había
 * forma de saber, desde fuera, en qué punto se rompía: si el POST llegaba, si
 * las marcas venían, o si el CRM las rechazaba y con qué palabras. Cada intento
 * de diagnóstico era una sesión de pruebas a mano y una conjetura.
 *
 * Esto es SOLO LECTURA: no escribe en el CRM. Enseña dos cosas:
 *
 *   1. El diario de los últimos intentos de guardado (lo escribe
 *      `sticpa_pl_log_save()` desde las pantallas), incluidos los que NO
 *      escribieron nada y por qué.
 *   2. Las llamadas al CRM de ESTA petición, con su coste en milisegundos, que
 *      es la única forma de saber si una pantalla va lenta por el CRM.
 *
 * Se abre con `?pl_diag=1` en la portada de Pasar Lista y solo lo ve
 * coordinación (filtro `sticpa_pl_debug_allowed`). Lleva ids del CRM, así que
 * no es para un monitor.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** ¿Se ha pedido el panel, y puede verlo quien lo pide? */
function sticpa_pl_diag_requested($objSCP)
{
    if (empty($_GET['pl_diag'])) {
        return false;
    }
    return sticpa_pl_debug_allowed($objSCP);
}

/** El coste de las llamadas al CRM de esta petición, en texto corto. */
function sticpa_pl_diag_cost()
{
    if (!class_exists('SugarRestApiCall')) {
        return array('calls' => 0, 'ms' => 0.0, 'log' => array());
    }
    return array(
        'calls' => (int) SugarRestApiCall::$callCount,
        'ms' => round((float) SugarRestApiCall::$callMs, 1),
        'log' => (array) SugarRestApiCall::$callLog,
    );
}

/**
 * Deja en el registro de errores las peticiones que se han pasado de lentas.
 *
 * Es el radar de producción: sin esto, «va lento» es una sensación y no se
 * puede comparar el antes y el después de un cambio.
 */
function sticpa_pl_diag_log_slow()
{
    if (!class_exists('SugarRestApiCall') || (int) SugarRestApiCall::$callCount === 0) {
        return;
    }
    $limit = (float) apply_filters('sticpa_pl_slow_request_ms', 3000);
    $ms = (float) SugarRestApiCall::$callMs;
    if ($limit <= 0 || $ms < $limit) {
        return;
    }
    $page = isset($_REQUEST['internalpage']) ? (string) $_REQUEST['internalpage'] : '(sin pantalla)';
    $detail = array();
    foreach ((array) SugarRestApiCall::$callLog as $c) {
        $detail[] = $c['method'] . ($c['module'] !== '' ? ':' . $c['module'] : '') . ' ' . $c['ms'] . 'ms';
    }
    error_log('[sticpa] Petición lenta en ' . $page . ': ' . (int) SugarRestApiCall::$callCount
        . ' llamadas al CRM, ' . round($ms) . ' ms — ' . implode(', ', array_slice($detail, 0, 20)));
}
add_action('shutdown', 'sticpa_pl_diag_log_slow');

/** El panel entero, ya escapado. */
function sticpa_pl_diag_html($objSCP)
{
    $cost = sticpa_pl_diag_cost();

    $html = '<details class="pl-hint" open><summary>'
        . esc_html__('Diagnóstico de Pasar Lista', 'sticpa') . '</summary>';

    // 1. Coste de esta petición.
    $html .= '<p>' . esc_html(sprintf(
        /* translators: 1: número de llamadas, 2: milisegundos */
        __('Esta pantalla ha hecho %1$d llamadas al CRM (%2$s ms en total).', 'sticpa'),
        $cost['calls'],
        (string) $cost['ms']
    )) . '</p>';
    if (!empty($cost['log'])) {
        $html .= '<ul>';
        foreach ($cost['log'] as $c) {
            $html .= '<li><code>' . esc_html($c['method'])
                . ($c['module'] !== '' ? ':' . esc_html($c['module']) : '') . '</code> — '
                . esc_html((string) $c['ms']) . ' ms'
                . ($c['error'] !== '' ? ' — <strong>' . esc_html($c['error']) . '</strong>' : '')
                . '</li>';
        }
        $html .= '</ul>';
    }

    // 2. El diario de guardados.
    $log = sticpa_pl_save_log();
    $html .= '<h4>' . esc_html__('Últimos intentos de guardado', 'sticpa') . '</h4>';
    if (empty($log)) {
        $html .= '<p>' . esc_html__('Todavía no hay ninguno anotado. Se apunta cada vez que se pulsa Guardar, incluso cuando no se escribe nada.', 'sticpa') . '</p>';
    } else {
        foreach ($log as $entry) {
            $html .= '<p style="border-top:1px solid var(--border);padding-top:8px">';
            $html .= '<code>' . esc_html((string) $entry['ts']) . '</code> · '
                . esc_html((string) $entry['pantalla']) . ' · <strong>'
                . esc_html((string) $entry['motivo']) . '</strong><br>';
            $html .= esc_html(sprintf(
                /* translators: 1: bytes del campo de marcas, 2: marcas usadas, 3: escritas, 4: fallidas, 5: llamadas */
                __('marcas recibidas: %1$d bytes · usadas: %2$d · escritas: %3$d · fallidas: %4$d · llamadas al CRM: %5$d', 'sticpa'),
                (int) $entry['marcas_post'],
                (int) $entry['marcas_usadas'],
                (int) $entry['saved'],
                (int) $entry['failed'],
                (int) $entry['llamadas']
            ));
            if (!empty($entry['lista_id'])) {
                $html .= '<br>' . esc_html__('lista:', 'sticpa') . ' <code>' . esc_html((string) $entry['lista_id']) . '</code>';
            }
            if (!empty($entry['errores'])) {
                $html .= '<br>';
                foreach ((array) $entry['errores'] as $e) {
                    $html .= '· <code>' . esc_html(isset($e['paso']) ? (string) $e['paso'] : '?') . '</code> '
                        . esc_html(isset($e['error']) ? (string) $e['error'] : '') . '<br>';
                }
            }
            $html .= '</p>';
        }
    }

    // 3. Contra qué instancia estamos hablando: la confusión producción/aptest ya
    //    ha costado un diagnóstico entero. Sin credenciales, solo el destino.
    $url = function_exists('get_option') ? (string) get_option('sticpa_scp_rest_url', '') : '';
    if ($url !== '') {
        $html .= '<p>' . esc_html__('CRM configurado:', 'sticpa') . ' <code>'
            . esc_html($url) . '</code></p>';
    }

    $html .= '</details>';
    return $html;
}
