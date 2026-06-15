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
