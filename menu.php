<?php

#########################################################
# Customize menu settings here                          #
#########################################################
function getSticMenuElements()
{

    $menuElements['list_stic_events'] = __('Events', 'sticpa');
    $menuElements['list_stic_registrations'] = __('Registrations', 'sticpa');
    $menuElements['list_stic_documents'] = __('Documents', 'sticpa');
    $menuElements['list_stic_payments'] = __('Payments', 'sticpa');
    // $menuElements['single_stic_unsubscribe'] = __('Unsubscribe', 'sticpa');
    $menuElements['single_stic_password_change'] = __('Change password', 'sticpa');
    $menuElements['single_stic_activities_calendar'] = __('Calendar', 'sticpa');

    // $menuElements['list_stic_relationships'] = __('Relationships with the organization', 'sticpa');
    // if (getDestinationModule() == 'Accounts') {
    //     $menuElements['list_stic_contacts'] = __('Organization contacts', 'sticpa');
    //     $menuElements['list_stic_member_organizations'] = __('Member organizations', 'sticpa');
    // }

    // $menuElements['list_stic_payment_commitments'] = __('Payment commitments', 'sticpa');
    // $menuElements['list_stic_sessions'] = __('Sessions', 'sticpa');
    // $menuElements['list_stic_attendances'] = __('Attendances', 'sticpa');

    // if (getDestinationModule() == 'Contacts') {
    //     $menuElements['list_stic_job_offers'] = __('Job offers', 'sticpa');
    //     $menuElements['list_stic_job_applications'] = __('Job applications', 'sticpa');
    //     $menuElements['single_stic_payment_form'] = __('Payment form', 'sticpa');
    // }

    // if (isset($_SESSION['scp_user_adult']) && !$_SESSION['scp_user_adult']) {
    //     $menuElements['single_stic_profile_selection'] = __('Profile selection', 'sticpa');
    // }
    // $menuElements['custom_html'] = __('Custom HTML', 'sticpa');

    $defaultMenuElement = 'list_stic_events';
    return array($menuElements, $defaultMenuElement);
}
#########################################################

function defaultMenuElement()
{
    global $defaultMenuElement;
    return $defaultMenuElement;
}

/**
 * Return html menu from  $menuElements
 */
function menu()
{
    global $defaultMenuElement;
    list($menuElements, $defaultMenuElement) = getSticMenuElements();
    $menu = '';
    $tutorIsUser = $_SESSION['scp_tutor_is_user'] ?? false;

    // Información del usuario
    if (isset($_SESSION['scp_tutor_user_contact_name']) && !$tutorIsUser) {
        $menu .= "<div class='stic-userinfo'>" . __('Welcome', 'sticpa') . ", <span class='stic-user-name'><a href='?internalpage=single_stic_tutor_profile'>" . $_SESSION['scp_tutor_user_contact_name'] . "</a></span>";
        $menu .= " - " . __('Profile selected', 'sticpa') . ": <a href='?internalpage=single_stic_profile'>" . $_SESSION['scp_user_contact_name'] . "</a></span>&nbsp;";
    } else {
        $loggedIn = false;
        if (isset($_REQUEST['internalpage']) || (isset($_SESSION['scp_user_adult']) && $_SESSION['scp_user_adult'])) {
            $loggedIn =  ", <span class='stic-user-name'><a href='?internalpage=single_stic_profile'>" . $_SESSION['scp_user_contact_name'] . "</a></span>";
        }
        $menu .= "<div class='stic-userinfo'>" . __('Welcome', 'sticpa') . $loggedIn;
    }
    $menu .= "<a class='stic-logout' href='?logout=true'> ( " . __('Exit', 'sticpa') . " )</a>";
    $menu .= "</div>";

    // Página actual (para resaltar el item activo). Sin internalpage => Inicio.
    $page = empty($_REQUEST['internalpage']) ? 'single_stic_home' : $_REQUEST['internalpage'];

    // ¿Mostramos los items? (usuario adulto o tutor con perfil elegido).
    $showItems = (isset($_SESSION['scp_tutor_user_contact_name']) || (isset($_SESSION['scp_user_adult']) && $_SESSION['scp_user_adult']));

    // Construimos la lista de items: Inicio + las secciones configuradas.
    $items = array('single_stic_home' => __('Inicio', 'sticpa'));
    foreach ($menuElements as $key => $value) {
        $items[$key] = __($value, 'sticpa');
    }

    // Etiqueta de la sección activa (se muestra en el botón hamburguesa en móvil).
    $currentLabel = isset($items[$page]) ? $items[$page] : __('Inicio', 'sticpa');

    // Contenedor general del área.
    $menu .= "<br><div class='stic-container'>";

    if ($showItems) {
        $iconFn = function_exists('sticpa_section_icon');
        $menu .= "<nav class='stic-nav' aria-label='" . esc_attr__('Navegación principal', 'sticpa') . "'>";

        // Botón hamburguesa (solo visible en móvil vía CSS).
        $menu .= "<button type='button' class='stic-nav-toggle' aria-expanded='false' aria-controls='stic-nav-list'>
                    <span class='stic-nav-toggle-bars' aria-hidden='true'><i></i><i></i><i></i></span>
                    <span class='stic-nav-toggle-label'>" . __('Menú', 'sticpa') . "</span>
                    <span class='stic-nav-toggle-current'>" . esc_html($currentLabel) . "</span>
                  </button>";

        // Lista de items con icono + texto.
        $menu .= "<ul class='stic-nav-list' id='stic-nav-list'>";
        foreach ($items as $key => $label) {
            $isActive = ($page == $key) ? 'current-menu-item stic-current-menu-item' : '';
            $icon = $iconFn ? sticpa_section_icon($key) : '';
            $menu .= "<li class='stic-nav-item " . $isActive . "'>
                        <a class='stic-nav-link' href='?internalpage=" . $key . "'>
                            <span class='stic-nav-ico'>" . $icon . "</span>
                            <span class='stic-nav-text'>" . esc_html($label) . "</span>
                        </a>
                      </li>";
        }
        $menu .= "</ul>";
        $menu .= "</nav>";
    }

    $menu .= "<div class='stic-tab-content' style='width:100%;'>";
    return $menu;
}

