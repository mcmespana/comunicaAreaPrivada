<?php
/**
 * SELECCIÓN DE PARTICIPANTE (perfiles de familia).
 * ----------------------------------------------------------------------------
 * Un padre/madre/tutor entra con SU acceso y aquí elige a qué participante
 * quiere "ver": a partir de ese momento toda el área privada se muestra en
 * modo ese participante (inscripciones, documentos, pagos… son los suyos).
 * Puede volver a esta pantalla o usar el SELECTOR RÁPIDO de la barra superior
 * (menu.php) para cambiar en dos toques, sabiendo siempre a quién está viendo.
 *
 * CÓMO FUNCIONA EL CAMBIO DE MODO (importante para futuros agentes):
 *  · $_SESSION['scp_tutor_user_id'] / scp_tutor_user_contact_name = el FAMILIAR
 *    que ha iniciado sesión (fijo durante toda la sesión).
 *  · $_SESSION['scp_user_id'] / scp_user_contact_name = el PARTICIPANTE activo
 *    (es lo que leen todas las páginas list_* y single_*). Cambiar de participante
 *    es simplemente reescribir estas dos claves (lo hace el handler
 *    prefix_admin_single_stic_profile_selection en inc/stic-action.php).
 *  · $_SESSION['scp_available_profiles'] = participantes disponibles (id+name);
 *    lo rellena esta página y lo consume el selector rápido del menú.
 *
 * ORIGEN DE LOS PARTICIPANTES: relaciones stic_Personal_Environment del CRM
 * (tipos RELATIONSHIP_TUTOR_TYPES). En el CRM de Comunica esa parte AÚN NO
 * está montada, por eso:
 *  · el filtro 'sticpa_familia_participants' permite inyectarlos desde código, y
 *  · ?familia_demo=1 muestra participantes DE PRUEBA para revisar el diseño.
 * Cuando Sinergia tenga las relaciones, esta pantalla funcionará sola.
 */

$pageSettings['fileName'] = basename(__FILE__, ".php");

// ---------- 1) Recuperar participantes a cargo desde el CRM ----------
$parentModule = 'Contacts';
$relationship = 'stic_personal_environment_contacts_1';
$relationshipTypes = defined('RELATIONSHIP_TUTOR_TYPES') ? RELATIONSHIP_TUTOR_TYPES : array();

$availableContacts = array();
if (!empty($relationshipTypes)) {
    $query = "((stic_personal_environment.start_date <= DATE(NOW()) AND (stic_personal_environment.end_date >= DATE(NOW()) OR stic_personal_environment.end_date IS NULL)) AND stic_personal_environment.relationship_type in (";
    foreach ($relationshipTypes as $key => $type) {
        if ($key) {
            $query .= ',';
        }
        $query .= "'" . $type . "'";
    }
    $query .= "))";

    $getRelatedElements = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => $parentModule,
        'module_id' => $_SESSION['scp_tutor_user_id'] ?? $_SESSION['scp_user_id'],
        'link_field_name' => $relationship,
        'related_module_query' => $query,
        'related_fields' => array('id'),
        'related_module_link_name_to_fields_array' => array(),
        'deleted' => 0,
        'order_by' => '',
        'offset' => '',
        'limit' => 0,
    ));

    if (is_array($getRelatedElements)) {
        foreach ($getRelatedElements as $element) {
            $related = $objSCP->getRelatedElementsForLoggedUser(array(
                'module_name' => 'stic_Personal_Environment',
                'module_id' => $element->name_value_list->id->value,
                'link_field_name' => 'stic_personal_environment_contacts',
                'related_fields' => array('id', 'name'),
                'related_module_link_name_to_fields_array' => array(),
                'deleted' => 0,
                'order_by' => '',
                'offset' => '',
                'limit' => 0,
            ));
            if (isset($related[0]->name_value_list)) {
                $availableContacts[] = array(
                    'id' => $related[0]->name_value_list->id->value,
                    'name' => $related[0]->name_value_list->name->value,
                );
            }
        }
    }
}

// Punto de extensión: inyectar/transformar participantes sin tocar esta página.
$availableContacts = apply_filters('sticpa_familia_participants', $availableContacts);

// Modo DEMO (?familia_demo=1): participantes de prueba para revisar el diseño
// mientras la parte de relaciones familiares de Sinergia no está montada.
$isDemo = isset($_GET['familia_demo']) && empty($availableContacts);
if ($isDemo) {
    $availableContacts = array(
        array('id' => 'demo-participante-1', 'name' => 'Vega, Lucía'),
        array('id' => 'demo-participante-2', 'name' => 'Vega, Martín'),
        array('id' => 'demo-participante-3', 'name' => 'Vega, Carla'),
    );
}

// ---------- 2) Identidad del familiar y estado para el selector rápido ----------
if (isset($_SESSION['scp_tutor_user_id']) && $_SESSION['scp_tutor_user_id']) {
    $familiarId = $_SESSION['scp_tutor_user_id'];
    $familiarName = $_SESSION['scp_tutor_user_contact_name'];
} else {
    $familiarId = $_SESSION['scp_user_id'];
    $familiarName = $_SESSION['scp_user_contact_name'];
}
$activeId = $_SESSION['scp_user_id'] ?? '';

// El selector rápido del menú (menu.php) se alimenta de esta caché de sesión.
$_SESSION['scp_available_profiles'] = $availableContacts;
$_SESSION['scp_is_familia'] = count($availableContacts) > 0;

// ---------- 3) Render ----------
$current_url = explode('?', $_SERVER['REQUEST_URI'], 2);
$current_url = $current_url[0];
$defaultPage = defaultMenuElement();

/** URL que activa un perfil (handler admin-post single_stic_profile_selection). */
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

$goIcon = "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'><path d='M5 12h14'/><path d='m13 6 6 6-6 6'/></svg>";
$switchingText = esc_attr__('Cambiando de participante…', 'sticpa');

$html .= "<div class='stic-profiles'>";
$html .= "<div class='stic-entry-header'><h3>" . esc_html__('¿A quién quieres ver?', 'sticpa') . "</h3></div>";
$html .= "<p class='stic-profiles-lead'>" . esc_html__('Elige un participante para ver su información (inscripciones, documentos, pagos…). Podrás cambiar en cualquier momento desde la barra superior, y siempre verás de quién es lo que estás mirando.', 'sticpa') . "</p>";

if ($isDemo) {
    $html .= "<p class='stic-profiles-demo'>⚠️ " . esc_html__('Vista previa con datos de ejemplo (aún sin conexión con SinergiaCRM).', 'sticpa') . "</p>";
}

$html .= "<div class='stic-profiles-grid'>";

// Tarjetas de participantes a cargo.
foreach ($availableContacts as $contact) {
    $isActive = ($contact['id'] === $activeId) ? ' is-active' : '';
    $initial = function_exists('sticpa_name_initial') ? sticpa_name_initial($contact['name']) : '·';
    $html .= "
    <a class='stic-profile-card{$isActive}' href='" . $selectUrl($contact['id'], $contact['name']) . "' data-part-switch-to='{$switchingText}'>
        <span class='stic-profile-avatar' aria-hidden='true'>" . esc_html($initial) . "</span>
        <span class='stic-profile-name'>" . esc_html($contact['name']) . "</span>
        <span class='stic-profile-tag'>" . ($isActive ? esc_html__('Viéndolo ahora', 'sticpa') : esc_html__('Participante', 'sticpa')) . "</span>
        <span class='stic-profile-go'>" . ($isActive ? esc_html__('Seguir con este perfil', 'sticpa') : esc_html__('Ver como este participante', 'sticpa')) . " {$goIcon}</span>
    </a>";
}

// Tarjeta del propio familiar (sus datos, su medio de pago…).
$selfActive = ($familiarId === $activeId && !empty($_SESSION['scp_tutor_is_user'])) ? ' is-active' : '';
$selfInitial = function_exists('sticpa_name_initial') ? sticpa_name_initial($familiarName) : '·';
$html .= "
    <a class='stic-profile-card stic-profile-card--self{$selfActive}' href='" . $selectUrl($familiarId, $familiarName) . "' data-part-switch-to='{$switchingText}'>
        <span class='stic-profile-avatar' aria-hidden='true'>" . esc_html($selfInitial) . "</span>
        <span class='stic-profile-name'>" . esc_html($familiarName) . "</span>
        <span class='stic-profile-tag'>" . esc_html__('Yo (familiar)', 'sticpa') . "</span>
        <span class='stic-profile-go'>" . esc_html__('Entrar con mis propios datos', 'sticpa') . " {$goIcon}</span>
    </a>";

$html .= "</div>"; // .stic-profiles-grid

if (empty($availableContacts)) {
    $html .= "
    <div class='stic-empty-state' style='margin-top:1.25rem;'>
        <span class='stic-empty-ico'>
            <svg viewBox='0 0 24 24' width='28' height='28' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M16 21v-2a4 4 0 0 0-8 0v2'/><circle cx='12' cy='7' r='4'/><path d='M22 21v-2a4 4 0 0 0-3-3.87'/></svg>
        </span>
        <p class='stic-empty-title'>" . esc_html__('Todavía no tienes participantes vinculados', 'sticpa') . "</p>
        <p class='stic-empty-sub'>" . esc_html__('Cuando la organización vincule a tus participantes, aparecerán aquí automáticamente. Si crees que falta alguien, contacta con tu referente.', 'sticpa') . "</p>
    </div>";
}

$html .= "</div>"; // .stic-profiles
