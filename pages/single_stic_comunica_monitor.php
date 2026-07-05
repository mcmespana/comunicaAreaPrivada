<?php
/**
 * Comunica — DATOS DE MONITOR/A.
 * ----------------------------------------------------------------------------
 * Replica FUNCIONALMENTE la parte específica de monitores del formulario
 * público (comunicaFormularios/monitores/monitores.html): trayectoria,
 * formación (con los mismos tooltips explicativos), voluntariado, certificado
 * de delitos sexuales (opción automática/manual) y subida de archivos.
 *
 * Los datos GENERALES (contacto, dirección, MCM, salud, RGPD) viven en
 * "Mis datos" (single_stic_comunica_perfil.php) — no se duplican aquí.
 *
 * Guardado: prefix_comunica_save_contact (inc/stic-action.php). Los archivos
 * (mat_file/dat_file/ds_file/form_file) se convierten en Documentos del CRM
 * vinculados al contacto + flag ajmcm_*_c (ver comunica_upload_certificate).
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

// Icono de "enlace externo" (mismo que en los formularios públicos).
$extIcon = "<svg aria-hidden='true' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round' style='width:14px;height:14px;vertical-align:-2px;'><path d='M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6'/><polyline points='15 3 21 3 21 9'/><line x1='10' y1='14' x2='21' y2='3'/></svg>";

$fieldList[] = array('name' => 'id', 'type' => 'hidden');

// ===== Trayectoria =====
$fieldList[] = array('name' => 'trayectoria', 'type' => 'header', 'label' => __('Trayectoria', 'sticpa'));
// En el CRM es una fecha, pero al usuario SOLO se le pide/enseña el año
// (yearOnly guarda internamente AAAA-01-01, nunca se muestra el 1 de enero).
$fieldList[] = array(
    'name' => 'ajmcm_monitor_desde_c', 'required' => false, 'yearOnly' => true,
    'label' => __('Monitor/a desde…', 'sticpa'),
    'help' => __('Año (aproximado) en el que empezaste como monitor/a Consolación.', 'sticpa'),
);
$fieldList[] = array(
    'name' => 'ajmcm_monitor_de_c', 'required' => false,
    'label' => __('Monitor/a de…', 'sticpa'),
    'help' => __('Si estás en varias etapas, marca aquella que te parece más relevante.', 'sticpa'),
);

// ===== Formación =====
$fieldList[] = array('name' => 'formacion', 'type' => 'header', 'label' => __('📙 Formación', 'sticpa'));
$fieldList[] = array(
    'name' => 'ajmcm_premonitores1_c', 'required' => false,
    'label' => __('Premonitores Año I', 'sticpa'),
    'help' => __('Primer año del curso interno de Premonitores/as Consolación.', 'sticpa'),
);
$fieldList[] = array(
    'name' => 'ajmcm_premonitores2_c', 'required' => false,
    'label' => __('Premonitores Año II', 'sticpa'),
    'help' => __('Segundo año del curso interno de Premonitores/as Consolación.', 'sticpa'),
);
$fieldList[] = array(
    'name' => 'ajmcm_premonitores_year_c', 'required' => false,
    'label' => __('Año premonitores', 'sticpa'),
    'placeholder' => 'AAAA',
    'attributes' => array('inputmode' => 'numeric', 'maxlength' => '4'),
);
$fieldList[] = array(
    'name' => 'ajmcm_mat_c', 'required' => false,
    'label' => __('MAT', 'sticpa'),
    'help' => __('MAT = Curso oficial de Monitor/a de Actividades de Tiempo Libre infantil y juvenil. No es el curso de premonitores.', 'sticpa'),
);
$fieldList[] = array('name' => 'ajmcm_mat_year_c', 'required' => false, 'label' => __('Año MAT', 'sticpa'));
$fieldList[] = array(
    'name' => 'ajmcm_dat_c', 'required' => false,
    'label' => __('DAT', 'sticpa'),
    'help' => __('DAT = El siguiente paso al MAT, el curso oficial de Director/a de Actividades de Tiempo Libre infantil y juvenil.', 'sticpa'),
);
$fieldList[] = array('name' => 'ajmcm_dat_year_c', 'required' => false, 'label' => __('DAT · Año y escuela', 'sticpa'));
$fieldList[] = array(
    'name' => 'ajmcm_fa_c', 'required' => false,
    'label' => __('FA', 'sticpa'),
    'help' => __('FA es el curso oficial reconocido por la administración pública de Formador/a de Animadores/as, para impartir cursos MAT o DAT entre otros.', 'sticpa'),
);
$fieldList[] = array('name' => 'ajmcm_fa_year_c', 'required' => false, 'label' => __('FA · Año y escuela', 'sticpa'));
$fieldList[] = array(
    'name' => 'ajmcm_alimentos_c', 'required' => false,
    'label' => __('Manipulación de alimentos', 'sticpa'),
);
$fieldList[] = array(
    'name' => 'ajmcm_congreso_monis_c', 'type' => 'multienum', 'required' => false,
    'label' => __('Congresos M+C', 'sticpa'),
    'help' => __('Congresos de Monitores Consolación realizados en los últimos años. Indica solo en los que hayas estado.', 'sticpa'),
);
$fieldList[] = array(
    'name' => 'ajmcm_formacion_academica_c', 'type' => 'textarea', 'required' => false,
    'label' => __('Formación académica', 'sticpa'),
);

// ===== Voluntariado =====
$fieldList[] = array('name' => 'voluntariado', 'type' => 'header', 'label' => __('Voluntariado', 'sticpa'));
$fieldList[] = array(
    'name' => 'voluntariado_nota', 'type' => 'note', 'classes' => 'stic-note-soft',
    'html' => __('⏳ <strong>Acuerdo de voluntariado:</strong> en tramitación, te lo enviaremos y lo firmarás en breve.', 'sticpa'),
);
$fieldList[] = array(
    'name' => 'ajmcm_vol_descripcion_c', 'type' => 'textarea', 'required' => false,
    'label' => __('Voluntariado: descripción de la actividad', 'sticpa'),
);
$fieldList[] = array(
    'name' => 'ajmcm_vol_programas_c', 'type' => 'textarea', 'required' => false,
    'label' => __('Voluntariado: programas', 'sticpa'),
);

// ===== Certificado de Delitos Sexuales =====
$fieldList[] = array('name' => 'delitos', 'type' => 'header', 'label' => __('Certificado de Delitos Sexuales', 'sticpa'));
$fieldList[] = array(
    'name' => 'delitos_nota', 'type' => 'note',
    'html' => sprintf(
        __('La <strong>legislación de voluntariado y protección a menores</strong> nos exige mantener archivado cada año el %1$s.<br><br>Con el sistema <strong>COMUNICA</strong> gestionamos estos archivos de forma segura y digital. <strong>Tienes dos opciones para presentarlo cada curso:</strong><ul><li>💨 <strong>AUTOMÁTICO</strong>: autoriza al MCM a solicitar el certificado cada año por ti. Solo tienes que seguir una vez el %2$s para dar permiso usando la app móvil «Mi Carpeta Ciudadana». Son dos minutos y no tendrás que volverlo a hacer.</li><li>📥 <strong>MANUAL</strong>: descarga el certificado en PDF y súbelo tú mismo/a desde aquí. Tendrás que hacerlo cada mes de septiembre manualmente.</li></ul>', 'sticpa'),
        '<a href="https://www.aepd.es/preguntas-frecuentes/10-menores-y-educacion/FAQ-1053-sobre-certificado-negativo-de-delitos-de-naturaleza-sexual" target="_blank" rel="noopener">' . __('certificado negativo de delitos de naturaleza sexual', 'sticpa') . ' ' . $extIcon . '</a>',
        '<a href="https://comunica.movimientoconsolacion.com/monitoresDS" target="_blank" rel="noopener"><strong>' . __('paso a paso de la guía', 'sticpa') . '</strong> ' . $extIcon . '</a>'
    ),
);

// Tarjetas de opción: la elección se guarda en ajmcm_aut_del_sex_c (1 = automático,
// 0 = manual). El JS de abajo sincroniza el hidden y muestra el bloque que toque.
$autDelSex = isset($data->ajmcm_aut_del_sex_c->value) ? (string) $data->ajmcm_aut_del_sex_c->value : '';
$checkedAuto = $autDelSex === '1' ? 'checked' : '';
$checkedManual = $autDelSex === '0' ? 'checked' : '';
$fieldList[] = array(
    'name' => 'ds_option_row', 'type' => 'html',
    'html' => '
        <li class="stic-option-row">
            <div class="stic-option-grid">
                <label class="stic-option-card">
                    <input type="radio" name="ds_option" value="automatico" ' . $checkedAuto . ' />
                    <span class="stic-option-title">💨 ' . esc_html__('Automático', 'sticpa') . '</span>
                    <span class="stic-option-desc">' . esc_html__("Autorizaré al MCM en la app 'Mi Carpeta Ciudadana'.", 'sticpa') . '</span>
                </label>
                <label class="stic-option-card">
                    <input type="radio" name="ds_option" value="manual" ' . $checkedManual . ' />
                    <span class="stic-option-title">📥 ' . esc_html__('Manual', 'sticpa') . '</span>
                    <span class="stic-option-desc">' . esc_html__('Envío aquí el certificado y lo renovaré cada septiembre.', 'sticpa') . '</span>
                </label>
            </div>
            <small class="stic-field-hint" id="ds-choice-hint">' . esc_html__('Debes elegir Automático o Manual.', 'sticpa') . '</small>
            <input type="hidden" name="ajmcm_aut_del_sex_c" id="ajmcm_aut_del_sex_c" value="' . esc_attr($autDelSex) . '" />
        </li>',
);
// Bloque AUTOMÁTICO: enlace al tutorial.
$fieldList[] = array(
    'name' => 'ds_auto_block', 'type' => 'html',
    'html' => '
        <li class="stic-option-row" id="ds-auto-block" style="display:none;">
            <a class="stic-legal-link" href="https://comunica.movimientoconsolacion.com/monitoresDS" target="_blank" rel="noopener">'
                . esc_html__("Ver tutorial para autorizarnos en 'Mi Carpeta Ciudadana'", 'sticpa') . ' ' . $extIcon . '
            </a>
        </li>',
);

// Bloque MANUAL: subida del certificado + enlace al trámite oficial.
$uploadField = function ($name, $label, $current, $extraHtml = '') {
    $uploaded = !empty($current);
    // Con archivo ya subido: badge verde + enlace pequeño para revisarlo en
    // la sección Documentos (donde se puede ver/descargar el archivo real).
    $state = $uploaded
        ? '<span class="stic-file-uploaded-badge"><svg class="stic-icon-checkmark" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>' . __('Ya subido', 'sticpa') . '</span>'
            . '<a class="stic-mini-link" href="?internalpage=list_stic_documents">' . __('Revisarlo en Documentos →', 'sticpa') . '</a>'
        : '';
    // Si ya hay archivo, el mensaje invita a SUSTITUIRLO; si no, a subirlo.
    $hint = $uploaded
        ? __('Ya tienes un archivo guardado. Sube uno nuevo para sustituirlo.', 'sticpa')
        : __('Formatos: pdf, jpg, png · Tamaño máximo: 6MB · Puedes subirlo ahora o más adelante.', 'sticpa');
    return array(
        'name' => $name, 'type' => 'html',
        'html' => '
            <li class="' . ($uploaded ? 'stic-has-file' : '') . '" ' . ($name === 'ds_file' ? 'id="ds-manual-block" style="display:none;"' : '') . '>
                <label for="' . esc_attr($name) . '">' . esc_html($label) . '</label>
                ' . $state . '
                <small class="stic-field-hint">' . esc_html($hint) . '</small>
                <span><input type="file" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" accept="application/pdf,image/jpeg,image/png"></span>
                ' . $extraHtml . '
            </li>',
    );
};
$dsTramiteLink = '<a class="stic-legal-link" style="margin-top:0.7rem;" href="https://sede.mjusticia.gob.es/es/tramites/certificado-registro-central" target="_blank" rel="noopener">'
    . esc_html__('Ir al trámite del Ministerio de Justicia', 'sticpa') . ' ' . $extIcon . '</a>';
$fieldList[] = $uploadField('ds_file', __('Subir Certificado de Delitos Sexuales', 'sticpa'), $data->ajmcm_cert_del_sex_c->value ?? '', $dsTramiteLink);

// ===== Archivos (títulos y otros certificados) =====
$fieldList[] = array('name' => 'certificados', 'type' => 'header', 'label' => __('Archivos', 'sticpa'));
$fieldList[] = array(
    'name' => 'certificados_nota', 'type' => 'note', 'classes' => 'stic-note-soft',
    'html' => __('💡 Si no puedes completar esta parte en este momento, puedes volver y subir los archivos más adelante.', 'sticpa'),
);
$fieldList[] = $uploadField('mat_file', __('Título MAT', 'sticpa'), $data->ajmcm_mat_file_c->value ?? '');
$fieldList[] = $uploadField('dat_file', __('Título DAT', 'sticpa'), $data->ajmcm_dat_file_c->value ?? '');
$fieldList[] = $uploadField('form_file', __('Otros certificados de formación', 'sticpa'), $data->ajmcm_cert_files_c->value ?? '');
$fieldList[] = array(
    'name' => 'form_file_nota', 'type' => 'html',
    'html' => '<li class="stic-form-note stic-note-soft" style="margin-top:-0.4rem;">'
        . esc_html__('Puedes subir otros certificados para archivarlos: manipulador/a de alimentos, formador/a de animadores/as… (opcional).', 'sticpa')
        . '</li>',
);

$formSettings['fileName'] = basename(__FILE__, ".php");

// Aviso arriba del todo si eligió modo manual y aún no ha subido el certificado.
if (function_exists('sticpa_monitor_ds_pending') && sticpa_monitor_ds_pending($data)) {
    $html .= sticpa_ds_pending_alert_html(false);
}

$html .= makeForm($fieldList, $formSettings, $data);

// Sincroniza las tarjetas Automático/Manual con el campo ajmcm_aut_del_sex_c y
// muestra solo el bloque de la opción elegida (tutorial o subida manual).
$html .= "
<script>
(function () {
    function sync() {
        var checked = document.querySelector(\"input[name='ds_option']:checked\");
        var hidden = document.getElementById('ajmcm_aut_del_sex_c');
        var manualBlock = document.getElementById('ds-manual-block');
        var autoBlock = document.getElementById('ds-auto-block');
        var mode = checked ? checked.value : '';
        var hint = document.getElementById('ds-choice-hint');
        if (hidden) { hidden.value = mode === 'automatico' ? '1' : (mode === 'manual' ? '0' : ''); }
        if (manualBlock) { manualBlock.style.display = mode === 'manual' ? '' : 'none'; }
        if (autoBlock) { autoBlock.style.display = mode === 'automatico' ? '' : 'none'; }
        if (hint) { hint.style.display = mode === '' ? '' : 'none'; }
    }
    var radios = document.querySelectorAll(\"input[name='ds_option']\");
    for (var i = 0; i < radios.length; i++) { radios[i].addEventListener('change', sync); }
    sync();
})();
</script>";
