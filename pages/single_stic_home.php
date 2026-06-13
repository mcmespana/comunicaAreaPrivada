<?php
/**
 * Pantalla de BIENVENIDA / DASHBOARD del área privada.
 * ----------------------------------------------------------------------------
 * Es la primera pantalla tras el login. Da la bienvenida al usuario e invita a
 * ir a las subsecciones con tarjetas grandes y funcionales. Las tarjetas se
 * generan automáticamente desde getSticMenuElements() (menu.php), así que se
 * sincronizan solas con las secciones que tengas activas.
 */

if (!defined('ABSPATH')) {
    exit;
}

list($menuElements, $defaultMenuElement) = getSticMenuElements();

// Saludo personalizado: intentamos sacar el nombre de pila.
$fullName = $_SESSION['scp_user_contact_name'] ?? '';
$firstName = $fullName;
if ($fullName !== '') {
    if (strpos($fullName, ',') !== false) {
        // Formato "Apellidos, Nombre" -> nos quedamos con lo de después de la coma.
        $parts = explode(',', $fullName, 2);
        $firstName = trim($parts[1]);
    } else {
        $parts = preg_split('/\s+/', trim($fullName));
        $firstName = $parts[0];
    }
}

/**
 * Iconos (SVG inline) y descripción por sección. Si una sección no está en el
 * mapa, usa un icono y texto por defecto, así nunca se rompe al añadir nuevas.
 */
if (!function_exists('sticpa_home_card_meta')) :
function sticpa_home_card_meta($key)
{
    $map = array(
        'list_stic_events' => array(
            'desc' => __('Descubre los eventos y actividades disponibles.', 'sticpa'),
            'icon' => "<rect x='3' y='4' width='18' height='18' rx='2'/><path d='M16 2v4M8 2v4M3 10h18'/>",
        ),
        'list_stic_registrations' => array(
            'desc' => __('Revisa tus inscripciones y su estado.', 'sticpa'),
            'icon' => "<path d='M9 11l3 3L22 4'/><path d='M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11'/>",
        ),
        'list_stic_documents' => array(
            'desc' => __('Accede y descarga tus documentos.', 'sticpa'),
            'icon' => "<path d='M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z'/><path d='M14 2v6h6M9 13h6M9 17h6'/>",
        ),
        'list_stic_payments' => array(
            'desc' => __('Consulta tu historial de pagos.', 'sticpa'),
            'icon' => "<rect x='2' y='5' width='20' height='14' rx='2'/><path d='M2 10h20'/>",
        ),
        'list_stic_payment_commitments' => array(
            'desc' => __('Revisa tus compromisos de pago.', 'sticpa'),
            'icon' => "<path d='M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6'/>",
        ),
        'single_stic_payment_form' => array(
            'desc' => __('Realiza un pago de forma segura.', 'sticpa'),
            'icon' => "<rect x='2' y='5' width='20' height='14' rx='2'/><path d='M2 10h20M6 15h4'/>",
        ),
        'single_stic_activities_calendar' => array(
            'desc' => __('Visualiza tus actividades en el calendario.', 'sticpa'),
            'icon' => "<rect x='3' y='4' width='18' height='18' rx='2'/><path d='M16 2v4M8 2v4M3 10h18M8 14h.01M12 14h.01M16 14h.01'/>",
        ),
        'single_stic_password_change' => array(
            'desc' => __('Actualiza tu contraseña de acceso.', 'sticpa'),
            'icon' => "<rect x='4' y='11' width='16' height='10' rx='2'/><path d='M8 11V7a4 4 0 0 1 8 0v4'/>",
        ),
        'single_stic_profile' => array(
            'desc' => __('Consulta y edita tus datos personales.', 'sticpa'),
            'icon' => "<circle cx='12' cy='8' r='4'/><path d='M4 21v-1a8 8 0 0 1 16 0v1'/>",
        ),
        'list_stic_relationships' => array(
            'desc' => __('Tus relaciones con la organización.', 'sticpa'),
            'icon' => "<circle cx='9' cy='9' r='3'/><circle cx='17' cy='15' r='3'/><path d='M9 12v0a6 6 0 0 0 6 3'/>",
        ),
        'list_stic_contacts' => array(
            'desc' => __('Contactos de la organización.', 'sticpa'),
            'icon' => "<path d='M16 21v-2a4 4 0 0 0-8 0v2'/><circle cx='12' cy='7' r='4'/>",
        ),
        'list_stic_member_organizations' => array(
            'desc' => __('Organizaciones miembro.', 'sticpa'),
            'icon' => "<path d='M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6'/>",
        ),
        'list_stic_sessions' => array(
            'desc' => __('Tus sesiones programadas.', 'sticpa'),
            'icon' => "<circle cx='12' cy='12' r='9'/><path d='M12 7v5l3 2'/>",
        ),
        'list_stic_attendances' => array(
            'desc' => __('Registro de asistencias.', 'sticpa'),
            'icon' => "<path d='M9 11l3 3L22 4'/><path d='M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11'/>",
        ),
        'list_stic_job_offers' => array(
            'desc' => __('Ofertas de empleo disponibles.', 'sticpa'),
            'icon' => "<rect x='2' y='7' width='20' height='14' rx='2'/><path d='M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2'/>",
        ),
        'list_stic_job_applications' => array(
            'desc' => __('Tus candidaturas a ofertas.', 'sticpa'),
            'icon' => "<path d='M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z'/><path d='M14 2v6h6'/>",
        ),
        'single_stic_unsubscribe' => array(
            'desc' => __('Gestiona tu baja.', 'sticpa'),
            'icon' => "<circle cx='12' cy='12' r='9'/><path d='M15 9l-6 6M9 9l6 6'/>",
        ),
    );

    if (isset($map[$key])) {
        return $map[$key];
    }
    return array(
        'desc' => __('Accede a esta sección.', 'sticpa'),
        'icon' => "<circle cx='12' cy='12' r='9'/><path d='M12 8v4l3 2'/>",
    );
}
endif;

$goIcon = "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'><path d='M5 12h14'/><path d='m13 6 6 6-6 6'/></svg>";

$portalName = get_option('sticpa_scp_name');
?>
<div class="stic-welcome">
    <div class="stic-welcome-hero">
        <p class="stic-welcome-kicker"><?= esc_html($portalName ? $portalName : __('Área privada', 'sticpa')); ?></p>
        <h2 class="stic-welcome-title">
            <?php if ($firstName !== '') : ?>
                <?= esc_html__('Hola', 'sticpa'); ?>, <span class="stic-welcome-name"><?= esc_html($firstName); ?></span> 👋
            <?php else : ?>
                <?= esc_html__('¡Te damos la bienvenida!', 'sticpa'); ?> 👋
            <?php endif; ?>
        </h2>
        <p class="stic-welcome-lead">
            <?= esc_html__('Este es tu espacio personal. Desde aquí puedes consultar y gestionar toda tu información. Elige una sección para empezar.', 'sticpa'); ?>
        </p>
    </div>

    <p class="stic-section-label"><?= esc_html__('Tus secciones', 'sticpa'); ?></p>

    <div class="stic-dashboard-grid">
        <?php foreach ($menuElements as $key => $label) :
            $meta = sticpa_home_card_meta($key);
            ?>
            <a class="stic-dash-card" href="?internalpage=<?= esc_attr($key); ?>">
                <span class="stic-dash-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $meta['icon']; ?></svg>
                </span>
                <span class="stic-dash-title"><?= esc_html(__($label, 'sticpa')); ?></span>
                <p class="stic-dash-desc"><?= esc_html($meta['desc']); ?></p>
                <span class="stic-dash-go"><?= esc_html__('Entrar', 'sticpa'); ?> <?= $goIcon; ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
