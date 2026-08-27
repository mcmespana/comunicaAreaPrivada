<?php
/**
 * PASAR LISTA — ficha del participante.
 * ----------------------------------------------------------------------------
 * Se llega desde la flecha de una fila de la lista: ?participante=<id>&grupo=<id>.
 *
 * El orden lo manda para qué se abre esta pantalla: con prisa, para llamar a la
 * familia. Así que primero el contacto, y los datos después. El pañuelo baja
 * con los permisos porque no es un dato de urgencia.
 *
 * Diseño: docs/comunica/PASAR-LISTA.md §6.4
 */

if (!defined('ABSPATH')) {
    exit;
}

$pageSettings['fileName'] = basename(__FILE__, ".php");

$contactId = isset($_REQUEST['participante']) ? sticpa_pl_safe_id($_REQUEST['participante']) : '';
$groupId = isset($_REQUEST['grupo']) ? sticpa_pl_safe_id($_REQUEST['grupo']) : '';

if ($contactId === '') {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('No se ha indicado ningún participante.', 'sticpa') . '</span></p>';
    return;
}

// El participante tiene que ser de un grupo de MI delegación. El CRM ya limita
// por grupo de seguridad, pero comprobarlo aquí evita enseñar media pantalla
// vacía cuando alguien cambia el id en la URL.
$groups = sticpa_pl_groups($objSCP);
$group = ($groupId !== '' && isset($groups[$groupId])) ? $groups[$groupId] : null;
$people = ($group !== null) ? sticpa_pl_group_people($objSCP, $groupId) : array('participants' => array(), 'monitors' => array());

$inGroup = false;
foreach ($people['participants'] as $p) {
    if ($p['id'] === $contactId) {
        $inGroup = true;
        break;
    }
}
if (!$inGroup) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('Este participante no está en el grupo indicado.', 'sticpa') . '</span></p>';
    return;
}

// ---------------------------------------------------------------------------
// Cambio de pañuelo (la única escritura de esta pantalla)
// ---------------------------------------------------------------------------

$panueloMsg = '';
if (!empty($_POST['pl_panuelo'])) {
    if (!isset($_POST['pl_nonce']) || !wp_verify_nonce($_POST['pl_nonce'], 'pl_ficha_' . $contactId)) {
        $panueloMsg = __('La sesión ha caducado. Vuelve a cargar la pantalla.', 'sticpa');
    } else {
        $ok = sticpa_pl_set_panuelo($objSCP, $contactId, $_POST['pl_panuelo']);
        $panueloMsg = $ok
            ? __('Pañuelo actualizado.', 'sticpa')
            : __('No se ha podido cambiar el pañuelo.', 'sticpa');
    }
}

// ---------------------------------------------------------------------------
// Poner un aviso de comportamiento
// ---------------------------------------------------------------------------

$avisoMsg = '';
if (isset($_POST['pl_aviso_motivo'])) {
    if (!isset($_POST['pl_nonce']) || !wp_verify_nonce($_POST['pl_nonce'], 'pl_ficha_' . $contactId)) {
        $avisoMsg = __('La sesión ha caducado. Vuelve a cargar la pantalla.', 'sticpa');
    } else {
        $ok = sticpa_pl_create_aviso(
            $objSCP,
            $contactId,
            sanitize_textarea_field(wp_unslash($_POST['pl_aviso_motivo'])),
            isset($_POST['pl_aviso_fecha']) ? $_POST['pl_aviso_fecha'] : '',
            !empty($_POST['pl_aviso_notificado'])
        );
        $avisoMsg = $ok
            ? __('Aviso registrado.', 'sticpa')
            : __('No se ha podido registrar el aviso. Revisa que hayas escrito el motivo.', 'sticpa');
    }
}

$ficha = sticpa_pl_ficha($objSCP, $contactId);
if ($ficha === null) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('No se han podido cargar los datos.', 'sticpa') . '</span></p>';
    return;
}

$family = sticpa_pl_family($objSCP, $contactId);

// ---------------------------------------------------------------------------
// Cabecera
// ---------------------------------------------------------------------------

/* La cabecera es solo la vuelta atrás y la palabra "Ficha": el nombre no va
 * aquí, va en el bloque de identidad de debajo. Meterlo en la cabecera lo dejaba
 * a 1,15 rem y compitiendo con la flecha; un nombre propio es la cara de esta
 * pantalla y merece su sitio y su tamaño. */
$html .= '<div class="pl-head">';
$html .= '<a class="pl-back" href="?internalpage=single_stic_pasar_lista_marcar&grupo=' . esc_attr($groupId) . '"'
    . ' aria-label="' . esc_attr__('Volver a la lista', 'sticpa') . '">' . sticpa_pl_icon('back') . '</a>';
$html .= '<div class="pl-head-titles">';
$html .= '<div class="pl-subtitle">' . esc_html__('Ficha', 'sticpa') . '</div>';
$html .= '</div>';
$html .= '</div>';

// Identidad: el avatar con el degradado de marca —la única "foto" que hay— y el
// nombre entero. Debajo, en una línea gris y sin etiquetas, lo que sitúa a la
// persona: grupo, etapa, curso y edad.
$bits = array();
if ($group !== null) {
    $bits[] = $group['code'] . ($group['name'] !== '' ? ' · ' . $group['name'] : '');
    $groupEtapa = sticpa_pl_group_etapa($group['level']);
    if ($groupEtapa !== '') {
        $bits[] = $groupEtapa;
    }
    if ($group['cursos'] !== '') {
        $bits[] = $group['cursos'];
    }
}
if ($ficha['stic_age_c'] !== '') {
    $bits[] = sprintf(
        /* translators: %s: edad en años */
        __('%s años', 'sticpa'),
        $ficha['stic_age_c']
    );
}

$html .= '<div class="pl-ident">';
$html .= '<span class="pl-ident-avatar" aria-hidden="true">'
    . esc_html(sticpa_pl_initials('', '', $ficha['name'])) . '</span>';
$html .= '<span class="pl-ident-body">';
$html .= '<span class="pl-ident-name">' . esc_html($ficha['name']) . '</span>';
if (!empty($bits)) {
    $html .= '<span class="pl-ident-meta">' . esc_html(implode(' · ', $bits)) . '</span>';
}
$html .= '</span>';
$html .= '</div>';

if ($panueloMsg !== '') {
    $html .= '<p class="pl-notice"><span>' . esc_html($panueloMsg) . '</span></p>';
}
if ($avisoMsg !== '') {
    $html .= '<p class="pl-notice"><span>' . esc_html($avisoMsg) . '</span></p>';
}

// ---------------------------------------------------------------------------
// Teléfonos: lo primero, porque es para lo que se abre la ficha
// ---------------------------------------------------------------------------

/**
 * Una tarjeta de teléfono con sus dos botones.
 * $whatsapp = false cuando el permiso no lo autoriza: entonces solo se llama.
 */
$phoneCard = function ($label, $rawPhone, $whatsapp = true) {
    $phone = sticpa_pl_phone($rawPhone);
    if ($phone === null) {
        return '';
    }
    $out = '<div class="pl-phone">';
    $out .= '<div class="pl-phone-who"><span class="pl-phone-label">' . esc_html($label) . '</span>'
        . '<span class="pl-phone-num">' . esc_html($phone['display']) . '</span></div>';
    $out .= '<div class="pl-phone-acts">';
    if ($whatsapp) {
        $out .= '<a class="pl-phone-btn pl-phone-btn--wa" href="https://wa.me/' . esc_attr($phone['wa']) . '"'
            . ' target="_blank" rel="noopener" aria-label="' . esc_attr__('WhatsApp', 'sticpa') . '">'
            . '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm5.3 14.1c-.2.6-1.2 1.2-1.7 1.2-.5 0-1 .1-1.8-.2a12 12 0 0 1-5-4.4c-.5-.9-.8-1.7-.8-2.4 0-.7.4-1.4.7-1.7.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .5.4l.7 1.7c.1.2 0 .4-.1.5l-.4.5c-.1.2-.2.3 0 .5.5.9 1.5 1.8 2.4 2.2.2.1.4.1.5 0l.6-.6c.2-.2.3-.1.5-.1l1.6.8c.2.1.3.2.3.3 0 .2 0 .8-.2 1.2Z"/></svg>'
            . '</a>';
    }
    $out .= '<a class="pl-phone-btn pl-phone-btn--call" href="tel:' . esc_attr($phone['tel']) . '"'
        . ' aria-label="' . esc_attr__('Llamar', 'sticpa') . '">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2 4.2 2 2 0 0 1 4 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.4 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.4 1.8.6 2.8.8a2 2 0 0 1 1.7 2Z"/></svg>'
        . '</a>';
    $out .= '</div></div>';
    return $out;
};

/* Contacto rápido. Los dos botones grandes ANTES de la lista de teléfonos,
 * porque la ficha se abre casi siempre para lo mismo: llamar a una casa ya. El
 * número al que apuntan es el de REFERENCIA de la familia —el que el CRM marca
 * como tal— y si no hay, el primero que haya. Elegir a quién llamar es la lista
 * de debajo; esto es el caso normal en un toque.
 *
 * Si no hay ningún teléfono de familia, los botones no se pintan: un botón
 * grande que no hace nada es peor que no tenerlo. */
$quick = null;
foreach ($family as $rel) {
    $p = sticpa_pl_phone($rel['mobile']);
    if ($p === null) {
        continue;
    }
    if ($rel['reference']) {
        $quick = array('phone' => $p, 'who' => $rel['name']);
        break;
    }
    if ($quick === null) {
        $quick = array('phone' => $p, 'who' => $rel['name']);
    }
}

if ($quick !== null) {
    $html .= '<div class="pl-contact">';
    $html .= '<a class="pl-contact-btn pl-contact-btn--wa" href="https://wa.me/'
        . esc_attr($quick['phone']['wa']) . '" target="_blank" rel="noopener"'
        . ' aria-label="' . esc_attr(sprintf(
            /* translators: %s: a quién se escribe */
            __('WhatsApp a %s', 'sticpa'),
            $quick['who']
        )) . '">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>'
        . '<span>' . esc_html__('WhatsApp', 'sticpa') . '</span></a>';
    $html .= '<a class="pl-contact-btn pl-contact-btn--call" href="tel:'
        . esc_attr($quick['phone']['tel']) . '"'
        . ' aria-label="' . esc_attr(sprintf(
            /* translators: %s: a quién se llama */
            __('Llamar a %s', 'sticpa'),
            $quick['who']
        )) . '">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2 4.2 2 2 0 0 1 4 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.4 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.4 1.8.6 2.8.8a2 2 0 0 1 1.7 2Z"/></svg>'
        . '<span>' . esc_html__('Llamar', 'sticpa') . '</span></a>';
    $html .= '</div>';
}

$phoneCards = '';
// El chaval primero si tiene móvil propio: en el COM lo tienen, y a veces es a
// quien hay que llamar. El botón de WhatsApp respeta ajmcm_menorwhatsapp_c.
$menorWa = ($ficha['ajmcm_menorwhatsapp_c'] === '1' || $ficha['ajmcm_menorwhatsapp_c'] === 'on');
$phoneCards .= $phoneCard(__('Suyo', 'sticpa'), $ficha['phone_mobile'], $menorWa);
foreach ($family as $rel) {
    $label = $rel['name'];
    // El parentesco, traducido: el CRM lo guarda en inglés («mother») y debajo
    // del nombre de la madre eso se lee raro.
    $parentesco = sticpa_pl_parentesco_label($rel['relationship']);
    if ($parentesco !== '') {
        $label .= ' · ' . $parentesco;
    }
    if ($rel['reference']) {
        $label .= ' · ' . __('REF', 'sticpa');
    }
    $phoneCards .= $phoneCard($label, $rel['mobile'], true);
}
$emergency = sticpa_pl_phone($ficha['phone_other']);
if ($emergency !== null) {
    $phoneCards .= $phoneCard(__('Emergencias', 'sticpa'), $ficha['phone_other'], false);
}

if ($phoneCards !== '') {
    $html .= '<div class="pl-sec">' . esc_html__('Teléfonos', 'sticpa') . '</div>';
    $html .= '<div class="pl-list">' . $phoneCards . '</div>';
} else {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('No hay ningún teléfono en la ficha. Avisa a coordinación para que lo completen.', 'sticpa')
        . '</span></p>';
}

// ---------------------------------------------------------------------------
// Asistencia hasta hoy
// ---------------------------------------------------------------------------

/* Todo el histórico del participante en UNA llamada, desde su inscripción. El
 * porcentaje va sobre las sesiones YA CELEBRADAS y se escribe con denominador:
 * en febrero el curso no lleva 24 sesiones, y un 82 % de noviembre y uno de
 * mayo se leen igual si no se dice sobre cuántas. */
$etapa = sticpa_pl_group_etapa($group['level']);
$events = sticpa_pl_etapa_events($objSCP);

if (isset($events[$etapa])) {
    $sessions = sticpa_pl_event_sessions($objSCP, $events[$etapa]['id']);
    $regMap = sticpa_pl_event_registrations($objSCP, $events[$etapa]['id']);
    $regId = array_search($contactId, $regMap, true);

    if ($regId !== false) {
        $marks = sticpa_pl_contact_marks($objSCP, $regId);
        $att = sticpa_pl_attendance($sessions, $marks);
        $streak = sticpa_pl_absence_streak($sessions, $marks);

        /* El porcentaje grande, y a su lado SIEMPRE el denominador: un 79 % de
         * noviembre y uno de mayo se leen igual si no se dice sobre cuántas.
         * La barra dice el mismo dato sin números, para el vistazo de un
         * segundo; el ancho se pone en línea porque es un dato, no un estilo. */
        $pct = max(0, min(100, (int) $att['pct']));
        $html .= '<div class="pl-sec">' . esc_html__('Asistencia', 'sticpa') . '</div>';
        $html .= '<div class="pl-att">';
        $html .= '<div class="pl-att-top">';
        $html .= '<span class="pl-att-pct">' . esc_html($att['pct']) . '<span>%</span></span>';
        $html .= '<span class="pl-att-meta">' . esc_html(sprintf(
            /* translators: 1: horas asistidas, 2: horas celebradas */
            __('%1$s h de %2$s h', 'sticpa'),
            $att['hours'],
            $att['hours_total']
        )) . '</span>';
        $html .= '</div>';
        $html .= '<div class="pl-att-bar" role="img" aria-label="' . esc_attr(sprintf(
            /* translators: %d: porcentaje de asistencia */
            __('%d %% de asistencia', 'sticpa'),
            $pct
        )) . '"><div class="pl-att-fill" style="width:' . esc_attr($pct) . '%"></div></div>';
        $html .= '<div class="pl-att-body">';
        $html .= '<span class="pl-att-main">' . esc_html(sprintf(
            /* translators: 1: sesiones a las que vino, 2: sesiones celebradas */
            __('%1$d de %2$d sesiones hasta hoy', 'sticpa'),
            $att['attended'],
            $att['elapsed']
        )) . '</span>';
        $html .= '</div>';
        $html .= '</div>';

        if ($streak >= sticpa_pl_streak_threshold()) {
            // El aviso solo si son SEGUIDAS. Tres repartidas en el curso no
            // dicen nada; tres seguidas sí, y merece una llamada a casa.
            $html .= '<p class="pl-notice" style="color:var(--danger-dark);padding-top:0.5rem">'
                . sticpa_pl_icon('clock') . '<span>' . esc_html(sprintf(
                    /* translators: %d: ausencias consecutivas */
                    _n('Lleva %d ausencia seguida.', 'Lleva %d ausencias seguidas.', $streak, 'sticpa'),
                    $streak
                )) . '</span></p>';
        }
    }
}

// ---------------------------------------------------------------------------
// Avisos de comportamiento
// ---------------------------------------------------------------------------

/* Un registro por aviso, con fecha y quién lo puso. El número (1, 2, 3) no está
 * guardado: sale de ordenar por fecha, así que retirar el de en medio renumera
 * los siguientes en vez de dejar un «1» y un «3» sin «2».
 *
 * El bloque se pinta aunque no haya ninguno: el monitor tiene que poder poner
 * el primero, y una sección que solo aparece cuando ya hay un problema no se
 * encuentra el día que hace falta. */
if (sticpa_pl_avisos_enabled()) {
    $avisos = sticpa_pl_avisos($objSCP, $contactId);
    $limite = sticpa_pl_avisos_limite();
    $puestos = count($avisos);

    $html .= '<div class="pl-sec-row">';
    $html .= '<div class="pl-sec">' . esc_html__('Avisos de comportamiento', 'sticpa') . '</div>';
    if ($puestos > 0) {
        // La escala: un punto por aviso puesto y un hueco por los que quedan.
        $html .= '<div class="pl-avi-scale">';
        for ($i = 1; $i <= max($limite, $puestos); $i++) {
            if ($i <= $puestos) {
                $html .= '<span class="pl-avi-pip" style="background:'
                    . esc_attr(sticpa_pl_aviso_color($i)) . '"></span>';
            } else {
                // El hueco del ÚLTIMO va en rojo: es el que no quieres llenar.
                $html .= '<span class="pl-avi-pip pl-avi-pip--free'
                    . ($i === $limite ? ' pl-avi-pip--last' : '') . '"></span>';
            }
        }
        $html .= '<span class="pl-avi-count">' . esc_html(sprintf(
            /* translators: 1: avisos puestos, 2: avisos que implican la salida */
            __('%1$d de %2$d', 'sticpa'),
            $puestos,
            $limite
        )) . '</span>';
        $html .= '</div>';
    }
    $html .= '</div>';

    $html .= '<div class="pl-list">';

    if ($puestos === 0) {
        $html .= '<p class="pl-avi-empty">' . esc_html__('Ningún aviso este curso.', 'sticpa') . '</p>';
    }

    foreach ($avisos as $aviso) {
        $html .= '<div class="pl-avi" style="border-left-color:' . esc_attr($aviso['color']) . '">';
        // El número se anuncia entero al lector de pantalla: "2" a secas dentro
        // de un círculo no dice de qué es.
        $html .= '<span class="pl-avi-num" style="background:' . esc_attr($aviso['color'])
            . ';color:' . esc_attr($aviso['ink']) . '"'
            . ' aria-label="' . esc_attr(sprintf(
                /* translators: 1: número del aviso, 2: avisos que implican la salida */
                __('Aviso %1$d de %2$d', 'sticpa'),
                $aviso['num'],
                $limite
            )) . '">' . esc_html($aviso['num']) . '</span>';
        $html .= '<span class="pl-avi-body">';
        $html .= '<span class="pl-avi-text">' . esc_html($aviso['motivo']) . '</span>';
        $html .= '<span class="pl-avi-meta">';

        // "8 nov · lo puso Mercedes": el cuándo y el quién, que es exactamente
        // lo que las tres casillas de AppSheet no guardaban.
        $when = date_i18n('j M', $aviso['ts']);
        $html .= '<span class="pl-avi-when">' . esc_html($aviso['puesto_por'] !== ''
            ? sprintf(
                /* translators: 1: fecha corta, 2: quién puso el aviso */
                __('%1$s · lo puso %2$s', 'sticpa'),
                $when,
                $aviso['puesto_por']
            )
            : $when) . '</span>';

        if ($aviso['notificado']) {
            $html .= '<span class="pl-avi-chip pl-avi-chip--yes">' . sticpa_pl_glyph('check')
                . esc_html__('Familia avisada', 'sticpa') . '</span>';
        } else {
            $html .= '<span class="pl-avi-chip pl-avi-chip--no">' . sticpa_pl_icon('clock')
                . esc_html__('Familia sin avisar', 'sticpa') . '</span>';
        }

        $html .= '</span></span></div>';
    }

    /* El recordatorio de que el siguiente es la salida: DEBAJO del último aviso
     * y ANTES del botón de añadir. Se dice antes de poner el tercero, no
     * después, que es cuando ya no sirve de nada. */
    if ($puestos >= $limite) {
        $html .= '<p class="pl-avi-warn">' . sticpa_pl_icon('warn') . '<span>' . sprintf(
            /* translators: %s: "el límite" en negrita */
            esc_html__('Ya está en %s. Esto implica la salida del grupo: hablad en coordinación antes de hacer nada.', 'sticpa'),
            '<strong>' . esc_html(sprintf(
                /* translators: %d: número de avisos */
                _n('%d aviso', '%d avisos', $puestos, 'sticpa'),
                $puestos
            )) . '</strong>'
        ) . '</span></p>';
    } elseif ($puestos === $limite - 1) {
        $html .= '<p class="pl-avi-warn">' . sticpa_pl_icon('warn') . '<span>' . sprintf(
            /* translators: %s: "el tercer aviso" en negrita */
            esc_html__('%s implica la salida del grupo. Habla con coordinación antes.', 'sticpa'),
            '<strong>' . esc_html(sprintf(
                /* translators: %d: el número del aviso que implica la salida */
                __('El aviso %d', 'sticpa'),
                $limite
            )) . '</strong>'
        ) . '</span></p>';
    }

    // Añadir: el botón enseña el formulario, que sale oculto. Poner un aviso no
    // puede ser un roce — la misma regla que el pañuelo.
    $html .= '<button type="button" class="pl-avi-add" data-pl-aviso-add>'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M5 12h14"/><path d="M12 5v14"/></svg>'
        . esc_html__('Añadir un aviso', 'sticpa') . '</button>';

    $html .= '<form method="post" class="pl-avi-form" data-pl-aviso-form hidden>';
    $html .= wp_nonce_field('pl_ficha_' . $contactId, 'pl_nonce', true, false);
    $html .= '<div class="pl-field">';
    $html .= '<label class="pl-field-label" for="pl-aviso-motivo">'
        . esc_html__('Qué ha pasado', 'sticpa') . '</label>';
    $html .= '<textarea id="pl-aviso-motivo" name="pl_aviso_motivo" rows="3" required'
        . ' placeholder="' . esc_attr__('En tus palabras. Lo va a leer coordinación y quizá la familia.', 'sticpa')
        . '"></textarea>';
    $html .= '</div>';
    $html .= '<div class="pl-field-row">';
    $html .= '<div class="pl-field pl-field--date">';
    $html .= '<label class="pl-field-label" for="pl-aviso-fecha">'
        . esc_html__('Cuándo', 'sticpa') . '</label>';
    // Por defecto hoy, y no se puede poner en el futuro.
    $html .= '<input type="date" id="pl-aviso-fecha" name="pl_aviso_fecha"'
        . ' value="' . esc_attr(date('Y-m-d', sticpa_pl_now())) . '"'
        . ' max="' . esc_attr(date('Y-m-d', sticpa_pl_now())) . '">';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '<label class="pl-avi-check">'
        . '<input type="checkbox" name="pl_aviso_notificado" value="1">'
        . '<span>' . esc_html__('Ya he hablado con la familia', 'sticpa') . '</span></label>';
    $html .= '<button type="submit" class="pl-avi-submit" data-pl-confirm="'
        . esc_attr(sprintf(
            /* translators: %s: nombre del participante */
            __('¿Poner un aviso a %s? Coordinación lo verá.', 'sticpa'),
            $ficha['name']
        )) . '">' . esc_html__('Registrar el aviso', 'sticpa') . '</button>';
    $html .= '</form>';

    $html .= '</div>';
}

// ---------------------------------------------------------------------------
// Salud: los cinco campos en UNA tarjeta, y solo lo que tenga contenido
// ---------------------------------------------------------------------------

$healthFields = array(
    'ajmcm_descripcion_allergies__c' => __('Alergias', 'sticpa'),
    'ajmcm_descripcion_intoler_c' => __('Intolerancias', 'sticpa'),
    'ajmcm_descripcion_tratam_c' => __('Tratamientos', 'sticpa'),
    'ajmcm_descripcion_enfermed_c' => __('Enfermedades', 'sticpa'),
    'ajmcm_descripcion_otros_c' => __('Otras patologías', 'sticpa'),
);
$health = '';
foreach ($healthFields as $field => $label) {
    if (trim($ficha[$field]) === '') {
        continue;   // una ficha sin nada de salud no enseña la tarjeta
    }
    $health .= '<div class="pl-data"><span class="pl-data-label">' . esc_html($label) . '</span>'
        . '<span class="pl-data-value">' . esc_html($ficha[$field]) . '</span></div>';
}
if ($health !== '') {
    $html .= '<div class="pl-sec">' . esc_html__('Salud', 'sticpa') . '</div>';
    $html .= '<div class="pl-list pl-list--data">' . $health . '</div>';
}

// ---------------------------------------------------------------------------
// Permisos
// ---------------------------------------------------------------------------

$yesNo = function ($raw) {
    $on = ($raw === '1' || $raw === 'on' || $raw === 'true');
    return '<span class="pl-flag pl-flag--' . ($on ? 'yes' : 'no') . '">'
        . ($on ? esc_html__('Sí', 'sticpa') : esc_html__('No', 'sticpa')) . '</span>';
};

$html .= '<div class="pl-sec">' . esc_html__('Permisos', 'sticpa') . '</div>';
$html .= '<div class="pl-list pl-list--data">';
$html .= '<div class="pl-flagrow"><span>' . esc_html__('Puede irse solo/a a casa', 'sticpa') . '</span>'
    . $yesNo($ficha['ajmcm_soloacasa_c']) . '</div>';
$html .= '<div class="pl-flagrow"><span>' . esc_html__('Cesión de imágenes', 'sticpa') . '</span>'
    . $yesNo($ficha['ajmcm_cesionimagenes_interne_c']) . '</div>';
$html .= '<div class="pl-flagrow"><span>' . esc_html__('WhatsApp del menor', 'sticpa') . '</span>'
    . $yesNo($ficha['ajmcm_menorwhatsapp_c']) . '</div>';
$html .= '</div>';

// ---------------------------------------------------------------------------
// Pañuelo: editable, con confirmación
// ---------------------------------------------------------------------------

$panuelos = sticpa_pl_panuelos();
$current = isset($panuelos[$ficha['ajmcm_panuelo_c']]) ? $ficha['ajmcm_panuelo_c'] : 'na';

$html .= '<div class="pl-sec">' . esc_html__('Pañuelo', 'sticpa') . '</div>';
$html .= '<form method="post" class="pl-panuelo" data-pl-panuelo>';
$html .= wp_nonce_field('pl_ficha_' . $contactId, 'pl_nonce', true, false);
$html .= '<div class="pl-panuelo-now">';
$html .= '<span class="pl-panuelo-dot" style="background:' . esc_attr($panuelos[$current]['color']) . '"></span>';
$html .= '<span class="pl-panuelo-label">' . esc_html($panuelos[$current]['label']) . '</span>';
$html .= '<button type="button" class="pl-panuelo-edit" data-pl-panuelo-edit>'
    . esc_html__('Cambiar', 'sticpa') . '</button>';
$html .= '</div>';

// Las opciones salen ocultas y las enseña el botón: cambiar el pañuelo tiene
// que costar dos toques a propósito.
$html .= '<div class="pl-panuelo-opts" data-pl-panuelo-opts hidden>';
foreach ($panuelos as $key => $meta) {
    if ($key === $current) {
        continue;
    }
    $html .= '<button type="submit" name="pl_panuelo" value="' . esc_attr($key) . '" class="pl-panuelo-opt"'
        . ' data-pl-confirm="' . esc_attr(sprintf(
            /* translators: 1: nombre del participante, 2: color del pañuelo */
            __('¿Cambiar el pañuelo de %1$s a %2$s?', 'sticpa'),
            $ficha['name'],
            $meta['label']
        )) . '">'
        . '<span class="pl-panuelo-dot" style="background:' . esc_attr($meta['color']) . '"></span>'
        . esc_html($meta['label'])
        . '</button>';
}
$html .= '</div>';
$html .= '</form>';

// ---------------------------------------------------------------------------
// Datos
// ---------------------------------------------------------------------------

$dataRows = array();
if ($ficha['ajmcm_etapa_c'] !== '') {
    $dataRows[__('Etapa', 'sticpa')] = $ficha['ajmcm_etapa_c'];
}
if ($ficha['birthdate'] !== '') {
    $ts = strtotime($ficha['birthdate']);
    $dataRows[__('Fecha de nacimiento', 'sticpa')] = $ts ? date_i18n('j F Y', $ts) : $ficha['birthdate'];
}
if ($ficha['ajmcm_tallas_c'] !== '') {
    $dataRows[__('Talla', 'sticpa')] = $ficha['ajmcm_tallas_c'];
}

if (!empty($dataRows)) {
    $html .= '<div class="pl-sec">' . esc_html__('Datos', 'sticpa') . '</div>';
    $html .= '<div class="pl-list pl-list--data">';
    foreach ($dataRows as $label => $value) {
        $html .= '<div class="pl-flagrow"><span>' . esc_html($label) . '</span>'
            . '<strong>' . esc_html($value) . '</strong></div>';
    }
    $html .= '</div>';
}

// Los avisos de comportamiento llegan cuando exista el módulo AVI_avisos
// (PASAR-LISTA-CAMPOS-CRM.md §6). No se pinta un bloque vacío: enseñar una
// sección que no hace nada es peor que no enseñarla.
