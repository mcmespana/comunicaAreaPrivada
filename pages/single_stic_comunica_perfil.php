<?php
/**
 * Comunica — PANTALLA DE DATOS (la ficha que se está viendo/editando).
 * ----------------------------------------------------------------------------
 * Replica FUNCIONALMENTE los datos generales de los formularios públicos de
 * Comunica (comunicaFormularios/monitores/monitores.html y com-lc/laicos.html):
 * mismos campos, mismo orden, mismos tooltips ('help') y textos ('note').
 * Los campos del formulario de laicos son TODOS generales → viven aquí.
 *
 * ═══ AUDIENCIAS (sticpa_profile_audience, inc/stic-comunica-roles.php) ═══
 * La MISMA página sirve tres casos y decide título + secciones según quién es
 * la ficha. HOY el contenido es casi idéntico; el día que haya que divergir
 * (p. ej. autorizaciones de menores) SOLO hay que tocar $sectionsByAudience
 * y/o añadir bloques `in_array('xxx', $sections)` — no crear páginas nuevas:
 *
 *   'miembro'      → "Mis datos" (monitor/laico con su propia ficha; incluye
 *                    también al adulto que es familiar Y miembro a la vez).
 *   'participante' → "Sus datos" (un familiar viendo a un menor a su cargo).
 *                    Futuro: autorizaciones de menores (ajmcm_actividadesout_c,
 *                    ajmcm_menorwhatsapp_c, ajmcm_soloacasa_c…, ver CAMPOS.md).
 *   'familiar'     → "Mis datos" del familiar SIN rol de miembro (sin MCM).
 *                    Sus datos "administrativos" (pago) están en
 *                    single_stic_tutor_profile.php.
 *
 * NOTA deliberada: lo de la Asamblea de mayo de 2026 NO se replica (ya pasó).
 * Guardado: prefix_comunica_save_contact (inc/stic-action.php) — escribe sobre
 * $_SESSION['scp_user_id'] (= la ficha activa: participante o uno mismo).
 */

$role = function_exists('sticpa_get_comunica_role') ? sticpa_get_comunica_role() : '';
$audience = function_exists('sticpa_profile_audience') ? sticpa_profile_audience() : 'miembro';

// Secciones visibles por audiencia. Filtrable para casos especiales.
$sectionsByAudience = array(
    'miembro'      => array('identidad', 'contacto', 'direccion', 'foto', 'mcm', 'salud', 'rgpd'),
    'participante' => array('identidad', 'contacto', 'direccion', 'foto', 'salud', 'rgpd'), // TODO futuro: + 'autorizaciones_menor'
    'familiar'     => array('identidad', 'contacto', 'direccion', 'foto', 'salud', 'rgpd'),
);
$sections = apply_filters(
    'sticpa_perfil_sections',
    $sectionsByAudience[$audience] ?? $sectionsByAudience['miembro'],
    $audience
);

$formSettings['moduleName'] = 'Contacts';
$formSettings['title'] = ($audience === 'participante') ? __('Sus datos', 'sticpa') : __('Mis datos', 'sticpa');
$formSettings['msg'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('Los datos se han guardado correctamente.', 'sticpa'));
$formSettings['msg'][] = array('value' => 'error', 'type' => 'error', 'msg' => __('Error al guardar los datos.', 'sticpa'));
$formSettings['msg'][] = array('value' => 'error_type', 'type' => 'error', 'msg' => __('El formato del archivo no es válido.', 'sticpa'));
$formSettings['msg'][] = array('value' => 'error_size', 'type' => 'error', 'msg' => __('El archivo es demasiado grande.', 'sticpa'));
$formSettings['msg'][] = array('value' => 'error_upload', 'type' => 'error', 'msg' => __('Error al subir la imagen.', 'sticpa'));
$formSettings['submitButton']['save'] = __('Guardar', 'sticpa');
$formSettings['submitButtonActions']['save'] = array('onclick' => 'return verifyFormIsValid(this)');
$formSettings['attributes'] = 'enctype="multipart/form-data"';

$id = $_SESSION['scp_user_id'];
$data = $objSCP->getRecordDetail($id, $formSettings['moduleName'])->entry_list[0]->name_value_list;

// Nombre de pila de la ficha activa (para el saludo / "Sus datos de X").
$activeFullName = $_SESSION['scp_user_contact_name'] ?? '';
$activeFirstName = $activeFullName;
if (strpos($activeFullName, ',') !== false) {
    $parts = explode(',', $activeFullName, 2);
    $activeFirstName = trim($parts[1]) !== '' ? trim($parts[1]) : trim($parts[0]);
}

// Correo de la persona de referencia para solicitar cambios en los datos
// no editables. Ajustable por local mediante el filtro 'sticpa_mail_referencia'.
$mailReferencia = apply_filters('sticpa_mail_referencia', 'comunica@movimientoconsolacion.com');

// Icono de "enlace externo" para los botones legales (mismo de los forms públicos).
$extIcon = "<svg aria-hidden='true' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><path d='M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6'/><polyline points='15 3 21 3 21 9'/><line x1='10' y1='14' x2='21' y2='3'/></svg>";

$fieldList[] = array('name' => 'id', 'type' => 'hidden');

// ===== Identidad (solo lectura, como en los formularios públicos) =====
if (in_array('identidad', $sections, true)) {
    $fieldList[] = array('name' => 'datos_personales', 'type' => 'header', 'label' => __('Datos personales', 'sticpa'));
    // Saludo de bienvenida (mismo espíritu que el "Hola X, estos son los datos
    // que tenemos" del formulario público).
    $saludo = ($audience === 'participante')
        ? sprintf(__('Estos son los datos que tenemos de <strong>%s</strong>.', 'sticpa'), esc_html($activeFirstName))
        : sprintf(__('Hola <strong>%s</strong> 👋, estos son los datos que tenemos.', 'sticpa'), esc_html($activeFirstName));
    $fieldList[] = array('name' => 'saludo_nota', 'type' => 'note', 'classes' => 'stic-note-soft', 'html' => $saludo);
    $fieldList[] = array('name' => 'first_name', 'required' => false, 'attributes' => array('disabled' => 'disabled', 'autocomplete' => 'given-name'));
    $fieldList[] = array('name' => 'last_name', 'required' => false, 'attributes' => array('disabled' => 'disabled', 'autocomplete' => 'family-name'));
    $fieldList[] = array('name' => 'stic_identification_type_c', 'required' => false, 'attributes' => array('disabled' => 'disabled'));
    $fieldList[] = array('name' => 'stic_identification_number_c', 'required' => false, 'attributes' => array('disabled' => 'disabled'));
    $fieldList[] = array('name' => 'birthdate', 'required' => false, 'attributes' => array('disabled' => 'disabled', 'autocomplete' => 'bday'));
    $fieldList[] = array('name' => 'stic_gender_c', 'required' => false, 'label' => __('Género', 'sticpa'));

    // Aviso: los campos con asterisco morado no se pueden editar desde aquí.
    $fieldList[] = array(
        'name' => 'datos_personales_nota',
        'type' => 'html',
        'html' => '
            <li class="stic-readonly-note">
                <span>' . sprintf(
                    /* translators: 1: marca visual del campo bloqueado, 2: correo de referencia */
                    __('Los campos marcados con %1$s no se pueden editar desde el área privada. Si necesitas modificarlos, escribe a tu referente en %2$s.', 'sticpa'),
                    '<strong class="stic-readonly-note-mark">✱</strong>',
                    '<a href="mailto:' . esc_attr($mailReferencia) . '">' . esc_html($mailReferencia) . '</a>'
                ) . '</span>
            </li>',
    );
}

// ===== Contacto =====
if (in_array('contacto', $sections, true)) {
    $fieldList[] = array('name' => 'contacto', 'type' => 'header', 'label' => __('Datos de contacto', 'sticpa'));
    // Aviso del correo institucional según el caso:
    //  · monitor SIN correo @movimientoconsolacion.com → alerta (debería usarlo);
    //  · resto de miembros → recordatorio suave;
    //  · si el monitor ya lo tiene puesto → nada.
    $emailActual = strtolower(trim((string) ($data->email1->value ?? '')));
    $tieneCorreoMcm = strpos($emailActual, '@movimientoconsolacion.com') !== false;
    if ($role === 'monitor' && !$tieneCorreoMcm) {
        $fieldList[] = array(
            'name' => 'contacto_nota', 'type' => 'note', 'classes' => 'stic-note-warning',
            'html' => __('📣 Mejor utiliza tu correo <strong>@movimientoconsolacion.com</strong>: es el que usamos para todas las comunicaciones de monitores.', 'sticpa'),
        );
    } elseif ($audience === 'miembro' && $role !== 'monitor') {
        // El monitor con su correo MCM ya puesto no necesita recordatorio.
        $fieldList[] = array(
            'name' => 'contacto_nota', 'type' => 'note', 'classes' => 'stic-note-soft',
            'html' => __('Si tienes, usa el correo <strong>@movimientoconsolacion.com</strong>.', 'sticpa'),
        );
    }
    $fieldList[] = array(
        'name' => 'email1',
        'label' => __('Correo electrónico', 'sticpa'),
        'attributes' => array(
            'pattern' => "^[a-zA-Z0-9.!#$%&’*+/=?^_`{|}~-]+@[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)+$",
            'autocomplete' => 'email',
            'inputmode' => 'email',
        ),
    );
    $fieldList[] = array(
        'name' => 'phone_mobile',
        'label' => __('Móvil', 'sticpa'),
        'attributes' => array('autocomplete' => 'tel', 'inputmode' => 'tel'),
    );
    $fieldList[] = array(
        'name' => 'phone_other',
        'label' => __('Contacto de emergencia', 'sticpa'),
        'help' => __('Guardaremos el teléfono de un familiar con el que contactar en el (poco probable) caso de que pase algo grave.', 'sticpa'),
        'attributes' => array('inputmode' => 'tel'),
    );
}

// ===== Dirección =====
if (in_array('direccion', $sections, true)) {
    $fieldList[] = array('name' => 'direccion', 'type' => 'header', 'label' => __('Dirección', 'sticpa'));
    $fieldList[] = array('name' => 'primary_address_street', 'label' => __('Calle y número', 'sticpa'), 'attributes' => array('autocomplete' => 'street-address'));
    $fieldList[] = array('name' => 'primary_address_city', 'label' => __('Población', 'sticpa'), 'attributes' => array('autocomplete' => 'address-level2'));
    $fieldList[] = array('name' => 'primary_address_state', 'label' => __('Provincia', 'sticpa'));
    $fieldList[] = array(
        'name' => 'primary_address_postalcode',
        'label' => __('Código postal', 'sticpa'),
        'attributes' => array('inputmode' => 'numeric', 'maxlength' => '5', 'autocomplete' => 'postal-code'),
    );
    $fieldList[] = array('name' => 'stic_primary_address_region_c', 'required' => false, 'label' => __('Comunidad Autónoma', 'sticpa'));
}

// ===== Foto de perfil =====
if (in_array('foto', $sections, true)) {
    $fieldList[] = array('name' => 'foto', 'type' => 'header', 'label' => __('Foto', 'sticpa'));
    $photoHint = __('Formatos: jpg, jpeg, gif, png · Tamaño máximo: 6MB', 'sticpa');
    if (!empty($data->photo->value)) {
        // La foto se sirve por el endpoint stic_profile_photo (miniatura
        // cacheada en disco) en vez de incrustarla en base64 en el HTML.
        // El parámetro v (md5 del nombre de archivo) rompe la caché del
        // navegador cuando cambia la foto; si el endpoint devuelve error,
        // onerror pinta el placeholder.
        $photoSrc = admin_url('admin-post.php?action=stic_profile_photo&v=' . rawurlencode(substr(md5((string) $data->photo->value), 0, 8)));
        $photoLabel = __('Cambiar foto de perfil', 'sticpa');
        $photoOnError = ' onerror="this.onerror=null;this.src=\'' . esc_js(plugins_url('../images/profile_picture.jpg', __FILE__)) . '\'"';
    } else {
        $photoSrc = plugins_url('../images/profile_picture.jpg', __FILE__);
        $photoLabel = __('Sube una foto para conocernos mejor ✨', 'sticpa');
        $photoOnError = '';
    }
    $fieldList[] = array(
        'name' => 'photo', 'type' => 'html',
        'html' => '
            <li class="stic-photo-block">
                <img class="stic-profile-picture" src="' . esc_url($photoSrc) . '" alt="' . esc_attr__('Foto de perfil actual', 'sticpa') . '" width="150" height="150" decoding="async"' . $photoOnError . '/>
                <span class="stic-photo-main">
                    <label for="photo">' . esc_html($photoLabel) . '</label>
                    <small class="stic-field-hint">' . esc_html($photoHint) . '</small>
                    <span><input type="file" name="photo" id="photo" accept="image/*"></span>
                </span>
            </li>',
    );
}

// ===== MCM (solo miembros: monitor / laico; los campos son comunes a ambos) =====
if (in_array('mcm', $sections, true) && in_array($role, array('monitor', 'laico'), true)) {
    $fieldList[] = array('name' => 'mcm', 'type' => 'header', 'label' => __('MCM', 'sticpa'));
    $fieldList[] = array('name' => 'ajmcm_etapa_c', 'required' => false, 'label' => __('Etapa', 'sticpa'));
    $fieldList[] = array('name' => 'ajmcm_panuelo_c', 'required' => false, 'label' => __('Pañuelo', 'sticpa'));
    // Solo visible cuando Etapa = COM (lo gestiona bindConditionalFields en stic-ui.js).
    $fieldList[] = array(
        'name' => 'ajmcm_nivel_com_c', 'required' => false, 'label' => __('Nivel COM', 'sticpa'),
        'attributes' => array('data-visible-when' => 'ajmcm_etapa_c:COM'),
    );
    // Solo visible cuando Etapa = LC Incorporado.
    $fieldList[] = array(
        'name' => 'ajmcm_ano_incorporacion_lc_c', 'required' => false,
        'label' => __('Año incorporación LC', 'sticpa'),
        'help' => __('Indica el año en el que hiciste la incorporación / compromiso como Laico/a Consolación Incorporado. Si no lo sabes, aproxímalo.', 'sticpa'),
        'placeholder' => 'AAAA',
        'attributes' => array('data-visible-when' => 'ajmcm_etapa_c:lcincorporado', 'inputmode' => 'numeric', 'maxlength' => '4'),
    );
    $fieldList[] = array('name' => 'ajmcm_tallas_c', 'required' => false, 'label' => __('Talla de camiseta', 'sticpa'));
    $fieldList[] = array(
        'name' => 'ajmcm_grupotemp_c', 'required' => false,
        'label' => __('Grupo MCM', 'sticpa'),
        'help' => __("Indica el nombre de tu grupo del COM o de Laicos Consolación.<br>Si no tiene nombre, indica algunos datos que nos permitan identificarlo, por ejemplo 'Grupo de Laicos creado recientemente'.<br><br>Si no tienes grupo indica 'Sin grupo'.<br><strong>NO es el nombre del grupo del que eres monitor/a, es el de tu grupo de referencia.</strong>", 'sticpa'),
    );
    $fieldList[] = array('name' => 'ajmcm_procendencia_c', 'required' => false, 'label' => __('MCM Local', 'sticpa'));
    // En el CRM es una fecha, pero al usuario SOLO se le pide/enseña el año
    // (yearOnly guarda internamente AAAA-01-01; el 1 de enero nunca se muestra).
    $fieldList[] = array(
        'name' => 'ajmcm_mcm_desde_c', 'required' => false, 'yearOnly' => true,
        'label' => __('Pertenezco al MCM desde…', 'sticpa'),
        'help' => __('¡Más o menos! 😄 Escribe el año en el que comenzaste a participar en los grupos, tanto del MIC, como del COM como LC.<br><br>Si no lo recuerdas porque hace muuucho tiempo, una fecha aproximada 😉', 'sticpa'),
    );
}

// ===== Información sanitaria (mismos campos y ayudas que los forms públicos) =====
if (in_array('salud', $sections, true)) {
    $fieldList[] = array('name' => 'salud', 'type' => 'header', 'label' => __('Información sanitaria', 'sticpa'));
    $fieldList[] = array(
        'name' => 'salud_nota', 'type' => 'note', 'classes' => 'stic-note-soft',
        'html' => __('Te solicitamos algunos datos de salud que nos pueden ser útiles en las actividades presenciales. Trataremos estos datos con especial cuidado.', 'sticpa'),
    );
    $fieldList[] = array(
        'name' => 'ajmcm_descripcion_intoler_c', 'type' => 'textarea', 'required' => false,
        'label' => __('Intolerancias alimentarias', 'sticpa'),
        'help' => __('Alergias, intolerancias alimentarias u otros datos relacionados con la alimentación, para poder transmitirlos, por ejemplo, a un albergue que gestione las comidas de una actividad.<br>Si no tienes, déjalo en blanco.', 'sticpa'),
    );
    $fieldList[] = array(
        'name' => 'ajmcm_descripcion_allergies__c', 'type' => 'textarea', 'required' => false,
        'label' => __('Alergias', 'sticpa'),
        'help' => __('Alergias conocidas que no sean alimentarias.<br>Si no tienes, déjalo en blanco.', 'sticpa'),
    );
    $fieldList[] = array(
        'name' => 'ajmcm_descripcion_enfermed_c', 'type' => 'textarea', 'required' => false,
        'label' => __('Enfermedades', 'sticpa'),
        'help' => __('Si existen enfermedades crónicas o relevantes que pueda ser importante conocer en el desarrollo de las actividades.<br>Si no tienes, déjalo en blanco.', 'sticpa'),
    );
    $fieldList[] = array(
        'name' => 'ajmcm_descripcion_tratam_c', 'type' => 'textarea', 'required' => false,
        'label' => __('Tratamientos habituales', 'sticpa'),
        'help' => __('Si habitualmente se toma algún tipo de medicación, puedes indicarlo aquí.<br>Si no hay nada concreto o crónico, déjalo en blanco.', 'sticpa'),
    );
    $fieldList[] = array(
        'name' => 'ajmcm_descripcion_otros_c', 'type' => 'textarea', 'required' => false,
        'label' => __('Otros datos de salud', 'sticpa'),
        'help' => __('Cualquier otro dato de salud que no hayas reflejado anteriormente y consideres necesario compartir.<br>Si no tienes, déjalo en blanco.', 'sticpa'),
    );
}

// ===== Protección de datos (RGPD) — frase + SWITCH + enlace a las condiciones =====
// (El patrón antiguo botón-enlace + select Sí/No era horrible en móvil.)
// Switch encendido = acepta ('1'). El hidden garantiza que al guardar sin
// marcar se envía '0' (el checkbox, al ir después, gana cuando está marcado).
if (in_array('rgpd', $sections, true)) {
    $legalConsents = array(
        array(
            'name'      => 'ajmcm_acepta_lopd_c',
            'url'       => 'https://comunica.movimientoconsolacion.com/legal-rgpd',
            'statement' => __('Acepto las condiciones sobre protección de datos', 'sticpa'),
        ),
        array(
            'name'      => 'ajmcm_datossalud_c',
            'url'       => 'https://comunica.movimientoconsolacion.com/legal-salud',
            'statement' => __('Acepto las condiciones sobre el uso de mis datos de salud', 'sticpa'),
        ),
        array(
            'name'      => 'ajmcm_cesionimagenes_interne_c',
            'url'       => 'https://comunica.movimientoconsolacion.com/legal-imagenes',
            'statement' => __('Acepto las condiciones sobre la cesión de imágenes', 'sticpa'),
        ),
    );
    $fieldList[] = array('name' => 'rgpd', 'type' => 'header', 'label' => __('Autorizaciones legales', 'sticpa'));
    foreach ($legalConsents as $consent) {
        $current = isset($data->{$consent['name']}->value) ? (string) $data->{$consent['name']}->value : '';
        $checked = ($current === '1') ? 'checked' : '';
        $fieldList[] = array(
            'name' => $consent['name'] . '_row', 'type' => 'html',
            'html' => '
                <li class="stic-consent">
                    <span class="stic-consent-row">
                        <label for="' . esc_attr($consent['name']) . '">' . esc_html($consent['statement']) . '</label>
                        <input type="hidden" name="' . esc_attr($consent['name']) . '" value="0">
                        <input type="checkbox" id="' . esc_attr($consent['name']) . '" name="' . esc_attr($consent['name']) . '" value="1" ' . $checked . '>
                    </span>
                    <a class="stic-consent-link" href="' . esc_url($consent['url']) . '" target="_blank" rel="noopener">'
                        . esc_html__('Ver condiciones', 'sticpa') . ' ' . $extIcon . '
                    </a>
                </li>',
        );
    }
}

$formSettings['fileName'] = basename(__FILE__, ".php");
$html .= makeForm($fieldList, $formSettings, $data);
