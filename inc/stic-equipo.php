<?php

/**
 * ============================================================================
 *  EL EQUIPO DE MONITORES: quién entra, y POR QUÉ entra
 * ----------------------------------------------------------------------------
 *  Hasta ahora «Pasar lista» y «Mis grupos» se enseñaban con una sola
 *  condición: `sticpa_get_comunica_role() === 'monitor'`. Y eso dejaba fuera a
 *  quien coordina o acompaña sin llevar además la marca de monitor: no veía la
 *  puerta, así que no llegaba ni a las pantallas de coordinación que el sistema
 *  ya sabe pintarle. Que hoy no le pase a nadie es CASUALIDAD —la única persona
 *  con `coordinacion_mic_com` lleva también `monitor`—, y una casualidad no es
 *  una regla.
 *
 *  Aquí viven las tres preguntas juntas:
 *
 *    1. ¿Esta persona es del equipo de monitores? (para abrir la puerta)
 *    2. ¿POR QUÉ lo es? (para poder DECIRLO en pantalla)
 *    3. ¿Qué secciones son «las suyas de monitor»? (para agruparlas)
 *
 *  COSTE CERO. Todo sale de `stic_relationship_type_c`, que ya está en sesión
 *  (`scp_relationship_raw`) desde el login. Nada de esto llama al CRM, y es un
 *  requisito, no una casualidad: estas funciones las usa el MENÚ, que se pinta
 *  en TODAS las páginas —también las de las familias, que no tienen nada que
 *  ver con esto—. La lección está pagada: `sticpa_viewing_context()` coló una
 *  llamada al CRM por render y tumbó la build.
 *
 *  El ALCANCE de la coordinación (qué etapa, qué segmento) NO se decide aquí:
 *  eso es `sticpa_pl_coord_scope()`, que sí lee las relaciones del CRM una a
 *  una y sí cuesta una consulta. Aquí solo se responde «sí o no», que es lo que
 *  necesita un menú.
 *
 *  Diseño: docs/comunica/PASAR-LISTA-COORDINACION.md §5
 * ============================================================================
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Los PAPELES de la persona conectada dentro del equipo de monitores.
 *
 * Devuelve un subconjunto de `monitor` · `coordinacion` · `acompanamiento`, en
 * ese orden y sin repetir. Es una lista y no un valor porque las tres cosas se
 * acumulan: casi todo el que coordina es además monitor, y coordinar y
 * acompañar no son jerárquicos (`PASAR-LISTA-SEGUIMIENTOS.md` §5).
 *
 * @param string|null $raw Valor de `stic_relationship_type_c`. Si es null se
 *                         usa el de la sesión (y se resuelve si hace falta).
 */
function sticpa_equipo_papeles($raw = null)
{
    $esDeLaSesion = ($raw === null);
    if ($raw === null) {
        // Fuerza la resolución perezosa del rol si la sesión venía sin ella:
        // es la MISMA llamada que ya hace el menú, así que no añade nada.
        if (function_exists('sticpa_get_comunica_role')) {
            sticpa_get_comunica_role();
        }
        $raw = isset($_SESSION['scp_relationship_raw']) ? $_SESSION['scp_relationship_raw'] : '';
    }

    $v = function_exists('mb_strtolower')
        ? mb_strtolower((string) $raw, 'UTF-8')
        : strtolower((string) $raw);

    $papeles = array();

    // MONITOR: la misma detección que el resto del área, para que no haya dos
    // respuestas a «¿es monitor?». Compara por clave exacta y solo cae a la
    // subcadena con el formato viejo de etiquetas (ver stic-comunica-roles.php).
    if (function_exists('sticpa_detect_role_from_relationship')
        && sticpa_detect_role_from_relationship($raw) === 'monitor') {
        $papeles[] = 'monitor';
    }

    // COORDINACIÓN y ACOMPAÑAMIENTO: por prefijo del token, tolerante con la ñ
    // y con los acentos —una clave interna puede estar escrita de las dos
    // formas y no vamos a dejar a nadie sin coordinar por una tilde—. Y por
    // TOKEN, no por subcadena de la cadena entera: un futuro `ex_coordinacion`
    // no debe abrir esta puerta (es el falso positivo que ya nos pintó de verde
    // un «no pagado»).
    foreach (preg_split('/[\^,]+/', $v) as $token) {
        $token = trim($token);
        if ($token === '') {
            continue;
        }
        if (strpos($token, 'coordinaci') === 0 && !in_array('coordinacion', $papeles, true)) {
            $papeles[] = 'coordinacion';
        }
        if ((strpos($token, 'acompan') === 0 || strpos($token, 'acompañ') === 0)
            && !in_array('acompanamiento', $papeles, true)) {
            $papeles[] = 'acompanamiento';
        }
    }

    // Orden estable: monitor, coordinación, acompañamiento. Se pinta en chips y
    // un orden que baila según el CRM se lee como un error.
    $orden = array('monitor', 'coordinacion', 'acompanamiento');
    $out = array();
    foreach ($orden as $papel) {
        if (in_array($papel, $papeles, true)) {
            $out[] = $papel;
        }
    }

    // MONITOR SOLO DE GRUPOS QUE NO PASAN LISTA (20/11/2026): no es monitor. Lo
    // decide la casilla «entra en Pasar Lista» del grupo; ver
    // `sticpa_equipo_monitor_fuera()`. Solo con los papeles de la SESIÓN: con un
    // `$raw` explícito esta función sigue siendo pura sobre ese texto.
    if ($esDeLaSesion && in_array('monitor', $out, true) && sticpa_equipo_monitor_fuera()) {
        $out = array_values(array_diff($out, array('monitor')));
    }

    return (array) apply_filters('sticpa_equipo_papeles', $out, $raw);
}

/**
 * ¿La persona de la sesión es monitor SOLO de grupos que no pasan lista?
 *
 * Hay monitores que llevan un grupo de adultos o de Apoyo (para el seguro) o que
 * están en formación: tienen la relación de monitor, pero no son monitores de
 * verdad y no tienen por qué ver fichas de chavales, grupos ni asistencias. La
 * casilla del grupo (`ajmcm_pasar_lista_c`) ya dice cuáles son del sistema.
 * La regla vive en `sticpa_pl_monitor_fuera_de_pasar_lista()` y es el espejo de
 * la del middleware, que es la que ve la app.
 *
 * EL COSTE. Este módulo se escribió como «coste cero» porque lo pinta el menú en
 * todas las páginas. Esto lo rompe con medida: solo cuando la marca de la ficha
 * dice «monitor» (a las familias no les cuesta nada) y UNA vez cada diez minutos
 * por sesión y delegación, guardado en ella. Lo que lee —sus relaciones y los
 * grupos— está cacheado y caliente para quien abre Pasar Lista. Si algo falla,
 * «no»: lo que había.
 */
function sticpa_equipo_monitor_fuera()
{
    if (!function_exists('sticpa_pl_monitor_fuera_de_pasar_lista') || !class_exists('SugarRestApiCall')
        || empty($_SESSION['scp_user_id'])) {
        return false;
    }
    $objSCP = SugarRestApiCall::getObjSCP();
    $user = (string) $_SESSION['scp_user_id'];
    $deleg = (string) sticpa_pl_delegation($objSCP);
    $memo = isset($_SESSION['scp_equipo_fuera']) && is_array($_SESSION['scp_equipo_fuera']) ? $_SESSION['scp_equipo_fuera'] : array();
    if (isset($memo['u'], $memo['d'], $memo['t'], $memo['v'])
        && $memo['u'] === $user && $memo['d'] === $deleg && (sticpa_pl_now() - (int) $memo['t']) < 600) {
        return (bool) $memo['v'];
    }
    $fuera = (bool) sticpa_pl_monitor_fuera_de_pasar_lista($objSCP);
    // Durante la recolecta de una tanda los cargadores salen vacíos: no se recuerda.
    if (!sticpa_pl_collecting()) {
        $_SESSION['scp_equipo_fuera'] = array('u' => $user, 'd' => $deleg, 't' => sticpa_pl_now(), 'v' => $fuera);
    }
    return $fuera;
}

/**
 * ¿Se le abre a esta persona la sección de monitores?
 *
 * Dos condiciones, y la segunda importa tanto como la primera: un familiar
 * mirando la ficha de su hijo NO pasa lista de nadie, aunque él mismo sea
 * monitor. La sesión está viendo a otra persona y las pantallas de monitor
 * hablan de quien mira, no de a quién se mira.
 */
function sticpa_equipo_es_del_equipo()
{
    $audiencia = function_exists('sticpa_profile_audience') ? sticpa_profile_audience() : 'miembro';
    $es = ($audiencia !== 'participante') && !empty(sticpa_equipo_papeles());
    return (bool) apply_filters('sticpa_equipo_es_del_equipo', $es);
}

/**
 * Las secciones que forman «lo de monitor», en el orden en que se enseñan.
 *
 * Es la FUENTE ÚNICA: la usa el menú para decidir qué añadir y la home para
 * saber qué tarjetas van en el grupo «Equipo de monitores». Si estuviera
 * escrita dos veces, un día dirían cosas distintas.
 *
 * TRES COMO MUCHO, Y CADA UNA CON UN VERBO (repaso del 28/09/2026). Antes eran
 * cinco —«Monitor/a», «Pasar lista», «Mis grupos», «Monitores», «Reuniones»— y
 * no se entendían juntas: «Monitor/a» eran TUS datos, «Monitores» era pasar
 * lista a los monitores (no ver sus fichas, que estaban en una pestaña de Mis
 * grupos) y «Reuniones» era otra forma de pasar esa misma lista. Ahora:
 *
 *   · Pasar lista    → marcar quién ha venido (todo el equipo).
 *   · Grupos y fichas → leer las fichas de los chavales, sin marcar nada.
 *   · Coordinación   → todo lo del equipo de monitores en una sola pantalla:
 *                      sus fichas y seguimientos, su lista del sábado, las
 *                      reuniones y el resumen de grupos.
 *
 * Tus propios datos de monitor ya NO van aquí: son datos tuyos, como «Mis
 * datos», y van con ellos en «Tu perfil» (sticpa_equipo_secciones_de_cuenta()).
 */
function sticpa_equipo_secciones()
{
    $papeles = sticpa_equipo_papeles();
    $secciones = array();

    $secciones['single_stic_pasar_lista'] = __('Pasar lista', 'sticpa');
    // «Grupos y fichas» y no «Mis grupos» (28/09/2026): a lo que se viene es a
    // leer las fichas de los chavales, y quien coordina ve grupos que no son suyos.
    $secciones['single_stic_mis_grupos'] = __('Grupos y fichas', 'sticpa');

    if (in_array('coordinacion', $papeles, true) || in_array('acompanamiento', $papeles, true)) {
        // Acompañamiento también entra: las fichas de los monitores y sus
        // seguimientos son justo lo suyo. Lo que dentro es solo de coordinar
        // (la lista del sábado, las reuniones) la pantalla ya no se lo enseña.
        $secciones['single_stic_coordinacion'] = __('Coordinación', 'sticpa');
    }

    return (array) apply_filters('sticpa_equipo_secciones', $secciones, $papeles);
}

/**
 * Lo de monitor que es DATO TUYO y no herramienta: tu formación, trayectoria y
 * certificados. Va en «Tu perfil», al lado de «Mis datos», que es donde se
 * busca lo que se rellena una vez al año.
 *
 * Se llama «Monitor/a» (20/11/2026; antes «Mis datos de monitor»). Es de quien tiene
 * la relación de monitor AUNQUE no sea del equipo: quien lleva solo grupos que no
 * pasan lista (Comser, Apoyo, formación) ya no ve herramientas de monitor, pero
 * sigue teniendo esto y el aviso del certificado. Por eso NO pasa por
 * `sticpa_equipo_papeles()`, que le quita el papel: lee las marcas de la ficha tal cual.
 */
function sticpa_equipo_secciones_de_cuenta()
{
    if (!sticpa_equipo_tiene_datos_de_monitor()) {
        return array();
    }
    return array('single_stic_comunica_monitor' => __('Monitor/a', 'sticpa'));
}

/**
 * ¿Es la persona de la sesión monitor/a según su ficha (sin el filtro de los
 * grupos que no pasan lista)? Abre lo que es SUYO, no lo del equipo.
 */
function sticpa_equipo_tiene_datos_de_monitor()
{
    $audiencia = function_exists('sticpa_profile_audience') ? sticpa_profile_audience() : 'miembro';
    if ($audiencia === 'participante') {
        return false;
    }
    if (function_exists('sticpa_get_comunica_role')) {
        sticpa_get_comunica_role();   // resuelve la sesión si venía sin rol
    }
    $raw = isset($_SESSION['scp_relationship_raw']) ? (string) $_SESSION['scp_relationship_raw'] : '';
    return in_array('monitor', sticpa_equipo_papeles($raw), true);
}

/**
 * Etiqueta corta de un papel, para chips y frases.
 */
function sticpa_equipo_papel_label($papel)
{
    $labels = array(
        'monitor' => __('Monitor/a', 'sticpa'),
        'coordinacion' => __('Coordinación', 'sticpa'),
        'acompanamiento' => __('Acompañamiento', 'sticpa'),
    );
    return isset($labels[$papel]) ? $labels[$papel] : '';
}

/**
 * EL CHIP que dice POR QUÉ se ve esta sección.
 *
 * Uno como mucho, y a propósito:
 *
 * · «Monitor/a» no lleva chip. Es el caso normal de esta sección y un
 *   distintivo que lleva todo el mundo no distingue nada. El chip está para lo
 *   que SÍ es excepcional, que es lo que se quería que se viera.
 * · Quien coordina Y acompaña ve solo «Coordinación», que es el acceso más
 *   amplio. La primera versión pintaba los dos y a 375px se salían de la
 *   pantalla —siete píxeles de scroll horizontal, cazados con el arnés de
 *   render (design.md §9)—. Dos chips no cabían y tampoco hacían falta: lo de
 *   acompañamiento se dice donde importa, en los seguimientos.
 */
function sticpa_equipo_chips_html()
{
    $papel = sticpa_equipo_papel_principal();
    if ($papel === '') {
        return '';
    }
    return "<span class='stic-equipo-chip stic-equipo-chip--" . esc_attr($papel) . "'>"
        . esc_html(sticpa_equipo_papel_label($papel)) . "</span>";
}

/**
 * El papel que EXPLICA el acceso ampliado: coordinación si la hay, y si no,
 * acompañamiento. Ser monitor no explica nada aquí (lo es todo el mundo en esta
 * sección), así que devuelve '' y quien llame no pinta la frase.
 */
function sticpa_equipo_papel_principal()
{
    $papeles = sticpa_equipo_papeles();
    if (in_array('coordinacion', $papeles, true)) {
        return 'coordinacion';
    }
    if (in_array('acompanamiento', $papeles, true)) {
        return 'acompanamiento';
    }
    return '';
}

/**
 * ¿Es esta página de las del equipo de monitores?
 *
 * Todas las de Pasar Lista (`single_stic_pasar_lista*`: portada, árbol, marcar,
 * ficha, evento, monitores, reuniones, resumen), «Grupos y fichas» y
 * «Coordinación». «Mis datos de monitor» no entra: solo enseña y guarda los
 * datos de quien mira.
 */
function sticpa_equipo_es_pagina_del_equipo($page)
{
    $page = (string) $page;
    return $page === 'single_stic_mis_grupos'
        || $page === 'single_stic_coordinacion'
        || strpos($page, 'single_stic_pasar_lista') === 0;
}

/**
 * LA PUERTA DEL SERVIDOR (28/10/2026). Hasta hoy solo el menú escondía estas
 * páginas: el enrutador incluía cualquier `pages/*.php` y los datos solo se
 * acotaban por la delegación de la sesión, así que una familia con sesión que
 * escribiera `?internalpage=single_stic_pasar_lista` veía los grupos de su
 * delegación. Ahora el enrutador pregunta aquí antes de incluir la página, con
 * la MISMA regla que el menú (sticpa_equipo_es_del_equipo): quien no ve la
 * puerta, no entra. Los guardados son un POST a la propia página, así que
 * quedan detrás de la misma puerta.
 */
function sticpa_equipo_puede_abrir($page)
{
    if (!sticpa_equipo_es_pagina_del_equipo($page)) {
        return true;
    }
    return (bool) apply_filters('sticpa_equipo_puede_abrir', sticpa_equipo_es_del_equipo(), $page);
}

/**
 * Lo que se enseña en vez de la página cuando la puerta está cerrada. Viendo a
 * un hijo, se dice por qué y cómo volver: un monitor que mira la ficha de su
 * hija sigue siendo monitor, solo tiene que volver a la suya.
 */
function sticpa_equipo_puerta_cerrada_html()
{
    $viendoA = function_exists('sticpa_viendo_a_nombre') ? sticpa_viendo_a_nombre() : '';
    if ($viendoA !== '') {
        $texto = sprintf(
            /* translators: %s: nombre de la persona cuya ficha se está viendo */
            __('Estás viendo a %s. Pasar lista y las fichas de los grupos son de tu perfil: vuelve a él para entrar.', 'sticpa'),
            $viendoA
        );
        $enlace = "<a href='?internalpage=single_stic_profile_selection'>" . esc_html__('Cambiar de perfil', 'sticpa') . '</a>';
    } else {
        $texto = __('Esta sección es del equipo de monitores.', 'sticpa');
        $enlace = "<a href='?'>" . esc_html__('Ir al inicio', 'sticpa') . '</a>';
    }
    return "<p class='pl-hint'><span>" . esc_html($texto) . ' ' . $enlace . '</span></p>';
}
