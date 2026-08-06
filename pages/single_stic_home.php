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

    /**
     * ORDEN CON SENTIDO EN LA HOME.
     * ------------------------------------------------------------------
     * El menú tiene un orden pensado para la barra de navegación; la home
     * necesita otro: primero lo que la persona VIENE A HACER (apuntarse a
     * algo, ver qué tiene), y aparte —más pequeño— lo de "mi cuenta", que
     * se toca una vez cada muchos meses.
     *
     * Las claves que no estén en la lista de prioridad se mantienen en el
     * orden del menú, así que añadir una sección nueva sigue funcionando
     * sin tocar esto.
     */
    $accountKeys = array(
        'single_stic_comunica_perfil',
        'single_stic_comunica_monitor',
        'single_stic_comunica_laico',
        'single_stic_profile',
        'single_stic_tutor_profile',
        'single_stic_profile_selection',
        'single_stic_password_change',
        'single_stic_unsubscribe',
    );
    $mainPriority = array(
        'list_stic_events',
        'list_stic_registrations',
        'single_stic_activities_calendar',
        'list_stic_documents',
        'list_stic_payments',
        'list_stic_payment_commitments',
        'single_stic_payment_form',
    );

    $mainCards = array();
    $accountCards = array();
    foreach ($menuElements as $key => $label) {
        if (in_array($key, $accountKeys, true)) {
            $accountCards[$key] = $label;
        } else {
            $mainCards[$key] = $label;
        }
    }
    // Prioridad dentro del grupo principal (el resto, detrás y en su orden).
    $ordered = array();
    foreach ($mainPriority as $key) {
        if (isset($mainCards[$key])) {
            $ordered[$key] = $mainCards[$key];
            unset($mainCards[$key]);
        }
    }
    $mainCards = $ordered + $mainCards;
    // Las de cuenta en el orden en que aparecen en $accountKeys.
    $orderedAccount = array();
    foreach ($accountKeys as $key) {
        if (isset($accountCards[$key])) {
            $orderedAccount[$key] = $accountCards[$key];
        }
    }
    $accountCards = $orderedAccount + $accountCards;

    // Si no hubiera nada "principal", no partimos en dos: todo junto.
    if (empty($mainCards)) {
        $mainCards = $accountCards;
        $accountCards = array();
    }

    /** Pinta una tarjeta de acceso. */
    $renderCard = function ($key, $label) use ($goIcon) {
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
        <?php
    };
    ?>
    <div class="stic-home-layout<?= $showAgenda ? '' : ' stic-home-layout--solo'; ?>">
        <div class="stic-home-main">
            <p class="stic-section-label"><?= esc_html($accountCards ? __('Tu día a día', 'sticpa') : __('Tus secciones', 'sticpa')); ?></p>

            <div class="stic-dashboard-grid">
                <?php foreach ($mainCards as $key => $label) { $renderCard($key, $label); } ?>
            </div>

        </div>

        <?php if ($showAgenda) : ?>
            <aside class="stic-home-aside"><?= $agendaHtml; ?></aside>
        <?php endif; ?>
    </div>

    <?php if ($accountCards) : ?>
        <?php // "Tu cuenta" va FUERA de la rejilla de 2 columnas: es el cierre de
              // la página (en móvil, lo último; en escritorio, una fila a lo ancho). ?>
        <section class="stic-home-account">
            <p class="stic-section-label stic-section-label--mini"><?= esc_html__('Tu cuenta', 'sticpa'); ?></p>
            <div class="stic-dashboard-grid stic-dashboard-grid--mini">
                <?php foreach ($accountCards as $key => $label) { $renderCard($key, $label); } ?>
            </div>
        </section>
    <?php endif; ?>
</div>
