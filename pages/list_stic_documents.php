<?php

#########################################################
# List settings                                         #
#########################################################
switch (getDestinationModule()) {
    case 'Accounts':
        $parentModule = 'Accounts';
        break;
    case 'Contacts':
        $parentModule = 'Contacts';
        break;
}
$relationship = 'documents';
// NOTA: este listado ya NO usa makeList() ni DataTables. La acción de un
// documento es DESCARGARLO, y era lo único que no se podía hacer: el único
// botón, "Abrir", llevaba a un formulario de editar metadatos desde el que —tres
// toques más abajo— había otro botón de descarga. Se pinta con
// sticpa_documents_list_html() (inc/stic-documents.php).
$listTitle = __('Mis documentos', 'sticpa');
$fieldsToRetrieve = sticpa_document_list_fields();


#########################################################
# Params for the API query to retrieve related beans
#########################################################
//set the params for the API query
$params = array(
    'module_name' => $parentModule,
    "module_id" => $_SESSION['scp_user_id'], //Do not touch
    "link_field_name" => $relationship,
    "related_module_query" => "", //sql where conditions Attention, not all sql run ok
    "related_fields" => $fieldsToRetrieve, //Do not touch
    "related_module_link_name_to_fields_array" => array(),
    "deleted" => 0, //show or not deleted elements (usually 0)
    "order_by" => "",
    "offset" => "",
    "limit" => 0,
);
#########################################################

$getRelatedElements = $objSCP->getRelatedElementsForLoggedUser($params);

// Etiquetas traducidas del estado (definición cacheada 6h).
$definition = sticpa_cached_field_definition($objSCP, 'Documents', array('status_id'));

$html .= "<div class='stic-entry-header'><h3>" . esc_html($listTitle) . "</h3></div>";
$html .= sticpa_documents_list_html($getRelatedElements, $definition);

// Subir un documento es una acción real de esta pantalla, pero secundaria: se
// entra a bajarse algo mucho más a menudo que a subirlo.
$html .= "<div class='stic-rec-cta-row'>"
    . sticpa_record_action_html(array(
        'label' => __('Subir un documento', 'sticpa'),
        'url'   => '?internalpage=single_stic_documents&action=create',
    ))
    . "</div>";
