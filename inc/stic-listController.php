<?php
// Prepare HTML list
function makeList($columnsList, $listSettings, $data, $extraActions = array())
{
    $objSCP = SugarRestApiCall::getObjSCP();

    $fields = array_column($columnsList, 'name');
    // Definición de campos cacheada 6h — misma estrategia que makeForm
    // (ver sticpa_cached_field_definition en inc/stic-formController.php).
    $fieldsDefinition = sticpa_cached_field_definition($objSCP, $listSettings['moduleName'], $fields);

    // El CSS de DataTables ya no se inyecta aquí desde el CDN: está vendorizado
    // en css/vendor/ y se encola en sugar_crm_portal_style_and_script (plan 010).
    $html = '';

    $current_url = explode('?', $_SERVER['REQUEST_URI'], 2);
    $current_url = $current_url[0];
    $html .= renderDeleteMessage($listSettings['msgDelete']);

    $html .= "<div class='stic-entry-header'>
    <h3>{$listSettings['title']}</h3>";


    if ((isset($_REQUEST['order_by'])) && (isset($_REQUEST['order']))) {
        $params['order_by'] = $_REQUEST['order_by'] . " " . $_REQUEST['order'];
    }

    // Init de DataTables dirigida por datos (plan 021): en vez de un <script>
    // inline por listado, la configuración viaja en data-dt-settings y la lee
    // js/stic-init.js. El objeto `language` (localizado) NO va aquí: se define
    // UNA sola vez en getSticScriptVars() (inc/stic-script-vars.php).
    $dtAttr = '';
    if (!empty($listSettings['datatables']['value'])) {
        // El listado se pinta como TARJETAS (thead fuera de pantalla, §22.b del CSS):
        // la ordenación por cabecera es inalcanzable y sus <th tabindex=0> ocultos
        // atrapaban el foco del teclado en -9999px. Se desactiva salvo petición explícita.
        if (!isset($listSettings['datatables']['jsonSettings']['ordering'])) {
            $listSettings['datatables']['jsonSettings']['ordering'] = false;
        }
        $dtAttr = " data-dt-settings='" . esc_attr(json_encode((object) $listSettings['datatables']['jsonSettings'])) . "'";
    }

    if ($data != null) {

        //buid column headers
        $html .= "
        <div class='stic-table-responsive {$listSettings['fileName']}'>
            <table id='this-list' class='display' cellspacing='0' width='100%'{$dtAttr}>
                <thead>
                    <tr class='main-col'>";
        $labelMap = array();
        foreach ($columnsList as $key => $value) {
            if ($value['name'] == 'id') {continue;}
            if (isset($value['label'])) {
                $label = $value['label'];
            } else {
                $label = $fieldsDefinition[$value['name']]['label'];
            }
            $labelMap[$value['name']] = $label;
            $html .= "<th scope='col' class='{$value['name']}'>{$label}</th>";
        }
        $html .= "<th scope='col'>" . __('Actions', 'sticpa') . "</th>";
        $html .= "
    </tr>
    </thead>";

        //build table rows
        foreach ($data as $key => $row) {
            $html .= '<tr>';
            $rowObj = $row->name_value_list ?? null;
            $isFirstCol = true; // la primera columna (nombre) se pinta como cabecera de la tarjeta
            foreach ($columnsList as $column) {
                $colName = $column['name'];
                if ($colName == 'id') {continue;}
                if (isset($column['format'])) {
                    $columnValue = formatValue(isset($rowObj->$colName) ? $rowObj->$colName->value : null, $column['format']);
                } else {
                    $columnValue = isseT($rowObj->$colName) ? $rowObj->$colName->value : null;
                }
                if (isset($column['format']) && $column['format'] != 'translate') {
                    $fieldDefinion = $fieldsDefinition[$column['name']];
                    $type = $fieldDefinion['type'];
                    if ($type == 'enum' || $type == 'multienum' || $type == 'dynamicenum') {
                        $options = $fieldDefinion['options'];
                        $columnValue = $options[$columnValue]['value'];
                    }
                }
                $attributes = "";
                if (isset($column['attributes'])) {
                    $attributes = $column['attributes'];
                }
                $dataLabel = esc_attr($labelMap[$colName] ?? '');
                $cellClass = $isFirstCol ? 'stic-cell-title' : '';
                // Celdas sin valor (no la cabecera): se marcan para ocultarlas en la
                // tarjeta y no dejar filas "Etiqueta:" vacías. '0' SÍ es un valor.
                if (!$isFirstCol && trim((string) $columnValue) === '') {
                    $cellClass = trim($cellClass . ' stic-cell-empty');
                }
                $isFirstCol = false;
                $html .= "<td data-label='{$dataLabel}' class='{$cellClass}' {$attributes}>" . $columnValue . "</td>";
            }
            $html .= buildActionsColumn($current_url, $row->id ?? null, $listSettings['actions'], $listSettings['linkDestination'] ?? null, isset($listSettings['linkDestinationLabel']) ? $listSettings['linkDestinationLabel'] : null, $extraActions[$key] ?? null);
            $html .= '</tr>';
        }
        $html .= '</table>';

    } else {
        // Estado vacío con estilo de marca (antes era texto pelado).
        $html .= "
        <div class='stic-empty-state'>
            <span class='stic-empty-ico'>
                <svg viewBox='0 0 24 24' width='30' height='30' fill='none' stroke='currentColor' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'><path d='M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z'/><path d='M14 2v6h6'/><line x1='9' y1='13' x2='15' y2='13'/><line x1='9' y1='17' x2='13' y2='17'/></svg>
            </span>
            <p class='stic-empty-title'>" . __('Aquí no hay nada todavía', 'sticpa') . "</p>
            <p class='stic-empty-sub'>" . __('Cuando haya registros, aparecerán en esta sección.', 'sticpa') . "</p>
        </div>";
    }

    if (isset($listSettings['createButton']) && $listSettings['createButton']['value'] == true) {
        $newElementDestination = array_key_exists("linkDestination", $listSettings['createButton']) ? $listSettings['createButton']['linkDestination'] : $listSettings['linkDestination'];
        $html .= "<a href='{$newElementDestination}'> <input class='stic-button' type='submit' name='add-sign-up' value='{$listSettings['createButton']['label']}'></a>";
    }

    if (isset($listSettings['additionalButtons'])) {
        foreach ($listSettings['additionalButtons'] as $button) {
            $html .= "<a href='{$button['link']}'> <input class='stic-button' type='submit' name='add-sign-up' value='{$button['label']}'></a>";
        }
    }

    return $html;
}
// build buttons that appear in the last column of the table list
function buildActionsColumn($current_url, $id, $actions, $linkDestination, $linkDestinationLabel, $extraAction = '')
{
    $actionsLabel = esc_attr__('Actions', 'sticpa');
    if ($actions === null) {
        $html = "<td data-label='{$actionsLabel}' class='stic-cell-actions'><a href='{$current_url}{$linkDestination}{$extraAction}&id={$id}'>{$linkDestinationLabel}</a></td>";
    } else {
        $html = "<td data-label='{$actionsLabel}' class='stic-cell-actions'>";
        foreach ($actions as $action) {
            $html .= "<a href='{$current_url}{$action['link']}{$extraAction}&id={$id}'>{$action['label']}</a> ";
        }
        $html .= '</td>';
    }
    return $html;
}

// shows message
function renderDeleteMessage($messages)
{
    $html = '';
    foreach ($messages as $key => $value) {
        if (isset($_REQUEST['msgDelete']) && $_REQUEST['msgDelete'] == $value['value']) {
            $role = ($value['type'] === 'error') ? 'alert' : 'status';
            $html .= "<span id='successMsg' role='{$role}' class='{$value['type']} stic-msg'>{$value['msg']}</span>";
        }
    }
    return $html;
}