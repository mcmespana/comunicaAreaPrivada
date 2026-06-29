<?php
/**
 * Comunica — Datos de Monitor/a (formación, legal, voluntariado, certificados).
 * Sube título MAT/DAT, certificado de delitos sexuales y otros como Documentos
 * del CRM vinculados al contacto.
 * Guardado: prefix_comunica_save_contact (inc/stic-action.php).
 */

$formSettings['moduleName'] = 'Contacts';
$formSettings['title'] = __('Datos de Monitor/a', 'sticpa');
$formSettings['msg'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('Los datos se han guardado correctamente.', 'sticpa'));
$formSettings['msg'][] = array('value' => 'error', 'type' => 'error', 'msg' => __('Error al guardar los datos.', 'sticpa'));
$formSettings['submitButton']['save'] = __('Guardar', 'sticpa');
$formSettings['submitButtonActions']['save'] = array('onclick' => 'return verifyFormIsValid(this)');
$formSettings['attributes'] = 'enctype="multipart/form-data"';

$id = $_SESSION['scp_user_id'];
$data = $objSCP->getRecordDetail($id, $formSettings['moduleName'])->entry_list[0]->name_value_list;

$fieldList[] = array('name' => 'id', 'type' => 'hidden');

// --- Trayectoria ---
$fieldList[] = array('name' => 'trayectoria', 'type' => 'header', 'label' => __('Trayectoria', 'sticpa'));
$fieldList[] = array('name' => 'ajmcm_monitor_desde_c', 'required' => false, 'label' => __('Monitor/a desde... (año aproximado)', 'sticpa'));
$fieldList[] = array('name' => 'ajmcm_monitor_de_c', 'required' => false, 'label' => __('Monitor/a de...', 'sticpa'));
$fieldList[] = array('name' => 'ajmcm_procendencia_c', 'required' => false, 'label' => __('MCM Local', 'sticpa'));

// --- Formación ---
$fieldList[] = array('name' => 'formacion', 'type' => 'header', 'label' => __('Formación', 'sticpa'));
$fieldList[] = array('name' => 'ajmcm_premonitores1_c', 'required' => false);
$fieldList[] = array('name' => 'ajmcm_premonitores2_c', 'required' => false);
$fieldList[] = array('name' => 'ajmcm_premonitores_year_c', 'required' => false);
$fieldList[] = array('name' => 'ajmcm_mat_c', 'required' => false);
$fieldList[] = array('name' => 'ajmcm_mat_year_c', 'required' => false);
$fieldList[] = array('name' => 'ajmcm_dat_c', 'required' => false);
$fieldList[] = array('name' => 'ajmcm_dat_year_c', 'required' => false);
$fieldList[] = array('name' => 'ajmcm_fa_c', 'required' => false);
$fieldList[] = array('name' => 'ajmcm_fa_year_c', 'required' => false);
$fieldList[] = array('name' => 'ajmcm_alimentos_c', 'required' => false, 'label' => __('Manipulación de alimentos', 'sticpa'));
$fieldList[] = array('name' => 'ajmcm_congreso_monis_c', 'type' => 'multienum', 'required' => false);
$fieldList[] = array('name' => 'ajmcm_formacion_academica_c', 'type' => 'textarea', 'required' => false);

// --- Voluntariado ---
$fieldList[] = array('name' => 'voluntariado', 'type' => 'header', 'label' => __('Voluntariado', 'sticpa'));
$fieldList[] = array('name' => 'ajmcm_vol_descripcion_c', 'type' => 'textarea', 'required' => false, 'label' => __('Voluntariado: descripción de la actividad', 'sticpa'));
$fieldList[] = array('name' => 'ajmcm_vol_programas_c', 'type' => 'textarea', 'required' => false, 'label' => __('Voluntariado: programas', 'sticpa'));

// --- Legal ---
$siNo = array('' => ' ', '1' => __('Sí', 'sticpa'), '0' => __('No', 'sticpa'));
$fieldList[] = array('name' => 'legal', 'type' => 'header', 'label' => __('Legal', 'sticpa'));
$fieldList[] = array(
    'name' => 'ajmcm_aut_del_sex_c', 'type' => 'select', 'required' => false,
    'label' => __('Autorizo a la entidad a obtener mi certificado de Delitos Sexuales (plataforma Te Autorizo)', 'sticpa'),
    'selectValues' => $siNo,
);

// --- Certificados (subida de archivos) ---
$fieldList[] = array('name' => 'certificados', 'type' => 'header', 'label' => __('Certificados', 'sticpa'));
$uploadField = function ($name, $label, $current) {
    $uploaded = !empty($current);
    $state = $uploaded ? '<span class="stic-file-uploaded-badge"><svg class="stic-icon-checkmark" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>' . __('Ya subido', 'sticpa') . '</span>' : '';
    // Si ya hay archivo, el mensaje invita a SUSTITUIRLO; si no, a subirlo.
    $hint = $uploaded
        ? __('Ya tienes un archivo guardado. Sube uno nuevo para sustituirlo.', 'sticpa')
        : __('(Formato: pdf, jpg, png — Tamaño máximo: 6MB)', 'sticpa');
    return array(
        'name' => $name, 'type' => 'html',
        'html' => '
            <li class="' . ($uploaded ? 'stic-has-file' : '') . '">
                <label>' . esc_html($label) . '</label>
                ' . $state . '
                <div style="font-size:11px; margin-bottom: 0.5rem; opacity: 0.75;">' . esc_html($hint) . '</div>
                <span><input type="file" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '"></span>
            </li>',
    );
};
$fieldList[] = $uploadField('mat_file', __('Título MAT', 'sticpa'), $data->ajmcm_mat_file_c->value ?? '');
$fieldList[] = $uploadField('dat_file', __('Título DAT', 'sticpa'), $data->ajmcm_dat_file_c->value ?? '');
$fieldList[] = $uploadField('ds_file', __('Certificado de Delitos Sexuales', 'sticpa'), $data->ajmcm_cert_del_sex_c->value ?? '');
$fieldList[] = $uploadField('form_file', __('Otros certificados', 'sticpa'), $data->ajmcm_cert_files_c->value ?? '');

$formSettings['fileName'] = basename(__FILE__, ".php");
$html .= makeForm($fieldList, $formSettings, $data);
