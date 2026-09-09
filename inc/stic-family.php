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
    // SOLO LEE LA SESIÓN, no resuelve nada: sticpa_profile_audience() acaba
    // llamando aquí al pintar listados y fichas, y resolver el rol pregunta al
    // CRM. Eso se paga UNA vez, al entrar (sticpa_recordar_si_familiar_es_miembro).
    return sticpa_es_miembro_por_tipo_de_relacion(
        $_SESSION['scp_relationship_raw'] ?? '',
        $_SESSION['scp_role'] ?? ''
    );
}

/**
 * Marcas de `stic_relationship_type_c` que significan SOLO «familiar de un
 * menor». Cualquier otra cosa en ese campo es vínculo propio con el MCM.
 */
function sticpa_marcas_de_solo_familiar()
{
    return apply_filters('sticpa_marcas_de_solo_familiar', array('familiar_menor'));
}

/**
 * ¿El tipo de relación de esta persona dice ALGO MÁS que «soy familiar de un
 * menor»?
 *
 * ESTO ARREGLA UN FALLO REAL, y conviene entender por qué no vale el rol.
 * `sticpa_get_comunica_role()` solo sabe decir 'monitor' o 'laico', porque su
 * mapa existe para decidir si se enseñan «Pasar lista» y «Mis grupos». Pero ser
 * MIEMBRO del MCM es más ancho que eso: una madre con `^familiar_menor^,^grupo^`
 * tiene su grupo y es del Movimiento, y sin embargo no es monitora ni laica, así
 * que el mapa devolvía '' y la dábamos por «solo familiar». A partir de ahí se le
 * recortaba el menú y se le escondían secciones que sí son suyas.
 *
 * La pregunta correcta es la de arriba: si en su tipo de relación hay algo que
 * NO sea una marca de «familiar de un menor», es miembro por derecho propio.
 *
 * Los valores salen del CRM (`^familiar_menor^,^grupo^`), no de una lista
 * inventada: se separan por `^` y `,` y se compara en minúsculas.
 *
 * @param string $raw  Valor crudo de stic_relationship_type_c.
 * @param string $role Rol ya detectado ('monitor', 'laico', …), si lo hay.
 */
function sticpa_es_miembro_por_tipo_de_relacion($raw, $role = '')
{
    // Un rol reconocido zanja la pregunta.
    if (trim((string) $role) !== '') {
        return true;
    }
    $raw = (string) $raw;
    if (trim($raw) === '') {
        // Sin dato NO se supone que sea miembro: se supone lo de menos
        // privilegios, y el resto de la sesión ya sabe apañarse.
        return false;
    }
    $marcas = array_map('strtolower', sticpa_marcas_de_solo_familiar());
    foreach (preg_split('/[\^,]+/', $raw) as $trozo) {
        $trozo = strtolower(trim($trozo));
        if ($trozo === '') {
            continue;
        }
        if (!in_array($trozo, $marcas, true)) {
            return true;
        }
    }
    return false;
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
    // sticpa_get_comunica_role() deja `scp_relationship_raw` puesto de camino,
    // y ese valor crudo es el que sabe distinguir «solo familiar» de «familiar
    // y además del MCM». El rol solo/'monitor'/'laico' se queda corto.
    $_SESSION['scp_tutor_es_miembro'] = sticpa_es_miembro_por_tipo_de_relacion(
        $_SESSION['scp_relationship_raw'] ?? '',
        $rol
    );
}

/**
 * ¿El perfil que ha accedido es de tipo "familia"? Solo en ese caso se muestran
 * el selector rápido de participante y la pantalla de selección.
 *
 * Se considera familia cuando hay participantes disponibles en sesión
 * (los carga pages/single_stic_profile_selection.php desde el CRM — relaciones
 * stic_Personal_Environment — o vía el filtro 'sticpa_familia_participants').
 * Mientras la parte de Sinergia no esté montada, puedes forzarlo con el filtro
 * 'sticpa_is_familia' o previsualizar con ?familia_demo=1 en la selección.
 */
function sticpa_is_familia()
{
    $isFamilia = !empty($_SESSION['scp_is_familia'])
        || (isset($_SESSION['scp_available_profiles']) && count((array) $_SESSION['scp_available_profiles']) > 0)
        || isset($_SESSION['scp_tutor_user_id']);
    return (bool) apply_filters('sticpa_is_familia', $isFamilia);
}

/**
 * Participantes disponibles para el selector rápido (id + name), cacheados en
 * sesión por la pantalla de selección. Devuelve array vacío si aún no se cargó.
 */
function sticpa_available_profiles()
{
    $profiles = isset($_SESSION['scp_available_profiles']) ? (array) $_SESSION['scp_available_profiles'] : array();
    return apply_filters('sticpa_available_profiles', $profiles);
}

/**
 * ¿Sigue viva una relación de `stic_Personal_Environment`?
 *
 * Ya empezó (o no dice cuándo empezó) y no ha terminado. Lo importante es cómo
 * se trata una fecha de fin AUSENTE: significa «no termina», y hay muchas
 * formas de estar ausente —el campo no viene, viene vacío, viene '0000-00-00'—
 * y todas quieren decir lo mismo. Darlas por terminadas dejaría a una madre sin
 * hijos, así que ante la duda la relación está VIVA.
 */
function sticpa_relacion_vigente($nvl)
{
    if (!$nvl) {
        return false;
    }
    $fecha = function ($campo) use ($nvl) {
        $v = isset($nvl->$campo->value) ? trim((string) $nvl->$campo->value) : '';
        // '0000-00-00' es la fecha nula de MySQL: es «no hay fecha».
        if ($v === '' || strpos($v, '0000-00-00') === 0) {
            return null;
        }
        $ts = strtotime($v);
        return $ts ?: null;
    };

    $hoy = strtotime('today');
    $inicio = $fecha('start_date');
    if ($inicio !== null && $inicio > $hoy) {
        return false;   // todavía no ha empezado
    }
    $fin = $fecha('end_date');
    if ($fin !== null && $fin < $hoy) {
        return false;   // ya terminó
    }
    return true;
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
    $huboRespuesta = false;

    if (!empty($tipos) && $objSCP) {
        $comillas = array();
        foreach ($tipos as $tipo) {
            $comillas[] = "'" . $tipo . "'";
        }
        // EL FILTRO DE FECHAS SALE DEL SQL Y SE HACE EN PHP. Antes el WHERE
        // incluía `end_date >= NOW() OR end_date IS NULL`, y eso da por hecho
        // que una relación sin fin guarda NULL. Si el CRM guarda cadena vacía o
        // '0000-00-00' —que es lo normal en SuiteCRM y no se puede comprobar
        // desde el MCP, cuya herramienta no acepta filtros SQL— la relación se
        // CAE del resultado y la persona se queda sin hijos, en silencio.
        //
        // Filtrar por `relationship_type` en SQL es seguro (igualdad sobre un
        // enum, y ya funcionaba); las fechas son tres comparaciones sobre una
        // lista de una o dos filas. No merece la pena arriesgar un WHERE que,
        // además, este CRM rechaza con un 400 en algunos módulos
        // (docs/comunica/PASAR-LISTA-ESTADO.md).
        $query = "(stic_personal_environment.relationship_type in (" . implode(',', $comillas) . "))";

        $relaciones = $objSCP->getRelatedElementsForLoggedUser(array(
            'module_name' => 'Contacts',
            'module_id' => $_SESSION['scp_tutor_user_id'] ?? ($_SESSION['scp_user_id'] ?? ''),
            'link_field_name' => 'stic_personal_environment_contacts_1',
            'related_module_query' => $query,
            // EL CAMPO PLANO, ADEMÁS DEL id. Es la regla de la casa
            // (docs/comunica/PASAR-LISTA-ESTADO.md §3.1): esta instancia no
            // devuelve enlaces anidados, así que se pide siempre el `..._ida`
            // y se usa el que llegue. Aquí trae directamente el id de la
            // persona del otro lado de la relación.
            'related_fields' => array(
                'id',
                'stic_personal_environment_contactscontacts_ida',
                // Las fechas viajan para poder decidir aquí si la relación
                // sigue viva (ver el comentario del filtro, arriba).
                'start_date',
                'end_date',
            ),
            'related_module_link_name_to_fields_array' => array(),
            'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 0,
        ));

        // Que el CRM CONTESTE es lo que distingue "no tiene hijos" de "no he
        // podido preguntar". Sin esta marca se cacheaba el fallo (ver abajo).
        $huboRespuesta = is_array($relaciones);

        foreach ((is_array($relaciones) ? $relaciones : array()) as $relacion) {
            $nvl = $relacion->name_value_list ?? null;
            if (!sticpa_relacion_vigente($nvl)) {
                continue;
            }
            $relId = $nvl->id->value ?? null;
            $hijoId = isset($nvl->stic_personal_environment_contactscontacts_ida->value)
                ? trim((string) $nvl->stic_personal_environment_contactscontacts_ida->value)
                : '';
            if (!$relId && $hijoId === '') {
                continue;
            }

            // El nombre hay que ir a buscarlo. Pero el id YA lo tenemos del
            // campo plano, así que si esta llamada falla NO se pierde al
            // participante: se le pinta con lo que haya.
            //
            // Antes se descartaba la fila entera cuando esta segunda llamada no
            // devolvía nada (`if (isset($persona[0]...))`), o sea que un hipo
            // del CRM hacía DESAPARECER a una hija de la lista de su madre.
            $nombre = '';
            if ($relId) {
                $persona = $objSCP->getRelatedElementsForLoggedUser(array(
                    'module_name' => 'stic_Personal_Environment',
                    'module_id' => $relId,
                    'link_field_name' => 'stic_personal_environment_contacts',
                    'related_fields' => array('id', 'name'),
                    'related_module_link_name_to_fields_array' => array(),
                    'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 0,
                ));
                if (isset($persona[0]->name_value_list->id->value)) {
                    $hijoId = $persona[0]->name_value_list->id->value;
                    $nombre = $persona[0]->name_value_list->name->value ?? '';
                }
            }

            if ($hijoId === '') {
                continue;
            }
            $participantes[] = array(
                'id' => $hijoId,
                // Sin nombre se pinta algo antes que nada: una fila sin texto
                // no se puede tocar, y perder el acceso es peor que un nombre feo.
                'name' => $nombre !== '' ? $nombre : __('Participante', 'sticpa'),
            );
        }
    }

    // Punto de extensión: inyectar participantes sin tocar el CRM (la parte de
    // relaciones familiares de Sinergia todavía no está montada).
    $participantes = apply_filters('sticpa_familia_participants', $participantes);

    // NO SE CACHEA UN VACÍO QUE NO SE HA PODIDO RESOLVER. Es exactamente la
    // lección del plan 040, que ya costó que a un monitor le desaparecieran
    // «Pasar lista» y «Mis grupos» en producción: si el CRM no contesta y se
    // guarda el resultado igual, la sesión —que dura un año— se queda pegada
    // con «esta persona no tiene hijos» y no hay forma de recuperarse salvo
    // cerrar sesión, si es que alguien acierta a probarlo.
    //
    // Una lista VACÍA con respuesta del CRM sí es un dato bueno («no tiene
    // participantes a cargo») y se cachea. Una lista vacía porque la llamada
    // falló, no.
    if (!empty($participantes) || $huboRespuesta) {
        $_SESSION['scp_available_profiles'] = $participantes;
    }
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
function sticpa_bootstrap_family($objSCP = null)
{
    // SE EJECUTA SIEMPRE, pida la URL la página que pida. Antes esto vivía
    // dentro de sticpa_landing_page(), que solo corre cuando NO hay
    // `?internalpage` — o sea, solo al entrar por la puerta principal. Con un
    // enlace profundo (la app abriendo una sección, un marcador, una pestaña
    // que el navegador restaura) la sesión de familia no se montaba NUNCA:
    // sin `scp_tutor_user_id`, sin saber si es miembro y sin participantes
    // cargados. Resultado: el selector vacío y, a un familiar, el menú
    // recortado. El mismo síntoma que ya costó un arreglo, por otra puerta.
    sticpa_recordar_si_familiar_es_miembro();

    if (!sticpa_es_familiar()) {
        return;
    }

    // Es familiar: se recuerda quién es él, porque a partir de ahora
    // `scp_user_id` va a ser el participante que esté mirando.
    if (!isset($_SESSION['scp_tutor_user_id'])) {
        $_SESSION['scp_tutor_user_id'] = $_SESSION['scp_user_id'] ?? '';
        $_SESSION['scp_tutor_user_contact_name'] = $_SESSION['scp_user_contact_name'] ?? '';
    }

    // Los participantes SIEMPRE, incluso para quien además es miembro del MCM:
    // son los que llenan el selector de la barra, y sin ellos una madre se
    // queda mirando un selector en el que no está su hija.
    $participantes = sticpa_load_family_participants($objSCP);

    if (sticpa_familiar_es_miembro()) {
        // Miembro del MCM que además es familiar: su sitio es el suyo, y para
        // ver a sus hijos tiene el selector.
        if (!isset($_SESSION['scp_tutor_is_user'])) {
            $_SESSION['scp_tutor_is_user'] = true;
        }
        return;
    }

    // Familiar y nada más, con UN solo participante: su área es la del hijo.
    // Se elige solo, y también cuando se llega por enlace profundo — porque su
    // propia área no tiene nada que enseñarle.
    if (count($participantes) === 1 && !isset($_SESSION['scp_tutor_is_user'])) {
        $_SESSION['scp_user_id'] = $participantes[0]['id'];
        $_SESSION['scp_user_contact_name'] = $participantes[0]['name'];
        $_SESSION['scp_tutor_is_user'] = false;
        // El rol de la sesión era el del familiar; el perfil activo es otro y
        // hay que volver a resolverlo, o el hijo hereda el menú de su madre.
        unset($_SESSION['scp_role'], $_SESSION['scp_role_resolved'], $_SESSION['scp_relationship_raw']);
        return;
    }

    if (empty($participantes) && !isset($_SESSION['scp_tutor_is_user'])) {
        // Sin participantes localizados: su propia pantalla, que es lo único.
        $_SESSION['scp_tutor_is_user'] = true;
    }
}

/**
 * A DÓNDE se aterriza tras el login, cuando la URL no pide página concreta.
 * El estado de la sesión ya lo ha dejado montado sticpa_bootstrap_family().
 */
function sticpa_landing_page($objSCP = null)
{
    sticpa_bootstrap_family($objSCP);

    if (!sticpa_es_familiar()) {
        return 'single_stic_home';
    }

    // Varios participantes y solo familiar: hay algo que elegir de verdad.
    // (Con uno solo, el bootstrap ya ha entrado en su ficha; con cero o siendo
    // miembro, la home es la suya.)
    if (!sticpa_familiar_es_miembro() && count(sticpa_available_profiles()) > 1
        && empty($_SESSION['scp_tutor_is_user'])) {
        return 'single_stic_profile_selection';
    }

    return 'single_stic_home';
}
