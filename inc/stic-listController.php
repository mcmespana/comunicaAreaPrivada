<?php
// Prepare HTML list
function makeList($columnsList, $listSettings, $data, $extraActions = array())
{
    $objSCP = SugarRestApiCall::getObjSCP();

    $fields = array_column($columnsList, 'name');
    $fieldsDefinitionResults = $objSCP->getFieldDefinition($listSettings['moduleName'], $fields);
    $fieldsDefinitionResultsArray = json_decode(json_encode($fieldsDefinitionResults), true);

    $fieldsDefinition = $fieldsDefinitionResultsArray['module_fields'] ?? null;

    $html = "<link href='https://cdn.datatables.net/1.12.1/css/jquery.dataTables.min.css' rel='stylesheet'>";

    $current_url = explode('?', $_SERVER['REQUEST_URI'], 2);
    $current_url = $current_url[0];
    $html .= renderDeleteMessage($listSettings['msgDelete']);

    $html .= "<div class='stic-entry-header'>
    <h3>{$listSettings['title']}</h3>";


    if ((isset($_REQUEST['order_by'])) && (isset($_REQUEST['order']))) {
        $params['order_by'] = $_REQUEST['order_by'] . " " . $_REQUEST['order'];
    }

    if ($data != null) {

        //buid column headers
        $html .= "
        <div class='stic-table-responsive {$listSettings['fileName']}'>
            <table id='this-list' class='display' cellspacing='0' width='100%'>
                <thead>
                    <tr class='main-col'>";
        foreach ($columnsList as $key => $value) {
            if ($value['name'] == 'id') {continue;}
            if (isset($value['label'])) {
                $label = $value['label'];
            } else {
                $label = $fieldsDefinition[$value['name']]['label'];
            }
            $html .= "<th class='{$value['name']}'>{$label}</th>";
        }
        $html .= "<th>" . __('Actions', 'sticpa') . "</th>";
        $html .= "
    </tr>
    </thead>";

        //build table rows
        foreach ($data as $key => $row) {
            $html .= '<tr>';
            $rowObj = $row->name_value_list ?? null;
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
                $html .= "<td {$attributes}>" . $columnValue . "</td>";
            }
            $html .= buildActionsColumn($current_url, $row->id ?? null, $listSettings['actions'], $listSettings['linkDestination'] ?? null, isset($listSettings['linkDestinationLabel']) ? $listSettings['linkDestinationLabel'] : null, $extraActions[$key] ?? null);
            $html .= '</tr>';
        }
        $html .= '</table>';

    } else {
        $html .= __('There is no record.', 'sticpa');
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

    if ($listSettings['datatables']['value'] == true) {
        $listSettings['datatables']['jsonSettings']['language'] = array(
            "decimal" =>        "",
            "emptyTable" =>     __("No data available in table.", 'sticpa'),
            "info" =>           __("Showing _START_ to _END_ of _TOTAL_ entries", 'sticpa'),
            "infoEmpty" =>      __("Showing 0 to 0 of 0 entries", 'sticpa'),
            "infoFiltered" =>   __("(filtered from _MAX_ total entries)", 'sticpa'),
            "infoPostFix" =>    "",
            "thousands" =>      ",",
            "lengthMenu" =>    __("Show _MENU_ entries", 'sticpa'),
            "loadingRecords" => __("Loading...", 'sticpa'),
            "processing" =>     "",
            "search" =>         __("Search", 'sticpa'),
            "zeroRecords" =>    __("No matching records found.", 'sticpa'),
            "paginate" => array(
                "first" =>      __("First", 'sticpa'),
                "last" =>       __("Last", 'sticpa'),
                "next" =>       __("Next", 'sticpa'),
                "previous" =>   __("Previous", 'sticpa')
            ),
            "aria" => array(
                "sortAscending" =>  __(": activate to sort column ascending", 'sticpa'),
                "sortDescending" => __(": activate to sort column descending", 'sticpa'),
            )
        );
        $html .= "<script type='text/javascript'>";
        // $html .= "$(document).ready( function () {";
        $html .= "document.addEventListener('DOMContentLoaded', function(event) { ";
        $html .= "$('#this-list').DataTable(" . json_encode($listSettings['datatables']['jsonSettings']) . ");";
        $html .= "});";
        $html .= "</script>";
    }

    return $html;
}
// build buttons that appear in the last column of the table list
function buildActionsColumn($current_url, $id, $actions, $linkDestination, $linkDestinationLabel, $extraAction = '')
{
    if ($actions === null) {
        $html = "<td><a href='{$current_url}{$linkDestination}{$extraAction}&id={$id}'>{$linkDestinationLabel}</a></td>";
    } else {
        $html = '<td>';
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
            $html .= "<span  style='transition: all 2s ease-in-out;' id='successMsg' class='{$value['type']} stic-msg'>{$value['msg']}</span>";
        }
    }
    return $html;
}