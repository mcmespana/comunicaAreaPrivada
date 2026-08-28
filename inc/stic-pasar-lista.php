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
/**
 * Los cursos escolares que cubre una relación, de sus fechas.
 *
 * El CRM no guarda «2024-2025» en ningún campo de `stic_Contacts_Relationships`
 * —se miró—, así que el curso se deduce de `start_date` y `end_date`. Una
 * relación que duró tres años sale en los tres cursos, porque esos tres años
 * estuvo: agruparla solo por el primero contaría mal la historia.
 *
 * Reglas de los huecos, que son la mitad de los casos reales:
 *   - Sin fecha de inicio: se toma la de fin, y si tampoco hay, hoy.
 *   - Sin fecha de fin: sigue abierta, así que llega hasta hoy.
 *   - Con fecha de inicio en el futuro: solo su curso, sin inventar más.
 *
 * @return string[] etiquetas de curso, de la más antigua a la más reciente.
 */
function sticpa_pl_rel_cursos($startTs, $endTs, $nowTs = null)
{
    $nowTs = ($nowTs === null) ? sticpa_pl_now() : (int) $nowTs;
    $startTs = (int) $startTs;
    $endTs = (int) $endTs;

    if ($startTs <= 0) {
        $startTs = ($endTs > 0) ? $endTs : $nowTs;
    }
    $hasta = ($endTs > 0) ? min($endTs, $nowTs) : $nowTs;
    if ($hasta < $startTs) {
        $hasta = $startTs;
    }

    $out = array();
    $cursor = $startTs;
    // Tope de doce vueltas: doce cursos son más que la vida de monitor de
    // nadie, y un bucle sobre fechas del CRM no puede quedarse colgado.
    for ($i = 0; $i < 12; $i++) {
        $curso = sticpa_pl_course_for($cursor);
        $out[] = $curso['label'];
        if ($curso['end'] >= $hasta) {
            break;
        }
        $cursor = $curso['end'] + 86400;
    }
    return $out;
}

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

// ===========================================================================
// SEGUIMIENTOS DE MONITORES
// ---------------------------------------------------------------------------
// Tres tipos de nota sobre un monitor, con TRES visibilidades distintas. Es la
// parte más delicada de todo el sistema: aquí un filtro mal puesto no enseña un
// dato de más, enseña a una persona lo que otra escribió sobre ella.
//
// Diseño: docs/comunica/PASAR-LISTA-SEGUIMIENTOS.md
// ===========================================================================

/**
 * Los tres tipos de seguimiento, y quién puede leer y escribir cada uno.
 *
 * 'read' y 'write' son listas de papeles: 'coordinacion' | 'acompanamiento'.
 * Nadie más lee nada, y eso incluye al propio monitor.
 */
function sticpa_pl_seg_tipos()
{
    return array(
        'incidencia' => array(
            'label' => __('Incidencia', 'sticpa'),
            'help' => __('Algo concreto que pasó un día', 'sticpa'),
            'read' => array('coordinacion', 'acompanamiento'),
            'write' => array('coordinacion'),
            'color' => '#f59e0b',
        ),
        'valoracion' => array(
            'label' => __('Valoración de trimestre', 'sticpa'),
            'help' => __('Cómo ha ido el trimestre', 'sticpa'),
            'read' => array('coordinacion', 'acompanamiento'),
            'write' => array('coordinacion'),
            'color' => '#1c6fb3',
        ),
        'acompanamiento' => array(
            'label' => __('Acompañamiento', 'sticpa'),
            'help' => __('Solo lo ve quien acompaña', 'sticpa'),
            // La única que NO ve coordinación. Es el motivo de que exista el
            // papel de acompañamiento como algo separado.
            'read' => array('acompanamiento'),
            'write' => array('acompanamiento'),
            'color' => '#6c4b9e',
        ),
    );
}

/**
 * Los papeles del usuario, para decidir qué seguimientos puede ver.
 *
 * `$isCoord` y `$isAcomp` vienen de las relaciones del CRM. Se devuelve una
 * lista y no un solo papel porque alguien puede ser las dos cosas, y entonces
 * ve la unión — no hay jerarquía entre coordinar y acompañar.
 */
function sticpa_pl_seg_roles($isCoord, $isAcomp)
{
    $roles = array();
    if ($isCoord) {
        $roles[] = 'coordinacion';
    }
    if ($isAcomp) {
        $roles[] = 'acompanamiento';
    }
    return $roles;
}

/**
 * Los tipos que estos papeles pueden LEER.
 *
 * Sin papeles, la lista está vacía: un monitor no ve seguimientos, ni suyos ni
 * de nadie. No es un descuido — una valoración escrita para hablarla en persona
 * deja de servir si se lee antes en una pantalla.
 */
function sticpa_pl_seg_readable($roles)
{
    $out = array();
    foreach (sticpa_pl_seg_tipos() as $key => $meta) {
        foreach ((array) $roles as $role) {
            if (in_array($role, $meta['read'], true)) {
                $out[] = $key;
                break;
            }
        }
    }
    return $out;
}

/** Los tipos que estos papeles pueden ESCRIBIR. */
function sticpa_pl_seg_writable($roles)
{
    $out = array();
    foreach (sticpa_pl_seg_tipos() as $key => $meta) {
        foreach ((array) $roles as $role) {
            if (in_array($role, $meta['write'], true)) {
                $out[] = $key;
                break;
            }
        }
    }
    return $out;
}

/**
 * ¿Puede este usuario ver seguimientos DE SÍ MISMO? No.
 *
 * Está en una función propia y no como un `if` suelto porque es una regla de
 * encuadre, no un detalle: se lee explícita en cada sitio donde se aplica, y si
 * algún día se cambia, se cambia aquí y se ve en el histórico por qué.
 */
function sticpa_pl_seg_can_see_own()
{
    return false;
}

/**
 * Filtra una lista de seguimientos a lo que este usuario puede ver.
 *
 * Es la última puerta y la que hay que probar: aunque la consulta al CRM ya pida
 * solo lo permitido, esto se aplica igual sobre lo que vuelva. Dos cierres
 * independientes, porque el coste de un fallo aquí no es un dato de más.
 */
function sticpa_pl_seg_filter($items, $roles, $viewerId = '', $subjectId = '')
{
    // Sobre uno mismo, nada, ni siquiera coordinando.
    if ($viewerId !== '' && $subjectId !== '' && $viewerId === $subjectId
        && !sticpa_pl_seg_can_see_own()) {
        return array();
    }

    $allowed = sticpa_pl_seg_readable($roles);
    if (empty($allowed)) {
        return array();
    }

    $out = array();
    foreach ((array) $items as $item) {
        $tipo = isset($item['tipo']) ? (string) $item['tipo'] : '';
        // Un tipo que no conocemos NO se enseña. Si alguien escribe a mano un
        // valor raro en el CRM, el defecto es ocultarlo, no pintarlo.
        if ($tipo !== '' && in_array($tipo, $allowed, true)) {
            $out[] = $item;
        }
    }
    return $out;
}

/**
 * El trimestre del curso al que pertenece una fecha.
 *
 * Devuelve 1, 2 o 3, para agrupar las valoraciones. Los cortes son los del
 * curso escolar y no los del año natural: 1.º de septiembre a Navidad, de
 * Navidad a Semana Santa —aproximada por el 1 de abril, que no hace falta
 * clavarla— y de ahí a final de curso.
 */
function sticpa_pl_seg_trimestre($ts)
{
    $month = (int) date('n', (int) $ts);
    if ($month >= 9 && $month <= 12) {
        return 1;
    }
    if ($month >= 1 && $month <= 3) {
        return 2;
    }
    return 3;
}

/**
 * Orden de los grupos dentro de su etapa: por CÓDIGO, y en orden natural.
 *
 * El árbol agrupaba por etapa pero dentro de la etapa salían en el orden en que
 * los devolviera el CRM, o sea M4.3, M5.3, M6.2, M4.1… Buscar tu grupo en una
 * lista de veintisiete sin orden es leerla entera.
 *
 * Natural y no alfabético porque los códigos llevan números: alfabéticamente
 * «M4.10» va ANTES de «M4.2», que no es lo que espera nadie. Con strnatcasecmp
 * el 2 va antes del 10.
 *
 * Sin código se ordena por nombre, y los que no tienen ni código ni nombre caen
 * al final: un grupo a medio rellenar no debe encabezar la lista.
 */
function sticpa_pl_cmp_group($a, $b)
{
    $ca = isset($a['code']) ? trim((string) $a['code']) : '';
    $cb = isset($b['code']) ? trim((string) $b['code']) : '';
    $na = isset($a['name']) ? trim((string) $a['name']) : '';
    $nb = isset($b['name']) ? trim((string) $b['name']) : '';

    $keyA = ($ca !== '') ? $ca : $na;
    $keyB = ($cb !== '') ? $cb : $nb;

    if (($keyA === '') !== ($keyB === '')) {
        return ($keyA === '') ? 1 : -1;   // el vacío, al final
    }
    $cmp = strnatcasecmp($keyA, $keyB);
    if ($cmp !== 0) {
        return $cmp;
    }
    // Mismo código en dos grupos: se desempata por nombre para que el orden sea
    // estable entre peticiones y no baile de una carga a otra.
    return strnatcasecmp($na, $nb);
}

/**
 * ¿Se puede confiar en un recuento nocturno, por su marca de tiempo?
 *
 * La regla de PASAR-LISTA-RECUENTOS.md §6: si el dato es viejo, la pantalla se
 * CALLA. Un hueco se entiende; un número mal no se detecta, y este recuento
 * acaba puesto al lado de nombres de menores.
 *
 * El margen es de tres días y no de uno porque el cálculo es nocturno y puede
 * fallar una noche sin que eso invalide el número: lo que no vale es un dato de
 * hace un mes.
 *
 * @param string $raw  `ajmcm_recuento_al_c` tal cual viene del CRM.
 * @param int    $now  Ahora, en marca de tiempo.
 * @return bool
 */
function sticpa_pl_recuento_fresco($raw, $now = null)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return false;
    }
    if ($now === null) {
        $now = sticpa_pl_now();
    }
    $ts = strtotime($raw);
    if (!$ts) {
        return false;
    }
    // Una fecha en el futuro tampoco es de fiar: es un reloj mal puesto, y
    // fiarse de ella sería fiarse de lo que no se sabe.
    if ($ts > $now + DAY_IN_SECONDS) {
        return false;
    }
    $maxAge = (int) apply_filters('sticpa_pl_recuento_max_age', 3 * DAY_IN_SECONDS);
    return ($now - $ts) <= $maxAge;
}

/**
 * La línea de datos de un grupo en el árbol.
 *
 * El artboard `Grupos` pone «monitores · curso · N participantes». La etapa NO
 * va aquí: el árbol ya agrupa por etapa y repetirla en cada fila es ruido —
 * decía «MIC · 4º Primaria» debajo de una cabecera que ya dice MIC.
 *
 * El recuento solo aparece si es fresco (ver sticpa_pl_recuento_fresco): la
 * alternativa a un número dudoso es no ponerlo, no ponerlo con un asterisco.
 */
function sticpa_pl_group_meta($group, $now = null)
{
    $bits = array();

    $monitores = isset($group['monitores']) ? trim((string) $group['monitores']) : '';
    if ($monitores !== '') {
        $bits[] = $monitores;
    }
    $cursos = isset($group['cursos']) ? trim((string) $group['cursos']) : '';
    if ($cursos !== '') {
        $bits[] = $cursos;
    }

    $n = isset($group['n_participantes']) ? (int) $group['n_participantes'] : -1;
    $al = isset($group['recuento_al']) ? $group['recuento_al'] : '';
    if ($n >= 0 && sticpa_pl_recuento_fresco($al, $now)) {
        $bits[] = sprintf(
            /* translators: %d: número de participantes del grupo */
            _n('%d participante', '%d participantes', $n, 'sticpa'),
            $n
        );
    }

    return $bits;
}

/**
 * Las etiquetas de los segmentos del COM, y el orden en que se enseñan.
 *
 * `ajmcm_segmento_com_c` guarda `com_1`/`com_2`/`com_3`; en pantalla son COM I,
 * COM II y COM III. Un segmento es una división DENTRO del COM que agrupa
 * cursos (COM I son los dos primeros de la ESO), y se mantiene a mano.
 */
function sticpa_pl_segmento_labels()
{
    return apply_filters('sticpa_pl_segmento_labels', array(
        'com_1' => 'COM I',
        'com_2' => 'COM II',
        'com_3' => 'COM III',
    ));
}

/**
 * Los grupos repartidos en las secciones del artboard `Main`: la navegación de
 * «Pasar lista de otro grupo» — MIC, COM I, COM II, COM III, LC.
 *
 * POR QUÉ ASÍ y no una lista de veintiocho: el artboard entra al árbol POR
 * SECCIÓN, y esa es la diferencia entre elegir entre cuatro cosas y leer
 * veintiocho. El árbol completo sigue existiendo, pero como destino, no como
 * puerta.
 *
 * Cada sección lleva el número de participantes cuando hay recuentos frescos
 * (es el 93 / 48 / 37 / 22 del artboard) y, si no, cuántos grupos tiene: un
 * número que no se puede calcular no se inventa, se cambia por el que sí.
 *
 * @param array $groups  Tal como los devuelve sticpa_pl_groups().
 * @param int|null $now
 * @return array Lista ordenada de secciones.
 */
function sticpa_pl_group_buckets($groups, $now = null)
{
    $segmentos = sticpa_pl_segmento_labels();

    // El orden es el del artboard, y es el del recorrido de un chaval por el
    // movimiento: primero los pequeños.
    // El color de cada seccion, del artboard: MIC rojo, y el COM recorre
    // verde -> violeta -> magenta, que es el orden en que se crece dentro del
    // COM. Todos verdes (el color de la etapa) hacia indistinguibles tres
    // secciones que son justo lo que hay que distinguir.
    $dots = array(
        'MIC' => 'var(--danger-color)',
        'com_1' => 'var(--success-color)',
        'com_2' => '#7c3aed',
        'com_3' => '#be185d',
        'COM' => 'var(--success-color)',
        'LC' => 'var(--primary-color)',
    );

    $order = array(array('key' => 'MIC', 'label' => 'MIC', 'etapa' => 'MIC', 'segmento' => '', 'dot' => $dots['MIC']));
    foreach ($segmentos as $seg => $label) {
        $order[] = array(
            'key' => 'COM:' . $seg,
            'label' => $label,
            'etapa' => 'COM',
            'segmento' => $seg,
            'dot' => isset($dots[$seg]) ? $dots[$seg] : $dots['COM'],
        );
    }
    // El COM sin segmento puesto: existe y hay que poder llegar a él, o esos
    // grupos desaparecen de la navegación sin decir por qué.
    $order[] = array('key' => 'COM', 'label' => 'COM', 'etapa' => 'COM', 'segmento' => '', 'dot' => $dots['COM']);
    $order[] = array('key' => 'LC', 'label' => 'LC', 'etapa' => 'LC', 'segmento' => '', 'dot' => $dots['LC']);
    $order[] = array('key' => '?', 'label' => __('Sin etapa', 'sticpa'), 'etapa' => '', 'segmento' => '', 'dot' => 'var(--gray-300, #d1d5db)');

    $buckets = array();
    foreach ($order as $b) {
        $b['groups'] = 0;
        $b['participants'] = 0;
        $b['fresh'] = false;
        $b['ids'] = array();
        $buckets[$b['key']] = $b;
    }

    foreach ($groups as $id => $g) {
        $etapa = isset($g['etapa']) ? (string) $g['etapa'] : '';
        $seg = isset($g['segmento']) ? trim((string) $g['segmento']) : '';

        if ($etapa === 'COM' && $seg !== '' && isset($buckets['COM:' . $seg])) {
            $key = 'COM:' . $seg;
        } elseif ($etapa !== '' && isset($buckets[$etapa])) {
            $key = $etapa;
        } else {
            $key = '?';
        }

        $buckets[$key]['groups']++;
        $buckets[$key]['ids'][] = $id;

        $n = isset($g['n_participantes']) ? (int) $g['n_participantes'] : -1;
        $al = isset($g['recuento_al']) ? $g['recuento_al'] : '';
        if ($n >= 0 && sticpa_pl_recuento_fresco($al, $now)) {
            $buckets[$key]['participants'] += $n;
            $buckets[$key]['fresh'] = true;
        }
    }

    // Las secciones vacías no se pintan: una fila «COM III · 0» no ayuda a
    // nadie a llegar a ningún sitio.
    return array_values(array_filter($buckets, function ($b) {
        return $b['groups'] > 0;
    }));
}

/** ¿Cae este grupo dentro de la sección indicada? */
function sticpa_pl_group_in_bucket($group, $bucketKey)
{
    $bucketKey = (string) $bucketKey;
    if ($bucketKey === '') {
        return true;
    }
    $etapa = isset($group['etapa']) ? (string) $group['etapa'] : '';
    $seg = isset($group['segmento']) ? trim((string) $group['segmento']) : '';

    if (strpos($bucketKey, ':') !== false) {
        list($wantEtapa, $wantSeg) = explode(':', $bucketKey, 2);
        return ($etapa === $wantEtapa && $seg === $wantSeg);
    }
    if ($bucketKey === '?') {
        return ($etapa === '');
    }
    // Una etapa a secas NO se lleva los grupos que tienen segmento: esos ya
    // tienen su propia sección, y saldrían dos veces.
    if ($bucketKey === 'COM') {
        return ($etapa === 'COM' && $seg === '');
    }
    return ($etapa === $bucketKey);
}

/**
 * El nombre como se lee en una lista: nombre y PRIMER apellido.
 *
 * «Solete Vilarroya Messguerr» ocupa dos líneas en un móvil y el segundo
 * apellido no distingue a nadie dentro de un grupo de doce. Se queda fuera
 * hasta que haga falta de verdad (dos personas con el mismo nombre y primer
 * apellido en el mismo grupo, que es cuando el dato empieza a servir).
 *
 * Solo se recorta el APELLIDO: un nombre compuesto («José María») se respeta
 * entero, porque ahí las dos palabras son el nombre.
 */
function sticpa_pl_short_name($first, $last, $full = '')
{
    $first = trim((string) $first);
    $last = trim((string) $last);

    if ($first === '' && $last === '') {
        // Sin nombre y apellidos separados: se parte el completo y se queda con
        // las dos primeras palabras, que es lo mismo en el caso normal.
        $parts = preg_split('/\s+/', trim((string) $full));
        if (!is_array($parts) || empty($parts[0])) {
            return trim((string) $full);
        }
        return implode(' ', array_slice($parts, 0, 2));
    }

    if ($last !== '') {
        $lastParts = preg_split('/\s+/', $last);
        if (is_array($lastParts) && !empty($lastParts[0])) {
            $last = $lastParts[0];
        }
    }
    return trim($first . ' ' . $last);
}

// ---------------------------------------------------------------------------
// Seguimiento de monitores: las pistas de cuadraditos
// ---------------------------------------------------------------------------

/**
 * La pista de asistencia: un cuadrado por sesión celebrada, y el porcentaje.
 *
 * LA REGLA de todo este bloque, y la usan también la ficha del participante y
 * la lista de monitores: **una sesión sin marcar no cuenta en el porcentaje**. Si el
 * sábado que Marta faltó nadie pasó la lista de monitores, ese hueco no es una
 * falta suya, es un dato que no existe; meterlo en el denominador la acusa de
 * algo que nadie ha registrado. Los huecos se cuentan aparte (`unknown`) y la
 * pantalla los dice, que es lo honesto.
 *
 * `pct` vale -1 cuando no hay NINGUNA sesión marcada: no es un 0 %, es un «no
 * se sabe», y pintar un 0 % ahí sería mentir con un número redondo.
 *
 * @return array squares[] (id, start, state), elapsed, attended, missed,
 *               unknown, counted, pct.
 */
function sticpa_pl_att_track($sessions, $marks, $nowTs = null)
{
    $states = sticpa_pl_states();
    $elapsed = sticpa_pl_elapsed_sessions($sessions, $nowTs);

    $squares = array();
    $vino = 0;
    $falto = 0;
    $sin = 0;
    $horas = 0.0;
    $horasTotal = 0.0;

    foreach ($elapsed as $s) {
        $id = isset($s['id']) ? (string) $s['id'] : '';
        $key = ($id !== '' && isset($marks[$id])) ? $marks[$id] : '';
        $dur = 0.0;
        if (!empty($s['start']) && !empty($s['end']) && (int) $s['end'] > (int) $s['start']) {
            $dur = ((int) $s['end'] - (int) $s['start']) / 3600;
        }

        if (!sticpa_pl_is_state($key)) {
            $key = '';
            $sin++;
        } else {
            // Las horas siguen la MISMA regla que el porcentaje: solo cuentan
            // las sesiones marcadas. Si no, saldría «3 h de 12 h» con nueve de
            // esas horas sin que nadie sepa si estuvo o no.
            $horasTotal += $dur;
            if (!empty($states[$key]['counts'])) {
                $vino++;
                $horas += $dur;
            } else {
                $falto++;
            }
        }

        $squares[] = array(
            'id' => $id,
            'start' => isset($s['start']) ? (int) $s['start'] : 0,
            'state' => $key,
        );
    }

    $contadas = $vino + $falto;
    return array(
        'squares' => $squares,
        'elapsed' => count($elapsed),
        'attended' => $vino,
        'missed' => $falto,
        'unknown' => $sin,
        'counted' => $contadas,
        'pct' => ($contadas > 0) ? (int) round(($vino / $contadas) * 100) : -1,
        'hours' => round($horas, 1),
        'hours_total' => round($horasTotal, 1),
    );
}

/**
 * La pista de «listas pasadas» de un grupo, vista desde un monitor.
 *
 * ⚠️ Esta fila se lee JUNTO a la de sesiones o no se lee. Una lista de grupo la
 * puede pasar cualquiera que cubra ese sábado, así que «no la pasó ella» puede
 * significar «no vino y la pasó un compañero», que es lo correcto. Por eso hay
 * dos verdes distintos —`suya` y `otro`— en vez de un verde y un rojo: el dato
 * que importa es si el grupo quedó registrado, y de propina quién lo hizo.
 *
 * `omitida` (el grupo no se reunió ese sábado) sale del denominador: no es una
 * lista que falte, es un sábado que no hubo.
 *
 * @param array  $listas    sessionId => datos de la lista de ESE grupo.
 * @return array squares[] (id, start, state), elapsed, suyas, otras,
 *               omitidas, sin, con_lista, esperadas.
 */
function sticpa_pl_listas_track($sessions, $listas, $monitorId, $nowTs = null)
{
    $elapsed = sticpa_pl_elapsed_sessions($sessions, $nowTs);
    $estados = sticpa_pl_lista_estados();
    $monitorId = (string) $monitorId;

    $squares = array();
    $suyas = 0;
    $otras = 0;
    $omitidas = 0;
    $sin = 0;

    foreach ($elapsed as $s) {
        $id = isset($s['id']) ? (string) $s['id'] : '';
        $lista = ($id !== '' && isset($listas[$id]) && is_array($listas[$id])) ? $listas[$id] : null;
        $estado = isset($lista['estado']) ? (string) $lista['estado'] : '';

        if ($lista === null || $estado === '') {
            $state = 'sin';
            $sin++;
        } elseif ($estado === $estados['omitida']) {
            $state = 'omitida';
            $omitidas++;
        } elseif ($monitorId !== '' && isset($lista['monitor_id'])
            && (string) $lista['monitor_id'] === $monitorId) {
            $state = 'suya';
            $suyas++;
        } else {
            $state = 'otra';
            $otras++;
        }

        $squares[] = array(
            'id' => $id,
            'start' => isset($s['start']) ? (int) $s['start'] : 0,
            'state' => $state,
        );
    }

    return array(
        'squares' => $squares,
        'elapsed' => count($elapsed),
        'suyas' => $suyas,
        'otras' => $otras,
        'omitidas' => $omitidas,
        'sin' => $sin,
        'con_lista' => $suyas + $otras,
        // Los sábados en los que TOCABA haber lista: los celebrados menos los
        // que el propio grupo marcó como que no hubo.
        'esperadas' => count($elapsed) - $omitidas,
    );
}

/**
 * Los umbrales del aviso de seguimiento. Conservadores a propósito.
 *
 * Es un dato sensible entre compañeros: un aviso de más quema la confianza en
 * el aviso, y a la tercera vez que salta sin motivo nadie lo mira. Filtrables
 * porque cada delegación sabe cómo es su curso.
 */
function sticpa_pl_seguimiento_umbrales()
{
    return apply_filters('sticpa_pl_seguimiento_umbrales', array(
        // Por debajo de esto, la lista lo señala.
        'pct_minimo' => 60,
        // Faltas seguidas al final: es lo que de verdad hace llamar. Alguien
        // que falta una de cada cinco desde octubre no preocupa; alguien que
        // lleva tres seguidas, sí.
        'seguidas' => 3,
        // Y por debajo de esta cuenta de sesiones marcadas no se avisa de nada:
        // con dos datos, un porcentaje es una anécdota.
        'minimo_para_opinar' => 4,
    ));
}

/**
 * ¿Hay algo que mirar en este monitor? Una frase corta, o nada.
 *
 * Se calcula sobre una pista ya hecha (`sticpa_pl_att_track()`), así que no
 * cuesta ninguna consulta: en la lista de monitores es lo que decide a quién
 * hay que mirar de los treinta, que es la única pregunta que se hace ahí.
 *
 * Devuelve '' cuando no hay nada que decir, que es el caso normal y el que
 * mantiene la lista limpia.
 */
function sticpa_pl_seguimiento_aviso($track, $nowTs = null)
{
    $u = sticpa_pl_seguimiento_umbrales();
    if (!is_array($track) || (int) $track['counted'] < (int) $u['minimo_para_opinar']) {
        return '';
    }

    // Las últimas seguidas, mirando hacia atrás y SALTÁNDOSE los huecos: un
    // sábado sin marcar no rompe una racha de faltas ni la crea.
    $states = sticpa_pl_states();
    $seguidas = 0;
    for ($i = count($track['squares']) - 1; $i >= 0; $i--) {
        $key = $track['squares'][$i]['state'];
        if ($key === '') {
            continue;
        }
        if (!empty($states[$key]['counts'])) {
            break;
        }
        $seguidas++;
    }
    if ($seguidas >= (int) $u['seguidas']) {
        return sprintf(
            /* translators: %d: cuántas sesiones seguidas lleva sin venir */
            _n('%d sesión seguida sin venir', '%d seguidas sin venir', $seguidas, 'sticpa'),
            $seguidas
        );
    }

    $pct = (int) $track['pct'];
    if ($pct >= 0 && $pct < (int) $u['pct_minimo']) {
        return sprintf(
            /* translators: %d: porcentaje de asistencia */
            __('%d %% de asistencia', 'sticpa'),
            $pct
        );
    }
    return '';
}

// ---------------------------------------------------------------------------
// Los datos de un monitor, agrupados
// ---------------------------------------------------------------------------

/**
 * Los desplegables del CRM que aparecen en la ficha de un monitor.
 *
 * Las claves internas están verificadas contra el CRM (`get_module_fields` no
 * devuelve las opciones en esta instancia, así que salen de `CAMPOS.md` y de
 * los valores reales de los registros). Una clave que no esté en el mapa se
 * pinta tal cual: es feo, pero es la verdad, y es mejor que esconder un dato
 * porque no supimos traducirlo.
 */
function sticpa_pl_monitor_enums()
{
    return apply_filters('sticpa_pl_monitor_enums', array(
        'ajmcm_nivel_com_c' => array(
            'conocimiento' => __('I · Conocimiento', 'sticpa'),
            'incorporacion' => __('II · Incorporación', 'sticpa'),
            'crecimiento' => __('III · Crecimiento', 'sticpa'),
            'opcion_responsable' => __('IV · Opción responsable', 'sticpa'),
        ),
        'ajmcm_monitor_de_c' => array(
            'MIC' => 'MIC', 'COM' => 'COM', 'LC' => 'LC',
            'apoyo' => __('Apoyo', 'sticpa'),
            'otros' => __('Otros', 'sticpa'),
        ),
        'ajmcm_etapa_c' => array('MIC' => 'MIC', 'COM' => 'COM', 'LC' => 'LC'),
        'stic_gender_c' => array(
            'male' => __('Hombre', 'sticpa'),
            'female' => __('Mujer', 'sticpa'),
        ),
        'stic_identification_type_c' => array(
            'nif' => 'NIF', 'nie' => 'NIE', 'cif' => 'CIF',
            'passport' => __('Pasaporte', 'sticpa'),
        ),
        // El estado de una titulación. `titulado` y `finalizado` son «la tiene»;
        // el resto son escalones del camino y se dicen con esas palabras.
        'formacion' => array(
            'no' => __('No', 'sticpa'),
            'en_curso' => __('En curso', 'sticpa'),
            'practicas' => __('En prácticas', 'sticpa'),
            'pendiente_titulo' => __('Pendiente del título', 'sticpa'),
            'titulado' => __('Titulado', 'sticpa'),
            'finalizado' => __('Finalizado', 'sticpa'),
        ),
        'ajmcm_mat_year_c' => array(
            '2013' => __('MAT Castellón 2013', 'sticpa'),
            '2018' => __('MAT Tortosa 2018', 'sticpa'),
            '2022' => __('MAT El Campello 2022', 'sticpa'),
            '2024' => __('MAT Godelleta 2024', 'sticpa'),
            'otra_escuela' => __('Otra escuela', 'sticpa'),
        ),
        'ajmcm_congreso_monis_c' => array(
            '2010_vlc' => __('2010 València', 'sticpa'),
            '2012_cs' => __('2012 Castelló', 'sticpa'),
            '2016_cs' => __('2016 Castelló', 'sticpa'),
            '2019_godelleta' => __('2019 Godelleta', 'sticpa'),
            '2022_burriana' => __('2022 Borriana', 'sticpa'),
            '2025_benicassim' => __('2025 Benicàssim', 'sticpa'),
            '2026_benicassim' => __('2026 Benicàssim', 'sticpa'),
        ),
    ));
}

/** La etiqueta de un valor de desplegable, o el valor crudo si no la hay. */
function sticpa_pl_enum_label($campo, $valor)
{
    $valor = trim((string) $valor);
    if ($valor === '') {
        return '';
    }
    $mapas = sticpa_pl_monitor_enums();
    if (isset($mapas[$campo][$valor])) {
        return $mapas[$campo][$valor];
    }
    // Sin distinguir mayúsculas: en el CRM conviven `COM` y `com` para el mismo
    // valor, y por una mayúscula no se pinta la clave interna en pantalla.
    if (isset($mapas[$campo])) {
        foreach ($mapas[$campo] as $clave => $etiqueta) {
            if (strcasecmp((string) $clave, $valor) === 0) {
                return $etiqueta;
            }
        }
    }
    return $valor;
}

/**
 * Los valores de un multienum de SuiteCRM.
 *
 * Vienen así: `^2019_godelleta^,^2022_burriana^`. Los acentos circunflejos son
 * del formato, no del dato.
 */
function sticpa_pl_multienum($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return array();
    }
    $out = array();
    foreach (explode(',', $raw) as $trozo) {
        $v = trim(trim($trozo), '^');
        if ($v !== '') {
            $out[] = $v;
        }
    }
    return $out;
}

/** Un booleano del CRM: '1' es sí y todo lo demás es no. */
function sticpa_pl_si_no($raw)
{
    return (trim((string) $raw) === '1');
}

/** Un año de una fecha ISO del CRM, o cadena vacía. */
function sticpa_pl_anyo($raw)
{
    return sticpa_pl_monitor_since($raw);
}

/**
 * Los datos de un monitor repartidos en bloques, y EN ORDEN DE INTERÉS.
 *
 * El orden lo dictó el propietario: **en regla**, después los **datos MCM**
 * (nivel, etapa, pañuelo, desde cuándo) y después la **formación**. Al final,
 * plegados, los datos de padrón, que casi nunca se miran. El certificado de
 * delitos sexuales no abre la ficha: es obligatorio, sí, pero es una casilla, y
 * una casilla no es la persona.
 *
 * Estos bloques van DESPUÉS del seguimiento, de sus grupos y de su histórico:
 * son el papeleo, y el papeleo no es por lo que se abre la ficha de alguien.
 *
 * Cada bloque trae `kind`, que decide cómo se pinta:
 *   - `check`: una lista de obligaciones, con su ✓ o su ✗. Se enseñan TODAS,
 *     también las que están bien: es una lista de comprobación y el valor está
 *     en verla entera.
 *   - `flag`:  lo que tiene, con su pastilla. Lo que no tiene, no sale.
 *   - `data`:  etiqueta y valor. Lo vacío no sale.
 *
 * Un bloque sin filas no se devuelve: media pantalla de secciones vacías dice
 * «esto no funciona» aunque funcione.
 */
function sticpa_pl_monitor_bloques($ficha)
{
    $val = function ($key) use ($ficha) {
        return isset($ficha[$key]) ? trim((string) $ficha[$key]) : '';
    };
    $bloques = array();

    // --- En regla -----------------------------------------------------------
    $ds = sticpa_pl_ds_state($ficha);
    $dsNotas = array(
        'auto' => __('Autorizó a pedirlo cada año', 'sticpa'),
        'uploaded' => __('Entregado y archivado', 'sticpa'),
        'missing' => __('Hay que reclamarlo', 'sticpa'),
    );
    $regla = array(
        array(
            'label' => __('Certificado de delitos sexuales', 'sticpa'),
            'ok' => ($ds !== 'missing'),
            'req' => true,
            'note' => $dsNotas[$ds],
        ),
        array(
            'label' => __('Formación en protección del menor', 'sticpa'),
            'ok' => sticpa_pl_si_no($val('ajmcm_form_intera_proteccion_c')),
            'req' => true,
            'note' => '',
        ),
        array(
            'label' => __('Código de conducta', 'sticpa'),
            'ok' => sticpa_pl_si_no($val('stic_conduct_code_c')),
            'req' => true,
            'note' => '',
        ),
        array(
            'label' => __('Acuerdo de confidencialidad', 'sticpa'),
            'ok' => sticpa_pl_si_no($val('stic_confidentiality_agreement_c')),
            'req' => true,
            'note' => '',
        ),
        array(
            'label' => __('Acuerdo de incorporación', 'sticpa'),
            'ok' => sticpa_pl_si_no($val('ajmcm_vol_acuerdo_c')),
            'req' => true,
            'note' => '',
        ),
        array(
            'label' => __('Compromiso firmado', 'sticpa'),
            'ok' => sticpa_pl_si_no($val('ajmcm_compromiso_c')),
            'req' => true,
            'note' => '',
        ),
        // Los dos de abajo son PERMISOS, no obligaciones: un «no» aquí es una
        // decisión suya, no un incumplimiento, y no se pinta en rojo.
        array(
            'label' => __('Protección de datos (LOPD)', 'sticpa'),
            'ok' => sticpa_pl_si_no($val('ajmcm_acepta_lopd_c')),
            'req' => false,
            'note' => '',
        ),
        array(
            'label' => __('Cesión de imágenes', 'sticpa'),
            'ok' => sticpa_pl_si_no($val('ajmcm_cesionimagenes_interne_c')),
            'req' => false,
            'note' => '',
        ),
    );
    $bloques[] = array(
        'key' => 'regla',
        'label' => __('En regla', 'sticpa'),
        'kind' => 'check',
        'rows' => $regla,
    );

    // --- Formación ----------------------------------------------------------
    $formacion = array();
    $titulos = array(
        'ajmcm_premonitores1_c' => array(__('Premonitores I', 'sticpa'), 'ajmcm_premonitores_year_c', ''),
        'ajmcm_premonitores2_c' => array(__('Premonitores II', 'sticpa'), 'ajmcm_premonitores_year_c', ''),
        'ajmcm_mat_c' => array(__('MAT · Monitor/a de tiempo libre', 'sticpa'), 'ajmcm_mat_year_c', 'ajmcm_mat_file_c'),
        'ajmcm_dat_c' => array(__('DAT · Director/a de tiempo libre', 'sticpa'), 'ajmcm_dat_year_c', 'ajmcm_dat_file_c'),
        'ajmcm_fa_c' => array(__('FA · Formación de animadores', 'sticpa'), 'ajmcm_fa_year_c', ''),
    );
    foreach ($titulos as $campo => $meta) {
        $estado = $val($campo);
        if ($estado === '' || strtolower($estado) === 'no') {
            continue;   // lo que no tiene no se lista: se pregunta qué tiene
        }
        $anyo = ($meta[1] !== '') ? sticpa_pl_enum_label($meta[1], $val($meta[1])) : '';
        $conArchivo = ($meta[2] !== '') ? sticpa_pl_si_no($val($meta[2])) : null;
        $completo = in_array(strtolower($estado), array('titulado', 'finalizado'), true);
        $formacion[] = array(
            'label' => $meta[0],
            'value' => sticpa_pl_enum_label('formacion', $estado),
            'note' => $anyo,
            // El descuadre típico: dice que está titulado y no hay archivo del
            // título. Meses después nadie sabe si falta el papel o falta el curso.
            'warn' => ($completo && $conArchivo === false) ? __('sin archivo', 'sticpa') : '',
            'ok' => $completo,
        );
    }
    if (sticpa_pl_si_no($val('ajmcm_alimentos_c'))) {
        $formacion[] = array(
            'label' => __('Manipulación de alimentos', 'sticpa'),
            'value' => __('Sí', 'sticpa'), 'note' => '', 'warn' => '', 'ok' => true,
        );
    }
    if (sticpa_pl_si_no($val('ajmcm_cert_files_c'))) {
        $formacion[] = array(
            'label' => __('Otros certificados', 'sticpa'),
            'value' => __('Archivados', 'sticpa'), 'note' => '', 'warn' => '', 'ok' => true,
        );
    }
    $congresos = array();
    foreach (sticpa_pl_multienum($val('ajmcm_congreso_monis_c')) as $c) {
        $congresos[] = sticpa_pl_enum_label('ajmcm_congreso_monis_c', $c);
    }
    if (!empty($formacion) || !empty($congresos) || $val('ajmcm_formacion_academica_c') !== '') {
        $bloques[] = array(
            'key' => 'formacion',
            'label' => __('Formación', 'sticpa'),
            'kind' => 'flag',
            'rows' => $formacion,
            'chips' => $congresos,
            'chips_label' => __('Congresos de monitores', 'sticpa'),
            'nota' => $val('ajmcm_formacion_academica_c'),
            'nota_label' => __('Formación académica', 'sticpa'),
        );
    }

    // --- Datos MCM ----------------------------------------------------------
    $panuelos = sticpa_pl_panuelos();
    $panuelo = $val('ajmcm_panuelo_c');
    $tray = array();
    $añadir = function ($label, $value) use (&$tray) {
        if (trim((string) $value) !== '') {
            $tray[] = array('label' => $label, 'value' => $value);
        }
    };
    $añadir(__('Nivel COM', 'sticpa'), sticpa_pl_enum_label('ajmcm_nivel_com_c', $val('ajmcm_nivel_com_c')));
    $añadir(__('Etapa', 'sticpa'), sticpa_pl_enum_label('ajmcm_etapa_c', $val('ajmcm_etapa_c')));
    if ($panuelo !== '' && $panuelo !== 'na') {
        $añadir(__('Pañuelo', 'sticpa'), isset($panuelos[$panuelo]) ? $panuelos[$panuelo]['label'] : $panuelo);
    }
    $añadir(__('Monitor/a de', 'sticpa'), sticpa_pl_enum_label('ajmcm_monitor_de_c', $val('ajmcm_monitor_de_c')));
    $añadir(__('Monitor/a desde', 'sticpa'), sticpa_pl_anyo($val('ajmcm_monitor_desde_c')));
    $añadir(__('En el MCM desde', 'sticpa'), sticpa_pl_anyo($val('ajmcm_mcm_desde_c')));
    $añadir(__('Incorporación a LC', 'sticpa'), $val('ajmcm_ano_incorporacion_lc_c'));
    if (!empty($tray)) {
        $bloques[] = array(
            'key' => 'mcm',
            'label' => __('Datos MCM', 'sticpa'),
            'kind' => 'data',
            'rows' => $tray,
        );
    }

    // --- Datos personales ---------------------------------------------------
    // Plegado: en dos años de coordinación esto se mira una vez, y ocupa media
    // pantalla de las que hay que pasar para llegar al histórico.
    $pers = array();
    $añadirP = function ($label, $value) use (&$pers) {
        if (trim((string) $value) !== '') {
            $pers[] = array('label' => $label, 'value' => $value);
        }
    };
    $doc = $val('stic_identification_number_c');
    if ($doc !== '') {
        $tipo = sticpa_pl_enum_label('stic_identification_type_c', $val('stic_identification_type_c'));
        $añadirP(($tipo !== '') ? $tipo : __('Documento', 'sticpa'), $doc);
    }
    $añadirP(__('Fecha de nacimiento', 'sticpa'), $val('birthdate'));
    $añadirP(__('Género', 'sticpa'), sticpa_pl_enum_label('stic_gender_c', $val('stic_gender_c')));
    // La dirección, en UNA línea: calle, código postal y población. Tres filas
    // para un dato que se lee de corrido es tres veces el mismo dato.
    $direccion = array_filter(array(
        $val('primary_address_street'),
        trim($val('primary_address_postalcode') . ' ' . $val('primary_address_city')),
    ), function ($x) {
        return trim((string) $x) !== '';
    });
    if (!empty($direccion)) {
        $añadirP(__('Dirección', 'sticpa'), implode(' · ', $direccion));
    } else {
        $añadirP(__('Población', 'sticpa'), $val('primary_address_city'));
    }
    $añadirP(__('Centro educativo', 'sticpa'), $val('ajmcm_centro_educativo_c'));
    $añadirP(__('Nº de persona', 'sticpa'), $val('ajmcm_numero_persona_c'));
    if (!empty($pers)) {
        $bloques[] = array(
            'key' => 'personales',
            'label' => __('Datos personales', 'sticpa'),
            'kind' => 'data',
            'plegado' => true,
            'rows' => $pers,
        );
    }

    // EL ORDEN, en un solo sitio y explícito. Los bloques se construyen arriba
    // en el orden que resulte más cómodo de leer en el código; el que se pinta
    // es este, que es el que pidió el propietario.
    $orden = array('regla', 'mcm', 'formacion', 'personales');
    $porClave = array();
    foreach ($bloques as $b) {
        $porClave[$b['key']] = $b;
    }
    $out = array();
    foreach ($orden as $clave) {
        if (isset($porClave[$clave])) {
            $out[] = $porClave[$clave];
            unset($porClave[$clave]);
        }
    }
    // Un bloque nuevo que alguien añada y se olvide de ordenar sale igual, al
    // final: mejor descolocado que desaparecido.
    foreach ($porClave as $b) {
        $out[] = $b;
    }
    return $out;
}

/**
 * Los seguimientos de un curso escolar (o de varios).
 *
 * La ficha enseña por defecto los de ESTE curso: una lista con las notas de
 * cinco años es un archivo, no una pantalla de seguimiento. Los anteriores se
 * traen con un botón, y no cuesta ninguna consulta más porque el CRM ya los
 * devuelve todos en la misma lectura — lo único que cambia es el filtro.
 *
 * @param array $items    lo que devuelve `sticpa_pl_seguimientos()`.
 * @param array $cursos   etiquetas de curso a dejar pasar («2025-2026»).
 */
function sticpa_pl_seg_del_curso($items, $cursos)
{
    $cursos = array_filter(array_map('strval', (array) $cursos));
    if (empty($cursos)) {
        return $items;
    }
    $out = array();
    foreach ((array) $items as $it) {
        $ts = isset($it['ts']) ? (int) $it['ts'] : 0;
        // Sin fecha no se puede colocar en ningún curso, y esconderlo sería
        // perderlo: sale siempre, que es el defecto seguro para una nota.
        if ($ts <= 0) {
            $out[] = $it;
            continue;
        }
        $curso = sticpa_pl_course_for($ts);
        if (in_array($curso['label'], $cursos, true)) {
            $out[] = $it;
        }
    }
    return $out;
}

/** La etiqueta del curso anterior al que se le pase (o al de hoy). */
function sticpa_pl_curso_anterior($label = '')
{
    $label = trim((string) $label);
    if ($label === '') {
        $label = sticpa_pl_course_for()['label'];
    }
    if (!preg_match('/^(\d{4})-(\d{4})$/', $label, $m)) {
        return '';
    }
    return ((int) $m[1] - 1) . '-' . ((int) $m[2] - 1);
}
