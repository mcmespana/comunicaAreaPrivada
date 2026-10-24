<?php
/**
 * MIS GRUPOS — ver los datos sin pasar lista.
 * ----------------------------------------------------------------------------
 * La pantalla que faltaba. Hasta ahora, para mirar la ficha de un chaval había
 * que «pagar el precio» de entrar a pasar una lista: portada → árbol → marcar →
 * flecha de la fila. La ficha ya lo tenía todo; estaba enterrada detrás de un
 * flujo que es para otra cosa.
 *
 * Aquí NO se marca nada. Se lee. Por eso las filas son enlaces y no botones.
 *
 * NO TIENE NI UN CARGADOR PROPIO. Todo sale de los mismos que ya usa Pasar
 * Lista —los grupos, el mapa de relaciones, el alcance de coordinación— así que
 * con la caché caliente esta pantalla cuesta CERO llamadas al CRM. Si alguien
 * añade aquí una consulta nueva, casi seguro que se está repitiendo una que ya
 * existe: mirar antes `PASAR-LISTA-ESTADO.md` §3.
 *
 * Tres vistas de lo mismo, porque son tres formas de buscar a alguien:
 *
 *   ?ver=grupos  (por defecto)  tus grupos primero, luego por etapa
 *   ?ver=cursos                 por curso escolar, que cruza los grupos
 *   ?ver=az                     todas las personas, alfabético — aquí busca el buscador
 *
 * UNA SOLA POBLACIÓN: los chavales. Hasta el 28/09/2026 coordinación tenía
 * aquí una segunda pestaña, «Monitores», con las fichas del equipo; era lo que
 * hacía de esta pantalla «un jaleo que habla de monitores y de niños». Ese
 * directorio vive ahora en Coordinación (pages/single_stic_coordinacion.php).
 * Dentro de un grupo sí se ve quién lo lleva: es parte de lo que es el grupo.
 *
 * Con ?grupo=<id> enseña la gente de ese grupo.
 */

if (!defined('ABSPATH')) {
    exit;
}

$pageSettings['fileName'] = basename(__FILE__, ".php");

sticpa_pl_maybe_refresh($objSCP);

// ---------------------------------------------------------------------------
// Vincular a un grupo a quien no lo tiene (solo coordinación)
// ---------------------------------------------------------------------------

/* Va AQUÍ, antes de la tanda que lee: `sticpa_pl_assign_group()` vacía la caché
 * al escribir, así que si se leyera primero, la persona recién vinculada
 * seguiría saliendo suelta hasta recargar a mano.
 *
 * Es el MISMO escritor que usa el resumen de grupos —comprueba por su cuenta
 * que quien lo llama coordina y que el grupo es de su delegación—, así que aquí
 * no hay una segunda versión de la regla que pueda quedarse desfasada. Lo que
 * sí se comprueba aquí es el nonce, que es de la pantalla. */
$asignarMsg = '';
if (!empty($_POST['pl_assign_rel'])) {
    if (!isset($_POST['pl_nonce']) || !wp_verify_nonce($_POST['pl_nonce'], 'pl_mis_grupos')) {
        $asignarMsg = __('La sesión ha caducado. Vuelve a cargar la pantalla.', 'sticpa');
    } else {
        list($relAsignar, $grupoAsignar) = sticpa_pl_assign_post();
        $asignarMsg = sticpa_pl_assign_group($objSCP, $relAsignar, $grupoAsignar)
            ? __('Vinculado. Ya sale en su grupo.', 'sticpa')
            : __('No se ha podido vincular. Si no eres de coordinación, no puedes hacerlo desde aquí.', 'sticpa');
    }
}

// La MISMA tanda que el árbol de grupos, menos las listas: aquí no se pregunta
// «¿de qué grupo falta la lista?», se pregunta «¿quién está en este grupo?».
sticpa_pl_prime($objSCP, function () use ($objSCP) {
    sticpa_pl_groups($objSCP);
    sticpa_pl_my_groups($objSCP);
    sticpa_pl_all_relationships($objSCP);
    // Coordinar y acompañar salen de la MISMA consulta (sticpa_pl_mis_rels):
    // deciden si los monitores de un grupo llevan enlace a su ficha.
    sticpa_pl_coord_scope($objSCP);
    sticpa_pl_is_acompanante($objSCP);
});

$groups = sticpa_pl_groups($objSCP);
$myGroups = sticpa_pl_my_groups($objSCP);

$ver = isset($_REQUEST['ver']) ? (string) $_REQUEST['ver'] : 'grupos';
if (!in_array($ver, array('grupos', 'cursos', 'az', 'sueltos'), true)) {
    $ver = 'grupos';
}
$groupId = isset($_REQUEST['grupo']) ? sticpa_pl_safe_id($_REQUEST['grupo']) : '';
// La ficha de un grupo de otra de sus delegaciones (quien colabora con dos).
if ($groupId !== '' && !isset($groups[$groupId]) && sticpa_pl_follow_group_delegation($objSCP, $groupId)) {
    $groups = sticpa_pl_groups($objSCP);
    $myGroups = sticpa_pl_my_groups($objSCP);
}

// ¿Puede abrir la FICHA de un monitor? Coordinación y acompañamiento, igual que
// la propia ficha (pages/single_stic_pasar_lista_monitor.php). A un monitor sin
// esos papeles el nombre de sus compañeros se le enseña, pero sin enlace: antes
// era un enlace que acababa en «Esta pantalla es de coordinación».
$scope = sticpa_pl_coord_scope($objSCP);
$veFichasDeMonitores = ($scope !== null) || sticpa_pl_is_acompanante($objSCP);

/** El enlace a esta misma pantalla con un parámetro cambiado. */
$url = function ($cambios = array()) use ($ver, $groupId) {
    $q = array_merge(
        array('internalpage' => 'single_stic_mis_grupos', 'ver' => $ver),
        ($groupId !== '') ? array('grupo' => $groupId) : array(),
        $cambios
    );
    $q = array_filter($q, function ($v) { return $v !== '' && $v !== null; });
    return '?' . http_build_query($q);
};

// ---------------------------------------------------------------------------
// Cabecera
// ---------------------------------------------------------------------------

$html .= '<div class="pl-head">';
// La flecha de atrás en las dos vistas que son un desvío del índice: la ficha
// de un grupo y la lista de sueltos. Sin ella, `ver=sueltos` no tiene ninguna
// pestaña activa y se queda uno atrapado.
if ($groupId !== '' || $ver === 'sueltos') {
    $html .= '<a class="pl-back" href="' . esc_url($url(array('grupo' => null, 'ver' => 'grupos'))) . '"'
        . ' aria-label="' . esc_attr__('Volver a Grupos y fichas', 'sticpa') . '">' . sticpa_pl_icon('back') . '</a>';
}
$html .= '<div class="pl-head-titles">';
$html .= '<div class="pl-title"><span class="pl-title-code pl-title-code--main">'
    . esc_html__('Grupos y fichas', 'sticpa') . '</span></div>';
$html .= '<div class="pl-subtitle">' . esc_html(sticpa_pl_course_for()['label']) . '</div>';
$html .= '</div>';
$html .= '<a class="pl-session-pick" href="' . esc_url($url(array('refrescar' => '1'))) . '"'
    . ' aria-label="' . esc_attr__('Refrescar datos', 'sticpa') . '">' . sticpa_pl_icon('refresh') . '</a>';
$html .= '</div>';
// En la ficha de un grupo no: el grupo es de una delegación concreta.
if ($groupId === '') {
    $html .= sticpa_pl_deleg_switch_html($objSCP, 'single_stic_mis_grupos');
}

if ($asignarMsg !== '') {
    $html .= '<p class="pl-notice"><span>' . esc_html($asignarMsg) . '</span></p>';
}

if (empty($groups)) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('No hay grupos de tu delegación en este curso. Si crees que es un error, avisa a coordinación.', 'sticpa')
        . '</span></p>';
    return;
}

// ---------------------------------------------------------------------------
// Los que no están en ningún grupo
// ---------------------------------------------------------------------------

/* Una vista aparte y no una pestaña fija: la mayoría de los días esta lista
 * está vacía, y una pestaña permanente para un caso excepcional es ruido. Se
 * llega por la tarjeta del final del índice, que solo aparece cuando hay
 * alguien — igual que la tarjeta ámbar del árbol de Pasar Lista.
 *
 * Aquí NO se enlaza a la ficha: sin grupo no hay ficha que enseñar (la ficha
 * comprueba que la persona esté en el grupo de la URL, y ese es justo el dato
 * que falta). Lo que se hace aquí es ponerle grupo, que es lo que desbloquea
 * todo lo demás. */
$sueltos = sticpa_pl_participants_without_group($objSCP);

/* LOS RECUENTOS DE TODOS LOS GRUPOS, EN UNA SOLA PASADA.
 *
 * La tentación era llamar a `sticpa_pl_group_people()` por grupo. No cuesta
 * llamadas al CRM —el mapa ya está cargado— pero recorre el mapa entero una vez
 * por grupo: con ~28 grupos y ~600 relaciones son diecisiete mil vueltas para
 * pintar veintiocho números. Aquí se recorre UNA vez y se cuenta todo.
 *
 * Se cuenta por id de persona y no con `count()` sobre las relaciones: quien
 * tiene dos relaciones vigentes con el mismo grupo es UNA persona, igual que en
 * `sticpa_pl_group_people()`.
 *
 * Va arriba y no dentro de la vista de grupos porque lo usan dos cosas: la
 * línea de cada grupo y el aviso de «curso cerrado» de aquí abajo. */
$recuentosTodos = array();
foreach (sticpa_pl_all_relationships($objSCP) as $rel) {
    $gid = $rel['group_id'];
    if ($gid === '' || $rel['person']['id'] === '' || !isset($groups[$gid])) {
        continue;
    }
    $bucket = ($rel['role'] === 'monitor') ? 'monitors' : 'participants';
    $recuentosTodos[$gid][$bucket][$rel['person']['id']] = true;
}

/* ¿SE HA ACABADO EL CURSO? Es la explicación de un vacío que si no se lee como
 * avería.
 *
 * Las relaciones de `stic_Contacts_Relationships` llevan fecha de fin, y las de
 * un curso se cierran todas el 31 de agosto. A partir del 1 de septiembre el
 * mapa deja de traer participantes vigentes: los grupos siguen ahí, los
 * monitores también —sus relaciones no caducan a la vez— y los chavales
 * desaparecen de golpe de todas las listas.
 *
 * Visto el 04/09/2026 en el entorno real: 19 grupos, cero participantes
 * vigentes, y la pantalla sin una palabra que lo explicara. Alguien que abre
 * eso un martes concluye que la aplicación se ha roto, y con razón.
 *
 * La señal es la CONTRADICCIÓN, no el cero a secas: hay grupos con gente
 * apuntada según el recuento del Guardián, y ni un participante vigente. Un
 * grupo vacío de verdad no tiene recuento que lo contradiga. */
$hayApuntados = false;
foreach ($groups as $g) {
    if ($g['n_participantes'] > 0) {
        $hayApuntados = true;
        break;
    }
}
$vigentes = 0;
foreach ($recuentosTodos as $n) {
    $vigentes += isset($n['participants']) ? count($n['participants']) : 0;
}
$cursoCerrado = ($hayApuntados && $vigentes === 0);

$avisoCursoCerrado = $cursoCerrado
    ? '<p class="pl-hint pl-hint--warn">' . sticpa_pl_icon('warn') . '<span>'
        . esc_html__('Los grupos tienen gente apuntada, pero ningún participante tiene la relación vigente: es lo que pasa cuando el curso anterior se ha cerrado y el nuevo no está abierto todavía. Las relaciones llevan fecha de fin y coordinación las renueva en el CRM. No es un fallo de la aplicación.', 'sticpa')
        . '</span></p>'
    : '';

if ($ver === 'sueltos') {
    $html .= '<div class="pl-sec-row"><div class="pl-sec">'
        . esc_html__('Sin grupo', 'sticpa') . '</div>'
        . '<span class="pl-etapa-count">' . esc_html(sprintf(
            /* translators: %d: cuántas personas no están en ningún grupo */
            _n('%d persona', '%d personas', count($sueltos), 'sticpa'),
            count($sueltos)
        )) . '</span></div>';

    if (empty($sueltos)) {
        $html .= '<p class="pl-hint">' . sticpa_pl_icon('check') . '<span>'
            . esc_html__('Todo el mundo está en un grupo. Nada que revisar.', 'sticpa')
            . '</span></p>';
        return;
    }

    $puedo = sticpa_pl_is_coordinator($objSCP);
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>' . esc_html(
        $puedo
            ? __('Estas personas tienen relación con la delegación pero no están en ningún grupo, así que no salen en ninguna lista. Elige su grupo y quedan vinculadas.', 'sticpa')
            : __('Estas personas no están en ningún grupo, así que no salen en ninguna lista. Vincularlas es cosa de coordinación: avísales.', 'sticpa')
    ) . '</span></p>';

    $html .= '<form method="post">';
    $html .= wp_nonce_field('pl_mis_grupos', 'pl_nonce', true, false);
    $html .= '<div class="pl-list">';
    foreach ($sueltos as $row) {
        $html .= '<div class="pl-rowwrap pl-suelto">';
        $html .= sticpa_pl_avatar_html($row, true);
        $html .= '<span class="pl-row-body">';
        $html .= '<span class="pl-name">' . esc_html($row['name']) . '</span>';
        if ($row['age'] !== '') {
            $html .= '<span class="pl-rowsub">' . esc_html(sprintf(
                /* translators: %s: edad en años */
                __('%s años', 'sticpa'),
                $row['age']
            )) . '</span>';
        }
        $html .= '</span>';
        if ($puedo) {
            // El desplegable y el botón, en la misma fila que el nombre: el
            // trabajo aquí es «este chaval, a este grupo», y separarlo en dos
            // pasos convierte veinte asignaciones en cuarenta gestos.
            $html .= sticpa_pl_vincular_html($row['rel_id'], $groups, $row['name']);
        }
        $html .= '</div>';
    }
    $html .= '</div></form>';
    return;
}

// ---------------------------------------------------------------------------
// La gente de UN grupo
// ---------------------------------------------------------------------------

if ($groupId !== '' && isset($groups[$groupId])) {
    $g = $groups[$groupId];
    $people = sticpa_pl_group_people($objSCP, $groupId);

    $html .= '<div class="pl-ident pl-ident--grupo">';
    $html .= '<span class="pl-ident-body">';
    $html .= '<span class="pl-ident-name">' . esc_html($g['code'])
        . ($g['name'] !== '' ? ' · ' . esc_html($g['name']) : '') . '</span>';
    $meta = array_filter(array(
        $g['etapa'],
        $g['cursos'],
        sticpa_pl_recuento_texto(count($people['participants']), count($people['monitors'])),
    ));
    $html .= '<span class="pl-ident-meta">' . esc_html(implode(' · ', $meta)) . '</span>';
    $html .= '</span></div>';

    $html .= sticpa_pl_buscador_html(__('Buscar por nombre…', 'sticpa'));

    /* «¿QUIÉN NO PUEDE SALIR EN FOTOS?» (plan 043, fila 9; F3). La pregunta de
     * antes de subir fotos, contestada con un toque: deja a la vista solo a
     * quien tiene la marca «Sin fotos». Es el chip de filtro que ya usa la
     * lista de un evento (`.pl-evchip`), y filtra lo pintado junto con el
     * buscador, sin ir al servidor.
     *
     * Solo sale si hay alguien: quien no ha contestado la cesión no lleva la
     * marca (un vacío no es un «no»), así que un «0» aquí no querría decir
     * «todos pueden salir». */
    $sinFotos = 0;
    foreach ($people['participants'] as $p) {
        if (isset($p['marcas']) && in_array('sin_fotos', $p['marcas'], true)) {
            $sinFotos++;
        }
    }
    if ($sinFotos > 0) {
        $html .= '<div class="pl-evchips">'
            . '<button type="button" class="pl-evchip" data-pl-filtro-fotos aria-pressed="false">'
            . esc_html__('¿Quién no puede salir en fotos?', 'sticpa')
            . '<span class="pl-evchip-n">' . (int) $sinFotos . '</span></button></div>';
    }

    if (!empty($people['monitors'])) {
        $html .= '<div class="pl-sec">' . esc_html__('Monitores', 'sticpa') . '</div>';
        $html .= '<div class="pl-list">';
        foreach ($people['monitors'] as $m) {
            if (!$veFichasDeMonitores) {
                $html .= '<div class="pl-rowwrap pl-rowstatic">' . sticpa_pl_avatar_html($m, true)
                    . '<span class="pl-row-body"><span class="pl-name">' . esc_html($m['name'])
                    . '</span></span></div>';
                continue;
            }
            $html .= sticpa_pl_person_link_html(
                $m,
                '?internalpage=single_stic_pasar_lista_monitor&monitor=' . rawurlencode($m['id'])
                    . '&vengo=grupo&vgrupo=' . rawurlencode($groupId),
                '',
                '',
                true
            );
        }
        $html .= '</div>';
    }

    $html .= '<div class="pl-sec">' . esc_html__('Participantes', 'sticpa') . '</div>';
    if (empty($people['participants'])) {
        $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
            . esc_html__('Este grupo no tiene participantes con relación vigente.', 'sticpa')
            . '</span></p>';
    } else {
        $html .= '<div class="pl-list">';
        foreach ($people['participants'] as $p) {
            $sub = ($p['age'] !== '') ? sprintf(
                /* translators: %s: edad en años */
                __('%s años', 'sticpa'),
                $p['age']
            ) : '';
            $html .= sticpa_pl_person_link_html(
                $p,
                '?internalpage=single_stic_pasar_lista_ficha&participante=' . rawurlencode($p['id'])
                    . '&grupo=' . rawurlencode($groupId) . '&vengo=grupo',
                $sub,
                '',
                true,
                isset($p['marcas']) ? $p['marcas'] : array()
            );
        }
        $html .= '</div>';
    }

    $html .= sticpa_pl_buscador_vacio_html();
    return;
}

// ---------------------------------------------------------------------------
// El índice
// ---------------------------------------------------------------------------

// Los tres modos de mirar. Es el mismo dato ordenado de otra forma: por eso
// son pestañas y no pantallas distintas.
$html .= '<div class="pl-tabs" role="tablist">';
foreach (array(
    'grupos' => __('Grupos', 'sticpa'),
    'cursos' => __('Cursos', 'sticpa'),
    'az' => __('A-Z', 'sticpa'),
) as $modo => $label) {
    $activo = ($ver === $modo);
    $html .= '<a class="pl-tab' . ($activo ? ' is-active' : '') . '" role="tab"'
        . ' aria-selected="' . ($activo ? 'true' : 'false') . '"'
        . ' href="' . esc_url($url(array('ver' => $modo))) . '">' . esc_html($label) . '</a>';
}
$html .= '</div>';

/* Las vistas de GENTE (cursos y A-Z) van dentro de su caja: es la que
 * engancha el JS de los chips y del interruptor de «Asignar grupos», y la que
 * despega el buscador de arriba para que lo que se quede pegado al bajar sean
 * los chips (dos barras pegadas se comen media pantalla de la app). */
$cierraGente = '';
if ($ver !== 'grupos') {
    $html .= '<div class="pl-gente" data-pl-gente>';
    $cierraGente = '</div>';
}

$html .= sticpa_pl_buscador_html(
    ($ver === 'grupos')
        ? __('Buscar grupo, monitor o curso…', 'sticpa')
        : __('Buscar por nombre…', 'sticpa')
);

// ---- Vista GRUPOS ---------------------------------------------------------

if ($ver === 'grupos') {
    $html .= $avisoCursoCerrado;

    /** La línea de debajo del nombre de un grupo: etapa, curso y recuentos.
     *
     * EL CERO INVENTADO, y la guarda mal puesta que costó verlo en producción.
     *
     * Si el mapa no trae participantes de este grupo, contar da cero. Un
     * «0 chavales» al lado de un grupo que tiene doce es peor que no decir
     * nada: se lee como un dato, no como un fallo. Cuando no hay a quién
     * contar se usa el recuento que el Guardián dejó escrito en el propio
     * grupo por la noche, y si tampoco ese sirve, el hueco.
     *
     * LA PRIMERA VERSIÓN PREGUNTABA `isset($recuentos[$gid])`, y eso estaba
     * mal: en cuanto el grupo tiene UN MONITOR la clave existe, así que la
     * guarda no saltaba y se pintaba «0 chavales · 2 monitores». Se vio el
     * 04/09 en el entorno real, en 18 de los 19 grupos —el curso 2025-2026
     * había terminado y las relaciones de participante ya no estaban
     * vigentes, pero las de monitor sí—. La pregunta buena es por los
     * PARTICIPANTES, que es el número que se está tapando. */
    $groupMeta = function ($gid, $g) use ($recuentosTodos) {
        $vivos = isset($recuentosTodos[$gid]['participants'])
            ? count($recuentosTodos[$gid]['participants'])
            : 0;
        $monis = isset($recuentosTodos[$gid]['monitors'])
            ? count($recuentosTodos[$gid]['monitors'])
            : 0;

        if ($vivos > 0) {
            $texto = sticpa_pl_recuento_texto($vivos, $monis);
        } elseif ($g['n_participantes'] >= 0
            && sticpa_pl_recuento_fresco(isset($g['recuento_al']) ? $g['recuento_al'] : '')) {
            // Los participantes del Guardián, pero los MONITORES contados de
            // verdad si los hay: es el dato de hoy y no cuesta nada.
            $texto = sticpa_pl_recuento_texto(
                $g['n_participantes'],
                ($monis > 0) ? $monis : (($g['n_monitores'] > 0) ? $g['n_monitores'] : 0)
            );
        } elseif ($monis > 0) {
            // Sin participantes que contar y sin recuento fresco: al menos se
            // dice quién lleva el grupo, sin inventarse un cero.
            $texto = sprintf(
                /* translators: %d: cuántos monitores tiene el grupo */
                _n('%d monitor', '%d monitores', $monis, 'sticpa'),
                $monis
            );
        } else {
            $texto = '';
        }
        return implode(' · ', array_filter(array($g['etapa'], $g['cursos'], $texto)));
    };

        $mine = array();
        $byEtapa = array();
        foreach ($groups as $id => $g) {
            if (in_array($id, $myGroups, true)) {
                $mine[$id] = $g;
                continue;
            }
            $etapa = $g['etapa'];
            $byEtapa[($etapa !== '') ? $etapa : '?'][$id] = $g;
        }

        /** Una tarjeta de grupo, con la misma pinta que en el árbol de Pasar Lista. */
        $card = function ($id, $g, $tuyo = false) use ($groupMeta, $url) {
            $out = '<a class="pl-group" href="' . esc_url($url(array('grupo' => $id))) . '">';
            $out .= '<span class="pl-group-body">';
            $out .= '<span class="pl-title"><span class="pl-title-code">' . esc_html($g['code']) . '</span>';
            if ($g['name'] !== '') {
                $out .= '<span class="pl-title-name">' . esc_html($g['name']) . '</span>';
            }
            $out .= '</span>';
            $meta = $groupMeta($id, $g);
            $out .= '<span class="pl-group-meta">';
            if ($tuyo) {
                $out .= '<span class="pl-mine-tag">' . esc_html__('Tu grupo', 'sticpa') . '</span>'
                    . ($meta !== '' ? ' · ' : '');
            }
            $out .= esc_html($meta) . '</span>';
            $out .= '</span>';
            $out .= '<span class="pl-detail pl-detail--static">' . sticpa_pl_icon('next') . '</span>';
            $out .= '</a>';
            return $out;
        };

        // LOS TUYOS PRIMERO, que es lo que se pidió y lo que se busca el 90 % de
        // las veces. Sin cabecera si no hay más: una sección de una sola cosa es
        // ruido.
        if (!empty($mine)) {
            $html .= '<div class="pl-etapa-title">' . esc_html__('Tus grupos', 'sticpa')
                . '<span class="pl-etapa-count">' . esc_html(sprintf(
                    /* translators: %d: cuántos grupos lleva */
                    _n('%d grupo', '%d grupos', count($mine), 'sticpa'),
                    count($mine)
                )) . '</span></div>';
            $html .= '<div class="pl-list">';
            foreach ($mine as $id => $g) {
                $html .= $card($id, $g, true);
            }
            $html .= '</div>';
        }

        $etapaDots = array('MIC' => 'var(--danger-color)', 'COM' => 'var(--success-color)', 'LC' => 'var(--primary-color)');
        foreach (array('MIC', 'COM', 'LC', '?') as $etapa) {
            if (empty($byEtapa[$etapa])) {
                continue;
            }
            $dot = isset($etapaDots[$etapa]) ? $etapaDots[$etapa] : 'var(--gray-300)';
            $titulo = ($etapa === '?') ? __('Sin etapa', 'sticpa') : $etapa;
            $html .= '<div class="pl-etapa-title">'
                . '<span class="pl-etapa-dot" style="background:' . esc_attr($dot) . '"></span>'
                . esc_html($titulo)
                . '<span class="pl-etapa-count">' . esc_html(sprintf(
                    /* translators: %d: cuántos grupos hay en la etapa */
                    _n('%d grupo', '%d grupos', count($byEtapa[$etapa]), 'sticpa'),
                    count($byEtapa[$etapa])
                )) . '</span></div>';
            $html .= '<div class="pl-list">';
            foreach ($byEtapa[$etapa] as $id => $g) {
                $html .= $card($id, $g);
            }
            $html .= '</div>';
        }

        $html .= sticpa_pl_buscador_vacio_html();

        /* LOS QUE NO ESTÁN EN NINGÚN GRUPO. Va al final del índice y solo si hay
         * alguien: es donde se nota el problema —falta gente en las listas— y
         * desde aquí se arregla en dos gestos. La misma tarjeta ámbar que el
         * árbol de Pasar Lista, pero esta lleva a poder vincularlos, no solo a
         * verlos. No cuesta una llamada: sale del mapa que ya está cargado. */
        if (!empty($sueltos)) {
            $html .= '<a class="pl-orphans" href="' . esc_url($url(array('ver' => 'sueltos'))) . '">';
            $html .= '<span class="pl-orphans-icon">' . sticpa_pl_icon('person') . '</span>';
            $html .= '<span class="pl-orphans-body">';
            $html .= '<span class="pl-orphans-title">' . esc_html(sprintf(
                /* translators: %d: personas sin grupo asignado */
                _n('%d persona sin grupo', '%d personas sin grupo', count($sueltos), 'sticpa'),
                count($sueltos)
            )) . '</span>';
            $html .= '<span class="pl-orphans-sub">' . esc_html(
                sticpa_pl_is_coordinator($objSCP)
                    ? __('No salen en ninguna lista. Vincúlalas aquí.', 'sticpa')
                    : __('No salen en ninguna lista', 'sticpa')
            ) . '</span>';
            $html .= '</span>';
            $html .= '<span class="pl-detail">' . sticpa_pl_icon('next') . '</span>';
            $html .= '</a>';
        }

        $html .= sticpa_pl_grupos_ocultos_html($objSCP);
        return;
}

// ---- Vistas CURSOS y A-Z: la gente, no los grupos -------------------------

/* TODA la gente de la delegación, del mapa que ya está cargado. Ni una consulta
 * más: `sticpa_pl_all_relationships()` trae persona, grupo, papel, curso y
 * vigencia de una vez, y es justo lo que hace falta aquí.
 *
 * QUIÉN SALE (16/10/2026, lo pidió coordinación): todo el que tiene una
 * relación VIGENTE de «Participante MIC-COM» con la delegación, TENGA GRUPO O
 * NO. Antes salía solo quien colgaba de un grupo de Pasar Lista, y a principio
 * de curso eso es casi nadie: de 210 participantes de Castellón, 20 tenían
 * grupo. Las vistas por curso y A-Z son justo las que sirven para repartirlos.
 * Quien no tiene grupo sale marcado «Sin grupo» y, a coordinación, con su
 * «Vincular» al lado — el mismo que en la vista de sueltos.
 *
 * EL CURSO ES EL DE LA RELACIÓN (`ajmcm_curso_escolar_c`, la de inicio más
 * reciente que lo tenga): es donde vive el curso de una persona. El del grupo
 * solo cubre a quien no lo tiene en su relación. */
$relsVivas = sticpa_pl_all_relationships($objSCP);
$cursoDe = sticpa_pl_ev_cursos_de($relsVivas);
$conGrupo = sticpa_pl_ev_rel_reciente($relsVivas, function ($rel) use ($groups) {
    return $rel['group_id'] !== '' && isset($groups[$rel['group_id']]);
});
$micCom = sticpa_pl_ev_rel_reciente($relsVivas, function ($rel) {
    return isset($rel['tipo']) && strtolower((string) $rel['tipo']) === 'participante_mic_com';
});
$cursosTxt = sticpa_pl_ev_cursos();

$personas = array();
foreach (array_merge(array_keys($conGrupo), array_keys($micCom)) as $id) {
    if (isset($personas[$id])) {
        continue;   // dos relaciones vigentes: una sola ficha
    }
    $rel = isset($conGrupo[$id]) ? $conGrupo[$id] : $micCom[$id];
    $gid = isset($conGrupo[$id]) ? $rel['group_id'] : '';
    $p = $rel['person'];
    $p['grupo'] = ($gid !== '') ? $groups[$gid]['code'] : '';
    $p['grupo_id'] = $gid;
    // La relación que hay que colgar de un grupo, para «Vincular».
    $p['rel_id'] = ($gid === '') ? $rel['rel_id'] : '';
    $key = isset($cursoDe[$id]) ? $cursoDe[$id] : '';
    if ($key === '' && $gid !== '') {
        $key = sticpa_pl_curso_key_de_texto(isset($groups[$gid]['cursos']) ? $groups[$gid]['cursos'] : '');
    }
    $p['curso_key'] = $key;
    $p['curso'] = ($key !== '' && isset($cursosTxt[$key]))
        ? $cursosTxt[$key]
        : (($gid !== '' && isset($groups[$gid]['cursos'])) ? (string) $groups[$gid]['cursos'] : '');
    $personas[$id] = $p;
}

if (empty($personas)) {
    // El aviso de «curso cerrado» EXPLICA el vacío; el genérico solo lo
    // constata. Si tenemos la explicación buena, se da esa.
    $html .= ($avisoCursoCerrado !== '')
        ? $avisoCursoCerrado
        : '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
            . esc_html__('No hay participantes con relación vigente en tu delegación.', 'sticpa')
            . '</span></p>';
    $html .= $cierraGente;
    return;
}

$html .= $avisoCursoCerrado;

$nSinGrupo = 0;
foreach ($personas as $p) {
    if ($p['grupo_id'] === '') {
        $nSinGrupo++;
    }
}
$puedoVincular = ($nSinGrupo > 0) && sticpa_pl_is_coordinator($objSCP);

/* POR CURSO, para las dos vistas. La CLAVE de curso, para que el de la
 * relación («3_eso») y el del grupo («3 ESO») caigan bajo la misma cabecera.
 * Lo que no tiene clave pero sí un texto de grupo, bajo ese texto; lo demás,
 * «Sin curso». La usa la vista por curso para sus secciones y las dos para los
 * chips, así que se calcula una vez aquí arriba. */
$claveCurso = function ($p) {
    return ($p['curso_key'] !== '') ? $p['curso_key'] : (($p['curso'] !== '') ? 'txt:' . $p['curso'] : '?');
};
$porCurso = array();
$tituloCurso = array();
foreach ($personas as $p) {
    $k = $claveCurso($p);
    $porCurso[$k][] = $p;
    $tituloCurso[$k] = ($k === '?') ? '' : $p['curso'];
}
/** El texto que entiende el rank, para ordenar Y colorear con la misma vara. */
$textoRank = function ($k) {
    if ($k === '?') {
        return '';
    }
    return (strpos($k, 'txt:') === 0) ? substr($k, 4) : sticpa_pl_curso_key_texto($k);
};
uksort($porCurso, function ($a, $b) use ($textoRank) {
    $ra = ($a === '?') ? 99999 : sticpa_pl_curso_rank($textoRank($a));
    $rb = ($b === '?') ? 99999 : sticpa_pl_curso_rank($textoRank($b));
    if ($ra !== $rb) {
        return ($ra < $rb) ? -1 : 1;
    }
    return strcmp($a, $b);
});

/* LOS CHIPS, con su número (16/10/2026, lo pidió el propietario): los mismos
 * que la lista de un evento de Pasar Lista (`.pl-evchip`), pegados arriba al
 * bajar. Un toque y solo quedan los de ese curso, y la lista vuelve a su
 * principio: «a ver los de 1.º de ESO, pam». Al final, «Sin grupo», que es lo
 * que coordinación reparte a principio de curso. Filtran lo ya pintado, junto
 * con el buscador, sin ir al servidor. Con un solo curso no se pintan: filtrar
 * no filtraría nada. */
if (count($porCurso) > 1 || ($nSinGrupo > 0 && $nSinGrupo < count($personas))) {
    $html .= '<div class="pl-evchips" data-pl-gente-chips role="group" aria-label="'
        . esc_attr__('Filtrar por curso', 'sticpa') . '">';
    $html .= '<button type="button" class="pl-evchip" data-pl-curso-chip="" aria-pressed="true">'
        . esc_html__('Todos', 'sticpa') . '<span class="pl-evchip-n">' . count($personas) . '</span></button>';
    foreach ($porCurso as $k => $gente) {
        $html .= '<button type="button" class="pl-evchip" data-pl-curso-chip="' . esc_attr($k) . '" aria-pressed="false">'
            . esc_html(($k === '?') ? __('Sin curso', 'sticpa') : $tituloCurso[$k])
            . '<span class="pl-evchip-n">' . count($gente) . '</span></button>';
    }
    if ($nSinGrupo > 0 && $nSinGrupo < count($personas)) {
        $html .= '<button type="button" class="pl-evchip pl-evchip--warn" data-pl-curso-chip="sin-grupo" aria-pressed="false">'
            . esc_html__('Sin grupo', 'sticpa') . '<span class="pl-evchip-n">' . $nSinGrupo . '</span></button>';
    }
    $html .= '</div>';
}

/* «ASIGNAR GRUPOS», UN INTERRUPTOR (16/10/2026). Con un desplegable y un botón
 * «Vincular» en cada una de las ciento y pico filas sin grupo, la lista dejaba
 * de ser una lista de gente: era un formulario. Ahora los controles salen
 * cuando coordinación los pide, y se quedan pedidos (el móvil lo recuerda)
 * mientras reparte. Elegir el grupo ya vincula —con una confirmación—, así que
 * el botón sobra cuando hay JavaScript; sin él, todo sigue a la vista y con su
 * botón, como antes. */
if ($nSinGrupo > 0) {
    $html .= '<div class="pl-asignar">';
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>' . esc_html(sprintf(
        $puedoVincular
            /* translators: %d: participantes sin grupo */
            ? _n('%d sin grupo todavía: no sale en ninguna lista.', '%d sin grupo todavía: no salen en ninguna lista.', $nSinGrupo, 'sticpa')
            /* translators: %d: participantes sin grupo */
            : _n('%d sin grupo todavía: no sale en ninguna lista. Vincularlo es cosa de coordinación.', '%d sin grupo todavía: no salen en ninguna lista. Vincularlos es cosa de coordinación.', $nSinGrupo, 'sticpa'),
        $nSinGrupo
    )) . '</span></p>';
    if ($puedoVincular) {
        $html .= '<label class="pl-switch" data-pl-asignar-wrap hidden>'
            . '<input type="checkbox" role="switch" data-pl-asignar'
            . ($asignarMsg !== '' ? ' checked' : '') . '>'
            . '<span class="pl-switch-track" aria-hidden="true"></span>'
            . '<span class="pl-switch-label">' . esc_html__('Asignar grupos', 'sticpa') . '</span>'
            . '</label>';
    }
    $html .= '</div>';
}

/** La fila de una persona en las vistas de gente.
 *
 * CON FOTO desde el 16/10/2026 (lo pidió el propietario). Antes no, porque
 * eran trescientas peticiones; ahora es barato: el mapa dice quién TIENE foto
 * (`foto`, del nombre del archivo en el CRM) y a quien no tiene no se le pide;
 * las que se piden van en segundo plano (`lazy`, prioridad baja), con la
 * versión en la URL, y el móvil las guarda un año (cabecera + service worker).
 * La segunda visita no baja ni una. Ver MIS-GRUPOS.md §4.
 *
 * SIN GRUPO: la misma fila, con «Sin grupo» en ámbar donde iría el código, y
 * a coordinación el desplegable para vincularlo. La fila entera ya no puede
 * ser un enlace (un `<select>` dentro de un `<a>` no se puede tocar), así que
 * el enlace a la ficha es el nombre y el desplegable va al lado, como en la
 * vista de sueltos. La ficha se abre sin grupo. */
$fila = function ($p) use ($ver, $groups, $puedoVincular, $claveCurso) {
    $edad = ($p['age'] !== '') ? sprintf(
        /* translators: %s: edad en años */
        __('%s años', 'sticpa'),
        $p['age']
    ) : '';
    $href = '?internalpage=single_stic_pasar_lista_ficha&participante=' . rawurlencode($p['id'])
        . ($p['grupo_id'] !== '' ? '&grupo=' . rawurlencode($p['grupo_id']) : '')
        . '&vengo=' . rawurlencode($ver);
    if ($p['grupo_id'] !== '') {
        return sticpa_pl_person_link_html(
            $p,
            $href,
            implode(' · ', array_filter(array($p['grupo'], $p['curso'], $edad))),
            '',
            true,
            isset($p['marcas']) ? $p['marcas'] : array(),
            array('data-pl-curso' => $claveCurso($p))
        );
    }
    $out = '<div class="pl-rowwrap pl-rowstatic pl-suelto" data-pl-sin-grupo'
        . sticpa_pl_attrs_html(array('data-pl-curso' => $claveCurso($p))) . '>';
    $out .= '<a class="pl-singrupo-link" href="' . esc_url($href) . '">';
    $out .= sticpa_pl_avatar_html($p, true);
    $out .= '<span class="pl-row-body">';
    $out .= '<span class="pl-name">' . esc_html($p['name']) . '</span>';
    $out .= sticpa_pl_marcas_html(isset($p['marcas']) ? $p['marcas'] : array(), $p['id']);
    $out .= '<span class="pl-rowsub"><span class="pl-singrupo">' . esc_html__('Sin grupo', 'sticpa') . '</span>'
        . esc_html(implode('', array_map(function ($b) { return ' · ' . $b; }, array_filter(array($p['curso'], $edad)))))
        . '</span>';
    $out .= '</span></a>';
    if ($puedoVincular && $p['rel_id'] !== '') {
        $out .= sticpa_pl_vincular_html($p['rel_id'], sticpa_pl_grupos_para_curso($groups, $p['curso_key']), $p['name']);
    }
    $out .= '</div>';
    return $out;
};

// Con «Vincular» en las filas, la lista va dentro de su formulario: es el mismo
// POST que la vista de sueltos, que se atiende arriba del todo.
$abreLista = function () use ($puedoVincular) {
    return $puedoVincular
        ? '<form method="post">' . wp_nonce_field('pl_mis_grupos', 'pl_nonce', true, false)
        : '';
};
$cierraLista = function () use ($puedoVincular) {
    return $puedoVincular ? '</form>' : '';
};

if ($ver === 'az') {
    $todos = array_values($personas);
    usort($todos, 'sticpa_pl_cmp_person');
    $html .= $abreLista();
    $html .= '<div class="pl-list">';
    foreach ($todos as $p) {
        $html .= $fila($p);
    }
    $html .= '</div>';
    $html .= $cierraLista();
    $html .= sticpa_pl_buscador_vacio_html();
    $html .= $cierraGente;
    return;
}

// ---- Por CURSO ------------------------------------------------------------

/* El curso CRUZA los grupos, que es justo la gracia: «todos los de 1.º de ESO»
 * son de C1 y de C2, y por grupo no se ven juntos nunca. El orden es el escolar
 * —4.º de primaria antes que 1.º de ESO—, que ya lo sabe `sticpa_pl_curso_rank()`. */
// Las secciones ya están hechas y ordenadas arriba (`$porCurso`), que es
// de donde salen también los chips.

/* EL COLOR DE CADA CURSO: más intenso cuanto más mayores.
 *
 * En una lista de trescientos, los títulos se leen uno a uno; el color se ve de
 * un vistazo y dice por dónde vas sin leer nada. Sale de
 * `sticpa_pl_curso_intensidad()`, que a su vez sale del MISMO rank que ordena
 * esta vista: el color y el orden no pueden contradecirse.
 *
 * Es opacidad sobre `--primary-color` y no un color fijo, para que el modo
 * oscuro siga funcionando: ahí el azul es claro sobre fondo oscuro, así que
 * «más intenso» se sigue leyendo como «más mayores».
 *
 * Un curso que no se reconoce NO se colorea: un color inventado sobre un dato
 * que no se entiende miente. */
$html .= $abreLista();
foreach ($porCurso as $curso => $gente) {
    usort($gente, 'sticpa_pl_cmp_person');
    $intensidad = ($curso === '?') ? -1.0 : sticpa_pl_curso_intensidad($textoRank($curso));
    $html .= '<div class="pl-etapa-title">';
    if ($intensidad >= 0) {
        $html .= '<span class="pl-etapa-dot pl-curso-dot"'
            . ' style="opacity:' . esc_attr(number_format(0.28 + 0.72 * $intensidad, 2, '.', '')) . '"'
            . '></span>';
    } else {
        $html .= '<span class="pl-etapa-dot" style="background:var(--gray-300)"></span>';
    }
    $html .= esc_html(($curso === '?') ? __('Sin curso', 'sticpa') : $tituloCurso[$curso])
        . '<span class="pl-etapa-count">' . esc_html(sprintf(
            /* translators: %d: cuántos participantes hay en el curso */
            _n('%d chavalín', '%d chavalines', count($gente), 'sticpa'),
            count($gente)
        )) . '</span></div>';
    $html .= '<div class="pl-list">';
    foreach ($gente as $p) {
        $html .= $fila($p);
    }
    $html .= '</div>';
}
$html .= $cierraLista();

$html .= sticpa_pl_buscador_vacio_html();
$html .= $cierraGente;
