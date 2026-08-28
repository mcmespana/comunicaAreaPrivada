<?php
/**
 * PASAR LISTA — piezas de HTML reutilizadas por las pantallas.
 * ----------------------------------------------------------------------------
 * Los glifos, la fila de la lista, la leyenda y la hoja de estados. Están aquí
 * y no en las páginas para que el círculo verde de "vino" sea EL MISMO en la
 * lista, en la leyenda y en la hoja: si cada pantalla se pinta su propio SVG,
 * al mes hay tres checks distintos.
 *
 * Estilos: css/pasar-lista.css · Interacción: js/stic-pasar-lista.js
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Los cuatro glifos de estado, siempre los cuatro.
 *
 * Se pintan todos y el CSS enseña el del estado actual (`[data-state]`), en vez
 * de decidirlo en PHP: así el JS cambia un atributo y ya está, sin tener que
 * reconstruir HTML al marcar.
 */
function sticpa_pl_glyphs()
{
    return '<svg class="pl-glyph-check" viewBox="0 0 24 24" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>'
        . '<svg class="pl-glyph-half" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 1 0 20Z"/></svg>'
        . '<svg class="pl-glyph-dash" viewBox="0 0 24 24" stroke-width="3.2" stroke-linecap="round" aria-hidden="true"><path d="M6 12h12"/></svg>'
        . '<svg class="pl-glyph-cross" viewBox="0 0 24 24" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
}

/** Un glifo suelto, para la leyenda y la hoja. */
function sticpa_pl_glyph($which)
{
    switch ($which) {
        case 'check':
            return '<svg class="pl-glyph-check" viewBox="0 0 24 24" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
        case 'half':
            return '<svg class="pl-glyph-half" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 1 0 20Z"/></svg>';
        case 'dash':
            return '<svg class="pl-glyph-dash" viewBox="0 0 24 24" stroke-width="3.6" stroke-linecap="round" aria-hidden="true"><path d="M6 12h12"/></svg>';
        case 'cross':
            return '<svg class="pl-glyph-cross" viewBox="0 0 24 24" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
    }
    return '';
}

/** Iconos sueltos de la pantalla (chevron, reloj, info…). */
function sticpa_pl_icon($which)
{
    $icons = array(
        'back' => '<path d="m15 18-6-6 6-6"/>',
        'next' => '<path d="m9 18 6-6-6-6"/>',
        'down' => '<path d="m6 9 6 6 6-6"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'skip' => '<path d="m5 4 10 8-10 8V4Z"/><path d="M19 5v14"/>',
        'refresh' => '<path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/>',
        // La lupa del buscador del árbol de grupos.
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        // Las barras del botón de «Resumen de grupos» (artboard `Main`).
        'chart' => '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-6"/><path d="M22 20H2"/>',
        // La persona con anillo de la tarjeta de «participantes sin grupo».
        'person' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="10" r="2.6"/><path d="M7.5 18a4.8 4.8 0 0 1 9 0"/>',
        // El triángulo de aviso: lo que reclama algo, no lo que informa. Se usa
        // en el título de las listas pendientes y en nada decorativo.
        'warn' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'pencil' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
    );
    if (!isset($icons[$which])) {
        return '';
    }
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $icons[$which] . '</svg>';
}

/**
 * Una fila de la lista de marcado.
 *
 * `$person` viene de sticpa_pl_group_people(); `$state` es la clave del CRM o ''.
 * Es un <button> y no un <div> con onclick: así lo alcanza el teclado y lo
 * anuncia el lector de pantalla sin tener que añadir roles a mano.
 */
/**
 * El AVATAR de una persona: su foto si la tiene, y si no sus iniciales.
 *
 * La foto se pide al endpoint `stic_pl_photo`, que la sirve como miniatura
 * cacheada en disco — no va incrustada en el HTML. Si esa persona no tiene
 * foto, el endpoint contesta 404 y el `onerror` deja las iniciales, que están
 * DEBAJO desde el principio: así no hay salto ni hueco mientras carga, y sin
 * JavaScript se ven las iniciales de siempre.
 *
 * @param bool $conFoto false donde no se quiera pagar la descarga (una lista
 *                      larga son tantas peticiones como filas).
 */
function sticpa_pl_avatar_html($person, $conFoto = false, $clase = 'pl-avatar')
{
    $ini = isset($person['initials']) ? $person['initials'] : '';
    // `aria-hidden`: las iniciales y la foto son decoración — el nombre va al
    // lado, y un lector de pantalla que lea «ese ve» antes de «Solete
    // Vilarroya» solo estorba.
    $out = '<span class="' . esc_attr($clase) . '" aria-hidden="true">' . esc_html($ini);
    if ($conFoto && !empty($person['id'])) {
        $src = admin_url('admin-post.php?action=stic_pl_photo&persona=' . rawurlencode($person['id']));
        $out .= '<img class="pl-avatar-img" src="' . esc_url($src) . '" alt=""'
            . ' loading="lazy" decoding="async"'
            . ' onerror="this.remove()">';
    }
    $out .= '</span>';
    return $out;
}

/**
 * EL BUSCADOR, en un solo sitio.
 *
 * Nació en el árbol de grupos y ahora lo usan las dos pantallas. Filtra lo YA
 * PINTADO, en el navegador: ni una consulta más, ni una recarga. Quien lo
 * pinta decide sobre qué filtra (grupos o personas); el JavaScript de
 * `stic-pasar-lista.js` se encarga del resto.
 *
 * @param string $placeholder lo que se busca en esta pantalla, en su idioma.
 * @param string $aria        etiqueta para el lector de pantalla.
 */
function sticpa_pl_buscador_html($placeholder, $aria = '')
{
    if ($aria === '') {
        $aria = __('Buscar', 'sticpa');
    }
    $html = '<div class="pl-search">';
    $html .= sticpa_pl_icon('search');
    $html .= '<input type="search" data-pl-filter'
        . ' placeholder="' . esc_attr($placeholder) . '"'
        . ' aria-label="' . esc_attr($aria) . '"'
        . ' autocomplete="off" enterkeyhint="search">';
    $html .= '</div>';
    return $html;
}

/**
 * El «no hay nada que coincida» del buscador.
 *
 * Va oculto y lo enseña el JavaScript cuando el filtro deja la lista vacía. Va
 * SIEMPRE al final de lo filtrable, no junto al cuadro de búsqueda: escribir y
 * ver desaparecer todo sin una palabra se lee como que la pantalla se ha roto.
 */
function sticpa_pl_buscador_vacio_html($msg = '')
{
    if ($msg === '') {
        $msg = __('Nada coincide con lo que buscas.', 'sticpa');
    }
    return '<p class="pl-search-empty" data-pl-filter-empty hidden>' . esc_html($msg) . '</p>';
}

/**
 * El recuento de un grupo: «12 chavales · 2 monitores».
 *
 * Se cuenta la gente que de verdad se ha pintado, no el recuento nocturno del
 * grupo: aquí el mapa de relaciones ya está cargado, así que contar es gratis y
 * el número es el de ahora mismo. El recuento del Guardián sigue siendo el que
 * usa el árbol de Pasar Lista, donde no se recorre la gente
 * (PASAR-LISTA-RECUENTOS.md).
 *
 * Los monitores solo salen si los hay: «· 0 monitores» en un grupo de MIC es
 * un dato que nadie ha pedido y que además alarma.
 */
function sticpa_pl_recuento_texto($nParticipantes, $nMonitores = 0)
{
    $nParticipantes = (int) $nParticipantes;
    $nMonitores = (int) $nMonitores;

    $bits = array(sprintf(
        /* translators: %d: cuántos participantes tiene el grupo */
        _n('%d chaval', '%d chavales', $nParticipantes, 'sticpa'),
        $nParticipantes
    ));
    if ($nMonitores > 0) {
        $bits[] = sprintf(
            /* translators: %d: cuántos monitores tiene el grupo */
            _n('%d monitor', '%d monitores', $nMonitores, 'sticpa'),
            $nMonitores
        );
    }
    return implode(' · ', $bits);
}

/**
 * La nota al pie de los grupos que la casilla del CRM deja fuera.
 *
 * En gris pequeño y al final: es una nota al pie, no un aviso. Pero tiene que
 * estar, porque un grupo que existe y no aparece, sin explicación, se lee como
 * que la pantalla está rota. No cuesta una llamada: el número lo dejó contado
 * `sticpa_pl_groups()` al filtrar.
 */
function sticpa_pl_grupos_ocultos_html($objSCP)
{
    $ocultos = sticpa_pl_grupos_ocultos($objSCP);
    if ($ocultos <= 0) {
        return '';
    }
    return '<p class="pl-footnote">' . esc_html(sprintf(
        /* translators: %d: cuántos grupos no salen */
        _n(
            '%d grupo más en el CRM sin marcar para Pasar Lista.',
            '%d grupos más en el CRM sin marcar para Pasar Lista.',
            $ocultos,
            'sticpa'
        ),
        $ocultos
    )) . '</p>';
}

/**
 * Una persona como ENLACE a su ficha, con la misma pinta que una fila de lista.
 *
 * Es la fila de «Mis grupos». No reutiliza `sticpa_pl_row_html()` a propósito:
 * aquella es un `<button>` que marca asistencia y lleva su gesto largo, su
 * anillo y su hoja de estados. Aquí no se marca nada — se lee — y meter un
 * modo dentro de aquella sería arriesgar la pantalla que de verdad importa un
 * sábado para ahorrarse veinte líneas.
 *
 * Lo que SÍ se reutiliza es todo lo visible: las mismas clases, el mismo
 * avatar, la misma tipografía, la misma flecha. Se ve igual porque ES igual.
 */
function sticpa_pl_person_link_html($person, $href, $sub = '', $extra = '', $conFoto = false)
{
    $html = '<a class="pl-rowwrap pl-rowlink" href="' . esc_url($href) . '">';
    $html .= sticpa_pl_avatar_html($person, $conFoto);
    $html .= '<span class="pl-row-body">';
    $html .= '<span class="pl-name">' . esc_html($person['name']) . '</span>';
    if ($sub !== '') {
        $html .= '<span class="pl-rowsub">' . esc_html($sub) . '</span>';
    }
    if ($extra !== '') {
        $html .= '<span class="pl-note" style="color:var(--danger-dark)">' . esc_html($extra) . '</span>';
    }
    $html .= '</span>';
    $html .= '<span class="pl-detail pl-detail--static">' . sticpa_pl_icon('next') . '</span>';
    $html .= '</a>';
    return $html;
}

/**
 * DE DÓNDE VIENES, para poder volver ahí.
 *
 * La ficha se abre desde dos sitios muy distintos: desde la lista de marcar —y
 * entonces volver es volver a marcar, en mitad de un sábado— y desde «Mis
 * grupos», donde volver a marcar sería justo el precio que esa pantalla existe
 * para no pagar.
 *
 * `vengo` lo dice, y solo acepta valores CONOCIDOS: lo que venga en la URL nunca
 * se convierte en un enlace tal cual, ni se propaga de una ficha a la siguiente.
 * Esta función es la lista blanca, y devuelve '' para todo lo demás.
 */
function sticpa_pl_vengo_modo($vengo)
{
    $vengo = (string) $vengo;
    $conocidos = array('grupo', 'grupos', 'cursos', 'az', 'monitores');
    return in_array($vengo, $conocidos, true) ? $vengo : '';
}

/**
 * El enlace de vuelta que corresponde a un `vengo`, o '' si no es de los
 * nuestros. La lista blanca está en `sticpa_pl_vengo_modo()`, en un solo sitio.
 */
function sticpa_pl_vengo_url($vengo, $vgrupo = '')
{
    $vengo = sticpa_pl_vengo_modo($vengo);
    $base = '?internalpage=single_stic_mis_grupos';

    if ($vengo === 'grupo') {
        $vgrupo = sticpa_pl_safe_id($vgrupo);
        return ($vgrupo !== '') ? ($base . '&grupo=' . rawurlencode($vgrupo)) : $base;
    }
    if ($vengo === 'grupos' || $vengo === 'cursos' || $vengo === 'az') {
        return $base . '&ver=' . $vengo;
    }
    if ($vengo === 'monitores') {
        return $base . '&quien=monitores';
    }
    return '';
}

/**
 * Los vecinos de alguien en una lista: quién va antes, quién después y en qué
 * posición está.
 *
 * Es lo que hace falta para leer varias fichas seguidas sin volver al índice
 * entre una y otra. La lista es la que ya tiene la pantalla —la gente del
 * grupo, que llega ordenada por apellido—, así que esto no cuesta nada.
 *
 * NO da la vuelta al final a propósito: llegar al último y volver al primero
 * sin avisar es leer dos veces la misma ficha creyendo que se avanza.
 */
function sticpa_pl_vecinos($lista, $id)
{
    $lista = array_values($lista);
    $out = array('prev' => null, 'next' => null, 'pos' => 0, 'total' => count($lista));
    foreach ($lista as $i => $p) {
        if (!isset($p['id']) || $p['id'] !== $id) {
            continue;
        }
        $out['pos'] = $i + 1;
        if ($i > 0) {
            $out['prev'] = $lista[$i - 1];
        }
        if ($i + 1 < count($lista)) {
            $out['next'] = $lista[$i + 1];
        }
        break;
    }
    return $out;
}

/**
 * El pie de «anterior / siguiente» de una ficha.
 *
 * Va AL FINAL y no en la cabecera: leer una ficha es bajar hasta abajo, y el
 * sitio donde se decide pasar a la siguiente es donde se acaba la anterior.
 *
 * Lleva el NOMBRE de quien viene, no solo una flecha: saber a quién pasas es lo
 * que convierte esto en «me leo el grupo entero» en vez de en un botón a
 * ciegas. Y en medio, la posición: sin ella no se sabe cuánto queda.
 *
 * @param callable $href recibe la persona y devuelve su URL.
 */
function sticpa_pl_pager_html($vecinos, $href)
{
    if (empty($vecinos['prev']) && empty($vecinos['next'])) {
        return '';   // una sola ficha: un pie que no lleva a ningún sitio es ruido
    }

    $lado = function ($p, $dir) use ($href) {
        $clase = 'pl-pager-side pl-pager-side--' . $dir;
        if (empty($p)) {
            // El hueco se mantiene: sin él, el «siguiente» del último se
            // desplaza al centro y parece otro botón.
            return '<span class="' . $clase . ' pl-pager-side--none" aria-hidden="true"></span>';
        }
        $etiqueta = ($dir === 'prev') ? __('Anterior', 'sticpa') : __('Siguiente', 'sticpa');
        $out = '<a class="' . $clase . '" href="' . esc_url($href($p)) . '">';
        if ($dir === 'prev') {
            $out .= '<span class="pl-pager-arrow">' . sticpa_pl_icon('back') . '</span>';
        }
        $out .= '<span class="pl-pager-body">'
            . '<span class="pl-pager-label">' . esc_html($etiqueta) . '</span>'
            . '<span class="pl-pager-name">' . esc_html($p['name']) . '</span>'
            . '</span>';
        if ($dir === 'next') {
            $out .= '<span class="pl-pager-arrow">' . sticpa_pl_icon('next') . '</span>';
        }
        return $out . '</a>';
    };

    $html = '<nav class="pl-pager" aria-label="' . esc_attr__('Otras fichas del grupo', 'sticpa') . '">';
    $html .= $lado(isset($vecinos['prev']) ? $vecinos['prev'] : null, 'prev');
    if (!empty($vecinos['total']) && !empty($vecinos['pos'])) {
        $html .= '<span class="pl-pager-pos">' . esc_html(sprintf(
            /* translators: 1: posición de esta ficha, 2: cuántas hay en el grupo */
            __('%1$d de %2$d', 'sticpa'),
            $vecinos['pos'],
            $vecinos['total']
        )) . '</span>';
    }
    $html .= $lado(isset($vecinos['next']) ? $vecinos['next'] : null, 'next');
    $html .= '</nav>';
    return $html;
}

function sticpa_pl_row_html($person, $state, $streak = 0, $fichaUrl = '', $sub = '', $motive = '', $aviso = '', $track = null)
{
    $states = sticpa_pl_states();
    $state = sticpa_pl_is_state($state) ? $state : '';

    $warn = '';
    if ($streak >= sticpa_pl_streak_threshold()) {
        $warn = sprintf(
            /* translators: %d: número de ausencias consecutivas */
            _n('%d ausencia seguida', '%d ausencias seguidas', $streak, 'sticpa'),
            $streak
        );
    }
    // Un aviso ya escrito por quien llama —el seguimiento de un monitor— usa el
    // mismo sitio y el mismo rojo que la racha de ausencias de un chaval: es el
    // mismo mensaje («a esta persona hay que mirarla») y no merece un segundo
    // idioma en la misma lista.
    if ($aviso !== '') {
        $warn = $aviso;
    }

    // La nota bajo el nombre: el estado del gesto largo (que si no, es invisible)
    // y el aviso de ausencias seguidas, que solo sale si son SEGUIDAS. El JS la
    // recompone al marcar, y por eso el aviso viaja también en `data-warn`.
    $notes = array();
    $noteClass = '';
    if ($state === 'partial' || $state === 'no_justified') {
        $notes[] = $states[$state]['label'];
        $noteClass = 'style="color:' . esc_attr($states[$state]['ink']) . '"';
    }
    if ($warn !== '') {
        $notes[] = $warn;
        $noteClass = 'style="color:var(--danger-dark)"';
    }
    $note = implode(' · ', $notes);

    $html = '<button type="button" class="pl-row" data-state="' . esc_attr($state) . '"'
        . ' data-contact="' . esc_attr($person['id']) . '"'
        . ' data-warn="' . esc_attr($warn) . '"'
        . ' data-motive="' . esc_attr($motive) . '"'
        . ' data-name="' . esc_attr($person['name']) . '"'
        . ' data-initials="' . esc_attr($person['initials']) . '"'
        . ' data-label-partial="' . esc_attr($states['partial']['label']) . '"'
        . ' data-label-no_justified="' . esc_attr($states['no_justified']['label']) . '"'
        . ' aria-label="' . esc_attr($person['name']) . '">';

    $html .= '<span class="pl-avatar">' . esc_html($person['initials']) . '</span>';
    $html .= '<span class="pl-row-body">';
    $html .= '<span class="pl-name">' . esc_html($person['name']) . '</span>';
    // Línea fija: los grupos de un monitor. Es lo que distingue a dos personas
    // con el mismo nombre de pila y lo que explica por qué están en esta lista.
    if ($sub !== '' || $track !== null) {
        $html .= '<span class="pl-rowsub">';
        if ($sub !== '') {
            $html .= esc_html($sub);
        }
        /* EL PORCENTAJE, CALLADO. (ROADMAP «ausencias de monitores», 28/08/2026.)
         *
         * Coordinación quiere el número, pero treinta porcentajes en fila no se
         * leen: todos pesan lo mismo y ninguno destaca. Así que va pequeño, gris
         * y al final de la línea que ya explica de qué grupo es cada uno — se
         * encuentra cuando se busca y no compite cuando se escanea.
         *
         * Lo que SÍ salta a la vista sigue siendo la nota roja de debajo, que
         * solo sale cuando hay algo que mirar. Este número no la sustituye: la
         * acompaña, para poder comparar dos monitores sin abrir sus fichas.
         *
         * Con menos sesiones marcadas que el mínimo no se pinta nada: con dos
         * datos un porcentaje es una anécdota, y una anécdota con pinta de dato
         * es peor que ningún dato. */
        if (is_array($track)) {
            $u = sticpa_pl_seguimiento_umbrales();
            $pct = (int) $track['pct'];
            if ($pct >= 0 && (int) $track['counted'] >= (int) $u['minimo_para_opinar']) {
                $flojo = ($pct < (int) $u['pct_minimo']);
                $html .= '<span class="pl-rowpct' . ($flojo ? ' pl-rowpct--bajo' : '') . '"'
                    . ' title="' . esc_attr(sprintf(
                        /* translators: 1: a cuántas vino, 2: cuántas se contaron */
                        __('Vino a %1$d de %2$d sesiones marcadas', 'sticpa'),
                        (int) $track['attended'],
                        (int) $track['counted']
                    )) . '">' . esc_html(sprintf(
                        /* translators: %d: porcentaje de asistencia */
                        __('%d %%', 'sticpa'),
                        $pct
                    )) . '</span>';
            }
        }
        $html .= '</span>';
    }
    $html .= '<span class="pl-note" data-pl-state-note ' . $noteClass
        . ($note === '' ? ' hidden' : '') . '>' . esc_html($note) . '</span>';
    $html .= '</span>';

    // El anillo del gesto largo va DENTRO del círculo, y se dibuja siempre: lo
    // enseña y lo llena el CSS cuando la fila está en `is-holding`. Pintarlo
    // desde el principio evita insertar nodos en medio de un gesto.
    $html .= '<span class="pl-mark">' . sticpa_pl_glyphs()
        . '<svg class="pl-hold-ring-svg" viewBox="0 0 44 44" aria-hidden="true">'
        . '<circle cx="22" cy="22" r="20"/></svg>'
        . '</span>';
    $html .= '</button>';

    // La flecha va FUERA del botón de marcar: dos controles anidados no se
    // pueden separar con el teclado, y aquí son dos acciones distintas.
    if ($fichaUrl !== '') {
        $html .= '<a class="pl-detail" data-pl-detail href="' . esc_url($fichaUrl) . '"'
            . ' aria-label="' . esc_attr(sprintf(
                /* translators: %s: nombre del participante */
                __('Ver la ficha de %s', 'sticpa'),
                $person['name']
            )) . '">' . sticpa_pl_icon('next') . '</a>';
    }

    return '<div class="pl-rowwrap">' . $html . '</div>';
}

/**
 * La leyenda de los cuatro círculos, más el chip del gesto largo.
 *
 * Va debajo de la lista y no en cada fila: el color y el glifo se aprenden una
 * vez y así la lista queda limpia. El chip de "mantén pulsado" es obligatorio,
 * no decorativo: sin él, parcial y justificada no existen para el usuario.
 */
function sticpa_pl_legend_html()
{
    $states = sticpa_pl_states();
    $order = array('yes' => 'yes', 'partial' => 'partial', 'just' => 'no_justified', 'no' => 'no_unjustified');

    $html = '<div class="pl-legend">';
    foreach ($order as $css => $key) {
        $html .= '<span class="pl-legend-item">'
            . '<span class="pl-legend-dot pl-legend-dot--' . esc_attr($css) . '">'
            . sticpa_pl_glyph($states[$key]['glyph']) . '</span>'
            . '<span class="pl-legend-label">' . esc_html($states[$key]['label']) . '</span>'
            . '</span>';
    }
    $html .= '<span class="pl-hold-hint"><span class="pl-hold-ring" aria-hidden="true"></span>'
        . esc_html__('Mantén pulsado', 'sticpa') . '</span>';
    $html .= '</div>';

    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . sprintf(
            /* translators: %s: "vino / no vino" en negrita */
            esc_html__('Toca la fila para %s. Mantén pulsado para parcial o justificar.', 'sticpa'),
            '<strong>' . esc_html__('vino / no vino', 'sticpa') . '</strong>'
        )
        . '</span></p>';

    return $html;
}

/**
 * La hoja inferior con los cuatro estados.
 *
 * Es lo que abre el gesto largo. Se pinta una sola vez por pantalla y el JS le
 * cambia el nombre y la marca: no hace falta una hoja por participante.
 */
function sticpa_pl_sheet_html($whenLabel = '')
{
    $states = sticpa_pl_states();
    $descs = array(
        'partial' => __('Cuenta como asistencia', 'sticpa'),
        'no_justified' => __('No es necesario justificar, pero a veces avisan', 'sticpa'),
    );

    $html = '<div class="pl-sheet-veil" data-pl-veil></div>';
    $html .= '<div class="pl-sheet" data-pl-sheet role="dialog" aria-modal="true" aria-hidden="true"'
        . ' aria-label="' . esc_attr__('Estado de asistencia', 'sticpa') . '">';
    // El agarre no es solo decoración: es la zona por la que se arrastra, así que
    // su área táctil es alta aunque la rayita se vea fina.
    $html .= '<div class="pl-sheet-griparea" aria-hidden="true"><span class="pl-sheet-grip"></span></div>';

    $html .= '<div class="pl-sheet-who">'
        . '<span class="pl-avatar" data-pl-sheet-initials></span>'
        . '<span><span class="pl-sheet-name" data-pl-sheet-name></span><br>'
        . '<span class="pl-sheet-when">' . esc_html($whenLabel) . '</span></span>'
        . '</div>';

    foreach (array('yes', 'partial', 'no_justified', 'no_unjustified') as $key) {
        $html .= '<button type="button" class="pl-opt" role="radio" aria-checked="false"'
            . ' data-value="' . esc_attr($key) . '">'
            . '<span class="pl-opt-dot">' . sticpa_pl_glyph($states[$key]['glyph']) . '</span>'
            . '<span class="pl-opt-body">'
            . '<span class="pl-opt-label">' . esc_html($states[$key]['label']) . '</span>'
            . (isset($descs[$key]) ? '<span class="pl-opt-desc">' . esc_html($descs[$key]) . '</span>' : '')
            . '</span>'
            . '<span class="pl-opt-check">' . sticpa_pl_glyph('check') . '</span>'
            . '</button>';
    }

    /* El motivo, opcional. Va al campo `description` de la asistencia, que es
     * donde el CRM lo espera y donde se puede leer luego desde el propio CRM.
     * Aquí abajo y no arriba: primero se dice QUÉ pasó (los cuatro estados) y
     * solo después, si hace falta, POR QUÉ. Sin estado no se pinta (lo oculta
     * el CSS): un motivo sin marca no significa nada. */
    $html .= '<label class="pl-motive">'
        . sticpa_pl_icon('pencil')
        . '<input type="text" data-pl-sheet-motive maxlength="255" autocomplete="off"'
        . ' placeholder="' . esc_attr__('Añadir un motivo (opcional)', 'sticpa') . '"'
        . ' aria-label="' . esc_attr__('Motivo de la ausencia', 'sticpa') . '">'
        . '</label>';

    /* La salida a la ficha, DESDE la hoja. Cuando marcas una falta, lo
     * siguiente que quieres casi siempre es el teléfono de casa — y la hoja
     * tapa la pantalla, así que la flecha de la fila queda detrás. El JS le
     * pone la dirección de la persona que está abierta. */
    $html .= '<a class="pl-sheet-ficha" data-pl-sheet-ficha href="#" hidden>'
        . sticpa_pl_icon('person') . '<span>'
        . esc_html__('Ficha y teléfonos', 'sticpa') . '</span>'
        . sticpa_pl_icon('next') . '</a>';

    $html .= '<button type="button" class="pl-sheet-clear" data-pl-sheet-clear>'
        . esc_html__('Quitar la marca', 'sticpa') . '</button>';
    $html .= '</div>';

    return $html;
}

/**
 * La cápsula de fecha del área privada: día grande y mes debajo.
 *
 * Es el componente de `docs/design-system.md` §11 (`.stic-cell-badge`), el
 * mismo que llevan los listados con `$listSettings['cardDate']`. Se reutiliza
 * tal cual en vez de pintar otra cápsula: si cada pantalla se hace la suya, al
 * mes hay tres formas distintas de decir "15 de noviembre".
 *
 * `$class` permite revestirla para el sitio donde va (sobre el degradado del
 * atajo, por ejemplo, donde la marca sobre la marca no se leería).
 */
function sticpa_pl_date_capsule($ts, $class = '')
{
    $ts = (int) $ts;
    if ($ts <= 0) {
        return '';
    }
    return '<span class="stic-cell-badge' . ($class !== '' ? ' ' . esc_attr($class) : '') . '" aria-hidden="true">'
        . '<span class="stic-cell-badge-day">' . esc_html(date_i18n('j', $ts)) . '</span>'
        . '<span class="stic-cell-badge-mon">' . esc_html(date_i18n('M', $ts)) . '</span>'
        . '</span>';
}

/**
 * El CUÁNDO en relativo, para la pastilla del atajo: "Hoy", "Hace 3 días"…
 *
 * Va en relativo y no con la fecha porque la fecha ya está en la cápsula de al
 * lado. Lo que la pastilla contesta es otra pregunta —"¿esto es lo de hoy o me
 * he quedado atrás?"— y esa se contesta antes de leer el nombre del grupo.
 */
function sticpa_pl_when_pill($pick, $done = false)
{
    if ($done) {
        return __('Pasada', 'sticpa');
    }
    $why = (is_array($pick) && !empty($pick['why'])) ? $pick['why'] : '';
    switch ($why) {
        case 'recent':
            $days = isset($pick['days']) ? (int) $pick['days'] : 0;
            return sprintf(
                /* translators: %d: cuántos días hace de la sesión */
                _n('Hace %d día', 'Hace %d días', $days, 'sticpa'),
                $days
            );
        case 'future':
            return __('Próxima', 'sticpa');
        default:
            return __('Hoy', 'sticpa');
    }
}

/**
 * El selector de sesión: un <select> NATIVO dentro de la pastilla de siempre.
 *
 * En el móvil —que es el 99 % de los usos— el desplegable nativo es lo mejor
 * que hay: rueda a pulgar, se abre pegado al dedo y no cuesta ni una pantalla
 * ni una consulta más. Antes esto era un viaje a otra pantalla para volver con
 * una fecha, que en un sábado con prisa son cuatro toques de más.
 *
 * Cada opción lleva el NÚMERO de sesión delante de la fecha corta ("3 · 11 ago")
 * porque el número es como se habla de ellas ("la tercera") y la fecha es como
 * se comprueba que es la que toca. Las dos juntas caben de sobra.
 */
function sticpa_pl_session_select_html($sessions, $currentId, $groupId = '', $page = 'single_stic_pasar_lista_marcar')
{
    $groupId = (string) $groupId;
    $elapsed = sticpa_pl_elapsed_sessions($sessions);
    if (count($elapsed) < 2) {
        // Con una sola sesión celebrada no hay nada que elegir: la pastilla
        // diría "sáb 15" y al abrirla habría una sola línea. Se pinta el dato
        // sin control, que es más honesto que un desplegable de un elemento.
        $current = null;
        foreach ($sessions as $s) {
            if ($s['id'] === $currentId) {
                $current = $s;
                break;
            }
        }
        if ($current === null) {
            return '';
        }
        return '<span class="pl-session-pick"><span class="pl-session-pick-text">'
            . esc_html(sticpa_pl_session_short($current)) . '</span></span>';
    }

    // El número de sesión es su posición en el CURSO, contando desde la
    // primera: así "la 3" es la 3 para todo el mundo y no cambia según lo que
    // se esté enseñando en pantalla.
    $numbers = array();
    $n = 0;
    foreach ($sessions as $s) {
        $n++;
        $numbers[$s['id']] = $n;
    }

    // De la más reciente a la más antigua: se pasa lista de lo que acaba de
    // pasar, y lo más probable está arriba sin tener que desplazar.
    $elapsed = array_reverse($elapsed);
    $currentLabel = '';

    $out = '<span class="pl-session-pick pl-session-pick--select">';
    $out .= '<select data-pl-session-jump'
        . ' aria-label="' . esc_attr__('Elegir la sesión', 'sticpa') . '">';
    foreach ($elapsed as $s) {
        $num = isset($numbers[$s['id']]) ? $numbers[$s['id']] : 0;
        // "3 · 11 ago": número de sesión y fecha corta, que es como se nombra
        // una sesión al hablar y como se comprueba que es la buena.
        $label = ($num > 0 ? $num . ' · ' : '') . sticpa_pl_session_short($s, true);
        // Sin grupo (la lista de monitores) la url no lo lleva: es la misma
        // pregunta —"¿de qué día?"— pero de una pantalla que no tiene grupo.
        $url = '?internalpage=' . $page
            . (($groupId !== '') ? '&grupo=' . rawurlencode($groupId) : '')
            . '&sesion=' . rawurlencode($s['id']);
        $selected = ($s['id'] === $currentId);
        if ($selected) {
            $currentLabel = $label;
        }
        $out .= '<option value="' . esc_url($url) . '"' . ($selected ? ' selected' : '') . '>'
            . esc_html($label) . '</option>';
    }
    $out .= '</select>';
    // El texto visible es el de la pastilla de siempre; el <select> va encima,
    // transparente y a pantalla completa de la pastilla (ver el CSS).
    $out .= '<span class="pl-session-pick-text">' . esc_html($currentLabel) . '</span>';
    $out .= sticpa_pl_icon('down');
    $out .= '</span>';

    return $out;
}

/**
 * Cómo se lee una sesión en pantalla: "sábado 15 de noviembre · 16:30".
 *
 * Se formatea a partir de `start_date`, NUNCA del `name` de la sesión: el CRM
 * genera ese nombre al crearla y no lo refresca si luego cambian las fechas,
 * y además arrastra un desajuste de zona horaria.
 */
function sticpa_pl_session_label($session, $withTime = true)
{
    if (empty($session['start'])) {
        return '';
    }
    $ts = (int) $session['start'];
    $label = function_exists('wp_date')
        ? wp_date('l j \d\e F', $ts)
        : date_i18n('l j \d\e F', $ts);
    if ($withTime) {
        $label .= ' · ' . date_i18n('H:i', $ts);
    }
    return $label;
}

/**
 * Versión corta de una sesión.
 *
 * Con `$withMonth` sale "11 ago", que es lo que hace falta en el desplegable:
 * un curso pasa por varios meses y "sáb 11" a secas se repite cuatro veces.
 * Sin él sale "sáb 15", que es lo que cabe en la pastilla de la cabecera.
 */
function sticpa_pl_session_short($session, $withMonth = false)
{
    if (empty($session['start'])) {
        return '';
    }
    $ts = (int) $session['start'];
    return $withMonth ? date_i18n('j M', $ts) : date_i18n('D j', $ts);
}

/**
 * El aviso de por qué se propone esta sesión y no otra.
 *
 * Solo se pinta cuando hay algo que decir: durante la sesión no hay aviso,
 * porque es el caso normal y un aviso ahí sería ruido.
 */
function sticpa_pl_notice_html($pick)
{
    if (!is_array($pick) || empty($pick['why'])) {
        return '';
    }
    $session = isset($pick['session']) ? $pick['session'] : array();
    $time = !empty($session['start']) ? date_i18n('H:i', (int) $session['start']) : '';
    $msg = '';

    switch ($pick['why']) {
        case 'today_before':
            $msg = sprintf(
                /* translators: %s: hora de inicio */
                __('Empieza a las %s — aún no han llegado', 'sticpa'),
                '<strong>' . esc_html($time) . '</strong>'
            );
            break;
        case 'recent':
            $msg = sprintf(
                /* translators: %s: cuántos días hace */
                _n('Es la sesión de hace %d día', 'Es la sesión de hace %d días', (int) $pick['days'], 'sticpa'),
                (int) $pick['days']
            );
            break;
        case 'future':
            $msg = sprintf(
                /* translators: %s: fecha de la próxima sesión */
                __('La próxima sesión es el %s', 'sticpa'),
                esc_html(sticpa_pl_session_label($session, false))
            );
            break;
        case 'today_now':
        case 'today_after':
        default:
            return '';
    }

    return '<p class="pl-notice">' . sticpa_pl_icon('clock') . '<span>' . $msg . '</span></p>';
}

/**
 * Qué hacer DESPUÉS de guardar bien.
 *
 * Hasta ahora la pantalla se quedaba igual que estaba, con un «Lista guardada»
 * pequeño arriba: el monitor ya ha terminado y la aplicación no le ofrece nada.
 * Lo que viene después es siempre una de estas dos cosas — mirar cómo va el
 * grupo, o pasar la lista de otro— y las dos estaban a tres toques.
 *
 * Solo se pinta cuando el guardado está CONFIRMADO contra el CRM: es una
 * recompensa, y una recompensa detrás de un fallo es una burla.
 */
function sticpa_pl_next_steps_html($groupId = '')
{
    $html = '<div class="pl-next">';
    $html .= '<a class="pl-next-btn" href="?internalpage=single_stic_pasar_lista_resumen">'
        . sticpa_pl_icon('chart') . '<span>' . esc_html__('Ver el resumen', 'sticpa') . '</span></a>';
    $html .= '<a class="pl-next-btn" href="?internalpage=single_stic_pasar_lista_grupos">'
        . sticpa_pl_icon('search') . '<span>' . esc_html__('Otro grupo', 'sticpa') . '</span></a>';
    $html .= '</div>';
    return $html;
}

// ---------------------------------------------------------------------------
// El resultado de un guardado, dicho sin mentir
// ---------------------------------------------------------------------------

/**
 * ¿Se le puede enseñar a esta persona el detalle técnico de un fallo?
 *
 * SÍ, a todo el mundo, y es deliberado. Estaba reservado a coordinación, y eso
 * convertía cada fallo en un teléfono escacharrado: el monitor decía «no se
 * guarda», alguien tenía que reproducirlo con otra cuenta y, hasta entonces, la
 * respuesta del CRM —que es la que dice qué pasa— no la leía nadie.
 *
 * Lo que se enseña son ids del CRM y el mensaje de error de la API. No hay
 * datos personales ahí, y va dentro de un `<details>` cerrado: quien no quiera
 * abrirlo ve solo el aviso en castellano.
 *
 * Si algún día molesta, se apaga sin tocar código:
 *
 *     add_filter('sticpa_pl_debug_allowed', '__return_false');
 */
function sticpa_pl_debug_allowed($objSCP = null)
{
    return (bool) apply_filters('sticpa_pl_debug_allowed', true, $objSCP);
}

/**
 * El aviso de después de guardar.
 *
 * LA REGLA: «Lista guardada» SOLO si no ha fallado nada Y la relectura del CRM
 * lo confirma. Antes se decía siempre que no hubiera `failed`, y `failed` no
 * contaba ni el fallo de la lista ni el de las relaciones: la pantalla podía
 * felicitarte con el CRM vacío, que es exactamente el bug que nos ha costado
 * semanas.
 *
 * @param array $saved     lo que devolvió sticpa_pl_save()/_save_monitors()
 * @param array $problemas lo que devolvió sticpa_pl_check_saved() (relectura)
 */
function sticpa_pl_save_result_html($saved, $problemas = array(), $objSCP = null)
{
    if (!is_array($saved)) {
        return '';
    }
    $failed = isset($saved['failed']) ? (int) $saved['failed'] : 0;
    $problemas = (array) $problemas;
    $errors = isset($saved['errors']) ? (array) $saved['errors'] : array();

    if ($failed === 0 && empty($problemas)) {
        return '<p class="pl-notice" style="color:var(--success-dark)">' . sticpa_pl_icon('check')
            . '<span>' . esc_html__('Lista guardada.', 'sticpa') . '</span></p>';
    }

    $html = '<p class="pl-notice" style="color:var(--danger-dark)">' . sticpa_pl_icon('warn') . '<span>';
    if ($failed > 0) {
        $html .= esc_html(sprintf(
            /* translators: 1: marcas guardadas, 2: fallos */
            __('No se ha guardado del todo: %1$d bien y %2$d con fallo.', 'sticpa'),
            isset($saved['saved']) ? (int) $saved['saved'] : 0,
            $failed
        ));
    } else {
        // Ni un fallo y aun así no está: es el caso traicionero.
        $html .= esc_html__('El CRM ha aceptado el guardado, pero al volver a leerlo no está.', 'sticpa');
    }
    if (!empty($problemas)) {
        $html .= ' ' . esc_html(implode('; ', array_map('strval', $problemas)) . '.');
    }
    $html .= ' ' . esc_html__('Tus marcas siguen puestas en la pantalla: no se han perdido. Vuelve a intentarlo y, si sigue igual, avisa a coordinación.', 'sticpa');
    $html .= '</span></p>';

    // El detalle, solo para quien puede arreglarlo.
    if (!empty($errors) && sticpa_pl_debug_allowed($objSCP)) {
        $html .= '<details class="pl-hint"><summary>'
            . esc_html__('Detalle técnico del fallo', 'sticpa') . '</summary><ul>';
        foreach ($errors as $e) {
            $paso = isset($e['paso']) ? (string) $e['paso'] : '?';
            $msg = isset($e['error']) ? (string) $e['error'] : '';
            $id = isset($e['id']) ? (string) $e['id'] : '';
            $html .= '<li><code>' . esc_html($paso) . '</code>'
                . ($id !== '' ? ' <code>' . esc_html($id) . '</code>' : '')
                . ($msg !== '' ? ' — ' . esc_html($msg) : '')
                . '</li>';
        }
        $html .= '</ul></details>';
    }

    return $html;
}

// ---------------------------------------------------------------------------
// Las pistas de cuadraditos del seguimiento de monitores
// ---------------------------------------------------------------------------

/**
 * Los nombres y colores de cada cuadradito, por tipo de pista.
 *
 * `asistencia` reutiliza las cuatro claves de estado del marcado, así que el
 * verde de «vino» es EL MISMO verde de la lista: quien ha marcado una lista ya
 * sabe leer esta fila sin que nadie se la explique.
 */
function sticpa_pl_sq_meta($tipo)
{
    if ($tipo === 'listas') {
        return array(
            'suya' => array('class' => 'pl-sq--suya', 'label' => __('La pasó', 'sticpa')),
            'otra' => array('class' => 'pl-sq--otra', 'label' => __('La pasó otra persona', 'sticpa')),
            'omitida' => array('class' => 'pl-sq--omitida', 'label' => __('No hubo sesión', 'sticpa')),
            'sin' => array('class' => 'pl-sq--falta', 'label' => __('Sin lista', 'sticpa')),
        );
    }
    $out = array();
    foreach (sticpa_pl_states() as $key => $st) {
        $out[$key] = array('class' => 'pl-sq--' . $key, 'label' => $st['label']);
    }
    $out[''] = array('class' => 'pl-sq--none', 'label' => __('Sin marcar', 'sticpa'));
    return $out;
}

/**
 * La fila de cuadraditos.
 *
 * Un cuadrado por sesión celebrada, en orden, con una holgura extra cada vez
 * que cambia el mes: así se lee «esto fue en enero» sin poner ni una fecha, y
 * cuatro faltas seguidas de enero se ven como un bloque en vez de como cuatro
 * cuadrados sueltos entre veinte.
 *
 * Los cuadrados son decoración para el lector de pantalla —`aria-hidden`— y el
 * conjunto lleva un `aria-label` con el resumen en palabras: veinticuatro
 * «vino, vino, no vino» seguidos no ayudan a nadie.
 */
function sticpa_pl_squares_html($squares, $tipo = 'asistencia', $aria = '')
{
    if (empty($squares)) {
        return '';
    }
    $meta = sticpa_pl_sq_meta($tipo);

    /* AGRUPADOS POR MES, Y CON EL MES ESCRITO.
     *
     * Antes eran veinticuatro cuadraditos en fila con un respiro sin etiqueta
     * al cambiar de mes. A esa escala la fila no dice nada: se ve que «hay
     * rojos» pero no CUÁNDO, y cuándo es justo el dato — cuatro faltas seguidas
     * en enero y cuatro repartidas por el curso no son el mismo chaval.
     *
     * Con el mes debajo de cada grupo, la misma fila pasa a leerse como un
     * calendario pequeño: se localiza el bache sin abrir nada.
     */
    $grupos = array();
    foreach ($squares as $sq) {
        $ts = isset($sq['start']) ? (int) $sq['start'] : 0;
        $clave = ($ts > 0) ? date('Y-n', $ts) : '·';
        if (!isset($grupos[$clave])) {
            $grupos[$clave] = array('ts' => $ts, 'sqs' => array());
        }
        $grupos[$clave]['sqs'][] = $sq;
    }

    $ultimo = count($squares) - 1;
    $i = -1;
    $html = '<div class="pl-track-sqs" role="img" aria-label="' . esc_attr($aria) . '">';
    foreach ($grupos as $g) {
        $html .= '<span class="pl-sq-mon">';
        $html .= '<span class="pl-sq-row">';
        foreach ($g['sqs'] as $sq) {
            $i++;
            $ts = isset($sq['start']) ? (int) $sq['start'] : 0;
            $state = isset($sq['state']) ? (string) $sq['state'] : '';
            $m = isset($meta[$state]) ? $meta[$state] : $meta[''];
            $cuando = ($ts > 0) ? date_i18n('j M', $ts) : '';
            $titulo = ($cuando !== '') ? $cuando . ' · ' . $m['label'] : $m['label'];

            // El ÚLTIMO lleva un anillo: «cómo va últimamente» es la pregunta
            // que se hace de verdad, y sin marca hay que contar hasta el final
            // para saber dónde acaba el curso vivido.
            $ultima = ($i === $ultimo) ? ' pl-sq--last' : '';

            $html .= '<span class="pl-sq ' . esc_attr($m['class']) . esc_attr($ultima) . '"'
                . ' title="' . esc_attr($titulo) . '" aria-hidden="true"></span>';
        }
        $html .= '</span>';
        // La inicial del mes, en minúscula y pequeñísima: sitúa sin competir.
        $html .= '<span class="pl-sq-mlabel" aria-hidden="true">'
            . esc_html($g['ts'] > 0 ? date_i18n('M', $g['ts']) : '') . '</span>';
        $html .= '</span>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Una pista entera: título, marcador a la derecha, cuadrados y pie.
 *
 * El marcador NUNCA va solo. Un «78 %» no distingue a quien faltó cuatro
 * sábados seguidos en enero —y sigue sin venir— de quien falta uno de cada
 * cinco desde octubre, y esa diferencia es justo la que hace que coordinación
 * llame o no llame. Los cuadrados enseñan el patrón; el número sirve para
 * comparar y para apuntarlo.
 */
function sticpa_pl_track_html($titulo, $squares, $marcador, $pie, $tipo = 'asistencia', $aria = '')
{
    $html = '<div class="pl-track">';
    $html .= '<div class="pl-track-head">';
    $html .= '<span class="pl-track-title">' . esc_html($titulo) . '</span>';
    if ($marcador !== '') {
        $html .= '<span class="pl-track-score">' . $marcador . '</span>';
    }
    $html .= '</div>';
    $html .= sticpa_pl_squares_html($squares, $tipo, ($aria !== '') ? $aria : $pie);
    if ($pie !== '') {
        $html .= '<div class="pl-track-foot">' . esc_html($pie) . '</div>';
    }
    $html .= '</div>';
    return $html;
}

/** El marcador de un porcentaje, con el `%` pequeño. `-1` es «no se sabe». */
function sticpa_pl_pct_html($pct)
{
    if ((int) $pct < 0) {
        return '<span class="pl-track-score--none">' . esc_html__('sin datos', 'sticpa') . '</span>';
    }
    return esc_html((string) (int) $pct) . '<i>%</i>';
}

/** La leyenda de los cuadraditos, en enano. Solo los estados que salen. */
function sticpa_pl_sq_legend_html($tipo, $usados)
{
    $meta = sticpa_pl_sq_meta($tipo);
    $trozos = array();
    foreach ($meta as $key => $m) {
        if (!in_array((string) $key, $usados, true)) {
            continue;
        }
        $trozos[] = '<span class="pl-sq-key"><span class="pl-sq ' . esc_attr($m['class'])
            . '" aria-hidden="true"></span>' . esc_html($m['label']) . '</span>';
    }
    if (empty($trozos)) {
        return '';
    }
    return '<div class="pl-sq-legend">' . implode('', $trozos) . '</div>';
}

/** Los estados que aparecen de verdad en una pista, para la leyenda. */
function sticpa_pl_sq_usados($squares)
{
    $out = array();
    foreach ((array) $squares as $sq) {
        $s = isset($sq['state']) ? (string) $sq['state'] : '';
        if (!in_array($s, $out, true)) {
            $out[] = $s;
        }
    }
    return $out;
}
