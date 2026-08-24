<?php
/**
 * PASAR LISTA — home.
 * ----------------------------------------------------------------------------
 * Lo primero que ve un monitor el sábado. Un atajo grande a la sesión que toca
 * y su grupo, y debajo lo que le falta por pasar.
 *
 * La idea es que en el caso normal —tu grupo, la sesión de hoy— haya UN toque
 * entre entrar y estar marcando. Todo lo demás (otro grupo, otro día) sigue
 * ahí, un nivel más abajo.
 *
 * Diseño: docs/comunica/PASAR-LISTA.md §6.1
 */

if (!defined('ABSPATH')) {
    exit;
}

$pageSettings['fileName'] = basename(__FILE__, ".php");

// El botón de refrescar de cualquier pantalla de Pasar Lista cae aquí.
if (!empty($_REQUEST['refrescar'])) {
    sticpa_pl_flush($objSCP, 'all');
}

// El service worker se registra desde aquí, una sola vez: su alcance es todo el
// sitio y el navegador lo recuerda. Devuelve cadena vacía si el modo sin
// conexión no está encendido (ver inc/stic-pasar-lista-sw.php).
$html .= sticpa_pl_sw_register_html();

$groups = sticpa_pl_groups($objSCP);
$myGroups = sticpa_pl_my_groups($objSCP);
$events = sticpa_pl_etapa_events($objSCP);
$course = sticpa_pl_course_for();

// El grupo del atajo: el tuyo. Si tienes varios, el primero; si no tienes
// ninguno (coordinación, o un monitor sin relación puesta), no hay atajo y se
// entra por el árbol, que es lo honesto.
$mainGroupId = '';
foreach ($myGroups as $gid) {
    if (isset($groups[$gid])) {
        $mainGroupId = $gid;
        break;
    }
}

/* La sesión del atajo se resuelve ANTES de la cabecera porque el artboard pone
 * la FECHA del sábado como subtítulo («Sábado 15 de noviembre»), no el curso.
 * Es el dato que contesta «¿de qué día estoy hablando?», que es la primera
 * pregunta al abrir la pantalla. No cuesta nada: las sesiones están cacheadas y
 * el atajo las iba a pedir igual tres líneas más abajo. */
$heroPick = null;
if ($mainGroupId !== '') {
    $heroEtapa = sticpa_pl_group_etapa($groups[$mainGroupId]['level']);
    $heroEvent = isset($events[$heroEtapa]) ? $events[$heroEtapa] : null;
    if ($heroEvent !== null) {
        $heroPick = sticpa_pl_pick_session(sticpa_pl_event_sessions($objSCP, $heroEvent['id']));
    }
}

$html .= '<div class="pl-head">';
$html .= '<div class="pl-head-titles">';
$html .= '<div class="pl-title"><span class="pl-title-code">' . esc_html__('Pasar lista', 'sticpa') . '</span></div>';
$html .= '<div class="pl-subtitle">' . esc_html(
    ($heroPick !== null)
        ? sticpa_pl_session_label($heroPick['session'], false)
        : $course['label']
) . '</div>';
$html .= '</div>';
$html .= '<a class="pl-session-pick" href="?internalpage=single_stic_pasar_lista&refrescar=1"'
    . ' aria-label="' . esc_attr__('Refrescar datos', 'sticpa') . '">' . sticpa_pl_icon('refresh') . '</a>';
$html .= '</div>';

if (empty($groups)) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('No hay grupos de tu delegación en este curso. Si crees que es un error, avisa a coordinación.', 'sticpa')
        . '</span></p>';
    return;
}

// ---------------------------------------------------------------------------
// El atajo
// ---------------------------------------------------------------------------

if ($mainGroupId !== '') {
    $group = $groups[$mainGroupId];
    // Ya resuelta arriba para el subtítulo: no se vuelve a pedir.
    $pick = $heroPick;

    if ($pick !== null) {
        $session = $pick['session'];
        $lista = sticpa_pl_lista($objSCP, $session['id'], $mainGroupId);
        $estados = sticpa_pl_lista_estados();
        $done = ($lista !== null && $lista['estado'] === $estados['pasada']);

        // El recuento de participantes sale de la misma consulta que ya usa la
        // pantalla de marcado, y está cacheada con el TTL de estructura: en la
        // home no cuesta una llamada nueva salvo la primera vez del día.
        $heroPeople = sticpa_pl_group_people($objSCP, $mainGroupId);

        $html .= '<div class="pl-hero' . ($done ? ' pl-hero--done' : '') . '">';

        // Arriba, dos cosas y en este orden: a la izquierda el CUÁNDO en
        // relativo («Hoy») y el grupo; a la derecha la cápsula de fecha del
        // §11 del sistema de diseño, que es cómo se dice un día en toda el
        // área. La pastilla contesta "¿voy a tiempo?" y la cápsula "¿qué día?".
        $html .= '<div class="pl-hero-top">';
        $html .= '<div class="pl-hero-main">';
        $html .= '<span class="pl-hero-when">' . esc_html(sticpa_pl_when_pill($pick, $done)) . '</span>';
        $html .= '<div class="pl-hero-group">' . esc_html($group['code'])
            . ($group['name'] !== '' ? ' · ' . esc_html($group['name']) : '') . '</div>';

        // La línea de datos: sin etiquetas, separada por puntos. Curso, hora y
        // cuánta gente hay, que es lo que se comprueba de un vistazo antes de
        // entrar. Cuando ya está pasada, el recuento sustituye a la hora: lo
        // que se quiere saber entonces es el resultado, no cuándo empezaba.
        $meta = array();
        if ($group['cursos'] !== '') {
            $meta[] = $group['cursos'];
        }
        if ($done) {
            $meta[] = sprintf(
                /* translators: 1: cuántos vinieron, 2: cuántas ausencias */
                __('%1$d vinieron, %2$d ausencias', 'sticpa'),
                $lista['n_asistieron'],
                $lista['n_faltaron']
            );
        } else {
            // El artboard pone el RANGO, «16:30 – 18:00»: lo que se quiere
            // saber antes de entrar es cuánto dura, no solo cuándo empieza.
            $hora = date_i18n('H:i', (int) $session['start']);
            if (!empty($session['end']) && (int) $session['end'] > (int) $session['start']) {
                $hora .= ' – ' . date_i18n('H:i', (int) $session['end']);
            }
            $meta[] = $hora;
            $meta[] = sprintf(
                /* translators: %d: número de participantes del grupo */
                _n('%d participante', '%d participantes', count($heroPeople['participants']), 'sticpa'),
                count($heroPeople['participants'])
            );
        }
        $html .= '<div class="pl-hero-meta">' . esc_html(implode(' · ', $meta)) . '</div>';
        $html .= '</div>';
        $html .= sticpa_pl_date_capsule($session['start'], 'pl-hero-date');
        $html .= '</div>';

        $html .= '<a class="pl-hero-cta" href="?internalpage=single_stic_pasar_lista_marcar&grupo='
            . esc_attr($mainGroupId) . '&sesion=' . esc_attr($session['id']) . '">'
            . esc_html($done ? __('Revisar la lista', 'sticpa') : __('Pasar lista', 'sticpa'))
            . sticpa_pl_icon('next') . '</a>';
        $html .= '</div>';
    }
}

// ---------------------------------------------------------------------------
// Lo que falta por pasar
// ---------------------------------------------------------------------------

/* Solo de TUS grupos, y solo de la sesión que toca. Es a propósito: recorrer
 * todas las sesiones de todos los grupos es una consulta por par y no cabe en
 * una home. El panorama completo del curso es el resumen de coordinación
 * (fase 3), que ya recorre el curso entero y pinta la tira de listas. */
$pending = array();
// Los demás grupos MÍOS que no son deuda. Existen porque se puede ser monitor
// de varios grupos, y antes desaparecían de la portada en cuanto su lista
// estaba pasada: el atajo enseña uno, la deuda enseña los que faltan, y el
// segundo grupo al día no se podía alcanzar desde aquí. Ahora tiene su fila.
$otherMine = array();
foreach ($myGroups as $gid) {
    if (!isset($groups[$gid]) || $gid === $mainGroupId) {
        continue;
    }
    $g = $groups[$gid];
    $etapa = sticpa_pl_group_etapa($g['level']);
    if (!isset($events[$etapa])) {
        // Sin evento de su etapa no hay sesión que ofrecer, pero el grupo es
        // tuyo: se enseña para poder entrar, aunque sea sin fecha.
        $otherMine[] = array('group' => $g, 'id' => $gid, 'session' => null);
        continue;
    }
    $sessions = sticpa_pl_event_sessions($objSCP, $events[$etapa]['id']);
    $pick = sticpa_pl_pick_session($sessions);
    if ($pick === null || $pick['why'] === 'future') {
        $otherMine[] = array(
            'group' => $g,
            'id' => $gid,
            'session' => ($pick !== null) ? $pick['session'] : null,
        );
        continue;
    }
    $lista = sticpa_pl_lista($objSCP, $pick['session']['id'], $gid);
    if ($lista !== null && $lista['estado'] !== '') {
        // Pasada u omitida: no es deuda, pero sigue siendo tu grupo.
        $otherMine[] = array('group' => $g, 'id' => $gid, 'session' => $pick['session']);
        continue;
    }
    $pending[] = array('group' => $g, 'id' => $gid, 'session' => $pick['session']);
}

if (!empty($pending)) {
    // Esto no es una sección más del árbol: es una deuda. Va en ámbar, con el
    // triángulo de aviso en el título y con el número por delante —«Te faltan
    // 2 listas» dice de un golpe cuánto hay que hacer—, y cada fila lleva su
    // propio botón «Recuperar» en vez de una flecha: la acción no es mirar,
    // es arreglar.
    $html .= '<div class="pl-etapa-title pl-etapa-title--warn">'
        . sticpa_pl_icon('warn')
        . esc_html(sprintf(
            /* translators: %d: número de listas sin pasar */
            _n('Te falta %d lista', 'Te faltan %d listas', count($pending), 'sticpa'),
            count($pending)
        )) . '</div>';
    $html .= '<div class="pl-pending">';
    foreach ($pending as $row) {
        $html .= '<a class="pl-pending-row" href="?internalpage=single_stic_pasar_lista_marcar&grupo='
            . esc_attr($row['id']) . '&sesion=' . esc_attr($row['session']['id']) . '">';
        $html .= '<span class="pl-pending-body">';
        // La fecha primero y el grupo debajo: lo que falta es un DÍA, y el
        // grupo es el detalle de qué día.
        $html .= '<span class="pl-pending-when">'
            . esc_html(sticpa_pl_session_label($row['session'], false)) . '</span>';
        $html .= '<span class="pl-pending-group">' . esc_html($row['group']['code'])
            . ($row['group']['name'] !== '' ? ' · ' . esc_html($row['group']['name']) : '') . '</span>';
        $html .= '</span>';
        $html .= '<span class="pl-pending-cta">' . esc_html__('Recuperar', 'sticpa') . '</span>';
        $html .= '</a>';
    }
    $html .= '</div>';
}

// ---------------------------------------------------------------------------
// Tus otros grupos
// ---------------------------------------------------------------------------

/* Sin ámbar y sin botón: esto no es una deuda, es navegación. Se puede ser
 * monitor de más de un grupo y el atajo solo puede enseñar uno. */
if (!empty($otherMine)) {
    $html .= '<div class="pl-etapa-title">' . esc_html(
        _n('Tu otro grupo', 'Tus otros grupos', count($otherMine), 'sticpa')
    ) . '</div>';
    $html .= '<div class="pl-list">';
    foreach ($otherMine as $row) {
        $url = '?internalpage=single_stic_pasar_lista_marcar&grupo=' . rawurlencode($row['id']);
        if ($row['session'] !== null) {
            $url .= '&sesion=' . rawurlencode($row['session']['id']);
        }
        $html .= '<a class="pl-group" href="' . esc_url($url) . '">';
        $html .= '<span class="pl-group-body">';
        $html .= '<span class="pl-name">' . esc_html($row['group']['code'])
            . ($row['group']['name'] !== '' ? ' · ' . esc_html($row['group']['name']) : '') . '</span>';
        $meta = array();
        if ($row['group']['cursos'] !== '') {
            $meta[] = $row['group']['cursos'];
        }
        if ($row['session'] !== null) {
            $meta[] = sticpa_pl_session_short($row['session']);
        }
        if (!empty($meta)) {
            $html .= '<span class="pl-group-meta">' . esc_html(implode(' · ', $meta)) . '</span>';
        }
        $html .= '</span>';
        $html .= '<span class="pl-detail">' . sticpa_pl_icon('next') . '</span>';
        $html .= '</a>';
    }
    $html .= '</div>';
}

// ---------------------------------------------------------------------------
// Entrada al árbol
// ---------------------------------------------------------------------------

/* «Pasar lista de otro grupo» del artboard `Main`: MIC, COM I, COM II, COM III,
 * LC — con su punto de color y su número. Antes había UNA fila, «Ver todos los
 * grupos · 28 grupos», que es pedirle a un monitor que lea veintiocho nombres
 * para encontrar uno. Elegir entre cuatro secciones es otra cosa. */
$buckets = sticpa_pl_group_buckets($groups);
$etapaDots = array(
    'MIC' => 'var(--danger-color)',
    'COM' => 'var(--success-color)',
    'LC' => 'var(--primary-color)',
);

if (!empty($buckets)) {
    $html .= '<div class="pl-sec">' . esc_html__('Pasar lista de otro grupo', 'sticpa') . '</div>';
    $html .= '<div class="pl-list">';
    foreach ($buckets as $b) {
        $dot = isset($etapaDots[$b['etapa']]) ? $etapaDots[$b['etapa']] : 'var(--gray-300)';
        $html .= '<a class="pl-bucket" href="?internalpage=single_stic_pasar_lista_grupos&seccion='
            . rawurlencode($b['key']) . '">';
        $html .= '<span class="pl-etapa-dot" style="background:' . esc_attr($dot) . '"></span>';
        $html .= '<span class="pl-bucket-name">' . esc_html($b['label']) . '</span>';

        // El número: participantes si hay recuentos frescos —es el 93/48/37/22
        // del artboard— y si no, cuántos grupos. Un número que no se puede
        // calcular no se inventa: se cambia por el que sí, y se dice cuál es.
        // Con el total a CERO se enseñan los grupos, no el cero. El cero es
        // verdad (esa sección no tiene participantes con relación vigente),
        // pero una fila «MIC · 0» al lado de nueve grupos se lee como una
        // avería, y el dato útil para entrar ahí es cuántos grupos hay.
        if ($b['fresh'] && $b['participants'] > 0) {
            $html .= '<span class="pl-bucket-count" title="'
                . esc_attr__('Participantes', 'sticpa') . '">'
                . esc_html((string) $b['participants']) . '</span>';
        } else {
            $html .= '<span class="pl-bucket-count pl-bucket-count--groups" title="'
                . esc_attr__('Grupos', 'sticpa') . '">' . esc_html(sprintf(
                    /* translators: %d: número de grupos. Abreviado: va en una pastilla. */
                    __('%d gr.', 'sticpa'),
                    $b['groups']
                )) . '</span>';
        }
        $html .= '<span class="pl-detail">' . sticpa_pl_icon('next') . '</span>';
        $html .= '</a>';
    }
    $html .= '</div>';
}

// El árbol entero sigue existiendo, pero como destino y no como puerta.
$html .= '<a class="pl-all-groups" href="?internalpage=single_stic_pasar_lista_grupos">';
$html .= '<span>' . esc_html__('Ver todos los grupos', 'sticpa') . '</span>';
$html .= '<span class="pl-all-groups-meta">' . esc_html(sprintf(
    /* translators: %d: número de grupos de la delegación */
    _n('%d grupo', '%d grupos', count($groups), 'sticpa'),
    count($groups)
)) . '</span>';
$html .= sticpa_pl_icon('next');
$html .= '</a>';

// El resumen de coordinación: lo ve cualquier monitor (ver es útil para todos),
// pero solo coordinación puede editar los datos por revisar.
$html .= '<a class="pl-all-groups" href="?internalpage=single_stic_pasar_lista_resumen">';
$html .= sticpa_pl_icon('chart');
$html .= '<span>' . esc_html__('Resumen de grupos', 'sticpa') . '</span>';
$html .= sticpa_pl_icon('next');
$html .= '</a>';

// ---------------------------------------------------------------------------
// Coordinación
// ---------------------------------------------------------------------------

/* DEBAJO de los grupos, y como dos secciones más de la MISMA pantalla. Un
 * coordinador también tiene grupo (o no), así que no puede haber dos
 * interfaces: lo que cambia es cuántas secciones se ven, no cuál es la
 * pantalla. Y debajo porque lo frecuente es pasar lista de tu grupo —eso pasa
 * cada sábado— y coordinar monitores pasa una vez al mes. Lo que se usa más va
 * arriba, y el orden no cambia según quién entre. */
$scope = sticpa_pl_coord_scope($objSCP);
if ($scope !== null) {
    $scopeLabel = ($scope['etapa'] !== '')
        ? $scope['etapa']
        : __('toda la delegación', 'sticpa');

    $html .= '<div class="pl-etapa-title">'
        . '<span class="pl-etapa-dot" style="background:var(--secondary-color)"></span>'
        . esc_html__('Coordinación', 'sticpa')
        . '<span class="pl-scope">' . esc_html($scopeLabel) . '</span>'
        . '</div>';

    $html .= '<div class="pl-list">';
    $html .= '<a class="pl-group" href="?internalpage=single_stic_pasar_lista_monitores">';
    $html .= '<span class="pl-group-body">';
    $html .= '<span class="pl-name">' . esc_html__('Monitores', 'sticpa') . '</span>';
    $html .= '<span class="pl-group-meta">' . esc_html__('Pasar lista del sábado', 'sticpa') . '</span>';
    $html .= '</span>';
    $html .= '<span class="pl-detail">' . sticpa_pl_icon('next') . '</span>';
    $html .= '</a>';

    $html .= '<a class="pl-group" href="?internalpage=single_stic_pasar_lista_reuniones">';
    $html .= '<span class="pl-group-body">';
    $html .= '<span class="pl-name">' . esc_html__('Reuniones', 'sticpa') . '</span>';
    $html .= '<span class="pl-group-meta">' . esc_html__('Programación: crear y pasar lista', 'sticpa') . '</span>';
    $html .= '</span>';
    $html .= '<span class="pl-detail">' . sticpa_pl_icon('next') . '</span>';
    $html .= '</a>';
    $html .= '</div>';
}

if ($mainGroupId === '') {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('No tienes ningún grupo asignado como monitor/a, así que no hay atajo. Puedes pasar lista de cualquier grupo desde la lista de arriba.', 'sticpa')
        . '</span></p>';
}
