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

// Nombre de pila a partir de "Apellidos, Nombre" o "Nombre Apellidos".
$firstNameOf = function ($fullName) {
    $fullName = trim((string) $fullName);
    if ($fullName === '') { return ''; }
    if (strpos($fullName, ',') !== false) {
        $parts = explode(',', $fullName, 2);
        return trim($parts[1]) !== '' ? trim($parts[1]) : trim($parts[0]);
    }
    return preg_split('/\s+/', $fullName)[0];
};

// Audiencia: un familiar viendo la ficha de un participante ('participante')
// ve un mensaje distinto (habla del participante), no "tu espacio personal".
$audience = function_exists('sticpa_profile_audience') ? sticpa_profile_audience() : 'miembro';
$isFamilyView = ($audience === 'participante');

// Participante activo (para familias, el hijo/a que se está viendo).
$participantFirst = $firstNameOf($_SESSION['scp_user_contact_name'] ?? '');
// Familiar que ha accedido (para el saludo en modo familia).
$tutorFirst = $firstNameOf($_SESSION['scp_tutor_user_contact_name'] ?? '');

// Nombre para el saludo grande: el familiar si estamos en vista de familia; si
// no, la propia persona.
$firstName = $isFamilyView ? ($tutorFirst !== '' ? $tutorFirst : '') : $participantFirst;

/**
 * Iconos + descripción por sección: se reutiliza el mapa compartido
 * sticpa_section_meta() (definido en el plugin principal), el mismo que usa el
 * menú, para que ambos crezcan a la vez. Fallback por si no estuviera cargado.
 */
if (!function_exists('sticpa_home_card_meta')) :
function sticpa_home_card_meta($key)
{
    if (function_exists('sticpa_section_meta')) {
        return sticpa_section_meta($key);
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
        <h2 class="stic-welcome-title">
            <?php if ($firstName !== '') : ?>
                <?= esc_html__('Hola', 'sticpa'); ?>, <span class="stic-welcome-name"><?= esc_html($firstName); ?></span> 👋
            <?php else : ?>
                <?= esc_html__('¡Te damos la bienvenida!', 'sticpa'); ?> 👋
            <?php endif; ?>
        </h2>
        <p class="stic-welcome-lead">
            <?php if ($isFamilyView && $participantFirst !== '') : ?>
                <?= sprintf(
                    /* translators: %s = nombre del participante (hijo/a) */
                    esc_html__('Aquí puedes revisar los datos de %s e inscribirle a las actividades. Elige una sección para empezar.', 'sticpa'),
                    '<strong>' . esc_html($participantFirst) . '</strong>'
                ); ?>
            <?php else : ?>
                <?= esc_html__('Este es tu espacio personal. Desde aquí puedes consultar y gestionar toda tu información. Elige una sección para empezar.', 'sticpa'); ?>
            <?php endif; ?>
        </p>
        <?php if (isset($_GET['rol_debug'])) : ?>
            <span class="stic-role-chip" title="Valor del campo stic_relationship_type_c en el CRM">
                <?= esc_html__('Rol:', 'sticpa'); ?> <strong><?= esc_html(sticpa_get_comunica_role() ?: '(vacío)'); ?></strong>
                · <code><?= esc_html($_SESSION['scp_relationship_raw'] ?? '(vacío)'); ?></code>
            </span>
        <?php endif; ?>
    </div>

    <?php
    // Aviso accionable: monitor/a en modo manual sin el certificado de delitos
    // sexuales subido (sticpa_monitor_ds_pending consulta solo 2 campos al CRM).
    if (function_exists('sticpa_monitor_ds_pending') && sticpa_monitor_ds_pending()) {
        echo sticpa_ds_pending_alert_html(true);
    }
    ?>

    <?php
    // Layout de la home: en escritorio, las secciones a la izquierda y la agenda
    // "Próximas actividades" a un lado; en móvil se apilan (agenda debajo).
    // El widget es ligero (no carga FullCalendar). Se calcula ANTES: si no hay
    // nada concreto que mostrar devuelve '' y NO se pinta el panel (la home pasa
    // a una sola columna a ancho completo).
    $agendaHtml = '';
    if (function_exists('sticpa_home_agenda_html')
        && isset($objSCP)
        && isset($menuElements['single_stic_activities_calendar'])) {
        $agendaHtml = sticpa_home_agenda_html($objSCP);
    }
    $showAgenda = ($agendaHtml !== '');
    ?>
    <div class="stic-home-layout<?= $showAgenda ? '' : ' stic-home-layout--solo'; ?>">
        <div class="stic-home-main">
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

        <?php if ($showAgenda) : ?>
            <aside class="stic-home-aside"><?= $agendaHtml; ?></aside>
        <?php endif; ?>
    </div>
</div>
