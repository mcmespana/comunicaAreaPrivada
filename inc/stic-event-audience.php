<?php
/**
 * QUIÉN PUEDE APUNTARSE A UN EVENTO — la audiencia.
 * ============================================================================
 *
 * EL PROBLEMA QUE RESUELVE. «Eventos» enseñaba a TODO EL MUNDO todos los
 * eventos de la ventana viva, de cualquier delegación y para cualquier perfil.
 * Un padre de Reus veía —y podía apuntarse a— la convivencia de Castellón, y
 * los tres cursos de la ESO veían las sesiones semanales del MIC, que son de
 * 4.º, 5.º y 6.º de primaria.
 *
 * ⚠️ Y LOS GRUPOS DE SEGURIDAD DEL CRM NO LO EVITAN. Es la trampa más
 * importante de este archivo, así que queda escrita: el área privada NO se
 * conecta al CRM como la persona que ha entrado, se conecta con UN usuario
 * técnico (`SugarRestApiCall::login()`, credenciales del plugin). O sea que
 * los grupos de seguridad —que sí separan por delegación cuando un monitor
 * entra en SuiteCRM con su cuenta— no filtran ni una fila de lo que lee el
 * área privada. Todo lo que no filtre el plugin, se ve. Por eso este filtro
 * está aquí y no «ya lo hace el CRM».
 *
 * ────────────────────────────────────────────────────────────────────────────
 * LOS TRES EJES, Y POR QUÉ SON TRES Y NO UNO
 *
 *   1. ÁMBITO (¿de quién es el evento?)  →  delegación del evento
 *      Un evento local es de una delegación y solo se ofrece a su gente.
 *      Un congreso nacional no tiene delegación dueña: se ofrece a todas.
 *
 *   2. PERFIL (¿a qué papel va dirigido?)  →  `ajmcm_dirigido_a_c`
 *      «Solo monitores», «solo miembros con grupo COM-LC», «familias»…
 *      Se compara con el papel real de la persona en el CRM.
 *
 *   3. CURSO (¿de qué cursos escolares?)  →  `ajmcm_filtro_edades_c`
 *      «4.º, 5.º y 6.º de primaria». Solo estrecha ENTRE PARTICIPANTES: ver
 *      la regla de abajo, que es la que evita dejar fuera a los monitores.
 *
 * Están separados a propósito. Meter «monitores» y «4.º de primaria» en el
 * mismo desplegable parece más cómodo y es la forma de acabar sin poder
 * expresar «los monitores de 4.º» ni «cualquiera de 4.º»: son dos preguntas
 * distintas sobre la misma persona.
 *
 * NO se filtra por `ajmcm_etapa_c` del evento aunque exista y esté siempre
 * relleno. Ese campo dice **a qué etapas sirve el evento en Pasar Lista**
 * (un sábado marcado MIC+COM comparte sesiones), no a quién se le ofrece. Un
 * congreso de monitores marcado `^COM^` dejaría fuera a los monitores del MIC.
 * Un campo, un significado.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * QUÉ SE HACE CUANDO NO SE SABE (y por qué no es lo mismo «no» que «no sé»)
 *
 * Es la misma distinción que salvó a los monitores de quedarse sin menú
 * (`scp_role_resolved`, en inc/stic-comunica-roles.php): un vacío RESUELTO es
 * un dato; un vacío por no haber podido preguntar, no.
 *
 *   · No sabemos la delegación de quien mira  →  NO se esconde nada. Esconder
 *     todo por un problema de sesión hace que el área parezca rota.
 *   · El CRM contestó y la persona no tiene NINGÚN papel  →  un evento
 *     restringido por perfil NO es para ella. Es un «no» de verdad.
 *   · No se pudo preguntar por sus papeles  →  no se esconde nada.
 *   · La persona no tiene curso escolar (un monitor no lo tiene: el curso de
 *     su relación es el de SU GRUPO)  →  el filtro de cursos NO se le aplica.
 *     Los cursos estrechan entre participantes, no expulsan a quien no es uno.
 *
 * Y mientras los campos no existan en el CRM, esto no hace nada: un evento sin
 * `ajmcm_dirigido_a_c` no restringe perfiles, y un campo que no está creado ni
 * se le pide al CRM (`sticpa_event_fields_to_request()` pregunta antes qué
 * campos hay). Se puede desplegar hoy y rellenar el CRM mañana.
 *
 * Interruptor general, por si hay que apagarlo sin desplegar:
 *
 *     add_filter('sticpa_event_audience_enabled', '__return_false');
 *
 * Los campos del CRM están documentados en docs/comunica/EVENTOS.md §5 y en
 * docs/comunica/CAMPOS.md.
 */

if (!defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// Los campos del CRM
// ---------------------------------------------------------------------------

/** ¿Está encendido el filtro de audiencia? */
function sticpa_event_audience_enabled()
{
    return (bool) apply_filters('sticpa_event_audience_enabled', true);
}

/** Campo (multienum) con los perfiles a los que va dirigido el evento. */
function sticpa_event_audience_field_perfiles()
{
    return (string) apply_filters('sticpa_event_audience_field_perfiles', 'ajmcm_dirigido_a_c');
}

/**
 * Campo (multienum) con los cursos escolares a los que va dirigido el evento.
 * YA EXISTE en el CRM y ya está relleno en las sesiones semanales de MIC y COM
 * (comprobado el 09/09/2026): no hay que crearlo, hay que usarlo.
 */
function sticpa_event_audience_field_cursos()
{
    return (string) apply_filters('sticpa_event_audience_field_cursos', 'ajmcm_filtro_edades_c');
}

/** Campo (enum) con el ámbito del evento: de una delegación o de todas. */
function sticpa_event_audience_field_ambito()
{
    return (string) apply_filters('sticpa_event_audience_field_ambito', 'ajmcm_ambito_c');
}

/** Campo (enum) de la persona con su curso escolar, en la RELACIÓN con el grupo. */
function sticpa_event_audience_field_curso_persona()
{
    return (string) apply_filters('sticpa_event_audience_field_curso_persona', 'ajmcm_curso_escolar_c');
}

/**
 * Campos del evento que hay que pedirle al CRM para decidir la audiencia.
 * `sticpa_event_fields_to_request()` los cruza con los que EXISTEN de verdad,
 * así que aquí se pueden nombrar campos aún sin crear.
 */
function sticpa_event_audience_fields()
{
    return array_values(array_unique(array(
        sticpa_event_audience_field_ambito(),
        sticpa_event_audience_field_perfiles(),
        sticpa_event_audience_field_cursos(),
    )));
}

// ---------------------------------------------------------------------------
// Vocabulario: los perfiles del evento y los papeles del CRM
// ---------------------------------------------------------------------------

/**
 * PERFIL del evento  =>  claves del CRM que lo cumplen.
 *
 * LAS CLAVES DEL DESPLEGABLE SON LAS DEL CRM, literalmente. No son un
 * vocabulario nuevo: son las de `relationship_type` y de
 * `stic_relationship_type_c` (inventario completo en `CAMPOS.md` §2). Por eso
 * el mapa es casi la identidad, y eso es exactamente lo que se quiere: dos
 * listas que significan lo mismo acaban divergiendo, y una que no existe no.
 *
 * El único agrupador es `coordinacion`, que cubre las dos claves del equipo.
 *
 * ⚠️ **No hay clave «laico», y no se inventa.** Ser del MCM es tener `grupo`
 * —del COM o laico, la ficha es la misma—; encima puedes ser monitor. El área
 * ya tuvo un rol 'laico' que buscaba `com-lc`, `laic` y `grupo com`, tres
 * cadenas que no existen en este CRM, y por eso no se disparó jamás. Así que
 * «evento para miembros COM-LC» se dice con `grupo`.
 */
function sticpa_event_audience_perfil_map()
{
    return apply_filters('sticpa_event_audience_perfil_map', array(
        'grupo'                => array('grupo'),
        'monitor'              => array('monitor'),
        'participante_mic_com' => array('participante_mic_com'),
        'coordinacion'         => array('coordinacion_mic_com', 'acompanamiento_mic_com'),
        'familiar_menor'       => array('familiar_menor'),
    ));
}

/** Etiquetas de respaldo de los perfiles, para cuando el CRM no da las suyas. */
function sticpa_event_audience_perfil_labels()
{
    return apply_filters('sticpa_event_audience_perfil_labels', array(
        'grupo'                => __('miembros del MCM con grupo', 'sticpa'),
        'monitor'              => __('monitores y monitoras', 'sticpa'),
        'participante_mic_com' => __('participantes de MIC y COM', 'sticpa'),
        'coordinacion'         => __('equipo de coordinación', 'sticpa'),
        'familiar_menor'       => __('familias', 'sticpa'),
    ));
}

/**
 * Usuarios del CRM que NO son una delegación.
 *
 * `assigned_user_id` es lo que marca la delegación de un registro (regla de
 * CLAUDE.md), pero el «Administrador MCM» (id `1`) es un usuario técnico, no
 * una delegación: lo que cuelga de él es de la oficina técnica y por tanto de
 * todos. Se usa para deducir el ámbito cuando el evento no lo dice.
 */
function sticpa_event_audience_non_delegation_users()
{
    return array_map('strval', (array) apply_filters('sticpa_event_audience_non_delegation_users', array('1')));
}

/** Trocea un multienum del CRM (`^a^,^b^`) en un array de claves. */
function sticpa_event_audience_multi($raw)
{
    // El troceador de la casa. Vive en Pasar Lista porque allí nació, y se
    // reutiliza en vez de copiarlo: es el mismo formato del mismo CRM.
    if (function_exists('sticpa_pl_multienum')) {
        return sticpa_pl_multienum($raw);
    }
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

/** Normaliza una clave de enum: en el CRM conviven `COM` y `com`. */
function sticpa_event_audience_key($value)
{
    return strtolower(trim((string) $value));
}

/**
 * Claves del desplegable de cursos que NO son un curso.
 *
 * `ajmcm_curso_escolar_c_list` tiene `na` [NA], que quiere decir «no aplica».
 * Contarlo como un curso normal sería un error silencioso y de los caros: no
 * casaría con ninguno, así que una persona marcada `na` quedaría FUERA de
 * cualquier evento que restrinja cursos. Se trata como «esta persona no tiene
 * curso», que es lo que el valor dice.
 *
 * `otros` NO está aquí a propósito: eso sí es un curso —uno que no está en la
 * lista— y casa con los eventos marcados `otros`.
 */
function sticpa_event_audience_cursos_no_aplica()
{
    return sticpa_event_audience_keys(
        apply_filters('sticpa_event_audience_cursos_no_aplica', array('na'))
    );
}

/** Normaliza una lista de claves y quita las vacías y las repetidas. */
function sticpa_event_audience_keys($values)
{
    $out = array();
    foreach ((array) $values as $v) {
        $k = sticpa_event_audience_key($v);
        if ($k !== '' && !in_array($k, $out, true)) {
            $out[] = $k;
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// La audiencia declarada por el evento
// ---------------------------------------------------------------------------

/**
 * Lee la audiencia de un evento de su `name_value_list`.
 *
 * @param object $nvl name_value_list del registro de stic_Events.
 * @return array delegacion, ambito, perfiles, cursos
 */
function sticpa_event_audience_from_nvl($nvl)
{
    $val = function ($field) use ($nvl) {
        return isset($nvl->$field->value) ? trim((string) $nvl->$field->value) : '';
    };

    $ambitoField   = sticpa_event_audience_field_ambito();
    $perfilesField = sticpa_event_audience_field_perfiles();
    $cursosField   = sticpa_event_audience_field_cursos();

    return array(
        'delegacion' => $val('assigned_user_id'),
        'ambito'     => sticpa_event_audience_key($val($ambitoField)),
        'perfiles'   => sticpa_event_audience_keys(sticpa_event_audience_multi($val($perfilesField))),
        'cursos'     => sticpa_event_audience_keys(sticpa_event_audience_multi($val($cursosField))),
    );
}

/**
 * Ámbito EFECTIVO del evento: 'local' o 'nacional'.
 *
 * Si el campo lo dice, manda el campo. Si está vacío —o no existe todavía— se
 * deduce de la delegación, que es como lo pensó quien lo pidió: «si un evento
 * no tiene delegación, es para todos».
 */
function sticpa_event_audience_scope($audience)
{
    $ambito = sticpa_event_audience_key($audience['ambito'] ?? '');
    if ($ambito !== '') {
        return in_array($ambito, array('nacional', 'todas', 'interdelegacional'), true) ? 'nacional' : 'local';
    }
    $deleg = trim((string) ($audience['delegacion'] ?? ''));
    if ($deleg === '' || in_array($deleg, sticpa_event_audience_non_delegation_users(), true)) {
        return 'nacional';
    }
    return 'local';
}

// ---------------------------------------------------------------------------
// Quién está mirando
// ---------------------------------------------------------------------------

/**
 * El perfil de quien mira: delegación, papeles y cursos escolares.
 *
 * COSTE: como mucho UNA llamada al CRM por sesión, y muchas veces ninguna.
 *   · La delegación sale de la sesión (`sticpa_pl_delegation()`).
 *   · Los papeles salen de `stic_relationship_type_c`, que ya está en sesión
 *     desde el login (`scp_relationship_raw`).
 *   · Los cursos y los papeles de las RELACIONES salen de
 *     `sticpa_pl_mis_rels()`, que es una sola llamada cacheada y que Pasar
 *     Lista ya hace: si el monitor ya ha pasado por allí, esto no pide nada.
 *
 * Y esa llamada solo se hace si hace falta ($conRelaciones): un listado de
 * eventos que no restringe nada no la paga.
 *
 * OJO: se describe el PERFIL ACTIVO, no necesariamente quien inició sesión.
 * Cuando una madre está viendo a su hija, `scp_user_id` es la hija y la
 * inscripción es de la hija (inc/stic-action.php lo invalida al cambiar), así
 * que la audiencia que toca comprobar es la de la hija. Es lo correcto.
 *
 * @param object $objSCP        Cliente del CRM (puede ser null: se degrada).
 * @param bool   $conRelaciones Pedir también las relaciones (cursos y papeles).
 */
function sticpa_viewer_audience($objSCP = null, $conRelaciones = true)
{
    $delegacion = '';
    if ($objSCP !== null && function_exists('sticpa_pl_delegation')) {
        $delegacion = (string) sticpa_pl_delegation($objSCP);
    } elseif (!empty($_SESSION['scp_user_assigned_user_id'])) {
        $delegacion = (string) $_SESSION['scp_user_assigned_user_id'];
    }

    // Papeles desde `stic_relationship_type_c`. Se pasa por
    // sticpa_get_comunica_role() a propósito: es quien resuelve el campo si la
    // sesión aún no lo tiene, y deja `scp_relationship_raw` puesto de camino.
    if (function_exists('sticpa_get_comunica_role')) {
        sticpa_get_comunica_role();
    }
    $raw = isset($_SESSION['scp_relationship_raw']) ? (string) $_SESSION['scp_relationship_raw'] : '';
    $papeles = sticpa_event_audience_keys(preg_split('/[\^,]+/', $raw));
    // ¿Hemos podido preguntar? Un vacío resuelto es «no tiene papeles»; un
    // vacío sin resolver es «no lo sabemos», y no significan lo mismo.
    $papelesConocidos = !empty($papeles)
        || (function_exists('sticpa_role_needs_resolution') ? !sticpa_role_needs_resolution() : false);

    $cursos = array();
    $cursosConocidos = false;

    $userId = isset($_SESSION['scp_user_id']) ? (string) $_SESSION['scp_user_id'] : '';
    if ($conRelaciones && $objSCP !== null && $userId !== '' && function_exists('sticpa_pl_mis_rels')) {
        $rels = sticpa_pl_mis_rels($objSCP, $userId);
        if (is_array($rels)) {
            $cursosConocidos = true;
            $papelesConocidos = true;
            $cursoField = sticpa_event_audience_field_curso_persona();
            // Papeles que cuentan como «participante del grupo» a la hora de
            // leer el CURSO: los menores llevan `participante_mic_com` y los
            // +18 su relación de `grupo` (que cuenta como participante a todos
            // los efectos, igual que en Pasar Lista).
            $comoParticipante = sticpa_event_audience_keys(
                apply_filters('sticpa_event_audience_roles_con_curso', array('participante_mic_com', 'grupo'))
            );

            foreach ($rels as $rel) {
                $v = isset($rel->name_value_list) ? $rel->name_value_list : null;
                if (!$v) {
                    continue;
                }
                if (!sticpa_event_audience_rel_vigente($v)) {
                    continue;   // una relación terminada no dice lo que eres HOY
                }
                $tipo = isset($v->relationship_type->value)
                    ? sticpa_event_audience_key($v->relationship_type->value) : '';
                if ($tipo !== '' && !in_array($tipo, $papeles, true)) {
                    $papeles[] = $tipo;
                }
                // EL CURSO SOLO CUENTA SI ES SUYO. En las relaciones de
                // MONITOR este campo lleva el curso DEL GRUPO que lleva
                // («5_primaria» en la relación de una monitora adulta), no el
                // suyo: contarlo la metería en los eventos de 5.º de primaria
                // por la puerta de atrás y, peor, la dejaría fuera de los de
                // su edad. Comprobado en datos reales el 09/09/2026.
                if (!in_array($tipo, $comoParticipante, true)) {
                    continue;
                }
                $curso = isset($v->$cursoField->value) ? sticpa_event_audience_key($v->$cursoField->value) : '';
                // `na` («no aplica») no es un curso: ver
                // sticpa_event_audience_cursos_no_aplica().
                if ($curso === '' || in_array($curso, sticpa_event_audience_cursos_no_aplica(), true)) {
                    continue;
                }
                if (!in_array($curso, $cursos, true)) {
                    $cursos[] = $curso;
                }
            }
        }
    }

    return apply_filters('sticpa_viewer_audience', array(
        'delegacion'        => $delegacion,
        'papeles'           => $papeles,
        'papeles_conocidos' => $papelesConocidos,
        'cursos'            => $cursos,
        'cursos_conocidos'  => $cursosConocidos,
    ));
}

/**
 * ¿Sigue vigente esta relación? `end_date` en el pasado la cierra; `active`
 * a '0' también. Sin fecha, sigue viva.
 */
function sticpa_event_audience_rel_vigente($v)
{
    if (isset($v->active->value) && trim((string) $v->active->value) === '0') {
        return false;
    }
    $end = isset($v->end_date->value) ? trim((string) $v->end_date->value) : '';
    if ($end === '') {
        return true;
    }
    $ts = strtotime($end . ' 23:59:59');
    if ($ts === false) {
        return true;   // fecha ilegible: no se cierra una relación por eso
    }
    $now = function_exists('sticpa_pl_now') ? sticpa_pl_now() : time();
    return $ts >= $now;
}

// ---------------------------------------------------------------------------
// La decisión
// ---------------------------------------------------------------------------

/**
 * ¿Puede esta persona apuntarse a este evento?
 *
 * @param array $audience Salida de sticpa_event_audience_from_nvl().
 * @param array $viewer   Salida de sticpa_viewer_audience().
 * @return array ok (bool) y motivo ('' | 'delegacion' | 'perfil' | 'curso').
 */
function sticpa_event_audience_match($audience, $viewer)
{
    if (!sticpa_event_audience_enabled()) {
        return array('ok' => true, 'motivo' => '');
    }

    // 1. ÁMBITO. Un evento local es de su delegación. Solo se esconde cuando
    // se saben las DOS delegaciones y son distintas: sin saber la de quien
    // mira no se esconde nada (ver la doctrina de arriba).
    if (sticpa_event_audience_scope($audience) === 'local') {
        $delegEvento = trim((string) ($audience['delegacion'] ?? ''));
        $delegPersona = trim((string) ($viewer['delegacion'] ?? ''));
        if ($delegEvento !== '' && $delegPersona !== '' && $delegEvento !== $delegPersona) {
            return array('ok' => false, 'motivo' => 'delegacion');
        }
    }

    // 2. PERFIL. Solo si el evento restringe.
    $perfiles = sticpa_event_audience_keys($audience['perfiles'] ?? array());
    if (!empty($perfiles)) {
        // Se normaliza también lo que trae quien mira: en el CRM conviven
        // `COM` y `com` para el mismo valor, y `sticpa_viewer_audience` es
        // filtrable, así que aquí no se da por bueno el formato de la entrada.
        $papeles = sticpa_event_audience_keys($viewer['papeles'] ?? array());
        if (empty($papeles) && empty($viewer['papeles_conocidos'])) {
            // No se pudo preguntar: no se esconde.
        } else {
            $aceptadas = array();
            $mapa = sticpa_event_audience_perfil_map();
            foreach ($perfiles as $perfil) {
                // Una clave que no está en el mapa se acepta tal cual: así, si
                // alguien añade un valor al desplegable del CRM y se le olvida
                // el mapa, sigue casando con el papel del mismo nombre en vez
                // de dejar el evento sin audiencia posible.
                $claves = isset($mapa[$perfil]) ? (array) $mapa[$perfil] : array($perfil);
                foreach (sticpa_event_audience_keys($claves) as $clave) {
                    $aceptadas[] = $clave;
                }
            }
            if (empty(array_intersect($aceptadas, $papeles))) {
                return array('ok' => false, 'motivo' => 'perfil');
            }
        }
    }

    // 3. CURSO. Solo estrecha ENTRE PARTICIPANTES: a quien no tiene curso
    // escolar propio (un monitor, una madre) el filtro no se le aplica. Si no
    // fuera así, los monitores del MIC se quedarían fuera de las sesiones
    // semanales del MIC, que están marcadas de 4.º a 6.º de primaria.
    $cursos = sticpa_event_audience_keys($audience['cursos'] ?? array());
    if (!empty($cursos)) {
        // También se limpia aquí: `sticpa_viewer_audience()` ya lo hace, pero
        // este mapa es filtrable y puede llegar un `na` de fuera.
        $mios = array_diff(
            sticpa_event_audience_keys($viewer['cursos'] ?? array()),
            sticpa_event_audience_cursos_no_aplica()
        );
        if (!empty($mios) && empty(array_intersect($cursos, $mios))) {
            return array('ok' => false, 'motivo' => 'curso');
        }
    }

    return array('ok' => true, 'motivo' => '');
}

/**
 * Lo mismo, pero partiendo del `name_value_list` del evento.
 * @return array ok, motivo
 */
function sticpa_event_audience_match_nvl($nvl, $viewer)
{
    return sticpa_event_audience_match(sticpa_event_audience_from_nvl($nvl), $viewer);
}

/**
 * Filtra una lista de eventos del CRM dejando solo los de esta persona.
 *
 * @param object $objSCP  Cliente del CRM.
 * @param array  $events  Filas del CRM (objetos con ->name_value_list).
 * @return array Las filas que sí son para quien mira.
 */
function sticpa_filter_events_for_viewer($objSCP, $events)
{
    if (!is_array($events) || empty($events) || !sticpa_event_audience_enabled()) {
        return is_array($events) ? $events : array();
    }

    // ¿Restringe ALGÚN evento por perfil o por curso? Si no, no hace falta
    // preguntar por las relaciones de nadie: la delegación ya está en sesión.
    $necesitaRelaciones = false;
    $audiencias = array();
    foreach ($events as $i => $row) {
        $nvl = $row->name_value_list ?? null;
        if (!$nvl) {
            continue;
        }
        $audiencias[$i] = sticpa_event_audience_from_nvl($nvl);
        if (!empty($audiencias[$i]['perfiles']) || !empty($audiencias[$i]['cursos'])) {
            $necesitaRelaciones = true;
        }
    }

    $viewer = sticpa_viewer_audience($objSCP, $necesitaRelaciones);

    $out = array();
    foreach ($events as $i => $row) {
        if (!isset($audiencias[$i])) {
            $out[] = $row;   // fila sin datos: no se juzga, ya la tirará el modelo
            continue;
        }
        if (sticpa_event_audience_match($audiencias[$i], $viewer)['ok']) {
            $out[] = $row;
        }
    }
    return array_values($out);
}

/**
 * ¿Puede quien mira apuntarse a ESTE evento (por id)?
 *
 * Es el guard puntual: lo usan la ficha del evento, el formulario de
 * inscripción y —lo importante— el guardado. Cuesta una llamada al CRM, la
 * misma que ya hacen esas pantallas para pintar el evento, así que se le puede
 * pasar el `name_value_list` si ya se tiene.
 *
 * Devuelve también la audiencia del evento (`audiencia`) para que quien
 * pregunta pueda escribir el aviso con los perfiles de verdad sin volver a
 * leer el registro.
 *
 * @param object      $objSCP
 * @param string      $eventId
 * @param object|null $nvl     name_value_list ya cargado, si lo hay.
 * @return array ok, motivo, audiencia
 */
function sticpa_event_audience_check($objSCP, $eventId, $nvl = null)
{
    $vacia = array('delegacion' => '', 'ambito' => '', 'perfiles' => array(), 'cursos' => array());
    if (!sticpa_event_audience_enabled()) {
        return array('ok' => true, 'motivo' => '', 'audiencia' => $vacia);
    }
    $eventId = trim((string) $eventId);
    if ($eventId === '') {
        return array('ok' => true, 'motivo' => '', 'audiencia' => $vacia);
    }
    if ($nvl === null) {
        if ($objSCP === null) {
            return array('ok' => true, 'motivo' => '', 'audiencia' => $vacia);
        }
        $fields = function_exists('sticpa_event_fields_to_request')
            ? sticpa_event_fields_to_request($objSCP)
            : array_merge(array('id', 'name', 'assigned_user_id'), sticpa_event_audience_fields());
        $detail = $objSCP->getRecordDetail($eventId, 'stic_Events', $fields);
        $nvl = $detail->entry_list[0]->name_value_list ?? null;
        if (!$nvl) {
            // El CRM no contesta sobre el evento: no se bloquea por eso. El
            // guard anti-duplicado y el propio guardado ya fallarán solos.
            return array('ok' => true, 'motivo' => '', 'audiencia' => $vacia);
        }
    }
    $audience = sticpa_event_audience_from_nvl($nvl);
    $necesita = !empty($audience['perfiles']) || !empty($audience['cursos']);
    $verdict = sticpa_event_audience_match($audience, sticpa_viewer_audience($objSCP, $necesita));
    $verdict['audiencia'] = $audience;
    return $verdict;
}

/**
 * El aviso completo listo para pintar, a partir del veredicto de
 * `sticpa_event_audience_check()`. Pide al CRM las etiquetas del desplegable
 * de perfiles SOLO cuando el motivo es el perfil: es lo único que las usa.
 */
function sticpa_event_audience_verdict_notice($objSCP, $verdict)
{
    if (!empty($verdict['ok'])) {
        return '';
    }
    $labels = array();
    if (($verdict['motivo'] ?? '') === 'perfil' && $objSCP !== null && function_exists('sticpa_cached_field_definition')) {
        $def = sticpa_cached_field_definition($objSCP, 'stic_Events', array(sticpa_event_audience_field_perfiles()));
        $labels = sticpa_event_audience_perfil_texts($verdict['audiencia'] ?? array(), is_array($def) ? $def : array());
    }
    $texto = sticpa_event_audience_notice($verdict['motivo'] ?? '', $labels);
    return $texto !== '' ? $texto : __('Esta actividad no está abierta a tu inscripción.', 'sticpa');
}

// ---------------------------------------------------------------------------
// Lo que se le dice a la persona
// ---------------------------------------------------------------------------

/**
 * El texto de «esta actividad no es para ti», por motivo.
 *
 * Se explica el motivo en vez de esconder el evento sin más porque a la ficha
 * se puede llegar por un enlace que alguien te ha pasado por WhatsApp, y
 * «no puedes» sin decir por qué es la peor pantalla posible.
 *
 * @param string $motivo  '' | 'delegacion' | 'perfil' | 'curso'
 * @param array  $labels  Etiquetas de los perfiles del evento, si se saben.
 */
function sticpa_event_audience_notice($motivo, $labels = array())
{
    switch ($motivo) {
        case 'delegacion':
            return __('Esta actividad la organiza otra delegación, así que la inscripción no está abierta para ti.', 'sticpa');
        case 'perfil':
            $labels = array_values(array_filter(array_map('trim', (array) $labels)));
            if (!empty($labels)) {
                /* translators: %s = lista de perfiles, p. ej. "monitores y monitoras" */
                return sprintf(
                    __('Esta actividad es solo para %s.', 'sticpa'),
                    sticpa_event_audience_join($labels)
                );
            }
            return __('Esta actividad va dirigida a un perfil concreto y tu ficha no lo tiene.', 'sticpa');
        case 'curso':
            return __('Esta actividad es para otros cursos escolares.', 'sticpa');
    }
    return '';
}

/** Une una lista en lenguaje natural: «a, b y c». */
function sticpa_event_audience_join($items)
{
    $items = array_values((array) $items);
    $n = count($items);
    if ($n === 0) {
        return '';
    }
    if ($n === 1) {
        return (string) $items[0];
    }
    $last = array_pop($items);
    /* translators: %1$s = lista separada por comas, %2$s = último elemento */
    return sprintf(__('%1$s y %2$s', 'sticpa'), implode(', ', $items), $last);
}

/**
 * Etiquetas de los perfiles a los que va dirigido un evento, para el aviso.
 * Se prefieren las del CRM (traducidas allí) y se cae en las de la casa.
 */
function sticpa_event_audience_perfil_texts($audience, $definition = array())
{
    $field = sticpa_event_audience_field_perfiles();
    $options = array();
    if (!empty($definition[$field]['options']) && is_array($definition[$field]['options'])) {
        foreach ($definition[$field]['options'] as $key => $option) {
            $options[sticpa_event_audience_key($key)] = is_array($option)
                ? (string) ($option['value'] ?? '') : (string) $option;
        }
    }
    $fallback = sticpa_event_audience_perfil_labels();

    $out = array();
    foreach ((array) ($audience['perfiles'] ?? array()) as $perfil) {
        $label = $options[$perfil] ?? ($fallback[$perfil] ?? '');
        if (trim($label) !== '') {
            $out[] = $label;
        }
    }
    return $out;
}
