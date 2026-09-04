<?php
/**
 * FICHA DE REGISTRO — el vocabulario común de "un registro del CRM".
 * ----------------------------------------------------------------------------
 * De dónde sale. "Eventos" fue el primer módulo que dejó de pintarse como el
 * volcado genérico "ETIQUETA: valor" y estrenó formato propio: cápsula de
 * fecha, nombre, estado como chip y una barra de acciones al pie
 * (inc/stic-events.php, §49 del CSS). Funcionó, y la ley de diseño lo elevó a
 * norma: «la tarjeta ES la unidad de registro» (design.md §6.1).
 *
 * El problema era que ese formato vivía dentro de Eventos y se llamaba
 * `stic-ev-*`. Los otros ocho módulos del área —inscripciones, pagos,
 * compromisos, documentos, sesiones, asistencias…— seguían con el formulario
 * genérico y todos los campos deshabilitados: cajas grises que no se pueden
 * tocar, con las etiquetas crudas del CRM. Parece un formulario roto, no la
 * ficha de nada.
 *
 * Aquí vive ese formato, ya sin apellido de módulo:
 *   · sticpa_record_icon()        — el juego de iconos a trazo.
 *   · sticpa_record_date_line()   — fechas en lenguaje humano.
 *   · sticpa_record_date_badge()  — la cápsula día/mes.
 *   · sticpa_record_chip()        — el estado, como chip con tono.
 *   · sticpa_record_card_html()   — UNA tarjeta de registro.
 *   · sticpa_record_list_html()   — la rejilla de tarjetas.
 *   · sticpa_record_empty_html()  — el estado vacío con su salida.
 *   · sticpa_record_detail_html() — LA FICHA: cabecera, datos clave,
 *     secciones de texto, avisos y la acción de la pantalla.
 *
 * Todo es declarativo: la página describe QUÉ se enseña y este fichero decide
 * CÓMO se pinta. Así ocho módulos comparten una sola decisión de diseño y
 * arreglar algo aquí lo arregla en todos.
 *
 * Reglas que este renderizador hace cumplir por ti (design.md §6):
 *   · UNA sola acción principal por ficha (la primera con 'primary' gana; las
 *     demás se degradan a fantasma solas).
 *   · Nada de filas "ETIQUETA: valor": los datos clave van como rejilla de
 *     hechos con icono, y el estado va como chip.
 *   · Los valores vacíos no se pintan. Nunca una etiqueta sin dato detrás.
 *   · Todo texto pasa por esc_html() salvo que la sección se declare 'raw'
 *     (que es responsabilidad de quien la declara).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Iconos en línea del área (SVG a trazo, 24×24, `currentColor`).
 * design.md §6.3: nada de fuentes de iconos, nada de emoji como icono.
 */
function sticpa_record_icon($name)
{
    $paths = array(
        'calendar' => "<rect x='3' y='4' width='18' height='18' rx='2'/><path d='M16 2v4M8 2v4M3 10h18'/>",
        'pin'      => "<path d='M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z'/><circle cx='12' cy='10' r='3'/>",
        'clock'    => "<circle cx='12' cy='12' r='9'/><path d='M12 7v5l3 2'/>",
        'users'    => "<path d='M16 21v-2a4 4 0 0 0-8 0v2'/><circle cx='12' cy='7' r='4'/>",
        'user'     => "<circle cx='12' cy='8' r='4'/><path d='M4 21v-1a8 8 0 0 1 16 0v1'/>",
        'euro'     => "<path d='M18 7a6 6 0 1 0 0 10'/><path d='M4 10h8M4 14h8'/>",
        'tag'      => "<path d='M20.6 13.4 12 22l-9-9V4h9z'/><circle cx='7.5' cy='7.5' r='1.5'/>",
        'go'       => "<path d='M5 12h14'/><path d='m13 6 6 6-6 6'/>",
        'back'     => "<path d='M19 12H5'/><path d='m11 18-6-6 6-6'/>",
        'card'     => "<rect x='2' y='5' width='20' height='14' rx='2'/><path d='M2 10h20'/>",
        'file'     => "<path d='M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z'/><path d='M14 2v6h6'/>",
        'download' => "<path d='M12 3v12'/><path d='m7 12 5 5 5-5'/><path d='M5 21h14'/>",
        'check'    => "<path d='M20 6 9 17l-5-5'/>",
        'alert'    => "<path d='M12 9v4M12 17h.01'/><path d='M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z'/>",
        'info'     => "<circle cx='12' cy='12' r='10'/><path d='M12 16v-4M12 8h.01'/>",
        'repeat'   => "<path d='m17 2 4 4-4 4'/><path d='M3 11V9a4 4 0 0 1 4-4h14'/><path d='m7 22-4-4 4-4'/><path d='M21 13v2a4 4 0 0 1-4 4H3'/>",
        'bank'     => "<path d='M3 21h18M4 10h16M5 10V21M19 10V21M9 10V21M15 10V21'/><path d='m12 3 9 5H3z'/>",
        'book'     => "<path d='M4 19.5A2.5 2.5 0 0 1 6.5 17H20'/><path d='M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z'/>",
        'briefcase' => "<rect x='2' y='7' width='20' height='14' rx='2'/><path d='M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2'/>",
        'building' => "<path d='M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6'/>",
        'link'     => "<path d='M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1'/><path d='M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1'/>",
    );
    $d = $paths[$name] ?? $paths['info'];
    return "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'>{$d}</svg>";
}

/**
 * Un rango de fechas en lenguaje humano. Nunca "01/07/2026 – 01/07/2026".
 *
 * @param int|null $startTs Marca de tiempo de inicio.
 * @param int|null $endTs   Marca de tiempo de fin (opcional).
 */
function sticpa_record_date_line($startTs, $endTs = null)
{
    if (!$startTs) {
        return '';
    }
    $long = function ($ts) {
        /* translators: formato de fecha larga; se traduce con los códigos de date_i18n */
        return date_i18n(__('j \d\e F \d\e Y', 'sticpa'), $ts);
    };
    if (!$endTs || date('Y-m-d', $startTs) === date('Y-m-d', $endTs)) {
        return $long($startTs);
    }
    // Mismo mes y año: "del 1 al 10 de julio de 2026" (el mes no se repite).
    if (date('Y-m', $startTs) === date('Y-m', $endTs)) {
        /* translators: 1 = día de inicio, 2 = fecha larga de fin */
        return sprintf(__('del %1$s al %2$s', 'sticpa'), date_i18n('j', $startTs), $long($endTs));
    }
    return sprintf(__('del %1$s al %2$s', 'sticpa'), $long($startTs), $long($endTs));
}

/**
 * Cápsula de fecha (día grande + mes en tres letras) para la tarjeta.
 * Sin fecha, cae a un icono para que la rejilla no se descuadre.
 *
 * @param int|null $ts     Marca de tiempo.
 * @param bool     $isPast Si el registro ya pasó (se apaga).
 * @param string   $icon   Icono de reserva cuando no hay fecha.
 */
function sticpa_record_date_badge($ts, $isPast = false, $icon = 'calendar')
{
    if (!$ts) {
        return "<span class='stic-rec-badge stic-rec-badge--empty' aria-hidden='true'>" . sticpa_record_icon($icon) . "</span>";
    }
    return "<span class='stic-rec-badge" . ($isPast ? ' is-past' : '') . "' aria-hidden='true'>"
        . "<span class='stic-rec-badge-day'>" . esc_html(date_i18n('j', $ts)) . "</span>"
        . "<span class='stic-rec-badge-mon'>" . esc_html(date_i18n('M', $ts)) . "</span>"
        . "</span>";
}

/**
 * Chip de estado.
 *
 * @param string $label Texto ya traducido (nunca el código crudo del CRM).
 * @param string $tone  '' | 'ok' | 'warn' | 'danger' | 'past' | 'info'.
 */
function sticpa_record_chip($label, $tone = '')
{
    $label = trim((string) $label);
    if ($label === '') {
        return '';
    }
    $tones = array('ok', 'warn', 'danger', 'past', 'info');
    $class = 'stic-rec-chip';
    if (in_array($tone, $tones, true)) {
        $class .= ' stic-rec-chip--' . $tone;
    }
    return "<span class='" . esc_attr($class) . "'>" . esc_html($label) . "</span>";
}

/**
 * Normaliza una lista de acciones y hace cumplir la regla de UNA sola acción
 * principal por pantalla (design.md §6.2): la primera marcada 'primary' se
 * queda con el degradado; el resto pasan a fantasma, aunque quien llame se
 * haya despistado.
 *
 * @param array $actions array de array('label','url','primary'?,'icon'?,'attrs'?)
 */
function sticpa_record_normalize_actions($actions)
{
    $out = array();
    $primaryTaken = false;
    foreach ((array) $actions as $action) {
        if (empty($action['label']) || !isset($action['url'])) {
            continue;
        }
        $isPrimary = !empty($action['primary']) && !$primaryTaken;
        if ($isPrimary) {
            $primaryTaken = true;
        }
        $action['primary'] = $isPrimary;
        $out[] = $action;
    }
    return $out;
}

/** Pinta una acción como enlace-botón del vocabulario de ficha. */
function sticpa_record_action_html($action, $extraClass = '')
{
    $class = 'stic-rec-btn ' . (!empty($action['primary']) ? 'stic-rec-btn--primary' : 'stic-rec-btn--ghost');
    if ($extraClass !== '') {
        $class .= ' ' . $extraClass;
    }
    $attrs = '';
    foreach ((array) ($action['attrs'] ?? array()) as $name => $value) {
        $attrs .= ' ' . esc_attr($name) . "='" . esc_attr($value) . "'";
    }
    $icon = !empty($action['icon']) ? sticpa_record_icon($action['icon']) : '';
    return "<a class='" . esc_attr($class) . "' href='" . esc_url($action['url']) . "'{$attrs}>"
        . esc_html($action['label']) . $icon . "</a>";
}

/**
 * UNA tarjeta de registro.
 *
 * @param array $card {
 *   'url'     => enlace de la zona principal (opcional; sin él no es pulsable),
 *   'ts'      => marca de tiempo para la cápsula (opcional),
 *   'icon'    => icono de reserva de la cápsula,
 *   'name'    => título del registro (obligatorio),
 *   'lines'   => array de array('icon','text') — dos como mucho, es una tarjeta,
 *   'chips'   => array de array('label','tone'),
 *   'is_past' => bool, apaga la tarjeta,
 *   'amount'  => texto ya formateado ("120,00 €"). Se pinta a la derecha, en
 *                grande. En un pago el importe NO es un dato más de la fila:
 *                es a lo que se entra, y una columna de importes alineada se
 *                recorre de un vistazo. Lleva cifras tabulares para que no
 *                baile,
 *   'amount_note' => línea pequeña bajo el importe ("al mes", "pendiente"),
 *   'actions' => array de acciones (ver sticpa_record_normalize_actions),
 * }
 */
function sticpa_record_card_html($card)
{
    $name = trim((string) ($card['name'] ?? ''));
    if ($name === '') {
        return '';
    }
    $isPast = !empty($card['is_past']);

    $inner = sticpa_record_date_badge($card['ts'] ?? null, $isPast, $card['icon'] ?? 'calendar');
    $inner .= "<span class='stic-rec-body'>";
    $inner .= "<span class='stic-rec-name'>" . esc_html($name) . "</span>";
    foreach ((array) ($card['lines'] ?? array()) as $line) {
        $text = trim((string) ($line['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $inner .= "<span class='stic-rec-where'>" . sticpa_record_icon($line['icon'] ?? 'info')
            . "<span>" . esc_html($text) . "</span></span>";
    }
    $chips = '';
    foreach ((array) ($card['chips'] ?? array()) as $chip) {
        $chips .= sticpa_record_chip($chip['label'] ?? '', $chip['tone'] ?? '');
    }
    if ($chips !== '') {
        $inner .= "<span class='stic-rec-chips'>{$chips}</span>";
    }
    $inner .= "</span>";

    $amount = trim((string) ($card['amount'] ?? ''));
    if ($amount !== '') {
        $inner .= "<span class='stic-rec-amount'>";
        $inner .= "<span class='stic-rec-amount-fig'>" . esc_html($amount) . "</span>";
        $note = trim((string) ($card['amount_note'] ?? ''));
        if ($note !== '') {
            $inner .= "<span class='stic-rec-amount-note'>" . esc_html($note) . "</span>";
        }
        $inner .= "</span>";
    }

    $html = "<article class='stic-rec-card" . ($isPast ? ' is-past' : '') . "'>";
    if (!empty($card['url'])) {
        $html .= "<a class='stic-rec-main' href='" . esc_url($card['url']) . "'>{$inner}</a>";
    } else {
        $html .= "<div class='stic-rec-main stic-rec-main--static'>{$inner}</div>";
    }

    $actions = sticpa_record_normalize_actions($card['actions'] ?? array());
    if (!empty($actions)) {
        $html .= "<div class='stic-rec-actions'>";
        foreach ($actions as $action) {
            $html .= sticpa_record_action_html($action);
        }
        $html .= "</div>";
    }
    $html .= "</article>";
    return $html;
}

/** La rejilla de tarjetas (1 columna en móvil, 2 desde 768px). */
function sticpa_record_list_html($cards)
{
    $body = '';
    foreach ((array) $cards as $card) {
        $body .= sticpa_record_card_html($card);
    }
    if ($body === '') {
        return '';
    }
    return "<div class='stic-rec-list'>{$body}</div>";
}

/**
 * Estado vacío: icono, qué pasa, y —si procede— por dónde seguir.
 * Un listado vacío sin salida es un callejón sin salida.
 */
function sticpa_record_empty_html($icon, $title, $sub = '', $action = null)
{
    $html = "<div class='stic-empty-state'>";
    $html .= "<span class='stic-empty-ico'>" . sticpa_record_icon($icon) . "</span>";
    $html .= "<p class='stic-empty-title'>" . esc_html($title) . "</p>";
    if ($sub !== '') {
        $html .= "<p class='stic-empty-sub'>" . esc_html($sub) . "</p>";
    }
    if (!empty($action['label']) && !empty($action['url'])) {
        $html .= sticpa_record_action_html(array(
            'label'   => $action['label'],
            'url'     => $action['url'],
            'primary' => !empty($action['primary']),
        ));
    }
    $html .= "</div>";
    return $html;
}

/**
 * LA FICHA de un registro.
 *
 * @param array $spec {
 *   'back'     => array('url','label')  — migaja de vuelta, dentro de la cabecera,
 *   'title'    => string (obligatorio),
 *   'meta'     => array de array('icon','text') — la línea bajo el título,
 *   'chips'    => array de array('label','tone'),
 *   'headline' => array('label','text') — EL dato de la ficha en grande
 *                 (el importe de un pago, la cuota de un compromiso). Opcional,
 *   'progress' => array('label','value','max','value_txt','max_txt','note') —
 *                 una historia de "llevas X de Y". Es UN bloque, no tres datos
 *                 sueltos: "Total del año / Aportado / Pendiente" en tres
 *                 cajas es la misma cuenta contada tres veces,
 *   'facts'    => array de array('icon','label','text') — los datos clave,
 *   'notes'    => array de array('tone','icon','text') — avisos,
 *   'sections' => array de array('title','body','raw'?) — bloques de texto,
 *   'actions'  => array de acciones; la primera 'primary' es LA de la pantalla,
 *   'cta_note' => string — se pinta en vez de las acciones cuando no hay nada
 *                 que hacer ("Ya está pagada"), o debajo de ellas si hay,
 * }
 */
function sticpa_record_detail_html($spec)
{
    $title = trim((string) ($spec['title'] ?? ''));
    if ($title === '') {
        return '';
    }

    $html = "<div class='stic-rec-detail'>";

    // --- Cabecera: quién es este registro ---
    $html .= "<header class='stic-rec-hero'>";
    if (!empty($spec['back']['url'])) {
        $html .= "<a class='stic-rec-back' href='" . esc_url($spec['back']['url']) . "'>"
            . sticpa_record_icon('back')
            . "<span>" . esc_html($spec['back']['label'] ?? __('Volver', 'sticpa')) . "</span></a>";
    }
    $html .= "<h3 class='stic-rec-hero-title'>" . esc_html($title) . "</h3>";

    $meta = '';
    foreach ((array) ($spec['meta'] ?? array()) as $item) {
        $text = trim((string) ($item['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $meta .= "<span class='stic-rec-hero-when'>" . sticpa_record_icon($item['icon'] ?? 'calendar')
            . "<span>" . esc_html($text) . "</span></span>";
    }
    foreach ((array) ($spec['chips'] ?? array()) as $chip) {
        $meta .= sticpa_record_chip($chip['label'] ?? '', $chip['tone'] ?? '');
    }
    if ($meta !== '') {
        $html .= "<div class='stic-rec-hero-meta'>{$meta}</div>";
    }
    $html .= "</header>";

    // --- EL dato, si la ficha tiene uno que manda sobre los demás ---
    if (!empty($spec['headline']['text'])) {
        $html .= "<div class='stic-rec-headline'>";
        if (!empty($spec['headline']['label'])) {
            $html .= "<span class='stic-rec-headline-label'>" . esc_html($spec['headline']['label']) . "</span>";
        }
        $html .= "<span class='stic-rec-headline-text'>" . esc_html($spec['headline']['text']) . "</span>";
        if (!empty($spec['headline']['sub'])) {
            $html .= "<span class='stic-rec-headline-sub'>" . esc_html($spec['headline']['sub']) . "</span>";
        }
        $html .= "</div>";
    }

    // --- Avisos: lo que hay que saber antes de leer los datos ---
    foreach ((array) ($spec['notes'] ?? array()) as $note) {
        $text = trim((string) ($note['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $tone = in_array($note['tone'] ?? '', array('ok', 'warn', 'danger', 'info'), true) ? $note['tone'] : 'info';
        $html .= "<p class='stic-rec-note stic-rec-note--" . esc_attr($tone) . "'>"
            . sticpa_record_icon($note['icon'] ?? ($tone === 'ok' ? 'check' : ($tone === 'info' ? 'info' : 'alert')))
            . "<span>" . esc_html($text) . "</span></p>";
    }

    // --- El progreso, si la ficha cuenta una historia de "llevas X de Y" ---
    if (isset($spec['progress']['max']) && (float) $spec['progress']['max'] > 0) {
        $pr = $spec['progress'];
        $max = (float) $pr['max'];
        $value = max(0.0, (float) ($pr['value'] ?? 0));
        // Se recorta al 100%: un CRM puede tener aportado de más y una barra
        // que se sale de su carril se lee como un fallo, no como buena noticia.
        $pct = (int) round(min(100, ($value / $max) * 100));

        $html .= "<div class='stic-rec-progress'>";
        if (!empty($pr['label'])) {
            $html .= "<span class='stic-rec-progress-label'>" . esc_html($pr['label']) . "</span>";
        }
        $html .= "<div class='stic-rec-progress-top'>";
        $html .= "<span class='stic-rec-progress-fig'>"
            . esc_html($pr['value_txt'] ?? (string) $value)
            /* translators: separador de "X de Y" en una barra de progreso */
            . "<span class='stic-rec-progress-of'>" . esc_html__('de', 'sticpa') . " "
            . esc_html($pr['max_txt'] ?? (string) $max) . "</span></span>";
        $html .= "<span class='stic-rec-progress-pct'>" . esc_html($pct) . "%</span>";
        $html .= "</div>";
        // role=img + aria-label: la barra es decorativa para un lector de
        // pantalla; lo que importa es la frase, y ya está escrita arriba.
        $html .= "<div class='stic-rec-progress-bar' role='img' aria-label='"
            . esc_attr(sprintf(__('%d %% completado', 'sticpa'), $pct)) . "'>"
            . "<span class='stic-rec-progress-fill' style='width:" . (int) $pct . "%'></span></div>";
        if (!empty($pr['note'])) {
            $html .= "<span class='stic-rec-progress-note'>" . esc_html($pr['note']) . "</span>";
        }
        $html .= "</div>";
    }

    // --- Datos clave (solo los que tengan valor) ---
    $facts = '';
    foreach ((array) ($spec['facts'] ?? array()) as $fact) {
        $text = trim((string) ($fact['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $facts .= "<li class='stic-rec-fact'>"
            . "<span class='stic-rec-fact-ico'>" . sticpa_record_icon($fact['icon'] ?? 'info') . "</span>"
            . "<span class='stic-rec-fact-body'>"
            . "<span class='stic-rec-fact-label'>" . esc_html($fact['label'] ?? '') . "</span>"
            . "<span class='stic-rec-fact-text'>" . esc_html($text) . "</span>"
            . "</span></li>";
    }
    if ($facts !== '') {
        $html .= "<ul class='stic-rec-facts'>{$facts}</ul>";
    }

    // --- Secciones de texto ---
    foreach ((array) ($spec['sections'] ?? array()) as $section) {
        $body = (string) ($section['body'] ?? '');
        if (trim($body) === '') {
            continue;
        }
        $html .= "<section class='stic-rec-desc'>";
        if (!empty($section['title'])) {
            $html .= "<h4>" . esc_html($section['title']) . "</h4>";
        }
        // 'raw' => quien declara la sección ya ha escapado su HTML.
        $html .= "<div class='stic-rec-desc-body'>" . (empty($section['raw']) ? nl2br(esc_html($body)) : $body) . "</div>";
        $html .= "</section>";
    }

    // --- La acción de la pantalla ---
    $actions = sticpa_record_normalize_actions($spec['actions'] ?? array());
    $ctaNote = trim((string) ($spec['cta_note'] ?? ''));
    if (!empty($actions) || $ctaNote !== '') {
        $html .= "<div class='stic-rec-cta-row'>";
        foreach ($actions as $index => $action) {
            // La principal, grande y a lo ancho en móvil: es a lo que se viene.
            $html .= sticpa_record_action_html($action, !empty($action['primary']) ? 'stic-rec-btn--lg' : '');
        }
        if ($ctaNote !== '') {
            $html .= "<p class='stic-rec-cta-note'>" . esc_html($ctaNote) . "</p>";
        }
        $html .= "</div>";
    }

    $html .= "</div>";
    return $html;
}

/**
 * TONO de un estado del CRM a partir de su clave interna.
 *
 * El color de un chip no puede salir de la ETIQUETA (cambia con el idioma y
 * con quien la edite en el CRM), así que sale de la clave interna, que es
 * estable. Y no puede salir de una lista cerrada de claves, porque cada
 * instancia de SinergiaCRM añade las suyas: lo que hay es un juego de raíces
 * que cubre las familias de estados de SuiteCRM (`Confirmed`, `pending`,
 * `cancelled`, `rejected`…), en inglés y en castellano.
 *
 * Lo importante: si no reconoce nada, devuelve '' y el chip sale NEUTRO. Nunca
 * pinta de verde algo que no ha entendido — un "cobrado" falso es peor que un
 * chip gris. La etiqueta que se lee siempre es la del CRM; esto solo elige el
 * color de fondo.
 *
 * @param string $key Clave interna del enum (no la etiqueta).
 */
function sticpa_record_status_tone($key)
{
    $k = strtolower(trim((string) $key));
    if ($k === '') {
        return '';
    }
    $familias = array(
        'danger' => array('cancel', 'reject', 'denied', 'denegad', 'rechaz', 'anulad', 'devuelt', 'returned', 'failed', 'fallid', 'error', 'impagad', 'unpaid', 'baja'),
        'ok'     => array('confirm', 'accept', 'aceptad', 'complet', 'finaliz', 'paid', 'pagad', 'cobrad', 'settled', 'active', 'activ', 'attended', 'asistio', 'validat', 'aprobad', 'approved', 'held', 'realizad'),
        'warn'   => array('pending', 'pendient', 'draft', 'borrador', 'waiting', 'espera', 'reserva', 'preinscr', 'process', 'tramit', 'partial', 'parcial', 'planned', 'planificad', 'previst'),
    );
    foreach ($familias as $tone => $raices) {
        foreach ($raices as $raiz) {
            if (strpos($k, $raiz) !== false) {
                return $tone;
            }
        }
    }
    return '';
}

/**
 * Etiqueta traducida de un valor de desplegable, tal y como está en el CRM.
 *
 * Nunca se le enseña a nadie la clave cruda (`Confirmed`, `not_participating`):
 * eso es jerga de base de datos. Si el CRM no sabe traducirla, se devuelve ''
 * y quien llama decide si pinta algo o nada — nunca el código.
 *
 * @param array  $definition Definición cacheada del módulo (sticpa_cached_field_definition).
 * @param string $field      Nombre del campo.
 * @param string $key        Valor crudo del registro.
 */
function sticpa_record_enum_label($definition, $field, $key)
{
    $key = (string) $key;
    if ($key === '' || empty($definition[$field]['options'])) {
        return '';
    }
    $options = $definition[$field]['options'];
    if (!isset($options[$key])) {
        return '';
    }
    $option = $options[$key];
    return is_array($option) ? (string) ($option['value'] ?? '') : (string) $option;
}
