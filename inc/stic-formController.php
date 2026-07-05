<?php
//debug($_REQUEST, 'REQUEST');

/**
 * ============================================================================
 *  MOTOR DE FORMULARIOS (makeForm / renderField)
 * ----------------------------------------------------------------------------
 *  Convierte un array $fieldList declarativo en HTML. Además de las claves
 *  clásicas (name, label, type, required, defaultValue, attributes,
 *  selectValues, actions, classes), cada campo admite:
 *
 *    'help'        => 'Texto…'   Botón ⓘ junto a la etiqueta con un tooltip
 *                                accesible (hover/focus/click). Úsalo para
 *                                explicar QUÉ se pide en el campo.
 *    'hint'        => 'Texto…'   Línea pequeña y gris DEBAJO del campo.
 *                                Úsalo para formatos ("AAAA", "máx. 6MB").
 *    'placeholder' => 'Texto…'   Atajo del attribute placeholder.
 *    'yearOnly'    => true       Para campos DATE del CRM que en realidad son
 *                                "un año": se muestra/edita SOLO el año (AAAA)
 *                                y al guardar se convierte en AAAA-01-01 (el
 *                                1 de enero es interno, nunca se enseña).
 *                                La conversión la hace sticpa_apply_year_only_fields()
 *                                en el handler (inc/stic-action.php) gracias a un
 *                                hidden stic_year_only_fields[] que emite el motor.
 *
 *  Tipos extra además de los básicos:
 *    'note'  => párrafo explicativo a ancho completo dentro de la sección
 *               (equivale al "helper-text" de los formularios Comunica).
 *               Usa 'html' => '…' para el contenido (admite HTML seguro).
 *    'html'  => HTML libre (tú controlas el <li>).
 *
 *  Campos condicionales (mostrar según el valor de otro campo): añade en
 *  'attributes' => array('data-visible-when' => 'otro_campo:valor1|valor2').
 *  El JS de js/stic-ui.js (bindConditionalFields) hace el resto.
 * ============================================================================
 */

/**
 * Botón de información ⓘ con tooltip accesible, para usar junto a etiquetas.
 * Replica el patrón "info-icon" de los formularios públicos de Comunica.
 */
function sticpa_field_help_html($text)
{
    if (trim((string) $text) === '') {
        return '';
    }
    return " <span class='stic-info' role='button' tabindex='0' aria-label='" . esc_attr__('Más información', 'sticpa') . "'>"
        . "<span class='stic-info-mark' aria-hidden='true'>?</span>"
        . "<span class='stic-info-tip' role='tooltip'>" . wp_kses_post($text) . "</span>"
        . "</span>";
}

// Prepare HTML form
function makeForm($fieldList, $formSettings, $data, $action = null)
{

    $objSCP = SugarRestApiCall::getObjSCP();

    $fields = array_column($fieldList, 'name');
    $fieldsDefinitionResults = $objSCP->getFieldDefinition($formSettings['moduleName'], $fields);
    $fieldsDefinitionResultsArray = json_decode(json_encode($fieldsDefinitionResults), true);
    $fieldsDefinition = $fieldsDefinitionResultsArray['module_fields'];

    $html = '';
    $html .= renderMessage($formSettings['msg']);
    $html .= renderFormTitle($formSettings['title']);
    $html .= renderFormHeader($formSettings['colClass'] ?? null, $formSettings['attributes'] ?? null);

    foreach ($fieldList as $value) {
        $html .= renderField($value, $data, $action, isset($value['name']) ? ($fieldsDefinition[$value['name']] ?? null) : array() );
    }

    if ($action === 'delete') {
        // If action is delete, fields will be disabled: we need hidden fields so values are sent from form
        $html .= renderHiddenData($fieldList, $data);
    }

    // If special action, it is rendered as hidden field so controller can receive it
    if ($action) {
        $html .= "<span><input type='hidden' name='stic-action' id='stic-action' value='{$action}' /> </span>";
    }
    if (isset($formSettings['linkButton'])) {
        $html .= renderLinkButton($formSettings);
    } else {
        $html .= renderSubmitButton($formSettings);
    }


    // Closes form
    $html .= "
            </form>
        </div>";
    return $html;
}

// Get default value
function getFieldDefaultValue($value, $name, $data, $type)
{
    //if it is a new element (no id) then use the default value in form definition
    if (empty($data->id->value)) {
        $defaultValue = $value['defaultValue'] ?? null;
    } elseif (isset($value['value'])) {
        $defaultValue = $value['value'];
    } else {
        $defaultValue = $data->$name->value ?? null;
    }
    if ($type === 'filler' || $type === 'info') {
        $defaultValue = $value['defaultValue'];
    }

    //set format
    if (isset($value['format'])) {
        $defaultValue = formatValue($defaultValue, $value['format']);
    }
    return $defaultValue;
}

// shows message
function renderMessage($messages)
{
    $html = '';
    foreach ($messages as $key => $value) {
        if (isset($_REQUEST['msg']) && $_REQUEST['msg'] == $value['value']) {
            $msg = "";
            if (isset($value['msg'])) {
                $msg = $value['msg'];
            }
            $html .= "<span style='transition: all 2s ease-in-out;' id='successMsg' class='{$value['type']} stic-msg'>{$msg}</span>";
        }
    }
    return $html;
}

// render title
function renderFormTitle($title)
{
    return "<h3>{$title}</h3>";
}
// render header
function renderFormHeader($colClass, $attributes)
{
    $colClass = $colClass ? $colClass : "stic-form-two-col";
    return "
    <div class='stic-form " . $colClass . "'>
        <form  " . $attributes . " flex id='stic-wp-pa' method='post' action='" . home_url() . "/wp-admin/admin-post.php'>
            <ul>";
}

// adds attributes to fields
function processFieldAttributes($attributes, $action)
{
    $attributesHtml = '';
    if ($attributes) {
        foreach ($attributes as $akey => $attribute) {
            $attributesHtml .= $akey . "='" . $attribute . "' ";
        }
    }
    if ($action === 'delete') {
        $attributesHtml .= 'disabled="true"';
    }
    return $attributesHtml;
}

// render a default field
function renderField($field, $data, $action, $crmDefinition)
{
    if (isset($field['label'])) {
        $label = $field['label'];
    } else {
        $label = isset($crmDefinition['label']) ? $crmDefinition['label'] : "";
    }

    if (isset($field['type'])) {
        //set short variables
        $type = $field['type'];
        if (empty($type)) {
            $type = 'text';
        }

        if ($type === 'div') {
            return processDiv($field);
        }
        if ($type === 'ul') {
            return processDiv($field, 'ul');
        }

        if ($type === 'html') {
            return $field['html'];
        }

        // Nota/explicación a ancho completo dentro de la sección (helper-text).
        if ($type === 'note') {
            $classes = isset($field['classes']) ? esc_attr($field['classes']) : '';
            return "<li class='stic-form-note {$classes}'>" . wp_kses_post($field['html'] ?? $field['label'] ?? '') . "</li>";
        }
    } else {
        $type = $crmDefinition['type'] ?? null;
    }

    if (isset($field['required'])) {
        $required = $field['required'] === true ? 'required' : '';
    } else {
        $required = ($crmDefinition['required'] ?? null) == '1' ? 'required' : '';
    }
    $defaultValue = getFieldDefaultValue($field, $field['name'] ?? null, $data, $type);

    // 'placeholder' como atajo (equivale a attributes => [placeholder => …]).
    if (isset($field['placeholder'])) {
        $field['attributes'] = ($field['attributes'] ?? array()) + array('placeholder' => $field['placeholder']);
    }
    $attributes = processFieldAttributes($field['attributes'] ?? null, $action);
    $fieldActions = processFieldActions(isset($field['actions']) ? $field['actions'] : array(), $field['name'] ?? null);

    // Botón ⓘ de ayuda pegado a la etiqueta + hint bajo el campo.
    if (!empty($field['help'])) {
        $label .= sticpa_field_help_html($field['help']);
    }
    if (!empty($field['hint'])) {
        $field['hintHtml'] = "<small class='stic-field-hint'>" . wp_kses_post($field['hint']) . "</small>";
    }

    // Campo "solo año": el CRM guarda una fecha (AAAA-01-01) pero al usuario
    // solo se le enseña y se le pide el AÑO. El hidden marca el campo para que
    // el handler lo reconvierta a fecha completa al guardar.
    if (!empty($field['yearOnly'])) {
        $type = 'text';
        if (is_string($defaultValue) && preg_match('/^(\d{4})/', $defaultValue, $m)) {
            $defaultValue = $m[1];
        }
        $attributes .= " inputmode='numeric' maxlength='4' pattern='[0-9]{4}' placeholder='AAAA' ";
        $field['hintHtml'] = ($field['hintHtml'] ?? '')
            . "<input type='hidden' name='stic_year_only_fields[]' value='" . esc_attr($field['name']) . "'>";
    }

    return getFieldHtml($label, $type, $required, $attributes, isset($field['classes']) ? $field['classes'] : null, $field['name'] ?? null, $defaultValue, $field, $fieldActions, $crmDefinition);
}

// render the div that contains the fields
function processDiv($field, $elem = 'div')
{
    if (array_key_exists('id', $field)) {
        $classes = array_key_exists('classes', $field) ? $field['classes'] : '';
        $html = "<{$elem} id='{$field['id']}' class='{$classes}'>";
        return $html;
    }
    return "</{$elem}>";
}

// transform the field in to html to be displayed in the form
function getFieldHtml($label, $type, $required, $attributes, $additionClasses, $name, $defaultValue, $value, $fieldActions, $crmDefinition)
{
    // Hint (línea de ayuda bajo el campo) y escapes seguros: los valores del CRM
    // pueden llevar comillas/apóstrofes ("C/ L'Horta") que rompían el atributo value.
    $hint = $value['hintHtml'] ?? '';
    $escValue = esc_attr((string) ($defaultValue ?? ''));
    $forAttr = $name ? " for='" . esc_attr($name) . "'" : '';

    // TODO Use classes and polymorphism to avoid switch
    switch ($type) {
        case 'hidden':
            $html = "<span><input  class='input-text {$additionClasses}' maxlength='255' type='" . $type . "' name='" . $name . "' id='" . $name . "' value='" . $escValue . "'  /> </span>";
            break;
        case 'header':
            $html = "
            </ul>
            <h5 class='{$additionClasses}' id='{$name}' " . $fieldActions . "> {$label}</h5>
            <ul>
            ";
            break;
        case 'subheader':
            $html = "
            <h5 class='{$additionClasses}' id='{$name}' " . $fieldActions . "> {$label}</h5>
            ";
            break;
        case 'varchar':
        case 'text':
        case 'name':
        case 'email':
        case 'date':
        case 'datetime':
        case 'number':
        case 'password':
        case 'decimal':
        case 'integer':
        case 'float':
        case 'phone':
            $html = "
            <li class='" . $required . "' " . ">
                <label{$forAttr}>" . $label . "</label>
                <span><input " . $required . " " . $attributes . " class='input-text {$additionClasses}' maxlength='255' type='" . $type . "' name='" . $name . "' id='" . $name . "' value='" . $escValue . "'" . $fieldActions . "  /> </span>
                {$hint}
            </li>";
            break;
        case 'datetimecombo':
        case 'datetime-local':
            if (!empty($defaultValue)) {
                $defaultValue = get_date_from_gmt($defaultValue);
                $html = "
                <li class='" . $required . "' " . ">
                    <label{$forAttr}>" . $label . ":</label>
                    <span><input " . $required . " " . $attributes . " class='input-text {$additionClasses}' maxlength='255' type='datetime-local' name='" . $name . "' id='" . $name . "' value='" . esc_attr($defaultValue) . "'" . $fieldActions . "  /> </span>
                    {$hint}
                </li>";
            }
            
            break;
        case 'textarea':
            $html = "
            <li class='" . $required . "' " . ">
                <label{$forAttr}>" . $label . ":</label>
                <span><textarea " . $required . " " . $attributes . " class='input-text {$additionClasses}' type='" . $type . "' name='" . $name . "' id='" . $name . "' " . $fieldActions . ">" . esc_textarea((string) ($defaultValue ?? '')) . "</textarea></span>
                {$hint}
            </li>";
            break;
        case 'enum':
        case 'dynamicenum':
        case 'select':
            $html = "<li class='" . $required . "' " . ">
            <label{$forAttr}>" . $label . ":</label>
            <span><select " . $required . " " . $attributes . " class='input-text {$additionClasses}' name='" . $name . "' id='" . $name . "' value='" . $escValue . "' " . $fieldActions . "/>";
            if (!isset($value['selectValues'])) {
                $list = array();
                foreach ($crmDefinition['options'] as $item) {
                    $list[$item['name']] = $item['value'];
                }
            } else {
                $list = $value['selectValues'];
            }
            foreach ($list as $skey => $svalue) {
                $defaultValue = $defaultValue === null ? '' : $defaultValue;
                if ($defaultValue === '' || $defaultValue === ' ') {
                    if ($skey === '' || $skey === ' ') {
                        $sel = 'Selected';
                    }
                    else {
                        $sel = '';
                    }
                } else {
                    if ($skey == $defaultValue) {
                        $sel = 'Selected';
                    } else {
                        $sel = '';
                    }
                }
                $html .= "<option value='" . $skey . "' label='" . $svalue . "' " . $sel . ">" . $svalue . "</option>";
            }
            $sel = "";
            $html .= "
                </select></span>
                {$hint}
            </li>";
            break;
        case 'bool';
            $html = "
            <li class='" . $required . "' " . ">
            <span><label>" . $label . "</label>
                <input " . $required . " " . $attributes . " class='{$additionClasses}' maxlength='255' type='checkbox' name='" . $name . "' id='" . $name . "' ". ($defaultValue ? " checked " : " ") . $fieldActions . "  /> </span>
            </li>";
            break;
        case 'radio':
            $html = "<li class='" . $required . "' " . ">
            <label>" . $label . ":</label>
            <div class='stic-check-group' id='{$name}'>";
            $defaultValue = $defaultValue === null ? '' : $defaultValue;
            foreach ($value['selectValues'] as $skey => $svalue) {
                $checked = $defaultValue == $skey ? 'checked' : '';
                $html .= "<div propi='{$defaultValue}' class='stic-check-container'><input class='stic-radio-input' type='radio' id='{$name}_{$skey}' name='{$name}' value='{$skey}' {$checked}><label class='stic-check-label' for='{$name}_{$skey}'>{$svalue}</label></div>";
            }
            $sel = "";
            $html .= "
                </div>
            </li>";
            break;
        case 'multienum';
        case 'selectMultiple':
            $html = "<li class='{$type} " . $required . "' " . ">
            <label{$forAttr}>" . $label . ":</label>
            <span><select multiple " . $required . " " . $attributes . " class='{$additionClasses}' name='" . $name . "[]' id='" . $name . "' value='" . $defaultValue . "' " . $fieldActions . "/>";
            $arrayValues = explode("^,^", $defaultValue);
            $arrayValues = str_replace("^", "", $arrayValues);
            if (!isset($value['selectValues'])) {
                $list = array();
                foreach ($crmDefinition['options'] as $item) {
                    $list[$item['name']] = $item['value'];
                }
            } else {
                $list = $value['selectValues'];
            }
            foreach ($list as $skey => $svalue) {
                $sel = in_array($skey, $arrayValues) ? 'Selected' : '';
                $html .= "<option value='" . $skey . "' label='" . $svalue . "' " . $sel . ">" . $svalue . "</option>";
            }
            $sel = "";
            $html .= "
                </select></span>
                {$hint}
            </li>";
            break;
        case 'readOnly':
            $html = "
            <li class=''>
                <label>" . $label . ":</label>
                <span class='{$additionClasses}' id='{$name}' {$fieldActions} > {$defaultValue} </span>
            </li>";
            break;
        case 'info':
            $html = "
            <li>
                <span class='{$additionClasses}' id='{$name}' {$fieldActions} > {$defaultValue}  </span>
            </li>";
            break;
        case 'filler':
            $html = "
            <li>
            </li>";
            break;
        case 'placeholder':
            break;
        case 'image':
            $html = "
            <li>
                <span class='{$additionClasses}' id='{$name}' {$fieldActions} > {$value}{$defaultValue}  </span>
            </li>";
            break;
        default:
            # code...
            break;
    }
    return $html ?? null;
}

// transform the data that will be hidden
function renderHiddenData($fieldList, $data)
{
    $html = '';
    foreach ($fieldList as $key => $value) {
        $name = $value['name'];
        $type = $value['type'] ?? null;
        if (empty($type)) {
            $type = 'text';
        }

        $defaultValue = getFieldDefaultValue($value, $name, $data, $type);
        $html .= "<input type='hidden' name='" . esc_attr($name) . "' id='" . esc_attr($name) . "' value='" . esc_attr((string) ($defaultValue ?? '')) . "'  />";
    }
    return $html;
}

// renders the submit button
function renderSubmitButton($formSettings)
{
    $html = '';
    $current_url = explode('?', $_SERVER['REQUEST_URI'], 2);
    $current_url = $current_url[0] . '?internalpage=' . $formSettings['fileName'];

    if (array_key_exists('buttons', $formSettings)) {
        return processButtons($formSettings['fileName'], $current_url, $formSettings['buttons']);
    }
    if (isset($formSettings['submitButton']) && is_array($formSettings['submitButton'])) {
        $html .= " </ul><ul class= 'stic-ctabs-list'><li class='stic-send'>
                    <input type='hidden' name='action' value='{$formSettings['fileName']}'>
                    <input type='hidden' name='scp_current_url' value='{$current_url}'>";

        foreach($formSettings['submitButton'] as $key => $submitButton) {

            $actions = isset($formSettings['submitButtonActions'][$key]) ? $formSettings['submitButtonActions'][$key] : array();
            // Una 'class' en las acciones se FUSIONA con stic-button (antes generaba
            // un segundo atributo class duplicado que el navegador ignoraba).
            $extraClass = '';
            if (isset($actions['class'])) {
                $extraClass = ' ' . $actions['class'];
                unset($actions['class']);
            }
            $formActions = processFormActions($actions, 'add-sign-up');
            $label = htmlentities($submitButton, ENT_QUOTES);
            $html .= "<input class='stic-button{$extraClass}' type='".($formSettings['submitButtonType'][$key] ?? 'submit')."' id = 'add-sign-up' name='add-sign-up' {$formActions} value='{$label}' />         ";
        }
        $html .= "</li></ul>";

    } else {
        $formActions = processFormActions(isset($formSettings['submitButtonActions']) ? $formSettings['submitButtonActions'] : array(), 'add-sign-up');

        if (array_key_exists('submitButton', $formSettings)) {
            $label = htmlentities($formSettings['submitButton'], ENT_QUOTES);
            $html .= " </ul><ul class= 'stic-ctabs-list'><li class='stic-send'>
                            <input type='hidden' name='action' value='{$formSettings['fileName']}'>
                            <input type='hidden' name='scp_current_url' value='{$current_url}'>
                            <input class='stic-button' type='submit' id='add-sign-up' name='add-sign-up' {$formActions} value='{$label}' />
                        </li>
                    </ul>";
        }
    }
    return $html;
    
}
// render buttons that links to an url
function renderLinkButton($formSettings)
{
    $html = '';
    $current_url = explode('?', $_SERVER['REQUEST_URI'], 2);
    $current_url = "location.href =". $current_url[0] . '?internalpage=' . $formSettings['linkButton']['fileName'];

    $html = " </ul><ul class= 'stic-ctabs-list'><li class='stic-send'>
                        <input type='hidden' name='action' value='{$formSettings['linkButton']['fileName']}'>
                    <input type='hidden' name='scp_current_url' value='{$current_url}'>
                    <span class='desc'><input class='stic-button' type='submit' id = 'add-sign-up' name='add-sign-up' value='{$formSettings['linkButton']['label']}' onclick='$current_url' /></span>
                </li>
            </ul>";
    return $html;
}

// renders the buttons that appear in the form
function processButtons($actionUrl, $currentUrl, $buttons)
{
    $html = " </ul><div>
    <input type='hidden' name='action' value='{$actionUrl}'>
    <input type='hidden' name='scp_current_url' value='{$currentUrl}'>
    <ul class='stic-button-list'>";
    foreach ($buttons as $button) {
        $formActions = processFormActions($button['actions'], 'add-sign-up');
        $html .= "<li class='stic-button'>
       <input class='stic-button' type='submit' value='{$button['label']}' {$formActions} />
        </li>";
    }

    $html .= '</ul></div>';
    return $html;
}

function processFieldActions($actions, $name)
{
    $html = '';
    foreach ($actions as $event => $function) {
        $html .= "{$event}=" . '"' . "{$function}(this)" . '" ';
    }
    return $html;
}

function processFormActions($actions, $name)
{
    $html = '';
    foreach ($actions as $event => $function) {
        $html .= "{$event}=" . '"' . "{$function}" . '" ';
    }
    return $html;
}
