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
 * Primera inicial (en mayúscula) de un nombre para el avatar. Soporta el formato
 * "Apellidos, Nombre" (usa el nombre de pila) y nombres simples.
 */
function sticpa_name_initial($name)
{
    $name = trim((string) $name);
    if ($name === '') {
        return '·';
    }
    if (strpos($name, ',') !== false) {
        $parts = explode(',', $name, 2);
        $name = trim($parts[1]) !== '' ? trim($parts[1]) : trim($parts[0]);
    }
    $first = function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1);
    return function_exists('mb_strtoupper') ? mb_strtoupper($first, 'UTF-8') : strtoupper($first);
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
    $iconFn = function_exists('sticpa_icon');

    // ---- Identidad: distinguimos "familiar" (quien accede) y "participante"
    // (la persona cuya ficha se está viendo). Un familiar puede ver a varios
    // participantes y cambiar entre ellos.
    $familiarName = (isset($_SESSION['scp_tutor_user_contact_name']) && !$tutorIsUser)
        ? $_SESSION['scp_tutor_user_contact_name'] : null;
    $participantName = $_SESSION['scp_user_contact_name'] ?? null;

    // Página actual (para resaltar el item activo). Sin internalpage => Inicio.
    $page = empty($_REQUEST['internalpage']) ? 'single_stic_home' : $_REQUEST['internalpage'];

    // ¿Mostramos los items de navegación? (usuario adulto o familiar con perfil elegido).
    $showItems = (isset($_SESSION['scp_tutor_user_contact_name']) || (isset($_SESSION['scp_user_adult']) && $_SESSION['scp_user_adult']));

    // Items: Inicio + las secciones configuradas.
    $items = array('single_stic_home' => __('Inicio', 'sticpa'));
    foreach ($menuElements as $key => $value) {
        $items[$key] = __($value, 'sticpa');
    }
    $currentLabel = isset($items[$page]) ? $items[$page] : __('Inicio', 'sticpa');

    // Avatar (inicial del familiar si lo hay; si no, del participante).
    $avatarName = $familiarName !== null ? $familiarName : $participantName;
    $initial = sticpa_name_initial($avatarName);

    // ---- Bloque de identidad (dentro de la barra única) ----
    $account = "<div class='stic-account'>";
    $account .= "<span class='stic-avatar' aria-hidden='true'>" . esc_html($initial) . "</span>";
    $account .= "<span class='stic-account-info'>";

    if ($familiarName !== null) {
        // Familiar viendo a un participante.
        $account .= "<span class='stic-account-name'><a href='?internalpage=single_stic_tutor_profile'>" . esc_html($familiarName) . "</a></span>";
        $account .= "<span class='stic-account-sub'>";
        $account .= "<span class='stic-account-tag'>" . __('Participante', 'sticpa') . "</span>";
        if ($participantName) {
            $account .= "<a class='stic-account-participant' href='?internalpage=single_stic_profile'>" . esc_html($participantName) . "</a>";
        }
        $account .= "<a class='stic-switch' href='?internalpage=single_stic_profile_selection' title='" . esc_attr__('Cambiar de participante', 'sticpa') . "' aria-label='" . esc_attr__('Cambiar de participante', 'sticpa') . "'>" . ($iconFn ? sticpa_icon('switch') : '') . "</a>";
        $account .= "</span>";
    } else {
        // Usuario individual.
        $name = $participantName ? $participantName : __('Mi cuenta', 'sticpa');
        $account .= "<span class='stic-account-name'><a href='?internalpage=single_stic_profile'>" . esc_html($name) . "</a></span>";
        $account .= "<span class='stic-account-sub stic-account-sub--muted'>" . __('Tu área privada', 'sticpa') . "</span>";
    }
    $account .= "</span></div>";

    // Item de "Salir" reutilizable (icono + texto), con estilo de item de menú.
    $logoutItem = "<a class='stic-nav-link stic-nav-logout' href='?logout=true'>
                        <span class='stic-nav-ico'>" . ($iconFn ? sticpa_icon('logout') : '') . "</span>
                        <span class='stic-nav-text'>" . __('Salir', 'sticpa') . "</span>
                   </a>";

    // ---- Acciones de la barra superior ----
    // Con menú: solo la hamburguesa (en móvil). El "Salir" va como último item.
    // Sin menú (p. ej. selección de perfil): dejamos "Salir" accesible aquí.
    $actions = "<div class='stic-nav-actions'>";
    if ($showItems) {
        $actions .= "<button type='button' class='stic-nav-toggle' aria-expanded='false' aria-controls='stic-nav-list' aria-label='" . esc_attr__('Abrir menú', 'sticpa') . "'>
                        <span class='stic-nav-toggle-bars' aria-hidden='true'><i></i><i></i><i></i></span>
                        <span class='stic-nav-toggle-current'>" . esc_html($currentLabel) . "</span>
                     </button>";
    } else {
        $actions .= "<a class='stic-iconbtn stic-logout' href='?logout=true' title='" . esc_attr__('Cerrar sesión', 'sticpa') . "' aria-label='" . esc_attr__('Cerrar sesión', 'sticpa') . "'>"
            . ($iconFn ? sticpa_icon('logout') : '') . "</a>";
    }
    $actions .= "</div>";

    // ---- Componente único: barra de identidad + navegación ----
    $menu .= "<div class='stic-container'>";
    $menu .= "<nav class='stic-nav' aria-label='" . esc_attr__('Navegación principal', 'sticpa') . "'>";
    $menu .= "<div class='stic-nav-bar'>" . $account . $actions . "</div>";

    if ($showItems) {
        $menu .= "<ul class='stic-nav-list' id='stic-nav-list'>";
        foreach ($items as $key => $label) {
            $isActive = ($page == $key) ? 'current-menu-item stic-current-menu-item' : '';
            $icon = function_exists('sticpa_section_icon') ? sticpa_section_icon($key) : '';
            $menu .= "<li class='stic-nav-item " . $isActive . "'>
                        <a class='stic-nav-link' href='?internalpage=" . $key . "'>
                            <span class='stic-nav-ico'>" . $icon . "</span>
                            <span class='stic-nav-text'>" . esc_html($label) . "</span>
                        </a>
                      </li>";
        }
        // "Más": recoge los items que no caben en una sola línea (lo gestiona el JS).
        $menu .= "<li class='stic-nav-item stic-nav-more-wrap' hidden>
                    <button type='button' class='stic-nav-link stic-nav-more' aria-expanded='false' aria-haspopup='true' aria-label='" . esc_attr__('Más secciones', 'sticpa') . "'>
                        <span class='stic-nav-ico'>" . ($iconFn ? sticpa_icon('more') : '') . "</span>
                        <span class='stic-nav-text'>" . __('Más', 'sticpa') . "</span>
                    </button>
                    <div class='stic-nav-more-menu'><ul></ul></div>
                  </li>";
        $menu .= "<li class='stic-nav-item stic-nav-logout-item'>" . $logoutItem . "</li>";
        $menu .= "</ul>";
    }
    $menu .= "</nav>";

    $menu .= "<div class='stic-tab-content' style='width:100%;'>";
    return $menu;
}

