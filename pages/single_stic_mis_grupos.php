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
 * Y dos poblaciones:
 *
 *   ?quien=participantes (por defecto)
 *   ?quien=monitores     solo coordinación, agrupados por etapa
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
        $asignarMsg = sticpa_pl_assign_group(
            $objSCP,
            $_POST['pl_assign_rel'],
            isset($_POST['pl_assign_group']) ? $_POST['pl_assign_group'] : ''
        )
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
});

$groups = sticpa_pl_groups($objSCP);
$myGroups = sticpa_pl_my_groups($objSCP);

$ver = isset($_REQUEST['ver']) ? (string) $_REQUEST['ver'] : 'grupos';
if (!in_array($ver, array('grupos', 'cursos', 'az', 'sueltos'), true)) {
    $ver = 'grupos';
}
$quien = (isset($_REQUEST['quien']) && $_REQUEST['quien'] === 'monitores') ? 'monitores' : 'participantes';
$groupId = isset($_REQUEST['grupo']) ? sticpa_pl_safe_id($_REQUEST['grupo']) : '';

// Los monitores son cosa de coordinación, igual que su lista. Si alguien llega
// con ?quien=monitores sin alcance, se le devuelve a los participantes en vez
// de enseñarle una pantalla vacía sin explicación.
$scope = sticpa_pl_coord_scope($objSCP);
if ($quien === 'monitores' && $scope === null) {
    $quien = 'participantes';
}

/** El enlace a esta misma pantalla con un parámetro cambiado. */
$url = function ($cambios = array()) use ($ver, $quien, $groupId) {
    $q = array_merge(
        array('internalpage' => 'single_stic_mis_grupos', 'ver' => $ver, 'quien' => $quien),
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
        . ' aria-label="' . esc_attr__('Volver a mis grupos', 'sticpa') . '">' . sticpa_pl_icon('back') . '</a>';
}
$html .= '<div class="pl-head-titles">';
$html .= '<div class="pl-title"><span class="pl-title-code pl-title-code--main">'
    . esc_html__('Mis grupos', 'sticpa') . '</span></div>';
$html .= '<div class="pl-subtitle">' . esc_html(sticpa_pl_course_for()['label']) . '</div>';
$html .= '</div>';
$html .= '<a class="pl-session-pick" href="' . esc_url($url(array('refrescar' => '1'))) . '"'
    . ' aria-label="' . esc_attr__('Refrescar datos', 'sticpa') . '">' . sticpa_pl_icon('refresh') . '</a>';
$html .= '</div>';

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
            $html .= '<span class="pl-suelto-act">';
            $html .= '<select name="pl_assign_group" class="pl-review-select"'
                . ' aria-label="' . esc_attr(sprintf(
                    /* translators: %s: nombre de la persona */
                    __('Grupo para %s', 'sticpa'),
                    $row['name']
                )) . '">';
            $html .= '<option value="">' . esc_html__('Elegir grupo…', 'sticpa') . '</option>';
            foreach ($groups as $gid => $g) {
                $html .= '<option value="' . esc_attr($gid) . '">'
                    . esc_html(trim($g['code'] . ($g['name'] !== '' ? ' · ' . $g['name'] : '')
                        . ($g['cursos'] !== '' ? ' (' . $g['cursos'] . ')' : ''))) . '</option>';
            }
            $html .= '</select>';
            $html .= '<button type="submit" name="pl_assign_rel" value="' . esc_attr($row['rel_id'])
                . '" class="pl-review-btn">' . esc_html__('Vincular', 'sticpa') . '</button>';
            $html .= '</span>';
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

    if (!empty($people['monitors'])) {
        $html .= '<div class="pl-sec">' . esc_html__('Monitores', 'sticpa') . '</div>';
        $html .= '<div class="pl-list">';
        foreach ($people['monitors'] as $m) {
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
                true
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

// Los tres modos de mirar, y las dos poblaciones. Es el mismo dato ordenado de
// otra forma: por eso son pestañas y no pantallas distintas.
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

if ($scope !== null) {
    // Coordinación mira dos poblaciones distintas y con preguntas distintas.
    $html .= '<div class="pl-tabs pl-tabs--quien" role="tablist">';
    foreach (array(
        'participantes' => __('Chavales', 'sticpa'),
        'monitores' => __('Monitores', 'sticpa'),
    ) as $q => $label) {
        $activo = ($quien === $q);
        $html .= '<a class="pl-tab' . ($activo ? ' is-active' : '') . '" role="tab"'
            . ' aria-selected="' . ($activo ? 'true' : 'false') . '"'
            . ' href="' . esc_url($url(array('quien' => $q, 'grupo' => null))) . '">'
            . esc_html($label) . '</a>';
    }
    $html .= '</div>';
}

$html .= sticpa_pl_buscador_html(
    ($ver === 'grupos')
        ? __('Buscar grupo, monitor o curso…', 'sticpa')
        : __('Buscar por nombre…', 'sticpa')
);

// ---- Vista MONITORES (coordinación) ---------------------------------------

if ($quien === 'monitores') {
    $enAlcance = sticpa_pl_scoped_groups($objSCP, $scope);
    $monitors = sticpa_pl_monitors_of($objSCP, $enAlcance);

    if (empty($monitors)) {
        $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
            . esc_html__('No hay monitores con relación vigente en los grupos de tu alcance.', 'sticpa')
            . '</span></p>';
        return;
    }

    // Por etapa, con los mismos puntos de color que el árbol y que la lista de
    // monitores: es el idioma que la aplicación ya tiene, y aprenderlo dos
    // veces sería aprenderlo mal.
    $porEtapa = array();
    foreach ($monitors as $m) {
        $etapa = (isset($m['etapa']) && $m['etapa'] !== '') ? $m['etapa'] : '?';
        $porEtapa[$etapa][] = $m;
    }
    if ($ver === 'az') {
        // Alfabético de verdad: sin secciones, todos seguidos.
        $todos = $monitors;
        usort($todos, 'sticpa_pl_cmp_person');
        $porEtapa = array('*' => $todos);
    }

    $etapaDots = array('MIC' => 'var(--danger-color)', 'COM' => 'var(--success-color)', 'LC' => 'var(--primary-color)');
    foreach (array('MIC', 'COM', 'LC', '?', '*') as $etapa) {
        if (empty($porEtapa[$etapa])) {
            continue;
        }
        if ($etapa !== '*' && count($porEtapa) > 1) {
            $dot = isset($etapaDots[$etapa]) ? $etapaDots[$etapa] : 'var(--gray-300)';
            $titulo = ($etapa === '?') ? __('Sin etapa', 'sticpa') : $etapa;
            $html .= '<div class="pl-etapa-title">'
                . '<span class="pl-etapa-dot" style="background:' . esc_attr($dot) . '"></span>'
                . esc_html($titulo)
                . '<span class="pl-etapa-count">' . esc_html(sprintf(
                    /* translators: %d: cuántos monitores hay en la etapa */
                    _n('%d monitor', '%d monitores', count($porEtapa[$etapa]), 'sticpa'),
                    count($porEtapa[$etapa])
                )) . '</span></div>';
        }
        $html .= '<div class="pl-list">';
        foreach ($porEtapa[$etapa] as $m) {
            $sub = implode(' · ', array_filter(array(
                implode(' · ', $m['groups']),
                isset($m['curso']) ? $m['curso'] : '',
            )));
            $html .= sticpa_pl_person_link_html(
                $m,
                '?internalpage=single_stic_pasar_lista_monitor&monitor=' . rawurlencode($m['id'])
                    . '&vengo=monitores',
                $sub,
                '',
                true
            );
        }
        $html .= '</div>';
    }

    $html .= sticpa_pl_buscador_vacio_html();
    return;
}

// ---- Vista GRUPOS ---------------------------------------------------------

if ($ver === 'grupos') {

    /* LOS RECUENTOS DE TODOS LOS GRUPOS, EN UNA SOLA PASADA.
     *
     * La tentación era llamar a `sticpa_pl_group_people()` por grupo. No cuesta
     * llamadas al CRM —el mapa ya está cargado— pero recorre el mapa entero una vez
     * por grupo: con ~28 grupos y ~600 relaciones son diecisiete mil vueltas para
     * pintar veintiocho números. Aquí se recorre UNA vez y se cuenta todo.
     *
     * Se cuenta por id de persona y no con `count()` sobre las relaciones: quien
     * tiene dos relaciones vigentes con el mismo grupo es UNA persona, igual que en
     * `sticpa_pl_group_people()`. */
    $recuentos = array();
    foreach (sticpa_pl_all_relationships($objSCP) as $rel) {
        $gid = $rel['group_id'];
        if ($gid === '' || $rel['person']['id'] === '' || !isset($groups[$gid])) {
            continue;
        }
        $bucket = ($rel['role'] === 'monitor') ? 'monitors' : 'participants';
        $recuentos[$gid][$bucket][$rel['person']['id']] = true;
    }

    /** La línea de debajo del nombre de un grupo: etapa, curso y recuentos.
     *
     * EL CERO INVENTADO. Si el mapa de relaciones viene mal —una respuesta a
     * medias del CRM— este grupo no sale en `$recuentos` y contar da cero. Un
     * «0 chavales» al lado de un grupo que tiene doce es peor que no decir
     * nada: se lee como un dato, no como un fallo.
     *
     * Así que cuando no hay a quién contar se usa el recuento que el Guardián
     * dejó escrito en el propio grupo por la noche —que es justo para lo que
     * está, y es el que enseña el árbol de Pasar Lista— y si tampoco ese sirve,
     * el hueco. Un número viejo lo tapa `sticpa_pl_recuento_fresco()`. */
    $groupMeta = function ($gid, $g) use ($recuentos) {
        if (isset($recuentos[$gid])) {
            $n = $recuentos[$gid];
            $texto = sticpa_pl_recuento_texto(
                isset($n['participants']) ? count($n['participants']) : 0,
                isset($n['monitors']) ? count($n['monitors']) : 0
            );
        } elseif ($g['n_participantes'] >= 0
            && sticpa_pl_recuento_fresco(isset($g['recuento_al']) ? $g['recuento_al'] : '')) {
            $texto = sticpa_pl_recuento_texto(
                $g['n_participantes'],
                ($g['n_monitores'] > 0) ? $g['n_monitores'] : 0
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
 * más: `sticpa_pl_all_relationships()` trae persona, grupo, papel y vigencia de
 * una vez, y es justo lo que hace falta aquí. */
$personas = array();
foreach (sticpa_pl_all_relationships($objSCP) as $rel) {
    if ($rel['role'] === 'monitor' || $rel['person']['id'] === '') {
        continue;
    }
    $gid = $rel['group_id'];
    if ($gid === '' || !isset($groups[$gid])) {
        continue;   // sin grupo, o de un grupo que no entra en Pasar Lista
    }
    $id = $rel['person']['id'];
    if (isset($personas[$id])) {
        continue;   // dos relaciones vigentes: una sola ficha
    }
    $personas[$id] = $rel['person'];
    $personas[$id]['grupo'] = $groups[$gid]['code'];
    $personas[$id]['grupo_id'] = $gid;
    $personas[$id]['curso'] = isset($groups[$gid]['cursos']) ? (string) $groups[$gid]['cursos'] : '';
}

if (empty($personas)) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('No hay participantes con relación vigente en los grupos de tu delegación.', 'sticpa')
        . '</span></p>';
    return;
}

/** La fila de una persona en las vistas de gente.
 *
 * SIN FOTO, y a propósito. Aquí la lista es TODA la delegación —trescientas
 * personas— y cada foto es una petición: con `loading="lazy"` solo bajan las
 * que se ven, pero bajando la lista entera son trescientos viajes en una
 * webview con datos móviles. Donde la lista la acota un grupo (veinte,
 * veinticinco) sí van, y en la ficha —que es donde se pidieron— también. */
$fila = function ($p) use ($ver) {
    $sub = implode(' · ', array_filter(array(
        $p['grupo'],
        $p['curso'],
        ($p['age'] !== '') ? sprintf(
            /* translators: %s: edad en años */
            __('%s años', 'sticpa'),
            $p['age']
        ) : '',
    )));
    return sticpa_pl_person_link_html(
        $p,
        '?internalpage=single_stic_pasar_lista_ficha&participante=' . rawurlencode($p['id'])
            . '&grupo=' . rawurlencode($p['grupo_id']) . '&vengo=' . rawurlencode($ver),
        $sub
    );
};

if ($ver === 'az') {
    $todos = array_values($personas);
    usort($todos, 'sticpa_pl_cmp_person');
    $html .= '<div class="pl-list">';
    foreach ($todos as $p) {
        $html .= $fila($p);
    }
    $html .= '</div>';
    $html .= sticpa_pl_buscador_vacio_html();
    return;
}

// ---- Por CURSO ------------------------------------------------------------

/* El curso CRUZA los grupos, que es justo la gracia: «todos los de 1.º de ESO»
 * son de C1 y de C2, y por grupo no se ven juntos nunca. El orden es el escolar
 * —4.º de primaria antes que 1.º de ESO—, que ya lo sabe `sticpa_pl_curso_rank()`. */
$porCurso = array();
foreach ($personas as $p) {
    $curso = ($p['curso'] !== '') ? $p['curso'] : '?';
    $porCurso[$curso][] = $p;
}
uksort($porCurso, function ($a, $b) {
    $ra = sticpa_pl_curso_rank($a === '?' ? '' : $a);
    $rb = sticpa_pl_curso_rank($b === '?' ? '' : $b);
    if ($ra !== $rb) {
        return ($ra < $rb) ? -1 : 1;
    }
    return strcmp($a, $b);
});

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
foreach ($porCurso as $curso => $gente) {
    usort($gente, 'sticpa_pl_cmp_person');
    $intensidad = ($curso === '?') ? -1.0 : sticpa_pl_curso_intensidad($curso);
    $html .= '<div class="pl-etapa-title">';
    if ($intensidad >= 0) {
        $html .= '<span class="pl-etapa-dot pl-curso-dot"'
            . ' style="opacity:' . esc_attr(number_format(0.28 + 0.72 * $intensidad, 2, '.', '')) . '"'
            . '></span>';
    } else {
        $html .= '<span class="pl-etapa-dot" style="background:var(--gray-300)"></span>';
    }
    $html .= esc_html(($curso === '?') ? __('Sin curso', 'sticpa') : $curso)
        . '<span class="pl-etapa-count">' . esc_html(sprintf(
            /* translators: %d: cuántos participantes hay en el curso */
            _n('%d participante', '%d participantes', count($gente), 'sticpa'),
            count($gente)
        )) . '</span></div>';
    $html .= '<div class="pl-list">';
    foreach ($gente as $p) {
        $html .= $fila($p);
    }
    $html .= '</div>';
}

$html .= sticpa_pl_buscador_vacio_html();
