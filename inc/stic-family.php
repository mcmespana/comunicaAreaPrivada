<?php
/**
 * QUIÉN ERES Y QUÉ VES — el contexto de familia.
 * ----------------------------------------------------------------------------
 * Esta es LA pregunta del área privada y estaba respondida a trozos: un poco en
 * el login (`sinergiacrm-private-area.php`), otro poco en el menú, otro en la
 * pantalla de selección y otro en la home. Cada sitio decidía por su cuenta y no
 * decían lo mismo. Aquí se responde una vez.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * AVISO SOBRE `scp_user_adult`, QUE NO SIGNIFICA LO QUE PARECE
 *
 * `$_SESSION['scp_user_adult']` NO quiere decir "es mayor de edad". Lo calcula
 * `check_user_adult()`, que pregunta al CRM si esta persona tiene a alguien A SU
 * CARGO (relaciones `stic_Personal_Environment` de tipo padre/madre/tutor/
 * cuidador) y devuelve `true` cuando NO tiene a nadie.
 *
 *     scp_user_adult === true   →  entra por sí misma, no representa a nadie
 *     scp_user_adult === false  →  ES FAMILIAR: tiene participantes a cargo
 *
 * O sea que un padre de 45 años tiene `scp_user_adult = false`. El nombre lleva
 * años induciendo a error; no se renombra la clave porque vive en cookies de
 * sesión de un año, pero **usa `sticpa_es_familiar()` y olvídate de ella**.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * LOS TRES CASOS QUE HAY QUE DISTINGUIR, y que antes se mezclaban:
 *
 *   1. FAMILIAR Y NADA MÁS. Su madre o su padre. No se apunta a nada, no tiene
 *      pagos suyos, no es del MCM. Todo lo que le importa está en la ficha de
 *      su hijo o hija. Su propia ficha existe para una sola cosa: corregir su
 *      teléfono, su correo y su forma de pago.
 *   2. FAMILIAR **Y ADEMÁS** MIEMBRO DEL MCM (monitora que además es madre, por
 *      ejemplo). Manda el rol: ve exactamente lo que ve cualquier miembro, y el
 *      selector de participante le sirve para saltar a la ficha de sus hijos.
 *   3. MIEMBRO A SECAS. El caso de siempre.
 *
 * DE AHÍ SALE A DÓNDE SE ATERRIZA TRAS EL LOGIN (`sticpa_landing_page`), y qué
 * secciones se enseñan (`sticpa_visible_sections`). La regla que lo resume:
 * **a un familiar que solo es familiar no se le enseña un área privada suya**,
 * porque no la tiene; se le lleva a la de su hijo o hija.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ¿Esta persona tiene participantes a cargo? (Es decir: ¿es familiar?)
 *
 * Es la lectura correcta de `scp_user_adult`, invertida y con nombre honesto.
 * Ante la duda —la clave no existe todavía— devuelve `false`: no se convierte a
 * nadie en familiar por accidente.
 */
function sticpa_es_familiar()
{
    if (!isset($_SESSION['scp_user_adult'])) {
        return false;
    }
    return !$_SESSION['scp_user_adult'];
}

/**
 * ¿Es miembro del MCM por derecho propio? (monitor, laico… cualquier rol.)
 *
 * OJO: el rol de la sesión es el del PERFIL ACTIVO. Cuando un familiar está
 * viendo a su hijo, el rol que hay en sesión puede ser el del hijo. Por eso
 * esta pregunta solo tiene sentido sobre quien ha iniciado sesión, y se guarda
 * aparte en cuanto se sabe (`scp_tutor_es_miembro`).
 */
function sticpa_familiar_es_miembro()
{
    if (isset($_SESSION['scp_tutor_es_miembro'])) {
        return (bool) $_SESSION['scp_tutor_es_miembro'];
    }
    // SOLO LEE LA SESIÓN, no resuelve nada. Antes llamaba a
    // sticpa_get_comunica_role(), que si el rol no está resuelto PREGUNTA AL
    // CRM — y esta función la acaba llamando sticpa_profile_audience(), que se
    // invoca al pintar listados y fichas. O sea: una llamada al CRM por render,
    // colada por la puerta de atrás, justo en un módulo escrito para no añadir
    // ni un viaje. Resolver el rol se paga UNA vez, al entrar, en
    // sticpa_recordar_si_familiar_es_miembro().
    return !empty($_SESSION['scp_role']);
}

/**
 * Recuerda si quien ha iniciado sesión es además miembro del MCM.
 *
 * Se llama UNA vez, al entrar, mientras el rol de la sesión sigue siendo el
 * suyo y antes de que elegir un participante lo sustituya por el del hijo.
 */
function sticpa_recordar_si_familiar_es_miembro()
{
    if (isset($_SESSION['scp_tutor_es_miembro'])) {
        return;
    }
    $rol = function_exists('sticpa_get_comunica_role') ? sticpa_get_comunica_role() : '';
    $_SESSION['scp_tutor_es_miembro'] = ($rol !== '');
}

/**
 * Los participantes a cargo de quien ha iniciado sesión (id + nombre).
 *
 * FUENTE ÚNICA. Antes esta consulta vivía dentro de
 * `pages/single_stic_profile_selection.php`, así que para saber CUÁNTOS hijos
 * tiene alguien había que pintar la pantalla de selección entera. Ahora se
 * puede preguntar antes de decidir a dónde mandarle.
 *
 * RENDIMIENTO: es un 1+N inevitable con esta API (una llamada para las
 * relaciones y otra por relación para sacar a la persona del otro lado), así
 * que el resultado se guarda en `scp_available_profiles` y NO se vuelve a
 * pedir en toda la sesión. Lo invalida cerrar sesión, como todo lo demás.
 *
 * @param object $objSCP Cliente del CRM.
 * @param bool   $forzar Recargar aunque haya caché (tras cambiar relaciones).
 */
function sticpa_load_family_participants($objSCP, $forzar = false)
{
    if (!$forzar && isset($_SESSION['scp_available_profiles'])) {
        return (array) $_SESSION['scp_available_profiles'];
    }

    $tipos = defined('RELATIONSHIP_TUTOR_TYPES') ? RELATIONSHIP_TUTOR_TYPES : array();
    $participantes = array();

    if (!empty($tipos) && $objSCP) {
        $comillas = array();
        foreach ($tipos as $tipo) {
            $comillas[] = "'" . $tipo . "'";
        }
        $query = "((stic_personal_environment.start_date <= DATE(NOW())"
            . " AND (stic_personal_environment.end_date >= DATE(NOW()) OR stic_personal_environment.end_date IS NULL))"
            . " AND stic_personal_environment.relationship_type in (" . implode(',', $comillas) . "))";

        $relaciones = $objSCP->getRelatedElementsForLoggedUser(array(
            'module_name' => 'Contacts',
            'module_id' => $_SESSION['scp_tutor_user_id'] ?? ($_SESSION['scp_user_id'] ?? ''),
            'link_field_name' => 'stic_personal_environment_contacts_1',
            'related_module_query' => $query,
            'related_fields' => array('id'),
            'related_module_link_name_to_fields_array' => array(),
            'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 0,
        ));

        foreach ((is_array($relaciones) ? $relaciones : array()) as $relacion) {
            $relId = $relacion->name_value_list->id->value ?? null;
            if (!$relId) {
                continue;
            }
            $persona = $objSCP->getRelatedElementsForLoggedUser(array(
                'module_name' => 'stic_Personal_Environment',
                'module_id' => $relId,
                'link_field_name' => 'stic_personal_environment_contacts',
                'related_fields' => array('id', 'name'),
                'related_module_link_name_to_fields_array' => array(),
                'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 0,
            ));
            if (isset($persona[0]->name_value_list->id->value)) {
                $participantes[] = array(
                    'id' => $persona[0]->name_value_list->id->value,
                    'name' => $persona[0]->name_value_list->name->value ?? '',
                );
            }
        }
    }

    // Punto de extensión: inyectar participantes sin tocar el CRM (la parte de
    // relaciones familiares de Sinergia todavía no está montada).
    $participantes = apply_filters('sticpa_familia_participants', $participantes);

    $_SESSION['scp_available_profiles'] = $participantes;
    return $participantes;
}

/**
 * EL contexto: quién eres, a quién estás viendo y qué te toca ver.
 *
 * @return array{
 *   es_familiar:bool, es_miembro:bool, viendo_se:bool,
 *   participantes:int, audiencia:string, solo_familiar:bool
 * }
 */
function sticpa_viewing_context()
{
    $esFamiliar = sticpa_es_familiar()
        || (function_exists('sticpa_is_familia') && sticpa_is_familia());
    $esMiembro  = sticpa_familiar_es_miembro();

    // "Viéndose a sí mismo" es: o no hay sesión de familiar (entra solo), o la
    // hay y el perfil activo es el suyo.
    $viendoSe = !isset($_SESSION['scp_tutor_user_id']) || !empty($_SESSION['scp_tutor_is_user']);

    $participantes = isset($_SESSION['scp_available_profiles'])
        ? count((array) $_SESSION['scp_available_profiles'])
        : 0;

    // La audiencia manda sobre qué se enseña. `solo_familiar` es EL caso
    // nuevo: familiar sin rol propio, mirándose a sí mismo. Ahí el área
    // privada se reduce a sus datos, porque no tiene nada más suyo.
    if ($esFamiliar && !$viendoSe) {
        $audiencia = 'participante';
    } elseif ($esFamiliar && !$esMiembro) {
        $audiencia = 'familiar';
    } else {
        $audiencia = 'miembro';
    }

    return array(
        'es_familiar'   => $esFamiliar,
        'es_miembro'    => $esMiembro,
        'viendo_se'     => $viendoSe,
        'participantes' => $participantes,
        'audiencia'     => $audiencia,
        'solo_familiar' => ($audiencia === 'familiar'),
    );
}

/**
 * Secciones que se le enseñan a quien está mirando ahora mismo.
 *
 * A un FAMILIAR QUE SOLO ES FAMILIAR, viéndose a sí mismo, no se le enseñan
 * Eventos, Inscripciones, Pagos ni Documentos: **no son suyos**. Él no se
 * apunta a nada; se apuntan sus hijos, y eso está en la ficha de sus hijos. Su
 * propia pantalla existe para corregir su teléfono, su correo y su forma de
 * pago, y ofrecerle un menú de ocho secciones vacías es prometerle cosas que no
 * va a encontrar.
 *
 * En cuanto elige a un participante, `audiencia` pasa a 'participante' y vuelve
 * el menú entero — porque entonces sí hay inscripciones y pagos que ver.
 *
 * @param array $secciones Mapa clave => etiqueta, tal y como lo da getSticMenuElements().
 */
function sticpa_visible_sections($secciones)
{
    $ctx = sticpa_viewing_context();
    if (!$ctx['solo_familiar']) {
        return $secciones;
    }

    // Lo único que es SUYO. Se filtra por lista blanca y no por lista negra: si
    // mañana alguien añade una sección al menú, el familiar no la ve hasta que
    // alguien decida a conciencia que le corresponde.
    //
    // EL DINERO SÍ ES SUYO, y esto se corrigió sobre la marcha. La primera
    // versión le quitaba Pagos y Compromisos junto con todo lo demás, y estaba
    // mal: un compromiso de pago es de QUIEN PAGA. El IBAN, el mandato SEPA y
    // la autorización son suyos, no del niño. SinergiaCRM lo modela así a
    // propósito —persona pagadora (obligatoria) y persona destinataria
    // (opcional)— y su documentación pone justo este ejemplo: «en el ámbito de
    // la infancia, los adultos realizan el pago de una actividad en la que
    // participa un menor».
    //
    // Lo que NO es suyo es apuntarse: eventos, inscripciones, sesiones y
    // asistencias son del participante y se ven en su ficha.
    $suyas = apply_filters('sticpa_secciones_del_familiar', array(
        'single_stic_tutor_profile',
        'single_stic_profile',
        'single_stic_comunica_perfil',
        'single_stic_password_change',
        'single_stic_profile_selection',
        // El dinero que sale de SU cuenta.
        'list_stic_payments',
        'list_stic_payment_commitments',
        'single_stic_payment_form',
        'custom_html',
    ));

    $filtradas = array();
    foreach ((array) $secciones as $clave => $etiqueta) {
        if (in_array($clave, $suyas, true)) {
            $filtradas[$clave] = $etiqueta;
        }
    }
    return $filtradas;
}

/**
 * A DÓNDE se aterriza tras el login, cuando la URL no pide una página concreta.
 *
 * La regla que pidió el propietario, y que es la que tiene sentido: **a un
 * familiar que solo es familiar, su propia área privada no le sirve de nada**,
 * así que no se le enseña.
 *
 *   · No es familiar                    → su home de siempre.
 *   · Familiar Y miembro del MCM        → su home. Es miembro primero; para ver
 *                                         a sus hijos tiene el selector.
 *   · Familiar a secas, UN participante → directo a la ficha de ese participante.
 *                                         Nada de una pantalla de "elige" con una
 *                                         sola opción, que es un toque de más
 *                                         para decir algo que ya sabíamos.
 *   · Familiar a secas, VARIOS          → la pantalla de selección. Ahí sí hay
 *                                         algo que elegir, y desde ella se llega
 *                                         también a sus propios datos.
 *
 * Devuelve la clave de página, y —en el caso de un solo participante— DEJA LA
 * SESIÓN puesta en ese participante, que es lo que hace que la home de después
 * sea la suya.
 *
 * @param object $objSCP Cliente del CRM (para contar participantes si hace falta).
 */
function sticpa_landing_page($objSCP = null)
{
    sticpa_recordar_si_familiar_es_miembro();

    if (!sticpa_es_familiar()) {
        return 'single_stic_home';
    }

    // Es familiar: se recuerda quién es él, porque a partir de ahora
    // `scp_user_id` va a ser el participante que esté mirando.
    if (!isset($_SESSION['scp_tutor_user_id'])) {
        $_SESSION['scp_tutor_user_id'] = $_SESSION['scp_user_id'] ?? '';
        $_SESSION['scp_tutor_user_contact_name'] = $_SESSION['scp_user_contact_name'] ?? '';
    }

    if (sticpa_familiar_es_miembro()) {
        // Miembro del MCM que además es familiar: su casa es su home, y el
        // selector de la barra le lleva a sus hijos cuando quiera.
        $_SESSION['scp_tutor_is_user'] = true;
        return 'single_stic_home';
    }

    // SIEMPRE por el cargador, aunque no venga cliente del CRM: si la caché de
    // sesión está caliente, la respuesta sale de ahí sin tocar el CRM. Pasando
    // por alto la caché cuando faltaba el cliente, un familiar con hijos
    // acababa en SU home en vez de en la de ellos — que es justo lo que este
    // fichero existe para evitar.
    $participantes = sticpa_load_family_participants($objSCP);

    if (count($participantes) === 1) {
        // Un solo hijo: se entra directamente a lo suyo.
        $_SESSION['scp_user_id'] = $participantes[0]['id'];
        $_SESSION['scp_user_contact_name'] = $participantes[0]['name'];
        $_SESSION['scp_tutor_is_user'] = false;
        // El rol de la sesión era el del familiar; ahora el perfil activo es
        // otra persona y hay que volver a resolverlo.
        unset($_SESSION['scp_role'], $_SESSION['scp_role_resolved']);
        return 'single_stic_home';
    }

    if (count($participantes) > 1) {
        return 'single_stic_profile_selection';
    }

    // Familiar sin participantes localizados (o la parte de relaciones del CRM
    // todavía sin montar): su propia pantalla, que es lo único que hay.
    $_SESSION['scp_tutor_is_user'] = true;
    return 'single_stic_home';
}
