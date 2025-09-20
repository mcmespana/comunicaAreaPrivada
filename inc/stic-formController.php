<?php
//debug($_REQUEST, 'REQUEST');
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
    } else {
        $type = $crmDefinition['type'] ?? null;
    }

    if (isset($field['required'])) {
        $required = $field['required'] === true ? 'required' : '';
    } else {
        $required = ($crmDefinition['required'] ?? null) == '1' ? 'required' : '';
    }
    $defaultValue = getFieldDefaultValue($field, $field['name'] ?? null, $data, $type);
    $attributes = processFieldAttributes($field['attributes'] ?? null, $action);
    $fieldActions = processFieldActions(isset($field['actions']) ? $field['actions'] : array(), $field['name'] ?? null);

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
    // TODO Use classes and polymorphism to avoid switch
    switch ($type) {
        case 'hidden':
            $html = "<span><input  class='input-text {$additionClasses}' maxlength='255' type='" . $type . "' name='" . $name . "' id='" . $name . "' value='" . $defaultValue . "'  /> </span>";
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
                <label>" . $label . "</label>
                <span><input " . $required . " " . $attributes . " class='input-text {$additionClasses}' maxlength='255' type='" . $type . "' name='" . $name . "' id='" . $name . "' value='" . $defaultValue . "'" . $fieldActions . "  /> </span>
            </li>";
            break;
        case 'datetimecombo':
        case 'datetime-local':
            if (!empty($defaultValue)) {
                $defaultValue = get_date_from_gmt($defaultValue);
                $html = "
                <li class='" . $required . "' " . ">
                    <label>" . $label . ":</label>
                    <span><input " . $required . " " . $attributes . " class='input-text {$additionClasses}' maxlength='255' type='datetime-local' name='" . $name . "' id='" . $name . "' value='" . $defaultValue . "'" . $fieldActions . "  /> </span>
                </li>";
            }
            
            break;
        case 'textarea':
            $html = "
            <li class='" . $required . "' " . ">
                <label>" . $label . ":</label>
                <span><textarea " . $required . " " . $attributes . " class='input-text {$additionClasses}' type='" . $type . "' name='" . $name . "' id='" . $name . "' " . $fieldActions . ">" . $defaultValue . "</textarea></span>
            </li>";
            break;
        case 'enum':
        case 'dynamicenum':
        case 'select':
            $html = "<li class='" . $required . "' " . ">
            <label>" . $label . ":</label>
            <span><select " . $required . " " . $attributes . " class='input-text {$additionClasses}' name='" . $name . "' id='" . $name . "' value='" . $defaultValue . "' " . $fieldActions . "/>";
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
            <label>" . $label . ":</label>
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
        $html .= "<input type='hidden'" . "' name='" . $name . "' id='" . $name . "' value='" . $defaultValue . "'  />";
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

            $formActions = processFormActions(isset($formSettings['submitButtonActions'][$key]) ? $formSettings['submitButtonActions'][$key] : array(), 'add-sign-up');
            $label = htmlentities($submitButton, ENT_QUOTES);
            $html .= "<input class='stic-button' type='".($formSettings['submitButtonType'][$key] ?? 'submit')."' id = 'add-sign-up' name='add-sign-up' {$formActions} value='{$label}' />         ";
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
