<?php
/**
 * PASAR LISTA — lógica de curso, sesiones y presentación.
 * ----------------------------------------------------------------------------
 * Este archivo contiene SOLO la lógica que no habla con el CRM: en qué curso
 * estamos, qué sesión hay que ofrecer al monitor, cómo se calcula el porcentaje
 * de asistencia a mitad de curso y cómo se escribe el nombre de un grupo.
 *
 * Está separado a propósito de las consultas (inc/stic-pasar-lista-crm.php)
 * porque es la parte que se puede probar sin instancia: los tests de
 * tests/PasarListaTest.php cubren estas funciones enteras.
 *
 * Diseño funcional: docs/comunica/PASAR-LISTA.md
 * Campos del CRM:   docs/comunica/PASAR-LISTA-CAMPOS-CRM.md
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Los cuatro estados de `stic_Attendances.status` más el "sin marcar".
 *
 * Las claves son las REALES del CRM y aquí son constantes cerradas: la API no
 * valida los enum (acepta cualquier cadena), así que nunca se derivan de lo que
 * venga de fuera. Los colores son los mismos del diseño y los de
 * sticpa_calendar_palette(), para que una falta sea del mismo rojo en el
 * calendario y en la lista.
 *
 * 'counts' => true significa que cuenta como haber asistido. "Parcial" cuenta:
 * vino, aunque fuera un rato.
 */
function sticpa_pl_states()
{
    return array(
        'yes' => array(
            'label' => __('Vino', 'sticpa'),
            'color' => '#2f9e44', 'tint' => '#e6fcf5', 'ink' => '#076b4d',
            'glyph' => 'check', 'counts' => true, 'absence' => false,
        ),
        'partial' => array(
            'label' => __('Parcial', 'sticpa'),
            'color' => '#0d9488', 'tint' => '#e6fffb', 'ink' => '#0b6b62',
            'glyph' => 'half', 'counts' => true, 'absence' => false,
        ),
        'no_justified' => array(
            'label' => __('Justificada', 'sticpa'),
            'color' => '#f59e0b', 'tint' => '#fffbeb', 'ink' => '#92400e',
            'glyph' => 'dash', 'counts' => false, 'absence' => true,
        ),
        'no_unjustified' => array(
            'label' => __('No vino', 'sticpa'),
            'color' => '#dc2626', 'tint' => '#fef2f2', 'ink' => '#b91c1c',
            'glyph' => 'cross', 'counts' => false, 'absence' => true,
        ),
    );
}

/**
 * ¿Es una clave de estado que conocemos? Todo lo que no esté en la lista se
 * trata como "sin marcar" en vez de pintarse crudo: si alguien escribió a mano
 * un valor raro en el CRM, la pantalla no debe inventarse un color para él.
 */
function sticpa_pl_is_state($key)
{
    $states = sticpa_pl_states();
    return is_string($key) && $key !== '' && isset($states[$key]);
}

/**
 * El ciclo del toque simple: sin marcar → vino → no vino → sin marcar.
 *
 * Solo estos tres. "Parcial" y "justificada" se ponen manteniendo pulsado,
 * porque son minoritarios y justificar implica saber por qué. Un estado que
 * viene del gesto largo NO entra en el ciclo: al tocarlo se va a 'yes', que es
 * lo que espera quien toca una fila ya marcada de forma especial.
 */
function sticpa_pl_next_state($current)
{
    switch ((string) $current) {
        case '':
            return 'yes';
        case 'yes':
            return 'no_unjustified';
        case 'no_unjustified':
            return '';
        default:
            // partial / no_justified / cualquier cosa rara
            return 'yes';
    }
}

/**
 * El curso escolar que contiene $ts. Arranca el 1 de septiembre.
 *
 * Devuelve array('label' => '2025-2026', 'start' => ts, 'end' => ts).
 * El fin es el 31 de agosto siguiente a las 23:59:59, para que una sesión de
 * julio caiga dentro del curso que empezó en septiembre.
 */
function sticpa_pl_course_for($ts = null)
{
    $ts = ($ts === null) ? sticpa_pl_now() : (int) $ts;
    $year = (int) date('Y', $ts);
    $month = (int) date('n', $ts);
    $startYear = ($month >= 9) ? $year : ($year - 1);

    return array(
        'label' => $startYear . '-' . ($startYear + 1),
        'start' => mktime(0, 0, 0, 9, 1, $startYear),
        'end' => mktime(23, 59, 59, 8, 31, $startYear + 1),
    );
}

/**
 * El reloj, en un solo sitio. Los tests lo mueven con
 * $GLOBALS['__stic_pl_now'] para poder probar "un sábado a las 15:00" sin
 * esperar al sábado.
 */
function sticpa_pl_now()
{
    if (isset($GLOBALS['__stic_pl_now'])) {
        return (int) $GLOBALS['__stic_pl_now'];
    }
    return time();
}

/**
 * QUÉ SESIÓN SE OFRECE. La regla que hace que pasar lista sean pocos clics.
 *
 * $sessions: lista de array('id','start','end') con timestamps, en cualquier
 * orden. Devuelve null si no hay ninguna, o array con:
 *   'session' => la sesión elegida
 *   'why'     => por qué se ha elegido, que es lo que la pantalla convierte en
 *                aviso: 'today_before' | 'today_now' | 'today_after'
 *                        | 'recent' | 'future'
 *   'days'    => días de diferencia con hoy (0 si es hoy)
 *
 * La regla, en palabras: si hoy hay sesión, es esa —aunque aún no haya
 * empezado, porque el monitor llega antes que los chavales y quiere la pantalla
 * lista. Si hoy no hay, la última que ya pasó, mientras no haga más de una
 * semana: es el sábado pasado, y durante los seis días siguientes es lo que
 * quieres corregir. Si no hay ninguna detrás, la siguiente que viene, para que
 * la pantalla pueda decir cuándo es en vez de quedarse muda.
 */
function sticpa_pl_pick_session($sessions, $nowTs = null)
{
    $nowTs = ($nowTs === null) ? sticpa_pl_now() : (int) $nowTs;
    if (!is_array($sessions) || empty($sessions)) {
        return null;
    }

    $today = date('Y-m-d', $nowTs);
    $todayOne = null;
    $recent = null;   // la más reciente ya empezada
    $future = null;   // la más próxima por venir

    foreach ($sessions as $s) {
        if (empty($s['start'])) {
            continue;
        }
        $start = (int) $s['start'];
        $end = !empty($s['end']) ? (int) $s['end'] : $start;

        if (date('Y-m-d', $start) === $today) {
            // Si hubiera dos el mismo día (raro), gana la primera.
            if ($todayOne === null || $start < (int) $todayOne['start']) {
                $todayOne = $s;
            }
            continue;
        }
        if ($start < $nowTs) {
            if ($recent === null || $start > (int) $recent['start']) {
                $recent = $s;
            }
        } else {
            if ($future === null || $start < (int) $future['start']) {
                $future = $s;
            }
        }
        unset($end);
    }

    if ($todayOne !== null) {
        $start = (int) $todayOne['start'];
        $end = !empty($todayOne['end']) ? (int) $todayOne['end'] : $start;
        if ($nowTs < $start) {
            $why = 'today_before';
        } elseif ($nowTs <= $end) {
            $why = 'today_now';
        } else {
            $why = 'today_after';
        }
        return array('session' => $todayOne, 'why' => $why, 'days' => 0);
    }

    if ($recent !== null) {
        $days = (int) floor(($nowTs - (int) $recent['start']) / DAY_IN_SECONDS);
        return array('session' => $recent, 'why' => 'recent', 'days' => $days);
    }

    if ($future !== null) {
        $days = (int) ceil(((int) $future['start'] - $nowTs) / DAY_IN_SECONDS);
        return array('session' => $future, 'why' => 'future', 'days' => $days);
    }

    return null;
}

/**
 * Las sesiones del curso que YA se han celebrado, de más antigua a más nueva.
 *
 * Es el denominador de todo. En febrero el curso no lleva 24 sesiones ni 36,5
 * horas: lleva las que han pasado, y cualquier porcentaje que use el total del
 * curso miente. Una sesión cuenta como celebrada cuando ya ha empezado.
 */
function sticpa_pl_elapsed_sessions($sessions, $nowTs = null)
{
    $nowTs = ($nowTs === null) ? sticpa_pl_now() : (int) $nowTs;
    $out = array();
    foreach ((array) $sessions as $s) {
        if (!empty($s['start']) && (int) $s['start'] <= $nowTs) {
            $out[] = $s;
        }
    }
    usort($out, 'sticpa_pl_cmp_start');
    return $out;
}

/** Orden por fecha de inicio. Función con nombre por PHP 7.4 en callbacks. */
function sticpa_pl_cmp_start($a, $b)
{
    $x = isset($a['start']) ? (int) $a['start'] : 0;
    $y = isset($b['start']) ? (int) $b['start'] : 0;
    if ($x === $y) {
        return 0;
    }
    return ($x < $y) ? -1 : 1;
}

/**
 * Asistencia de un participante HASTA HOY.
 *
 * $marks: array sessionId => clave de estado.
 * Devuelve array('attended','elapsed','pct','hours','hours_total','text').
 *
 * El 'text' lleva SIEMPRE el denominador ("82 % de 12 sesiones"). Un 82 % en
 * noviembre y un 82 % en mayo no son lo mismo, y sin denominador se leen igual.
 * Por eso tampoco se usa `attendance_percentage` de la inscripción, que el CRM
 * calcula sobre el evento completo y a mitad de curso da un número bajo que no
 * significa nada malo.
 */
function sticpa_pl_attendance($sessions, $marks, $nowTs = null)
{
    $states = sticpa_pl_states();
    $elapsed = sticpa_pl_elapsed_sessions($sessions, $nowTs);

    $attended = 0;
    $hours = 0.0;
    $hoursTotal = 0.0;

    foreach ($elapsed as $s) {
        $dur = 0.0;
        if (!empty($s['start']) && !empty($s['end']) && (int) $s['end'] > (int) $s['start']) {
            $dur = ((int) $s['end'] - (int) $s['start']) / HOUR_IN_SECONDS;
        }
        $hoursTotal += $dur;

        $key = isset($s['id'], $marks[$s['id']]) ? $marks[$s['id']] : '';
        if (sticpa_pl_is_state($key) && $states[$key]['counts']) {
            $attended++;
            $hours += $dur;
        }
    }

    $total = count($elapsed);
    $pct = ($total > 0) ? (int) round(($attended / $total) * 100) : 0;

    return array(
        'attended' => $attended,
        'elapsed' => $total,
        'pct' => $pct,
        'hours' => round($hours, 1),
        'hours_total' => round($hoursTotal, 1),
        'text' => ($total === 0)
            ? __('Aún no ha habido sesiones', 'sticpa')
            : sprintf(
                /* translators: 1: porcentaje, 2: número de sesiones celebradas */
                __('%1$d %% de %2$d sesiones', 'sticpa'),
                $pct,
                $total
            ),
    );
}

/**
 * Ausencias SEGUIDAS al final del histórico.
 *
 * El aviso de la pantalla de marcado se enseña solo a partir de tres seguidas:
 * tres faltas repartidas en el curso no dicen nada, tres seguidas sí. Se cuenta
 * desde la última sesión celebrada hacia atrás, y una sesión sin marcar CORTA
 * la cuenta en vez de sumar: no sabemos si vino, y no se acusa a nadie por un
 * hueco en los datos.
 */
function sticpa_pl_absence_streak($sessions, $marks, $nowTs = null)
{
    $states = sticpa_pl_states();
    $elapsed = sticpa_pl_elapsed_sessions($sessions, $nowTs);
    $streak = 0;

    for ($i = count($elapsed) - 1; $i >= 0; $i--) {
        $s = $elapsed[$i];
        $key = isset($s['id'], $marks[$s['id']]) ? $marks[$s['id']] : '';
        if (!sticpa_pl_is_state($key)) {
            break;              // hueco en los datos: se corta
        }
        if (!$states[$key]['absence']) {
            break;              // vino: se corta
        }
        $streak++;
    }

    return $streak;
}

/** A partir de cuántas ausencias seguidas se avisa. Filtrable por delegación. */
function sticpa_pl_streak_threshold()
{
    return (int) apply_filters('sticpa_pl_streak_threshold', 3);
}

/**
 * Cómo se escribe un grupo en pantalla: "C1 · Los Peques".
 *
 * Devuelve array('code','name'): el código va delante y en negrita, el nombre
 * solo si añade información. Los casos que hay de verdad en el CRM:
 *   código y nombre distintos  → los dos
 *   código y nombre iguales    → solo el código, nunca "C1 · C1"
 *   solo nombre                → el nombre hace de identificador
 *   solo código                → solo el código
 * La comparación ignora mayúsculas y espacios, porque "C1" y "c1 " son lo mismo
 * escrito de dos formas.
 */
function sticpa_pl_group_label($code, $name)
{
    $code = trim((string) $code);
    $name = trim((string) $name);

    $norm = function ($v) {
        $v = preg_replace('/\s+/u', '', $v);
        return function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
    };

    if ($code === '') {
        return array('code' => $name, 'name' => '');
    }
    if ($name === '' || $norm($code) === $norm($name)) {
        return array('code' => $code, 'name' => '');
    }
    return array('code' => $code, 'name' => $name);
}

/**
 * Estado de la lista de un grupo en una sesión, para la tira del resumen.
 *
 * 'ok' pasada · 'skip' sin registro (se saltó a propósito) · 'gap' falta por
 * pasar. Un hueco solo es 'gap' si la sesión YA se ha celebrado: una sesión que
 * viene no está pendiente de pasar, simplemente no ha pasado.
 */
function sticpa_pl_list_mark($estado, $sessionStart, $nowTs = null)
{
    $nowTs = ($nowTs === null) ? sticpa_pl_now() : (int) $nowTs;
    $estado = (string) $estado;

    if ($estado === 'pasada') {
        return 'ok';
    }
    if ($estado === 'omitida') {
        return 'skip';
    }
    return ((int) $sessionStart <= $nowTs) ? 'gap' : 'future';
}

// ===========================================================================
// COORDINACIÓN Y MONITORES
// ---------------------------------------------------------------------------
// Diseño: docs/comunica/PASAR-LISTA-COORDINACION.md
// ===========================================================================

/**
 * El ciclo del toque en la lista de MONITORES: verde ⇄ rojo.
 *
 * Al revés que en los chavales, aquí se asume que vienen siempre, así que no
 * existe el "sin marcar": la pantalla arranca en verde y el toque solo sirve
 * para poner una falta y quitarla. Parcial y justificada siguen estando en el
 * gesto largo, que es donde viven las cosas poco frecuentes.
 */
function sticpa_pl_next_state_monitor($current)
{
    return ((string) $current === 'no_unjustified') ? 'yes' : 'no_unjustified';
}

/**
 * ¿Entra este grupo en el alcance de coordinación?
 *
 * `$scope` es array('etapa' => 'COM'|'', 'segmento' => 'com_2'|''). Vacío del
 * todo significa TODA la delegación: quien no tiene alcance marcado es quien
 * mira el conjunto, y limitarle a nada sería justo lo contrario de lo que hace.
 */
function sticpa_pl_scope_matches($scope, $group)
{
    $etapa = isset($scope['etapa']) ? trim((string) $scope['etapa']) : '';
    $segmento = isset($scope['segmento']) ? trim((string) $scope['segmento']) : '';

    if ($etapa === '' && $segmento === '') {
        return true;
    }
    if ($etapa !== '') {
        $groupEtapa = isset($group['etapa']) ? (string) $group['etapa'] : '';
        if (strcasecmp($groupEtapa, $etapa) !== 0) {
            return false;
        }
    }
    if ($segmento !== '') {
        $groupSeg = isset($group['segmento']) ? trim((string) $group['segmento']) : '';
        // Un grupo sin segmento NO se cuela en un alcance por segmento: si se
        // colara, quien coordina COM II vería grupos que no le tocan y creería
        // que son suyos.
        if ($groupSeg === '' || strcasecmp($groupSeg, $segmento) !== 0) {
            return false;
        }
    }
    return true;
}

/**
 * Estado del certificado de delitos sexuales de un monitor.
 *
 * Es lo primero de su ficha porque es una obligación legal y porque es lo que
 * coordinación reclama. Tres estados, y el que importa es el tercero:
 *   'auto'     autorizó al MCM a pedirlo cada año (ajmcm_aut_del_sex_c = 1)
 *   'uploaded' lo subió a mano y está archivado
 *   'missing'  no está — hay que reclamarlo
 */
function sticpa_pl_ds_state($data)
{
    $auto = isset($data['ajmcm_aut_del_sex_c']) ? (string) $data['ajmcm_aut_del_sex_c'] : '';
    $file = isset($data['ajmcm_cert_del_sex_c']) ? (string) $data['ajmcm_cert_del_sex_c'] : '';

    if ($auto === '1') {
        return 'auto';
    }
    if ($file === '1') {
        return 'uploaded';
    }
    return 'missing';
}

/**
 * Las titulaciones de un monitor, ya resueltas para pintar.
 *
 * Devuelve una fila por titulación con:
 *   'label'  cómo se llama
 *   'has'    si la tiene
 *   'year'   el año o lo que haya escrito (el del DAT es texto libre: "2021 - EADB")
 *   'gap'    LA tiene pero NO hay archivo subido
 *
 * El `gap` es el descuadre típico y el motivo de que esto sea una función y no
 * cuatro líneas en la plantilla: alguien marca "titulado" y no sube el título,
 * y meses después nadie sabe si falta el papel o falta el curso.
 */
function sticpa_pl_titulaciones($data)
{
    $val = function ($key) use ($data) {
        return isset($data[$key]) ? trim((string) $data[$key]) : '';
    };
    // Los enum de formación valen 'titulado' / 'finalizado' / 'no' / '' y los
    // de archivo son booleanos. "Tiene" es cualquier cosa que no sea no/vacío.
    $has = function ($v) {
        $v = strtolower($v);
        return ($v !== '' && $v !== 'no' && $v !== '0');
    };

    $rows = array(
        array(
            'label' => __('Premonitores I', 'sticpa'),
            'has' => $has($val('ajmcm_premonitores1_c')),
            'year' => $val('ajmcm_premonitores_year_c'),
            'file' => null,
        ),
        array(
            'label' => __('Premonitores II', 'sticpa'),
            'has' => $has($val('ajmcm_premonitores2_c')),
            'year' => $val('ajmcm_premonitores_year_c'),
            'file' => null,
        ),
        array(
            'label' => __('MAT', 'sticpa'),
            'has' => $has($val('ajmcm_mat_c')),
            'year' => $val('ajmcm_mat_year_c'),
            'file' => $val('ajmcm_mat_file_c') === '1',
        ),
        array(
            'label' => __('DAT', 'sticpa'),
            'has' => $has($val('ajmcm_dat_c')),
            'year' => $val('ajmcm_dat_year_c'),
            'file' => $val('ajmcm_dat_file_c') === '1',
        ),
        array(
            'label' => __('FA', 'sticpa'),
            'has' => $has($val('ajmcm_fa_c')),
            'year' => $val('ajmcm_fa_year_c'),
            'file' => null,
        ),
        array(
            'label' => __('Manipulación de alimentos', 'sticpa'),
            'has' => $has($val('ajmcm_alimentos_c')),
            'year' => '',
            'file' => null,
        ),
    );

    $out = array();
    foreach ($rows as $row) {
        // Solo se enseña lo que tiene: una lista con cuatro "no" es ruido, y lo
        // que se pregunta es qué titulaciones tiene, no las que le faltan.
        if (!$row['has']) {
            continue;
        }
        $row['gap'] = ($row['file'] === false);
        $out[] = $row;
    }
    return $out;
}

/**
 * El año de "monitor/a desde", que en el CRM es una fecha completa.
 *
 * Se guarda como AAAA-01-01 y el 1 de enero nunca se enseña (ver el campo
 * `yearOnly` de pages/single_stic_comunica_monitor.php): aquí se saca solo el
 * año, que es el dato que significa algo.
 */
function sticpa_pl_monitor_since($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^(\d{4})/', $raw, $m)) {
        return $m[1];
    }
    return '';
}
