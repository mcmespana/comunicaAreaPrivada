<?php
/**
 * Comunica — Datos de Laico/a (etapa, COM, grupo, asamblea).
 * Guardado: prefix_comunica_save_contact (inc/stic-action.php).
 */

$formSettings['moduleName'] = 'Contacts';
$formSettings['title'] = __('Datos de Laico/a', 'sticpa');
$formSettings['msg'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('Los datos se han guardado correctamente.', 'sticpa'));
$formSettings['msg'][] = array('value' => 'error', 'type' => 'error', 'msg' => __('Error al guardar los datos.', 'sticpa'));
$formSettings['submitButton']['save'] = __('Guardar', 'sticpa');
$formSettings['submitButtonActions']['save'] = array('onclick' => 'return verifyFormIsValid(this)');

$id = $_SESSION['scp_user_id'];
$data = $objSCP->getRecordDetail($id, $formSettings['moduleName'])->entry_list[0]->name_value_list;

$fieldList[] = array('name' => 'id', 'type' => 'hidden');

$fieldList[] = array('name' => 'mcm', 'type' => 'header', 'label' => __('MCM', 'sticpa'));
$fieldList[] = array('name' => 'ajmcm_etapa_c', 'required' => false);
$fieldList[] = array('name' => 'ajmcm_nivel_com_c', 'required' => false);
$fieldList[] = array('name' => 'ajmcm_ano_incorporacion_lc_c', 'required' => false, 'label' => __('Año de incorporación como Laico/a', 'sticpa'));
$fieldList[] = array('name' => 'ajmcm_panuelo_c', 'required' => false);
$fieldList[] = array('name' => 'ajmcm_tallas_c', 'required' => false);
$fieldList[] = array('name' => 'ajmcm_grupotemp_c', 'required' => false, 'label' => __('Grupo MCM', 'sticpa'));
$fieldList[] = array('name' => 'ajmcm_procendencia_c', 'required' => false, 'label' => __('MCM Local', 'sticpa'));

$fieldList[] = array('name' => 'asamblea', 'type' => 'header', 'label' => __('Asamblea', 'sticpa'));
$fieldList[] = array('name' => 'ajmcm_asamblea_movimiento_es_c', 'type' => 'textarea', 'required' => false, 'label' => __('Para mí el Movimiento es...', 'sticpa'));
$fieldList[] = array('name' => 'ajmcm_asamblea_responsabilid_c', 'type' => 'textarea', 'required' => false, 'label' => __('Responsabilidades asumidas en el MCM', 'sticpa'));

$formSettings['fileName'] = basename(__FILE__, ".php");
$html .= makeForm($fieldList, $formSettings, $data);
