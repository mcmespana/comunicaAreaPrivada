<?php

$pageSettings['fileName'] = basename(__FILE__, ".php");

// Getting Registrations of current user
$parentModule = 'Contacts';
$relationship = 'stic_registrations_contacts';
$params = array(
    'module_name' => $parentModule,
    "module_id" => $_SESSION['scp_user_id'], //Do not touch
    "link_field_name" => $relationship,
    "related_module_query" => "(status = 'confirmed')", //sql where conditions
    // "related_module_query" => "(end_date is null OR end_date >curdate())", //sql where conditions
    "related_fields" => array('id'), //Do not touch
    "related_module_link_name_to_fields_array" => array(),
    "deleted" => 0, //show or not deleted elements (usually 0)
    "order_by" => "",
    "offset" => "",
    "limit" => 0,
);

$getRelatedRegistrations = $objSCP->getRelatedElementsForLoggedUser($params);
$availableSessions = array();
$sessionIds = array();

// Get the Sessions related to the Events of the Registrations
if (is_array($getRelatedRegistrations)){
    foreach($getRelatedRegistrations as $element) {
        $parentModule = 'stic_Registrations';
        $relationship = 'stic_registrations_stic_events';
        $params = array(
            'module_name' => $parentModule,
            "module_id" => $element->name_value_list->id->value, //Do not touch
            "link_field_name" => $relationship,
            // "related_module_query" => "(status = 'confirmed')", //sql where conditions
            "related_fields" => array('id'), //Do not touch
            "related_module_link_name_to_fields_array" => array(),
            "deleted" => 0, //show or not deleted elements (usually 0)
            "order_by" => "",
            "offset" => "",
            "limit" => 0,
        );
        
        $getRelatedEvents = $objSCP->getRelatedElementsForLoggedUser($params);

        foreach($getRelatedEvents as $element) {
            $parentModule = 'stic_Events';
            $relationship = 'stic_sessions_stic_events';
            $params = array(
                'module_name' => $parentModule,
                "module_id" => $element->name_value_list->id->value, //Do not touch
                "link_field_name" => $relationship,
                // "related_module_query" => "(end_date is null OR end_date >curdate())", //sql where conditions
                "related_fields" => array('id', 'name', 'start_date', 'end_date', 'stic_sessions_stic_events_name', 'stic_sessions_stic_eventsstic_events_ida'), //Do not touch
                "related_module_link_name_to_fields_array" => array(),
                "deleted" => 0, //show or not deleted elements (usually 0)
                "order_by" => "",
                "offset" => "",
                "limit" => 0,
            );
            
            $getRelatedSessions = $objSCP->getRelatedElementsForLoggedUser($params);
            // Parsing the Sessions regarding Calendar format
            foreach($getRelatedSessions as $session) {
                $data = $session->name_value_list;
                if (!in_array($data->id->value, $sessionIds)) {
                    $availableSessions[] = array(
                        'event_id' => $element->name_value_list->id->value,
                        'id' => $data->id->value,
                        'title' => $data->name->value,
                        'start' => get_date_from_gmt($data->start_date->value),
                        'end' => get_date_from_gmt($data->end_date->value),
                        'color' => '#dc001b',
                        'module' => 'single_stic_sessions',
                    );
                    $sessionIds[] = $data->id->value;
                }
            }
        }
        
    }
}

// Getting Events with start_date greater than last month and end_date is null or before next year
// We filter them in case we load too many of them
$filterParam = "(stic_events.start_date BETWEEN DATE_ADD(curdate(), INTERVAL -3 MONTH) AND DATE_ADD(curdate(), INTERVAL 12 MONTH))";
$fields = array('id', 'name', 'type', 'start_date', 'end_date');

$getElements = $objSCP->getRecordsModule('stic_Events', $filterParam, $fields);
$availableEvents = array();
if (is_array($getElements)) {
    foreach ($getElements as $key => $event) {
        if ($id = $event->id) {
            $event = $event->name_value_list;
            $availableEvents[] = array(
                'id' => $event->id->value,
                'title' => $event->name->value,
                'start' => $event->start_date->value,
                'end' => $event->end_date->value,
                'module' => 'single_stic_events',
            );
        }
    }
}

// Merging both records that the Calendar will display: Sessions and Events
$availableItems = array_merge($availableSessions,$availableEvents);
$availableSessionsJson = json_encode($availableItems);

$current_url = explode('?', $_SERVER['REQUEST_URI'], 2);
$current_url = $current_url[0];

$lang = explode('_', get_locale())[0];

// Loading FullCalendar
$html .= "<div class='stic-entry-header'>
<h4>".__('Calendar', 'sticpa')."</h4>
<label>".__("Registered events' sessions appear in red.", 'sticpa')."</label>
<label>".__('Available events appear in blue.', 'sticpa')."</label>
<body>
    <div id='calendar'></div>
  </body>
  <script>

    document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            locale: '$lang',
            contentHeight:'auto',
            handleWindowResize:true,
            eventTimeFormat: {
                // like '14:30:00'
                hour: '2-digit',
                minute: '2-digit',
                meridiem: false
            },
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,listMonth'
            },
            events: $availableSessionsJson,
            eventClick: function(arg) {
                window.location.assign(
                    '?internalpage='+arg.event.extendedProps.module+'&action=detail&id=' + arg.event.id
                );
            },
        });
        calendar.render();
    
    });

</script>";
