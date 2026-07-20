<?php

/**
 * ============================================================================
 *  Detección de ROL en Comunica (monitor / laico / …)
 * ----------------------------------------------------------------------------
 *  El rol del contacto vive en el campo multienum `stic_relationship_type_c`
 *  del módulo Contacts (lista `stic_contacts_relationships_types_list`).
 *  Etiquetas conocidas: "Monitor/a", "Grupo COM-LC", "Socio AJ".
 *
 *  Un contacto puede tener VARIOS valores a la vez (p. ej. monitor Y laico).
 *  Se prioriza por el orden del mapa: el primero que casa gana. Hoy:
 *      monitor  >  laico
 *
 *  La detección es TOLERANTE (busca subcadena, sin distinguir mayúsculas) para
 *  no depender de la clave interna exacta del desplegable. Si en el futuro hay
 *  más roles, basta con ampliar `sticpa_comunica_role_map()` (o el filtro).
 * ============================================================================
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mapa rol => subcadenas que lo identifican, EN ORDEN DE PRIORIDAD.
 * El primer rol cuyo patrón aparezca en el valor del CRM es el que se asigna.
 */
function sticpa_comunica_role_map()
{
    return apply_filters('sticpa_comunica_role_map', array(
        'monitor' => array('monitor'),
        'laico'   => array('com-lc', 'laic', 'grupo com'),
    ));
}

/**
 * Dado el valor crudo de `stic_relationship_type_c` (formato SuiteCRM
 * `^Monitor/a^,^Grupo COM-LC^`), devuelve el rol prioritario: 'monitor',
 * 'laico', … o '' si no se reconoce.
 */
function sticpa_detect_role_from_relationship($raw)
{
    $v = function_exists('mb_strtolower') ? mb_strtolower((string) $raw, 'UTF-8') : strtolower((string) $raw);
    if (trim($v) === '') {
        return '';
    }
    foreach (sticpa_comunica_role_map() as $role => $needles) {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && strpos($v, strtolower($needle)) !== false) {
                return $role;
            }
        }
    }
    return apply_filters('sticpa_comunica_role_default', '', $raw);
}

/**
 * Calcula el rol del contacto y lo guarda en sesión (`scp_role`). Reutilizable
 * desde cualquier flujo de login.
 *
 * @param object $entry  entry_list[0] del CRM (con ->id y ->name_value_list).
 * @param string $module 'Contacts' | 'Accounts'.
 * @return string Rol detectado.
 */
function sticpa_store_comunica_role($entry, $module)
{
    $raw = '';
    if (isset($entry->name_value_list->stic_relationship_type_c->value)) {
        $raw = $entry->name_value_list->stic_relationship_type_c->value;
    } elseif (!empty($entry->id) && class_exists('SugarRestApiCall')) {
        // El flujo de login no siempre trae el campo: lo pedimos al CRM.
        $detail = SugarRestApiCall::getObjSCP()->getRecordDetail(
            $entry->id,
            $module,
            array('id', 'stic_relationship_type_c')
        );
        if (isset($detail->entry_list[0]->name_value_list->stic_relationship_type_c->value)) {
            $raw = $detail->entry_list[0]->name_value_list->stic_relationship_type_c->value;
        }
    }

    $role = sticpa_detect_role_from_relationship($raw);
    $_SESSION['scp_relationship_raw'] = $raw;
    $_SESSION['scp_role'] = $role;
    return $role;
}

/**
 * Rol del usuario logueado ('monitor' | 'laico' | '' …). Filtrable.
 */
function sticpa_get_comunica_role()
{
    // Detección perezosa: si la sesión ya estaba abierta antes de tener esta lógica
    // (o el login no lo calculó), lo resolvemos al vuelo y lo cacheamos en sesión.
    if (!isset($_SESSION['scp_role']) && !empty($_SESSION['scp_user_id']) && class_exists('SugarRestApiCall')) {
        $module = $_SESSION['scp_module'] ?? 'Contacts';
        $entry = new stdClass();
        $entry->id = $_SESSION['scp_user_id'];
        $entry->name_value_list = new stdClass();
        sticpa_store_comunica_role($entry, $module);
    }
    $role = isset($_SESSION['scp_role']) ? $_SESSION['scp_role'] : '';
    return apply_filters('sticpa_comunica_role', $role);
}

/** ¿El usuario logueado tiene el rol indicado? */
function sticpa_is_role($role)
{
    return sticpa_get_comunica_role() === $role;
}

/**
 * AUDIENCIA de la pantalla de datos ("Mis datos"): de quién es la ficha que se
 * está viendo/editando. Determina título, textos y qué secciones se muestran
 * (pages/single_stic_comunica_perfil.php). Valores:
 *
 *   'participante' → un FAMILIAR está viendo a un menor/participante a su
 *                    cargo ("Sus datos"). A futuro tendrá campos propios
 *                    (autorizaciones de menores, etc.).
 *   'familiar'     → el familiar se está viendo A SÍ MISMO y NO es miembro
 *                    del MCM (sin rol monitor/laico): datos básicos, sin MCM.
 *   'miembro'      → persona con rol del MCM viendo su propia ficha (monitor,
 *                    laico… y también el caso "adulto que es familiar Y
 *                    miembro a la vez": si tiene rol, manda el rol).
 *
 * Filtrable con 'sticpa_profile_audience' para casos especiales.
 */
function sticpa_profile_audience()
{
    $audience = 'miembro';
    $enFamilia = function_exists('sticpa_is_familia') && sticpa_is_familia();
    if ($enFamilia && isset($_SESSION['scp_tutor_user_id']) && empty($_SESSION['scp_tutor_is_user'])) {
        // Viendo a un participante a cargo (no a sí mismo).
        $audience = 'participante';
    } elseif ($enFamilia && !sticpa_get_comunica_role()) {
        // El familiar en su propia ficha, sin rol de miembro del MCM.
        $audience = 'familiar';
    }
    return apply_filters('sticpa_profile_audience', $audience);
}

/**
 * ¿Le falta al monitor/a subir su Certificado de Delitos Sexuales?
 * Solo cuando eligió el modo MANUAL (ajmcm_aut_del_sex_c = 0) y el flag de
 * archivo subido (ajmcm_cert_del_sex_c) está vacío. Sirve para pintar la
 * alerta de la home y de la sección Monitor/a.
 *
 * @param object|null $data name_value_list ya cargado (evita otra llamada API);
 *                          si es null, se consultan solo los 2 campos al CRM.
 */
function sticpa_monitor_ds_pending($data = null)
{
    if (sticpa_get_comunica_role() !== 'monitor') {
        return false;
    }
    if ($data === null) {
        if (empty($_SESSION['scp_user_id']) || !class_exists('SugarRestApiCall')) {
            return false;
        }
        $detail = SugarRestApiCall::getObjSCP()->getRecordDetail(
            $_SESSION['scp_user_id'],
            'Contacts',
            array('id', 'ajmcm_aut_del_sex_c', 'ajmcm_cert_del_sex_c')
        );
        $data = $detail->entry_list[0]->name_value_list ?? null;
        if ($data === null) {
            return false;
        }
    }
    $aut = isset($data->ajmcm_aut_del_sex_c->value) ? (string) $data->ajmcm_aut_del_sex_c->value : '';
    $cert = isset($data->ajmcm_cert_del_sex_c->value) ? (string) $data->ajmcm_cert_del_sex_c->value : '';
    return $aut === '0' && ($cert === '' || $cert === '0');
}

/**
 * HTML de la alerta "te falta el certificado de delitos sexuales" (home y
 * sección Monitor/a). $withCta añade el botón hacia la sección Monitor/a.
 */
function sticpa_ds_pending_alert_html($withCta = true)
{
    $cta = '';
    if ($withCta) {
        $cta = "<a class='stic-alert-cta' href='?internalpage=single_stic_comunica_monitor'>"
            . __('Subir certificado', 'sticpa')
            . "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'><path d='M5 12h14'/><path d='m13 6 6 6-6 6'/></svg></a>";
    }
    return "
    <div class='stic-alert stic-alert--warning' role='alert'>
        <span class='stic-alert-ico' aria-hidden='true'>
            <svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M10.3 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.7 3.86a2 2 0 0 0-3.4 0Z'/><path d='M12 9v4'/><path d='M12 17h.01'/></svg>
        </span>
        <span class='stic-alert-body'>
            <p class='stic-alert-title'>" . esc_html__('Te falta subir tu Certificado de Delitos Sexuales', 'sticpa') . "</p>
            <p class='stic-alert-text'>" . esc_html__('Elegiste presentarlo de forma manual y todavía no lo tenemos. Es obligatorio para las actividades con menores: súbelo cuando puedas (se renueva cada septiembre).', 'sticpa') . "</p>
        </span>
        {$cta}
    </div>";
}
