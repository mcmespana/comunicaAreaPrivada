<?php

#########################################################
# Customize menu settings here                          #
#########################################################
function getSticMenuElements()
{
    $menuElements = array();

    // --- Edición de datos (Comunica) — según el ROL del contacto ---
    // "Mis datos" es común a todos (incluye TODOS los datos generales: contacto,
    // dirección, MCM, salud, RGPD). Solo monitor/a tiene sección propia: el
    // formulario de laicos no pide nada que no sea general (ver
    // pages/single_stic_comunica_laico.php para el histórico de esa decisión).
    $role = function_exists('sticpa_get_comunica_role') ? sticpa_get_comunica_role() : '';
    $menuElements['single_stic_comunica_perfil'] = __('Mis datos', 'sticpa');
    if ($role === 'monitor') {
        $menuElements['single_stic_comunica_monitor'] = __('Monitor/a', 'sticpa');
    }

    // --- Secciones del área privada (las que ya había) ---
    $menuElements['list_stic_events'] = __('Eventos', 'sticpa');
    $menuElements['list_stic_registrations'] = __('Inscripciones', 'sticpa');
    $menuElements['list_stic_documents'] = __('Documentos', 'sticpa');
    $menuElements['list_stic_payments'] = __('Pagos', 'sticpa');
    $menuElements['single_stic_activities_calendar'] = __('Calendario', 'sticpa');
    $menuElements['single_stic_password_change'] = __('Cambiar contraseña', 'sticpa');

    // Opcionales (descomentar si se usan):
    // $menuElements['list_stic_relationships'] = __('Relaciones con la organización', 'sticpa');
    // $menuElements['list_stic_payment_commitments'] = __('Compromisos de pago', 'sticpa');
    // $menuElements['single_stic_unsubscribe'] = __('Darse de baja', 'sticpa');

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
 * ¿El perfil que ha accedido es de tipo "familia"? Solo en ese caso se muestran
 * el selector rápido de participante y la pantalla de selección.
 *
 * Se considera familia cuando hay participantes disponibles en sesión
 * (los carga pages/single_stic_profile_selection.php desde el CRM — relaciones
 * stic_Personal_Environment — o vía el filtro 'sticpa_familia_participants').
 * Mientras la parte de Sinergia no esté montada, puedes forzarlo con el filtro
 * 'sticpa_is_familia' o previsualizar con ?familia_demo=1 en la selección.
 */
function sticpa_is_familia()
{
    $isFamilia = !empty($_SESSION['scp_is_familia'])
        || (isset($_SESSION['scp_available_profiles']) && count((array) $_SESSION['scp_available_profiles']) > 0)
        || isset($_SESSION['scp_tutor_user_id']);
    return (bool) apply_filters('sticpa_is_familia', $isFamilia);
}

/**
 * Participantes disponibles para el selector rápido (id + name), cacheados en
 * sesión por la pantalla de selección. Devuelve array vacío si aún no se cargó.
 */
function sticpa_available_profiles()
{
    $profiles = isset($_SESSION['scp_available_profiles']) ? (array) $_SESSION['scp_available_profiles'] : array();
    return apply_filters('sticpa_available_profiles', $profiles);
}

/**
 * SELECTOR RÁPIDO DE PARTICIPANTE para la barra de navegación.
 * Botón con el participante activo + desplegable con todos los perfiles
 * (participantes + el propio familiar + enlace a la pantalla completa).
 * El cambio real lo hace el handler admin-post single_stic_profile_selection.
 */
function sticpa_participant_switcher_html()
{
    $profiles = sticpa_available_profiles();
    $familiarId = $_SESSION['scp_tutor_user_id'] ?? ($_SESSION['scp_user_id'] ?? '');
    $familiarName = $_SESSION['scp_tutor_user_contact_name'] ?? ($_SESSION['scp_user_contact_name'] ?? '');
    $activeId = $_SESSION['scp_user_id'] ?? '';
    $activeName = $_SESSION['scp_user_contact_name'] ?? '';

    $current_url = explode('?', $_SERVER['REQUEST_URI'], 2)[0];
    $defaultPage = defaultMenuElement();
    $selectUrl = function ($id, $name) use ($current_url, $familiarId, $familiarName, $defaultPage) {
        return esc_url(add_query_arg(array(
            'action' => 'single_stic_profile_selection',
            'profile_selected_id' => $id,
            'profile_selected_name' => rawurlencode($name),
            'scp_user_id' => $familiarId,
            'scp_user_contact_name' => rawurlencode($familiarName),
            'default_page' => $defaultPage,
            'scp_current_url' => $current_url,
        ), admin_url('admin-post.php')));
    };

    $chevron = "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'><path d='m6 9 6 6 6-6'/></svg>";
    $switching = esc_attr__('Cambiando de participante…', 'sticpa');

    $html = "<span class='stic-part-switch'>";
    $html .= "<button type='button' class='stic-part-switch-btn' aria-haspopup='true' aria-expanded='false' title='" . esc_attr__('Cambiar de participante', 'sticpa') . "'>";
    $html .= "<span class='stic-part-avatar' aria-hidden='true'>" . esc_html(sticpa_name_initial($activeName)) . "</span>";
    $html .= "<span class='stic-part-name'>" . esc_html($activeName) . "</span>" . $chevron;
    $html .= "</button>";

    $html .= "<span class='stic-part-switch-menu'>";
    $html .= "<span class='stic-part-switch-title'>" . esc_html__('Ver como…', 'sticpa') . "</span><ul>";
    foreach ($profiles as $profile) {
        $isActive = ($profile['id'] === $activeId) ? ' is-active' : '';
        $html .= "<li><a class='stic-part-option{$isActive}' href='" . $selectUrl($profile['id'], $profile['name']) . "' data-part-switch-to='{$switching}'>"
            . "<span class='stic-part-avatar' aria-hidden='true'>" . esc_html(sticpa_name_initial($profile['name'])) . "</span>"
            . "<span>" . esc_html($profile['name']) . "</span></a></li>";
    }
    // El propio familiar como opción ("mis propios datos").
    $selfActive = (!empty($_SESSION['scp_tutor_is_user'])) ? ' is-active' : '';
    $html .= "<li><a class='stic-part-option{$selfActive}' href='" . $selectUrl($familiarId, $familiarName) . "' data-part-switch-to='{$switching}'>"
        . "<span class='stic-part-avatar' aria-hidden='true'>" . esc_html(sticpa_name_initial($familiarName)) . "</span>"
        . "<span>" . esc_html($familiarName) . " <small>(" . esc_html__('yo', 'sticpa') . ")</small></span></a></li>";
    // Acceso a la pantalla completa de selección.
    $html .= "<li><a class='stic-part-option stic-part-option--all' href='?internalpage=single_stic_profile_selection'>"
        . esc_html__('Ver todos los perfiles…', 'sticpa') . "</a></li>";
    $html .= "</ul></span></span>";

    return $html;
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

    $hasTutorSession = isset($_SESSION['scp_tutor_user_contact_name']);
    if (($familiarName !== null || $hasTutorSession) && sticpa_is_familia()) {
        // FAMILIA: nombre del familiar arriba y, debajo, el SELECTOR RÁPIDO con
        // el participante activo (siempre visible: nunca hay duda de a quién ves).
        $topName = $familiarName !== null ? $familiarName : ($_SESSION['scp_tutor_user_contact_name'] ?? $participantName);
        $account .= "<span class='stic-account-name'><a href='?internalpage=single_stic_tutor_profile'>" . esc_html($topName) . "</a></span>";
        $account .= "<span class='stic-account-sub'>";
        $account .= "<span class='stic-account-tag'>" . __('Viendo a', 'sticpa') . "</span>";
        $account .= sticpa_participant_switcher_html();
        $account .= "</span>";
    } elseif ($familiarName !== null) {
        // Tutor sin selector (sin perfiles cargados): identidad fija como antes.
        $account .= "<span class='stic-account-name'><a href='?internalpage=single_stic_tutor_profile'>" . esc_html($familiarName) . "</a></span>";
        $account .= "<span class='stic-account-sub'>";
        $account .= "<span class='stic-account-tag'>" . __('Participante', 'sticpa') . "</span>";
        if ($participantName) {
            $account .= "<a class='stic-account-participant' href='?internalpage=single_stic_profile'>" . esc_html($participantName) . "</a>";
        }
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
    // "Salir" SIEMPRE arriba a la derecha (icono + texto en escritorio, solo icono en móvil).
    $actions = "<div class='stic-nav-actions'>";
    $actions .= "<a class='stic-iconbtn stic-logout' href='?logout=true' title='" . esc_attr__('Cerrar sesión', 'sticpa') . "' aria-label='" . esc_attr__('Cerrar sesión', 'sticpa') . "'>"
        . ($iconFn ? sticpa_icon('logout') : '') . "<span class='stic-logout-text'>" . __('Salir', 'sticpa') . "</span></a>";
    if ($showItems) {
        $actions .= "<button type='button' class='stic-nav-toggle' aria-expanded='false' aria-controls='stic-nav-list' aria-label='" . esc_attr__('Abrir menú', 'sticpa') . "'>
                        <span class='stic-nav-toggle-bars' aria-hidden='true'><i></i><i></i><i></i></span>
                        <span class='stic-nav-toggle-current'>" . esc_html($currentLabel) . "</span>
                     </button>";
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
        $menu .= "</ul>";
    }
    $menu .= "</nav>";

    $menu .= "<div class='stic-tab-content' style='width:100%;'>";
    return $menu;
}

