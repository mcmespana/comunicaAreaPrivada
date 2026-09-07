<?php
/**
 * MIS INSCRIPCIONES — listado.
 * ----------------------------------------------------------------------------
 * Ya NO usa makeList() ni DataTables. Una inscripción no se lee bien como
 * "ETIQUETA: valor", y sobre todo: la cápsula de fecha llevaba
 * `registration_date`, el día en que te apuntaste. A una familia eso le da
 * igual; lo que necesita saber es cuándo es la actividad. Y la acción
 * principal de cada fila era "Editar", que no es lo que nadie viene a hacer.
 *
 * Se pinta con sticpa_registrations_list_html() (inc/stic-registrations.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

switch (getDestinationModule()) {
    case 'Accounts':
        $relationship = 'stic_registrations_accounts';
        $parentModule = 'Accounts';
        break;
    case 'Contacts':
    default:
        $relationship = 'stic_registrations_contacts';
        $parentModule = 'Contacts';
        break;
}

$listTitle = __('Mis inscripciones', 'sticpa');

$getRelatedElements = $objSCP->getRelatedElementsForLoggedUser(array(
    'module_name' => $parentModule,
    'module_id' => $_SESSION['scp_user_id'],
    'link_field_name' => $relationship,
    'related_fields' => sticpa_registration_list_fields(),
    'related_module_link_name_to_fields_array' => array(),
    'deleted' => 0,
    'order_by' => '',
    'offset' => '',
    'limit' => 0,
));

// Etiquetas de los desplegables, tal y como están traducidas en el CRM (el
// valor crudo es un código tipo "Confirmed", que no se le enseña a nadie).
// Cacheada 6h, así que no añade una llamada por vista.
$definition = sticpa_cached_field_definition($objSCP, 'stic_Registrations', array('status', 'participation_type'));

$html .= "<div class='stic-entry-header'><h3>" . esc_html($listTitle) . "</h3></div>";
$html .= sticpa_registrations_list_html($getRelatedElements, $definition);
