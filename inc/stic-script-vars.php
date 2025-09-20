<?php
/**
 * This file contains common Strings used throught all the plugin
 */

function getSticScriptVars() {
    return array(
        'deleteConfirmation' => __('Are you sure you want to delete this record?', 'sticpa'),
        'changeConfirmation' => __('Are you sure you want to change this value?', 'sticpa'),
        'wrongIban' => __('Wrong IBAN', 'sticpa'),
        'invalidElements' => __('There are invalid fields in your form. Please, correct them before submit.', 'sticpa'),
        'otherIdentificationType' => __('Identification type not validable', 'sticpa'),
        'enterValidIban' => __('Please, enter a valid IBAN number.', 'sticpa'), 
        'invalidDocumentNumber' => __('Please, enter a valid identification number.', 'sticpa'), 
        'reactivationConfirmation' => __('Si actualiza este registro dejará de estar marcado como programa a eliminar y pasará a status "mantener"', 'csme'), 
        'recordatorioTrabajo' => __('Recordad que desde la general debéis realizar tres tareas: modificar si es necesario los datos de Mi perfil, responder el cuestionario de satisfacción en relación al año anterior, revisar y actualizar la lista y datos de los programas del año pasado.', 'csme'), 
        'trabajoFinalizado' => __('Estás seguro que ya has completado todo el workplace necesario y no hay que modificar ya ningún dato?', 'csme'), 
    );
}