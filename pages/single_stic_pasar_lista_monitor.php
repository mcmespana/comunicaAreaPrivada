<?php
/**
 * PASAR LISTA — ficha del monitor (coordinación).
 * ----------------------------------------------------------------------------
 * La pantalla que sustituye a abrir el CRM. Coordinación mira a un monitor tres
 * veces al año y siempre con las mismas preguntas, así que el orden de la ficha
 * es el orden de esa conversación y no el orden en que el CRM guarda los campos:
 *
 *   1. Quién es y cómo se le llama. El móvil está en los dos botones grandes,
 *      así que debajo solo va lo que NO está ahí: el correo (que se lee) y el
 *      otro teléfono (que solo se pulsa, y por eso cabe en un botón redondo).
 *   2. **Cómo va**: sesiones, reuniones y listas, cada una en su fila, con
 *      cuadraditos. Es el motivo de abrir la ficha.
 *   3. Sus grupos: el que lleva y el suyo (la relación `grupo` COM-LC).
 *   4. Los seguimientos de ESTE curso, con el formulario detrás de un botón y
 *      un enlace para traer los del curso anterior.
 *   5. Por dónde ha pasado, curso a curso y con quién. Plegable, abierto.
 *   6. Y al final el papeleo: en regla (aquí dentro va el certificado de
 *      delitos sexuales, que antes abría la pantalla), datos MCM, formación y
 *      —plegados— los datos de padrón.
 *
 * COSTE. Cuatro tandas paralelas y ni una consulta por grupo ni por curso: el
 * histórico entero, los compañeros de cada curso y los recuentos de cada grupo
 * salen del mapa de relaciones que la pantalla ya carga (ver
 * `sticpa_pl_all_relationships_raw()`). Con la caché caliente son DOS consultas:
 * la ficha y los seguimientos.
 *
 * Diseño: docs/comunica/PASAR-LISTA-COORDINACION.md §4 y plans/038.
 */

if (!defined('ABSPATH')) {
    exit;
}

$pageSettings['fileName'] = basename(__FILE__, ".php");

// TANDA 0: lo que decide si esta pantalla se puede ver siquiera. Va antes que
// nada porque el alcance manda, y las cuatro consultas viajan juntas.
sticpa_pl_prime($objSCP, function () use ($objSCP) {
    sticpa_pl_coord_scope($objSCP);
    sticpa_pl_is_acompanante($objSCP);
    sticpa_pl_groups($objSCP);
    sticpa_pl_all_relationships($objSCP);
});

$scope = sticpa_pl_coord_scope($objSCP);
$isAcomp = sticpa_pl_is_acompanante($objSCP);

// Acompañar da acceso a la ficha aunque no se coordine: es justo la gente que
// tiene que poder leer los datos de los monitores.
if ($scope === null && !$isAcomp) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('Esta pantalla es de coordinación.', 'sticpa') . '</span></p>';
    return;
}

$segRoles = sticpa_pl_seg_roles($scope !== null, $isAcomp);

$monitorId = isset($_REQUEST['monitor']) ? sticpa_pl_safe_id($_REQUEST['monitor']) : '';
if ($monitorId === '') {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('No se ha indicado ningún monitor.', 'sticpa') . '</span></p>';
    return;
}

// El monitor tiene que estar en un grupo del alcance. El CRM ya limita por
// delegación, pero esto impide abrir a un monitor de otra etapa cambiando la URL.
$groups = sticpa_pl_scoped_groups($objSCP, $scope);
$monitors = sticpa_pl_monitors_of($objSCP, $groups);

$mine = null;
foreach ($monitors as $m) {
    if ($m['id'] === $monitorId) {
        $mine = $m;
        break;
    }
}
if ($mine === null) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('Este monitor no está en los grupos de tu alcance.', 'sticpa') . '</span></p>';
    return;
}

// ---------------------------------------------------------------------------
// Alta de un seguimiento
// ---------------------------------------------------------------------------

/* El alta va AQUÍ, antes de la tanda que lee: si se leyeran los seguimientos
 * primero, la nota recién escrita no saldría hasta recargar. Y va después de la
 * comprobación de alcance, que es lo que impide escribirle una nota a alguien
 * que no es tuyo cambiando la URL. */
$segMsg = '';
if (!empty($_POST['pl_seg_texto'])) {
    if (!isset($_POST['pl_nonce']) || !wp_verify_nonce($_POST['pl_nonce'], 'pl_seg_' . $monitorId)) {
        $segMsg = __('La sesión ha caducado. Vuelve a cargar la pantalla.', 'sticpa');
    } else {
        $ok = sticpa_pl_create_seguimiento(
            $objSCP,
            $monitorId,
            isset($_POST['pl_seg_tipo']) ? $_POST['pl_seg_tipo'] : '',
            $_POST['pl_seg_texto'],
            isset($_POST['pl_seg_fecha']) ? $_POST['pl_seg_fecha'] : '',
            $segRoles
        );
        $segMsg = $ok
            ? __('Guardado.', 'sticpa')
            : __('No se ha podido guardar.', 'sticpa');
    }
}

$segReadable = sticpa_pl_seg_readable($segRoles);
$segWritable = sticpa_pl_seg_writable($segRoles);
$segOn = (sticpa_pl_seguimientos_enabled() && (!empty($segReadable) || !empty($segWritable)));

// TANDA 1: todo lo que hace falta para pintar, menos lo que depende de un id
// que todavía no se conoce.
sticpa_pl_prime($objSCP, function () use ($objSCP, $monitorId, $segOn, $segRoles) {
    sticpa_pl_etapa_events($objSCP);
    sticpa_pl_reuniones_event($objSCP);
    sticpa_pl_all_listas($objSCP);
    sticpa_pl_monitor_ficha($objSCP, $monitorId);
    if ($segOn) {
        sticpa_pl_seguimientos($objSCP, $monitorId, $segRoles);
    }
});

$ficha = sticpa_pl_monitor_ficha($objSCP, $monitorId);
if ($ficha === null) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('No se han podido cargar los datos.', 'sticpa') . '</span></p>';
    return;
}

// ---------------------------------------------------------------------------
// Los dos eventos del curso
// ---------------------------------------------------------------------------

$events = sticpa_pl_etapa_events($objSCP);
// Quien solo acompaña no tiene alcance, así que no hay etapa de la que tirar:
// se coge el primer evento que haya, que para las asistencias da igual porque
// las sesiones son las mismas fechas.
$etapaKey = ($scope !== null && $scope['etapa'] !== '') ? $scope['etapa'] : '';
$event = ($etapaKey !== '' && isset($events[$etapaKey])) ? $events[$etapaKey] : null;
if ($event === null) {
    foreach (array('COM', 'MIC', 'LC') as $e) {
        if (isset($events[$e])) {
            $event = $events[$e];
            break;
        }
    }
}
$reunion = sticpa_pl_reuniones_event($objSCP);

// TANDA 2: sesiones e inscripciones de los DOS eventos a la vez. Antes las
// reuniones no se miraban en esta pantalla; pedirlas en su propio viaje habría
// duplicado la espera.
sticpa_pl_prime($objSCP, function () use ($objSCP, $event, $reunion) {
    foreach (array($event, $reunion) as $ev) {
        if (is_array($ev) && !empty($ev['id'])) {
            sticpa_pl_event_sessions($objSCP, $ev['id']);
            sticpa_pl_event_registrations($objSCP, $ev['id']);
        }
    }
});

// Cero consultas: las tres salen del mapa de relaciones y del índice de listas.
// (La tanda 3 —las asistencias de esta persona— la hace `..._seguimiento_...`.)
$grupos = sticpa_pl_monitor_grupos($objSCP, $monitorId);
$seg = sticpa_pl_seguimiento_monitor($objSCP, $monitorId, $event, $reunion, $grupos);
$historico = sticpa_pl_monitor_historico($objSCP, $monitorId);

// ---------------------------------------------------------------------------
// Utilidades de pintado
// ---------------------------------------------------------------------------

/** «Ana, Luis y Marta». Con más de cuatro, «Ana, Luis y 3 más». */
$conNombres = function ($personas, $tope = 4) {
    $nombres = array();
    foreach ($personas as $p) {
        $nombres[] = $p['name'];
    }
    $n = count($nombres);
    if ($n === 0) {
        return '';
    }
    if ($n > $tope) {
        $resto = $n - ($tope - 1);
        $nombres = array_slice($nombres, 0, $tope - 1);
        $nombres[] = sprintf(
            /* translators: %d: cuántas personas más hay en la lista */
            _n('%d más', '%d más', $resto, 'sticpa'),
            $resto
        );
    }
    if (count($nombres) === 1) {
        return $nombres[0];
    }
    $ultimo = array_pop($nombres);
    return implode(', ', $nombres) . ' ' . __('y', 'sticpa') . ' ' . $ultimo;
};

// ---------------------------------------------------------------------------
// Cabecera e identidad
// ---------------------------------------------------------------------------

/* La vuelta atrás, a donde venías: desde «Mis grupos» se vuelve a «Mis
 * grupos», no a la lista de monitores de coordinación. */
$volver = sticpa_pl_vengo_url(
    isset($_REQUEST['vengo']) ? $_REQUEST['vengo'] : '',
    isset($_REQUEST['vgrupo']) ? $_REQUEST['vgrupo'] : ''
);
$volverTexto = ($volver !== '') ? __('Volver a mis grupos', 'sticpa') : __('Volver a monitores', 'sticpa');
if ($volver === '') {
    $volver = '?internalpage=single_stic_pasar_lista_monitores';
}

$html .= '<div class="pl-head">';
$html .= '<a class="pl-back" href="' . esc_url($volver) . '"'
    . ' aria-label="' . esc_attr($volverTexto) . '">' . sticpa_pl_icon('back') . '</a>';
$html .= '<div class="pl-head-titles">';
$html .= '<div class="pl-subtitle">' . esc_html__('Ficha del monitor', 'sticpa') . '</div>';
$html .= '</div>';
$html .= '</div>';

/* El nombre, grande y entero, con el avatar de marca: es la misma identidad que
 * la ficha de un participante y se aprende una sola vez. Debajo, la línea que
 * sitúa a la persona sin etiquetas. */
$bits = array();
if (!empty($mine['groups'])) {
    $bits[] = implode(' · ', $mine['groups']);
}
if (!empty($mine['curso'])) {
    $bits[] = $mine['curso'];
}
if ($ficha['stic_age_c'] !== '') {
    $bits[] = sprintf(
        /* translators: %s: edad en años */
        __('%s años', 'sticpa'),
        $ficha['stic_age_c']
    );
}
$since = sticpa_pl_monitor_since($ficha['ajmcm_monitor_desde_c']);
if ($since !== '') {
    $bits[] = sprintf(
        /* translators: %s: año en que empezó como monitor/a */
        __('monitor/a desde %s', 'sticpa'),
        $since
    );
}

$html .= '<div class="pl-ident">';
// La foto del monitor, igual que en la ficha de un participante: una persona,
// una petición. Debajo quedan las iniciales si no la tiene.
$html .= sticpa_pl_avatar_html(
    array('id' => $monitorId, 'initials' => sticpa_pl_initials('', '', $ficha['name'])),
    true,
    'pl-ident-avatar'
);
$html .= '<span class="pl-ident-body">';
$html .= '<span class="pl-ident-name">' . esc_html($ficha['name']) . '</span>';
if (!empty($bits)) {
    $html .= '<span class="pl-ident-meta">' . esc_html(implode(' · ', $bits)) . '</span>';
}
$html .= '</span>';
$html .= '</div>';

// ---------------------------------------------------------------------------
// Contacto: los dos botones grandes, antes de cualquier dato
// ---------------------------------------------------------------------------

$phone = sticpa_pl_phone($ficha['phone_mobile']);
if ($phone !== null) {
    $html .= '<div class="pl-contact">';
    $html .= '<a class="pl-contact-btn pl-contact-btn--wa" href="https://wa.me/' . esc_attr($phone['wa']) . '"'
        . ' target="_blank" rel="noopener">'
        . '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm5.3 14.1c-.2.6-1.2 1.2-1.7 1.2-.5 0-1 .1-1.8-.2a12 12 0 0 1-5-4.4c-.5-.9-.8-1.7-.8-2.4 0-.7.4-1.4.7-1.7.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .5.4l.7 1.7c.1.2 0 .4-.1.5l-.4.5c-.1.2-.2.3 0 .5.5.9 1.5 1.8 2.4 2.2.2.1.4.1.5 0l.6-.6c.2-.2.3-.1.5-.1l1.6.8c.2.1.3.2.3.3 0 .2 0 .8-.2 1.2Z"/></svg>'
        . '<span>' . esc_html__('WhatsApp', 'sticpa') . '</span></a>';
    $html .= '<a class="pl-contact-btn pl-contact-btn--call" href="tel:' . esc_attr($phone['tel']) . '">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2 4.2 2 2 0 0 1 4 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.4 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.4 1.8.6 2.8.8a2 2 0 0 1 1.7 2Z"/></svg>'
        . '<span>' . esc_html__('Llamar', 'sticpa') . '</span></a>';
    $html .= '</div>';
}

$emergencia = sticpa_pl_phone($ficha['phone_other']);
$email = trim($ficha['email1']);

/* UNA sola línea debajo de los botones, y no la lista de teléfonos de antes.
 *
 * El móvil ya está en los dos botones grandes de arriba: repetirlo en una fila
 * es enseñar dos veces el mismo dato y empujar hacia abajo lo que sí se viene a
 * leer. Y de los otros dos, el correo se LEE (se copia, se reconoce) y el
 * teléfono de emergencias solo se PULSA: por eso el correo va en texto y el
 * otro teléfono cabe en un botón redondo al lado, con el número en su
 * `aria-label` para quien lo necesite. */
/* CADA DATO EN SU FILA, y el correo con su botón de copiar.
 *
 * Antes el correo y el teléfono de emergencia compartían una sola fila: un
 * correo de los largos («davidbadenes@movimientoconsolacion.com») se partía en
 * dos líneas y quedaba cortado contra un botón redondo sin etiqueta que además
 * NO era el teléfono de esa persona sino el otro, y eso no lo adivina nadie.
 *
 * Ahora: el correo ocupa su fila entera con un botón de COPIAR —que es lo que
 * se hace de verdad con un correo: pegarlo en otro sitio— y el otro teléfono
 * va en su propia fila, con su etiqueta y su número a la vista. */
if ($email !== '' || $emergencia !== null) {
    $html .= '<div class="pl-contactrows">';

    if ($email !== '') {
        $html .= '<div class="pl-contactrow">';
        $html .= '<a class="pl-contactrow-main" href="mailto:' . esc_attr($email) . '">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg>'
            . '<span class="pl-contactrow-body">'
            . '<span class="pl-contactrow-label">' . esc_html__('Correo', 'sticpa') . '</span>'
            . '<span class="pl-contactrow-value">' . esc_html($email) . '</span>'
            . '</span></a>';
        // Copiar: un toque. El texto va en el `data-` y no se lee del DOM, que
        // con puntos suspensivos devolvería el correo recortado.
        $html .= '<button type="button" class="pl-copy" data-pl-copy="' . esc_attr($email) . '"'
            . ' aria-label="' . esc_attr__('Copiar el correo', 'sticpa') . '"'
            . ' data-pl-copied="' . esc_attr__('Correo copiado', 'sticpa') . '">'
            . '<svg class="pl-copy-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>'
            . '<svg class="pl-copy-ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M20 6 9 17l-5-5"/></svg>'
            . '</button>';
        $html .= '</div>';
    } else {
        $html .= '<div class="pl-contactrow"><span class="pl-contactrow-main pl-contactrow-main--empty">'
            . esc_html__('Sin correo en el CRM', 'sticpa') . '</span></div>';
    }

    if ($emergencia !== null) {
        /* CON SU ETIQUETA. Este número NO es el suyo: es el que la ficha
         * guarda como «otro teléfono» y sirve para una urgencia. Un botón
         * redondo sin más se lee como «llamar a esta persona», que es
         * justo lo contrario de lo que hace. */
        $html .= '<div class="pl-contactrow">';
        $html .= '<a class="pl-contactrow-main" href="tel:' . esc_attr($emergencia['tel']) . '">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2 4.2 2 2 0 0 1 4 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.4 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.4 1.8.6 2.8.8a2 2 0 0 1 1.7 2Z"/></svg>'
            . '<span class="pl-contactrow-body">'
            . '<span class="pl-contactrow-label">' . esc_html__('Otro teléfono · para una urgencia', 'sticpa') . '</span>'
            . '<span class="pl-contactrow-value">' . esc_html($emergencia['display']) . '</span>'
            . '</span></a>';
        $html .= '</div>';
    }

    $html .= '</div>';
}

/* «No llamar», si lo ha pedido. Va AQUÍ y no enterrado en los datos de padrón:
 * es lo único de esta pantalla que cambia lo que puedes hacer con los botones
 * que tienes justo encima. */
if (sticpa_pl_si_no($ficha['do_not_call'])) {
    $html .= '<p class="pl-hint pl-hint--rule">' . sticpa_pl_icon('warn') . '<span>'
        . esc_html__('Ha pedido que no se le llame por teléfono.', 'sticpa') . '</span></p>';
}

// ---------------------------------------------------------------------------
// Seguimiento del curso: las tres pistas
// ---------------------------------------------------------------------------

/* LA RAZÓN DE ESTA PANTALLA.
 *
 * Tres filas y NO una media: las reuniones de programación son tres o cuatro al
 * año, así que faltar a una pesa mucho más que faltar a un sábado, y juntarlas
 * en un número esconde justo lo que se viene a mirar. Los cuadraditos van
 * delante del porcentaje porque enseñan el PATRÓN —cuatro faltas seguidas en
 * enero no es lo mismo que una de cada cinco desde octubre— y es el patrón lo
 * que hace que coordinación llame o no llame. */
$hayPistas = ($seg['sesiones'] !== null || $seg['reuniones'] !== null || !empty($seg['listas']));

if ($hayPistas) {
    $html .= '<div class="pl-sec">' . esc_html__('Cómo va este curso', 'sticpa') . '</div>';
    $html .= '<div class="pl-tracks">';
    $usados = array();

    // --- Sábados ---
    if ($seg['sesiones'] !== null) {
        $t = $seg['sesiones']['track'];
        if (!$seg['sesiones']['inscrito']) {
            // No es un 0 %: es que no está inscrito al evento. Decirlo con
            // palabras, porque un cero rojo aquí acusa a quien no debe.
            $html .= '<div class="pl-track"><div class="pl-track-head">'
                . '<span class="pl-track-title">' . esc_html__('Sábados', 'sticpa') . '</span></div>'
                . '<div class="pl-track-foot">'
                . esc_html__('No está inscrito al evento de sesiones, así que no hay asistencias suyas todavía.', 'sticpa')
                . '</div></div>';
        } else {
            $pie = sprintf(
                /* translators: 1: a cuántas vino, 2: cuántas se contaron */
                __('Vino a %1$d de %2$d', 'sticpa'),
                $t['attended'],
                $t['counted']
            );
            if ($t['unknown'] > 0) {
                $pie .= ' · ' . sprintf(
                    /* translators: %d: sesiones sin marcar */
                    _n('%d sábado sin marcar, fuera de la cuenta', '%d sábados sin marcar, fuera de la cuenta', $t['unknown'], 'sticpa'),
                    $t['unknown']
                );
            }
            $html .= sticpa_pl_track_html(
                __('Sábados', 'sticpa'),
                $t['squares'],
                sticpa_pl_pct_html($t['pct']),
                $pie,
                'asistencia'
            );
            $usados = array_merge($usados, sticpa_pl_sq_usados($t['squares']));
        }
    }

    // --- Reuniones de programación ---
    if ($seg['reuniones'] !== null) {
        $t = $seg['reuniones']['track'];
        if ($t['elapsed'] === 0) {
            $html .= '<div class="pl-track"><div class="pl-track-head">'
                . '<span class="pl-track-title">' . esc_html__('Reuniones', 'sticpa') . '</span></div>'
                . '<div class="pl-track-foot">'
                . esc_html__('Todavía no ha habido ninguna reunión de programación.', 'sticpa')
                . '</div></div>';
        } else {
            /* Con tres o cuatro reuniones al año un porcentaje es ruido —una
             * falta y ya estás en el 75 %—, así que el marcador es «2 de 4»,
             * que es como se dice en voz alta. */
            $marcador = '<span class="pl-track-frac">' . esc_html(sprintf(
                /* translators: 1: a cuántas vino, 2: cuántas ha habido */
                __('%1$d de %2$d', 'sticpa'),
                $t['attended'],
                $t['counted']
            )) . '</span>';
            $pie = ($t['unknown'] > 0)
                ? sprintf(
                    /* translators: %d: reuniones sin marcar */
                    _n('%d reunión sin marcar', '%d reuniones sin marcar', $t['unknown'], 'sticpa'),
                    $t['unknown']
                )
                : '';
            $html .= sticpa_pl_track_html(
                __('Reuniones', 'sticpa'),
                $t['squares'],
                $marcador,
                $pie,
                'asistencia',
                sprintf(
                    /* translators: 1: a cuántas vino, 2: cuántas ha habido */
                    _n('Vino a %1$d reunión de %2$d', 'Vino a %1$d reuniones de %2$d', $t['attended'], 'sticpa'),
                    $t['attended'],
                    $t['counted']
                )
            );
            $usados = array_merge($usados, sticpa_pl_sq_usados($t['squares']));
        }
    }

    if (!empty($usados)) {
        $html .= sticpa_pl_sq_legend_html('asistencia', array_values(array_unique($usados)));
    }
    $html .= '</div>';

    // --- Listas pasadas ---
    if (!empty($seg['listas'])) {
        $html .= '<div class="pl-tracks pl-tracks--listas">';
        $usadosL = array();
        foreach ($seg['listas'] as $fila) {
            $t = $fila['track'];
            $g = $fila['grupo'];
            $titulo = sprintf(
                /* translators: %s: código del grupo, p. ej. C2.3 */
                __('Listas del %s', 'sticpa'),
                $g['code']
            );
            $marcador = '<span class="pl-track-frac">' . esc_html(sprintf(
                /* translators: 1: sábados con lista, 2: sábados que tocaba */
                __('%1$d de %2$d', 'sticpa'),
                $t['con_lista'],
                $t['esperadas']
            )) . '</span>';
            /* El reparto solo se dice cuando HAY reparto. Con todas suyas, el
             * «1 de 3» de arriba ya lo cuenta y repetirlo en palabras sobra;
             * con ninguna suya, lo que hace falta es la explicación, no un
             * cero. */
            $pie = '';
            if ($t['suyas'] > 0 && $t['otras'] > 0) {
                $pie = sprintf(
                    /* translators: 1: cuántas pasó esta persona, 2: cuántas pasó otra */
                    __('%1$d las pasó, %2$d un compañero', 'sticpa'),
                    $t['suyas'],
                    $t['otras']
                );
            } elseif ($t['otras'] > 0) {
                $pie = ($t['otras'] === 1)
                    ? __('La pasó otra persona', 'sticpa')
                    : __('Las pasó otra persona', 'sticpa');
            } elseif ($t['suyas'] > 0) {
                $pie = __('Las ha pasado siempre', 'sticpa');
            }
            if ($t['omitidas'] > 0) {
                $pie .= ($pie !== '' ? ' · ' : '') . sprintf(
                    /* translators: %d: sábados marcados como que no hubo sesión */
                    _n('%d sábado sin sesión', '%d sábados sin sesión', $t['omitidas'], 'sticpa'),
                    $t['omitidas']
                );
            }
            $html .= sticpa_pl_track_html($titulo, $t['squares'], $marcador, $pie, 'listas');
            $usadosL = array_merge($usadosL, sticpa_pl_sq_usados($t['squares']));
        }
        if (!empty($usadosL)) {
            $html .= sticpa_pl_sq_legend_html('listas', array_values(array_unique($usadosL)));
        }
        $html .= '</div>';
        /* EL AVISO IMPORTANTE, y va en la pantalla y no solo en la
         * documentación: una lista de grupo la puede pasar cualquiera que cubra
         * ese sábado, así que «no la pasó» puede querer decir «no vino y la
         * pasó otro», que es lo correcto. Esta fila se lee junto a la de
         * sábados o no se lee. */
        $html .= '<p class="pl-footnote">'
            . esc_html__('La lista de un grupo la puede pasar quien cubra ese sábado, así que esta fila se lee junto a la de arriba.', 'sticpa')
            . '</p>';
    }
}

// ---------------------------------------------------------------------------
// Sus grupos
// ---------------------------------------------------------------------------

if (!empty($grupos)) {
    $html .= '<div class="pl-sec">' . esc_html__('Sus grupos', 'sticpa') . '</div>';
    $html .= '<div class="pl-list">';
    foreach ($grupos as $g) {
        $esMonitor = ($g['papel'] === 'monitor');
        // Si lleva el grupo y el grupo entra en Pasar Lista, la tarjeta lleva a
        // su lista: desde la ficha de un monitor lo siguiente que se quiere es
        // ver cómo va su grupo, y así es un toque.
        $url = ($esMonitor && $g['en_pasar_lista'])
            ? '?internalpage=single_stic_pasar_lista_marcar&grupo=' . rawurlencode($g['id'])
            : '';
        $tag = $esMonitor ? __('lo lleva', 'sticpa') : __('su grupo', 'sticpa');
        $tagClass = $esMonitor ? 'pl-grp-role--lleva' : 'pl-grp-role--suyo';

        $cuerpo = '<div class="pl-grp-head">';
        $cuerpo .= '<span class="pl-grp-code">' . esc_html($g['code']) . '</span>';
        $sub = trim($g['name'] . (($g['name'] !== '' && $g['cursos'] !== '') ? ' · ' : '') . $g['cursos']);
        if ($sub !== '') {
            $cuerpo .= '<span class="pl-grp-name">' . esc_html($sub) . '</span>';
        }
        $cuerpo .= '<span class="pl-grp-role ' . esc_attr($tagClass) . '">' . esc_html($tag) . '</span>';
        $cuerpo .= '</div>';

        $meta = array();
        if ($g['n_participantes'] > 0) {
            $meta[] = sprintf(
                /* translators: %d: cuántos participantes tiene el grupo */
                _n('%d participante', '%d participantes', $g['n_participantes'], 'sticpa'),
                $g['n_participantes']
            );
        }
        if ($g['n_monitores'] > 0) {
            $meta[] = sprintf(
                /* translators: %d: cuántos monitores tiene el grupo */
                _n('%d monitor', '%d monitores', $g['n_monitores'], 'sticpa'),
                $g['n_monitores']
            );
        }
        if ($g['desde'] > 0) {
            $meta[] = sprintf(
                /* translators: %s: año en que empezó en ese grupo */
                __('desde %s', 'sticpa'),
                date_i18n('Y', $g['desde'])
            );
        }
        if (!empty($meta)) {
            $cuerpo .= '<div class="pl-grp-meta">' . esc_html(implode(' · ', $meta)) . '</div>';
        }
        if (!empty($g['companeros'])) {
            $cuerpo .= '<div class="pl-grp-with">' . esc_html(sprintf(
                /* translators: %s: nombres de los otros monitores del grupo */
                __('Con %s', 'sticpa'),
                $conNombres($g['companeros'])
            )) . '</div>';
        }
        if (!$g['en_pasar_lista']) {
            $cuerpo .= '<div class="pl-grp-off">' . esc_html__('Este grupo no está marcado para Pasar Lista.', 'sticpa') . '</div>';
        }

        $html .= ($url !== '')
            ? '<a class="pl-grp pl-grp--link" href="' . esc_url($url) . '">' . $cuerpo
                . '<span class="pl-grp-go" aria-hidden="true">' . sticpa_pl_icon('next') . '</span></a>'
            : '<div class="pl-grp">' . $cuerpo . '</div>';
    }
    $html .= '</div>';
}

// ---------------------------------------------------------------------------
// Seguimientos
// ---------------------------------------------------------------------------

/* La sección solo existe si este usuario puede leer o escribir algo. No se
 * pinta un bloque vacío: enseñar una sección que no hace nada insinúa que hay
 * algo detrás, y aquí eso es peor que no enseñarla. */
$segTipos = sticpa_pl_seg_tipos();

if ($segOn) {
    $todos = sticpa_pl_seguimientos($objSCP, $monitorId, $segRoles);

    /* POR DEFECTO, SOLO ESTE CURSO.
     *
     * El CRM devuelve las notas de todos los años en la misma lectura, y una
     * lista con cinco cursos de incidencias es un archivo, no una pantalla de
     * seguimiento: lo de octubre queda sepultado bajo lo de hace tres años. Con
     * `?seg=antes` se suma el curso anterior — y no cuesta ninguna consulta
     * más, porque los datos ya estaban aquí: lo único que cambia es el filtro. */
    $cursoActual = sticpa_pl_course_for()['label'];
    $cursoAnterior = sticpa_pl_curso_anterior($cursoActual);
    $verAnterior = (isset($_REQUEST['seg']) && $_REQUEST['seg'] === 'antes');

    $cursos = array($cursoActual);
    if ($verAnterior && $cursoAnterior !== '') {
        $cursos[] = $cursoAnterior;
    }
    $items = sticpa_pl_seg_del_curso($todos, $cursos);
    // Cuántos quedan fuera: sin esto, el botón de traer los de antes aparecería
    // también cuando no hay nada que traer.
    $hayAnteriores = (count($todos) > count($items));

    $html .= '<div class="pl-sec" id="seguimientos">' . esc_html__('Seguimientos', 'sticpa') . '</div>';

    if ($segMsg !== '') {
        $html .= '<p class="pl-notice"><span>' . esc_html($segMsg) . '</span></p>';
    }

    if (!empty($items)) {
        $html .= '<div class="pl-list">';
        foreach ($items as $it) {
            $meta = $segTipos[$it['tipo']];
            $html .= '<div class="pl-seg" style="border-left-color:' . esc_attr($meta['color']) . '">';
            $html .= '<div class="pl-seg-head">';
            $html .= '<span class="pl-seg-tipo" style="background:' . esc_attr($meta['color']) . '">'
                . esc_html($meta['label']) . '</span>';
            if ($it['ts'] > 0) {
                $html .= '<span class="pl-seg-when">' . esc_html(date_i18n('j M Y', $it['ts'])) . '</span>';
            }
            if ($it['autor'] !== '') {
                $html .= '<span class="pl-seg-who">' . esc_html($it['autor']) . '</span>';
            }
            $html .= '</div>';
            $html .= '<div class="pl-seg-text">' . nl2br(esc_html($it['texto'])) . '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';
    } elseif (!empty($segReadable)) {
        /* El vacío, dicho con calma. Un aviso con icono de información parece
         * que algo falta o falla; aquí no falta nada: es que todavía no ha
         * pasado nada que apuntar, y eso es lo normal en octubre. */
        $html .= '<div class="pl-empty">';
        $html .= '<span class="pl-empty-mark" aria-hidden="true">' . sticpa_pl_icon('pencil') . '</span>';
        $html .= '<span class="pl-empty-text">' . esc_html(
            $verAnterior
                ? __('Nada apuntado en estos dos cursos.', 'sticpa')
                : __('Nada apuntado este curso.', 'sticpa')
        ) . '</span>';
        $html .= '</div>';
    }

    // Traer los del curso anterior. Un enlace de texto, discreto y al final de
    // lo que hay: es un «¿y antes?», no una acción de la pantalla.
    if ($hayAnteriores && !$verAnterior && $cursoAnterior !== '') {
        $html .= '<a class="pl-ghost" href="?internalpage=single_stic_pasar_lista_monitor&monitor='
            . rawurlencode($monitorId) . '&seg=antes#seguimientos">' . esc_html(sprintf(
                /* translators: %s: el curso anterior, p. ej. 2024-2025 */
                __('Ver también los de %s', 'sticpa'),
                $cursoAnterior
            )) . '</a>';
    } elseif ($verAnterior) {
        $html .= '<a class="pl-ghost" href="?internalpage=single_stic_pasar_lista_monitor&monitor='
            . rawurlencode($monitorId) . '#seguimientos">' . esc_html(sprintf(
                /* translators: %s: el curso actual, p. ej. 2025-2026 */
                __('Ver solo los de %s', 'sticpa'),
                $cursoActual
            )) . '</a>';
    }

    // El alta: solo los tipos que ESTE usuario puede escribir. Coordinación no
    // ve la opción de acompañamiento, y el guardado lo vuelve a comprobar.
    if (!empty($segWritable)) {
        /* El formulario, DETRÁS de un botón. Cuatro campos desplegados al final
         * de la ficha son cuatro campos que hay que pasar cada vez que se abre
         * a alguien, y se escribe una nota de cada veinte visitas. El botón
         * abre y pone el foco en el desplegable, que es el primer campo: se
         * puede escribir entera sin tocar la pantalla otra vez. */
        $html .= '<button type="button" class="pl-seg-add" data-pl-seg-add aria-expanded="false"'
            . ' aria-controls="pl-seg-form">'
            . sticpa_pl_icon('pencil')
            . '<span>' . esc_html__('Escribir un seguimiento', 'sticpa') . '</span></button>';
        $html .= '<form method="post" id="pl-seg-form" class="pl-newmeet stic-loading-form" hidden data-pl-seg-form'
            . ' data-loading-text="' . esc_attr__('Guardando…', 'sticpa') . '">';
        $html .= wp_nonce_field('pl_seg_' . $monitorId, 'pl_nonce', true, false);

        $html .= '<div class="pl-field-row">';
        $html .= '<label class="pl-field">';
        $html .= '<span class="pl-field-label">' . esc_html__('Tipo', 'sticpa') . '</span>';
        $html .= '<select name="pl_seg_tipo" data-pl-seg-first>';
        foreach ($segWritable as $key) {
            $html .= '<option value="' . esc_attr($key) . '">'
                . esc_html($segTipos[$key]['label']) . '</option>';
        }
        $html .= '</select>';
        $html .= '</label>';

        $html .= '<label class="pl-field pl-field--date">';
        $html .= '<span class="pl-field-label">' . esc_html__('Día', 'sticpa') . '</span>';
        // La fecha del HECHO, no la de hoy: se escribe el lunes lo del sábado.
        // Por defecto hoy, que es el caso más frecuente.
        $html .= '<input type="date" name="pl_seg_fecha" value="'
            . esc_attr(date('Y-m-d', sticpa_pl_now())) . '">';
        $html .= '</label>';
        $html .= '</div>';

        $html .= '<label class="pl-field">';
        $html .= '<span class="pl-field-label">' . esc_html__('Qué pasó', 'sticpa') . '</span>';
        $html .= '<textarea name="pl_seg_texto" rows="3" required'
            . ' placeholder="' . esc_attr__('En dos líneas, para acordarse.', 'sticpa') . '"></textarea>';
        $html .= '</label>';

        $html .= '<button type="submit" class="pl-save">' . esc_html__('Guardar seguimiento', 'sticpa') . '</button>';

        if (in_array('acompanamiento', $segWritable, true)) {
            $html .= '<p class="pl-seg-warn">' . sticpa_pl_icon('info') . '<span>'
                . esc_html__('Las notas de acompañamiento solo las ve quien acompaña. Ni coordinación ni la propia persona.', 'sticpa')
                . '</span></p>';
        }
        $html .= '</form>';
    }
}
// ---------------------------------------------------------------------------
// Por dónde ha pasado
// ---------------------------------------------------------------------------

/* Lo que el CRM no enseña de un vistazo: hay que abrir sus relaciones una a una
 * y cruzarlas con las de los demás. Aquí sale entero y gratis, porque las
 * relaciones terminadas vienen en la misma consulta que las vigentes. */
if (count($historico) > 1 || (count($historico) === 1 && !$historico[0]['actual'])) {
    /* Plegable, y ABIERTO de partida: es información que se viene a leer, no
     * que se esconde. Pero un monitor de doce años son doce cursos, y quien
     * solo quería el teléfono agradece poder cerrarlo de un toque. `<details>`
     * es nativo: funciona en la webview, no necesita JS y el navegador ya sabe
     * animarlo. */
    $html .= '<details class="pl-fold pl-fold--hist" open>';
    $html .= '<summary class="pl-fold-sum">' . esc_html__('Por dónde ha pasado', 'sticpa')
        . '<span class="pl-fold-count">' . esc_html(sprintf(
            /* translators: %d: en cuántos cursos ha sido monitor */
            _n('%d curso', '%d cursos', count($historico), 'sticpa'),
            count($historico)
        )) . '</span></summary>';
    $html .= '<ol class="pl-hist">';
    foreach ($historico as $curso) {
        $html .= '<li class="pl-hist-item' . ($curso['actual'] ? ' pl-hist-item--now' : '') . '">';
        $html .= '<span class="pl-hist-dot" aria-hidden="true"></span>';
        $html .= '<div class="pl-hist-body">';
        $html .= '<div class="pl-hist-curso">' . esc_html($curso['curso']);
        if ($curso['actual']) {
            $html .= ' <span class="pl-hist-now">' . esc_html__('ahora', 'sticpa') . '</span>';
        }
        $html .= '</div>';
        foreach ($curso['grupos'] as $g) {
            $html .= '<div class="pl-hist-g">';
            $html .= '<span class="pl-hist-code">' . esc_html($g['code']) . '</span>';
            $sub = trim($g['name'] . (($g['name'] !== '' && $g['cursos'] !== '') ? ' · ' : '') . $g['cursos']);
            if ($sub !== '') {
                $html .= '<span class="pl-hist-name">' . esc_html($sub) . '</span>';
            }
            $html .= '</div>';
            if (!empty($g['companeros'])) {
                $html .= '<div class="pl-hist-with">' . esc_html(sprintf(
                    /* translators: %s: nombres de los otros monitores de aquel curso */
                    __('con %s', 'sticpa'),
                    $conNombres($g['companeros'])
                )) . '</div>';
            }
        }
        $html .= '</div></li>';
    }
    $html .= '</ol>';
    $html .= '<p class="pl-footnote">'
        . esc_html__('El curso de cada relación se calcula de sus fechas de inicio y fin en el CRM.', 'sticpa')
        . '</p>';
    $html .= '</details>';
}

// ---------------------------------------------------------------------------
// El papeleo: en regla, datos MCM, formación y datos personales
// ---------------------------------------------------------------------------

foreach (sticpa_pl_monitor_bloques($ficha) as $bloque) {
    if ($bloque['kind'] === 'check') {
        // La cuenta de lo que falta va en la cabecera: es el resumen que se
        // busca, y evita repasar ocho filas para ver si hay algún rojo.
        $faltan = 0;
        foreach ($bloque['rows'] as $r) {
            if ($r['req'] && !$r['ok']) {
                $faltan++;
            }
        }
        $html .= '<div class="pl-sec-row">';
        $html .= '<div class="pl-sec">' . esc_html($bloque['label']) . '</div>';
        $html .= ($faltan > 0)
            ? '<span class="pl-flag pl-flag--bad">' . esc_html(sprintf(
                /* translators: %d: cuántas obligaciones faltan */
                _n('falta %d', 'faltan %d', $faltan, 'sticpa'),
                $faltan
            )) . '</span>'
            : '<span class="pl-flag pl-flag--yes">' . esc_html__('todo en regla', 'sticpa') . '</span>';
        $html .= '</div>';

        $html .= '<div class="pl-list pl-list--data">';
        foreach ($bloque['rows'] as $r) {
            $clase = $r['ok'] ? 'pl-chk--ok' : ($r['req'] ? 'pl-chk--bad' : 'pl-chk--opt');
            $html .= '<div class="pl-chk ' . esc_attr($clase) . '">';
            $html .= '<span class="pl-chk-icon" aria-hidden="true">'
                . sticpa_pl_glyph($r['ok'] ? 'check' : ($r['req'] ? 'cross' : 'dash')) . '</span>';
            $html .= '<span class="pl-chk-body">';
            $html .= '<span class="pl-chk-label">' . esc_html($r['label']) . '</span>';
            $nota = $r['note'];
            if ($nota === '' && !$r['ok'] && !$r['req']) {
                $nota = __('No lo ha autorizado', 'sticpa');
            }
            if ($nota !== '') {
                $html .= '<span class="pl-chk-note">' . esc_html($nota) . '</span>';
            }
            $html .= '</span></div>';
        }
        $html .= '</div>';
        continue;
    }

    if ($bloque['kind'] === 'flag') {
        /* PLEGABLE, con lo que importa en la solapa.
         *
         * «Formación» son cinco filas más los congresos, y en dos años de
         * coordinación se abre una vez: desplegada, empuja hacia abajo lo que
         * sí se mira. Pero plegarla a secas escondería el dato que de verdad
         * se busca —si tiene el MAT, si tiene el DAT—, así que ese resumen
         * sube a la solapa y se lee sin abrir nada. Plegar sin perder.
         */
        $plegable = !empty($bloque['plegado']);
        if ($plegable) {
            $resumen = array();
            foreach ($bloque['rows'] as $r) {
                if (!empty($r['ok']) && $r['warn'] === '') {
                    // Solo la sigla, que es como se nombran de verdad.
                    $sigla = trim(strtok($r['label'], '·'));
                    $resumen[] = ($sigla !== '') ? $sigla : $r['label'];
                }
            }
            $html .= '<details class="pl-fold"><summary class="pl-fold-sum">'
                . esc_html($bloque['label']);
            if (!empty($resumen)) {
                $html .= '<span class="pl-fold-count">'
                    . esc_html(implode(' · ', array_slice($resumen, 0, 3))) . '</span>';
            }
            $html .= '</summary>';
        } else {
            $html .= '<div class="pl-sec">' . esc_html($bloque['label']) . '</div>';
        }
        if (!empty($bloque['rows'])) {
            $html .= '<div class="pl-list pl-list--data">';
            foreach ($bloque['rows'] as $r) {
                $html .= '<div class="pl-flagrow">';
                $html .= '<span>' . esc_html($r['label']);
                if ($r['note'] !== '') {
                    $html .= ' <span class="pl-rowsub">' . esc_html($r['note']) . '</span>';
                }
                $html .= '</span>';
                if ($r['warn'] !== '') {
                    $html .= '<span class="pl-flag pl-flag--warn">' . esc_html($r['warn']) . '</span>';
                } else {
                    $html .= '<span class="pl-flag ' . ($r['ok'] ? 'pl-flag--yes' : 'pl-flag--no') . '">'
                        . esc_html($r['value']) . '</span>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }
        if (!empty($bloque['chips'])) {
            $html .= '<div class="pl-chips-wrap"><span class="pl-chips-label">'
                . esc_html($bloque['chips_label']) . '</span><div class="pl-chips">';
            foreach ($bloque['chips'] as $c) {
                $html .= '<span class="pl-chip">' . esc_html($c) . '</span>';
            }
            $html .= '</div></div>';
        }
        if (!empty($bloque['nota'])) {
            $html .= '<div class="pl-list pl-list--data"><div class="pl-data">'
                . '<span class="pl-data-label">' . esc_html($bloque['nota_label']) . '</span>'
                . '<span class="pl-data-value">' . nl2br(esc_html($bloque['nota'])) . '</span>'
                . '</div></div>';
        }
        if ($plegable) {
            $html .= '</details>';
        }
        continue;
    }

    // kind === 'data'
    $filas = '<div class="pl-list pl-list--data">';
    foreach ($bloque['rows'] as $r) {
        $filas .= '<div class="pl-kv"><span class="pl-kv-label">' . esc_html($r['label'])
            . '</span><span class="pl-kv-value">' . esc_html($r['value']) . '</span></div>';
    }
    $filas .= '</div>';

    if (!empty($bloque['plegado'])) {
        // Plegado y no escondido: en dos años de coordinación esto se mira una
        // vez, y desplegado son cinco filas de scroll entre lo que sí importa.
        // `<details>` es nativo, funciona en la webview y no necesita JS.
        $html .= '<details class="pl-fold"><summary class="pl-fold-sum">'
            . esc_html($bloque['label']) . '</summary>' . $filas . '</details>';
    } else {
        $html .= '<div class="pl-sec">' . esc_html($bloque['label']) . '</div>' . $filas;
    }
}

