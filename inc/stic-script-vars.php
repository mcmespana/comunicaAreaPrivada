<?php
/**
 * This file contains common Strings used throught all the plugin
 */

function getSticScriptVars() {
    return array(
        'deleteConfirmation' => __('Are you sure you want to delete this record?', 'sticpa'),
        'deleteTitle' => __('Delete record', 'sticpa'),
        'deleteCancel' => __('Cancel', 'sticpa'),
        'deleteConfirmBtn' => __('Delete', 'sticpa'),
        'changeConfirmation' => __('Are you sure you want to change this value?', 'sticpa'),
        'wrongIban' => __('Wrong IBAN', 'sticpa'),
        'invalidElements' => __('There are invalid fields in your form. Please, correct them before submit.', 'sticpa'),
        'otherIdentificationType' => __('Identification type not validable', 'sticpa'),
        'enterValidIban' => __('Please, enter a valid IBAN number.', 'sticpa'), 
        'invalidDocumentNumber' => __('Please, enter a valid identification number.', 'sticpa'), 
        'reactivationConfirmation' => __('Si actualiza este registro dejará de estar marcado como programa a eliminar y pasará a status "mantener"', 'csme'),
        'recordatorioTrabajo' => __('Recordad que desde la general debéis realizar tres tareas: modificar si es necesario los datos de Mi perfil, responder el cuestionario de satisfacción en relación al año anterior, revisar y actualizar la lista y datos de los programas del año pasado.', 'csme'),
        'trabajoFinalizado' => __('Estás seguro que ya has completado todo el workplace necesario y no hay que modificar ya ningún dato?', 'csme'),
        // Objeto `language` de DataTables, definido UNA sola vez (plan 021; antes
        // se repetía inline en cada listado). Lo consume js/stic-init.js.
        // searchPlaceholder (plan 019): DataTables lo pone como placeholder del
        // buscador; el texto del label se oculta visualmente en custom-style §43.
        'dtLanguage' => array(
            'decimal' => '',
            'emptyTable' => __('No data available in table.', 'sticpa'),
            'info' => __('Showing _START_ to _END_ of _TOTAL_ entries', 'sticpa'),
            'infoEmpty' => __('Showing 0 to 0 of 0 entries', 'sticpa'),
            'infoFiltered' => __('(filtered from _MAX_ total entries)', 'sticpa'),
            'infoPostFix' => '',
            'thousands' => ',',
            'lengthMenu' => __('Show _MENU_ entries', 'sticpa'),
            'loadingRecords' => __('Loading...', 'sticpa'),
            'processing' => '',
            'search' => __('Search', 'sticpa'),
            'searchPlaceholder' => __('Buscar en la lista…', 'sticpa'),
            'zeroRecords' => __('No matching records found.', 'sticpa'),
            'paginate' => array(
                'first' => __('First', 'sticpa'),
                'last' => __('Last', 'sticpa'),
                'next' => __('Next', 'sticpa'),
                'previous' => __('Previous', 'sticpa'),
            ),
            'aria' => array(
                'sortAscending' => __(': activate to sort column ascending', 'sticpa'),
                'sortDescending' => __(': activate to sort column descending', 'sticpa'),
            ),
        ),
    );
}