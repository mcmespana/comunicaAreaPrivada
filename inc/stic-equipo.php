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

    return (array) apply_filters('sticpa_equipo_papeles', $out, $raw);
}

/** ¿Tiene este papel la persona conectada? */
function sticpa_equipo_tiene($papel)
{
    return in_array($papel, sticpa_equipo_papeles(), true);
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
 * Las dos últimas son de coordinación y hasta hoy solo se llegaba a ellas
 * bajando del todo en la home de Pasar lista.
 */
function sticpa_equipo_secciones()
{
    $papeles = sticpa_equipo_papeles();
    $secciones = array();

    if (in_array('monitor', $papeles, true)) {
        $secciones['single_stic_comunica_monitor'] = __('Monitor/a', 'sticpa');
    }
    $secciones['single_stic_pasar_lista'] = __('Pasar lista', 'sticpa');
    $secciones['single_stic_mis_grupos'] = __('Mis grupos', 'sticpa');

    if (in_array('coordinacion', $papeles, true) || in_array('acompanamiento', $papeles, true)) {
        // La puerta a las fichas de monitor y, dentro de cada una, a sus
        // seguimientos: es el camino que pediste para «ver los seguimientos».
        $secciones['single_stic_pasar_lista_monitores'] = __('Monitores', 'sticpa');
    }
    if (in_array('coordinacion', $papeles, true)) {
        $secciones['single_stic_pasar_lista_reuniones'] = __('Reuniones', 'sticpa');
    }

    return (array) apply_filters('sticpa_equipo_secciones', $secciones, $papeles);
}

/**
 * De esas secciones, las que solo existen POR COORDINAR (o acompañar).
 *
 * Sirve para colocarlas: en la home van dentro del grupo, en su orden; en la
 * BARRA de navegación van al final, detrás de Eventos y compañía. La barra es
 * de una sola línea y lo que no cabe se va a «Más», así que meter dos entradas
 * de coordinación por delante empujaba «Eventos» —lo que usa todo el mundo,
 * coordinación incluida— dentro del desplegable. Lo que se usa más va delante:
 * es la misma regla que pone el bloque de coordinación DEBAJO de los grupos en
 * Pasar lista.
 */
function sticpa_equipo_secciones_de_coordinacion()
{
    $todas = sticpa_equipo_secciones();
    $solo = array();
    foreach (array('single_stic_pasar_lista_monitores', 'single_stic_pasar_lista_reuniones') as $clave) {
        if (isset($todas[$clave])) {
            $solo[$clave] = $todas[$clave];
        }
    }
    return $solo;
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
 * El aviso de «esto lo ves porque coordinas», para las pantallas que solo ve
 * coordinación (o acompañamiento).
 *
 * No es decorativo. Estas pantallas enseñan a personas datos de OTRAS personas
 * —la asistencia de un monitor, lo que alguien escribió sobre él—, y quien las
 * abre tiene que saber en calidad de qué las está viendo. Un permiso que no se
 * ve es un permiso que se olvida.
 *
 * @param string $ambito Alcance ya en lenguaje humano ("COM", "toda la
 *                       delegación"). Sale de sticpa_pl_coord_scope(), que es
 *                       quien sabe de esto y CUESTA UNA CONSULTA: por eso lo
 *                       pasa quien ya la ha hecho, y quien no (la home) manda
 *                       cadena vacía y se queda con la frase corta.
 * @param string $papel  'coordinacion' | 'acompanamiento'.
 * @param string $que    Qué es lo que se está viendo ("esta pantalla",
 *                       "estas secciones"), para que la frase encaje donde se
 *                       pinte en vez de sonar a plantilla.
 */
function sticpa_equipo_por_que_html($ambito = '', $papel = 'coordinacion', $que = '')
{
    if (trim((string) $que) === '') {
        $que = __('esta pantalla', 'sticpa');
    }

    if ($papel === 'acompanamiento') {
        $texto = sprintf(
            /* translators: %s: qué se está viendo ("esta pantalla") */
            __('Ves %s porque acompañas al equipo de monitores.', 'sticpa'),
            $que
        );
    } elseif (trim((string) $ambito) !== '') {
        $texto = sprintf(
            /* translators: 1: qué se está viendo; 2: alcance ("COM", "toda la delegación") */
            __('Ves %1$s porque coordinas %2$s.', 'sticpa'),
            $que,
            trim((string) $ambito)
        );
    } else {
        $texto = sprintf(
            /* translators: %s: qué se está viendo ("esta pantalla") */
            __('Ves %s porque eres coordinación.', 'sticpa'),
            $que
        );
    }

    $icono = "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2'"
        . " stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'>"
        . "<path d='M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z'/></svg>";

    return "<p class='stic-porque'>"
        . "<span class='stic-porque-ico' aria-hidden='true'>{$icono}</span>"
        . "<span class='stic-porque-text'>" . esc_html($texto) . "</span>"
        . "</p>";
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
