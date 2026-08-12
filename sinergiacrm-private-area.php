<?php

/*
 * Plugin Name:       SinergiaCRM Private Area
 * Description:       Private area plugin for SinergiaCRM
 * Version:           1.5
 * Author:            SinergiaTIC
 * Author URI:        https://www.sinergiacrm.org
 * Text Domain:       sticpa
 * Domain Path:       /languages
 */

// Comment the following line to disable tutor-profiles-family functionality
define ('RELATIONSHIP_TUTOR_TYPES',  array('father', 'mother', 'legal', 'carer'));

include_once plugin_dir_path(__FILE__) . '/inc/stic-class-6.php';
include_once plugin_dir_path(__FILE__) . '/inc/stic-formatter.php';
include_once plugin_dir_path(__FILE__) . 'menu.php';
include_once plugin_dir_path(__FILE__) . '/inc/stic-formController.php';
include_once plugin_dir_path(__FILE__) . '/inc/stic-listController.php';
include_once plugin_dir_path(__FILE__) . '/inc/stic-script-vars.php';

// Load translation files
add_action('plugins_loaded', 'sticpa_load_languages');

/**
 * This function is used through the rest of the plugin to determine if it has to use "Contacts" or "Accounts" module to log in users

 *
 * @return void
 */
function getDestinationModule()
{
    // $moduleToUse = 'Accounts';
    // $moduleToUse = 'Contacts';
    if(isset($_REQUEST['scp_module'])){
        $scp_module = $_REQUEST['scp_module'];
    } elseif (isset($_SESSION['scp_module'])) {
        $scp_module = $_SESSION['scp_module'];
    } else {
        $scp_module = get_option('sticpa_scp_module');
    }
    return $scp_module;
}

function sticpa_load_languages()
{
    $text_domain = 'sticpa';
    $path_languages = basename(dirname(__FILE__)) . '/languages/';
    load_plugin_textdomain($text_domain, false, $path_languages);
}

include plugin_dir_path(__FILE__) . 'inc/stic-action.php';
// stic-theme.php ANTES de stic-magic-login.php: la pantalla puente del enlace
// mágico resuelve su apariencia con sticpa_theme_pref().
include plugin_dir_path(__FILE__) . 'inc/stic-theme.php';
include plugin_dir_path(__FILE__) . 'inc/stic-magic-login.php';
include plugin_dir_path(__FILE__) . 'inc/stic-otp.php';
include plugin_dir_path(__FILE__) . 'inc/stic-app-links.php';
include plugin_dir_path(__FILE__) . 'inc/stic-comunica-roles.php';
include plugin_dir_path(__FILE__) . 'inc/stic-calendar.php';
include plugin_dir_path(__FILE__) . 'inc/stic-events.php';

add_action('admin_menu', 'sugar_crm_portal_create_menu');

/**
 * Página interna pedida (?internalpage), saneada con la misma whitelist que
 * sticpa_resolve_page_file. '' si no se pide ninguna (home/login).
 * Se usa para decidir qué librerías pesadas encolar (plan 010).
 */
function sticpa_current_internal_page()
{
    $page = isset($_REQUEST['internalpage']) ? (string) $_REQUEST['internalpage'] : '';
    return preg_match('/^[a-z0-9_]+$/', $page) ? $page : '';
}

// Add JS script for form management
// Don't add the action to avoid including the js in all the pages of the site. Instead it is loaded when the shortcode is applied
// add_action("wp_enqueue_scripts", "dcms_insertar_js");
/**
 * MAPA DE LIBRERÍAS POR PÁGINA (plan 010) — al añadir una página que use una
 * lib pesada hay que añadirla aquí:
 *   · FullCalendar (+locale)  → solo single_stic_activities_calendar.
 *   · DataTables (JS; el CSS vendorizado se encola en
 *     sugar_crm_portal_style_and_script) → solo páginas list_*.
 *   · Selectize (multiselect) → páginas de formulario single_* (los multienum
 *     pueden venir de la definición del CRM sin declararse en la página, así
 *     que se es conservador: TODAS las single_* menos el calendario).
 *   · iban.js → páginas con validación de IBAN (payment_form, tutor_profile,
 *     profile, payments, payment_commitments).
 *   · stic-cropper → solo páginas con input de archivo (documents,
 *     comunica_monitor, comunica_perfil, profile).
 *   · stic-utils / stic-ui / stic-init → SIEMPRE (propios, ligeros).
 * Sin ?internalpage (home/login/selección de perfil) no se carga ninguna pesada.
 */
function dcms_insertar_js()
{
    $page = sticpa_current_internal_page();
    $isList = strpos($page, 'list_') === 0;
    $isCalendar = ($page === 'single_stic_activities_calendar');
    $isSingleForm = (strpos($page, 'single_') === 0) && !$isCalendar;
    $ibanPages = array(
        'single_stic_payment_form',
        'single_stic_tutor_profile',
        'single_stic_profile',
        'single_stic_payments',
        'single_stic_payment_commitments',
    );

    if (in_array($page, $ibanPages, true)) {
        wp_register_script('sugarcrm', plugin_dir_url(__FILE__) . 'js/iban.js', array('jquery'), '1', true);
        wp_enqueue_script('sugarcrm');
    }
    if ($isCalendar) {
        // Build minificado (269 KB vs 718 KB del sin minificar que se cargaba antes).
        wp_register_script('fullcalendar', plugin_dir_url(__FILE__) . 'js/fullcalendar/lib/main.min.js', array('jquery'), '1', true);
        wp_enqueue_script('fullcalendar');
        // Solo el locale del idioma activo; el paquete con TODOS los idiomas (24 KB)
        // queda como fallback si no existe el archivo del locale.
        $fcLocale = strtolower(str_replace('_', '-', get_locale()));
        $fcLocaleShort = explode('-', $fcLocale)[0];
        $fcLocaleRel = null;
        foreach (array($fcLocale, $fcLocaleShort) as $candidate) {
            if ($candidate && $candidate !== 'en' && file_exists(plugin_dir_path(__FILE__) . 'js/fullcalendar/lib/locales/' . $candidate . '.js')) {
                $fcLocaleRel = 'js/fullcalendar/lib/locales/' . $candidate . '.js';
                break;
            }
        }
        if ($fcLocaleRel === null && $fcLocaleShort !== 'en') {
            $fcLocaleRel = 'js/fullcalendar/lib/locales-all.min.js';
        }
        if ($fcLocaleRel !== null) {
            wp_register_script('fullcalendar-locale', plugin_dir_url(__FILE__) . $fcLocaleRel, array('fullcalendar'), '1', true);
            wp_enqueue_script('fullcalendar-locale');
        }
    }
    // Versión por filemtime en los JS propios: cada deploy rompe la caché.
    $jsver = function ($rel) {
        $path = plugin_dir_path(__FILE__) . $rel;
        return file_exists($path) ? filemtime($path) : '1';
    };
    wp_register_script('sugarcrm-own', plugin_dir_url(__FILE__) . 'js/stic-utils.js', array('jquery'), $jsver('js/stic-utils.js'), true);
    wp_enqueue_script('sugarcrm-own');
    // UI helpers: overlay de carga + toggle de contraseña (sin dependencias)
    wp_register_script('stic-ui', plugin_dir_url(__FILE__) . 'js/stic-ui.js', array(), $jsver('js/stic-ui.js'), true);
    wp_enqueue_script('stic-ui');
    // Cropper de fotos móvil-first: solo en las páginas que tienen input de
    // archivo. Se cargaba en TODAS (login, home, selección de participante
    // incluidos), que son justo las del primer arranque de la app.
    $cropperPages = array(
        'single_stic_documents',
        'single_stic_comunica_monitor',
        'single_stic_comunica_perfil',
        'single_stic_profile',
    );
    if (in_array($page, $cropperPages, true)) {
        wp_register_script('stic-cropper', plugin_dir_url(__FILE__) . 'js/stic-cropper.js', array(), $jsver('js/stic-cropper.js'), true);
        wp_enqueue_script('stic-cropper');
    }
    // We use only one file for plugin literals, so although theoretically we should call this function twice (one efor each js), we only call it once.
    wp_localize_script('sugarcrm-own', 'stic_script_vars', getSticScriptVars());
    if ($isSingleForm) {
        wp_register_script('multiselect', plugin_dir_url(__FILE__) . 'js/selectize.min.js', array('jquery'), '1', true);
        wp_enqueue_script('multiselect');
    }
    if ($isList) {
        wp_register_script('datatables', plugin_dir_url(__FILE__) . 'js/jquery.dataTables.min.js', array('jquery'), '1', true);
        wp_enqueue_script('datatables');
    }
    // Init dirigida por data-* (plan 021): lee data-dt-settings / data-fc-settings
    // y arranca DataTables/FullCalendar sin <script> inline en el body.
    if ($isList || $isCalendar) {
        $initDeps = array('jquery', 'sugarcrm-own');
        if ($isList) {
            $initDeps[] = 'datatables';
        }
        if ($isCalendar) {
            $initDeps[] = 'fullcalendar';
        }
        wp_register_script('stic-init', plugin_dir_url(__FILE__) . 'js/stic-init.js', $initDeps, $jsver('js/stic-init.js'), true);
        wp_enqueue_script('stic-init');
    }
}

function sugar_crm_portal_create_menu()
{

    //create admin side menu
    add_menu_page('SinergiaCRM Private Area', __('SinergiaCRM Private Area', 'sticpa'), 'administrator', 'sugar-crm-portal', 'sugar_crm_portal_settings_page');

    //call register settings function
    add_action('admin_init', 'register_sugar_crm_portal_settings');
}

function register_sugar_crm_portal_settings()
{
//register our settings
    register_setting('sugar_crm_portal-settings-group', 'sticpa_scp_name');
    register_setting('sugar_crm_portal-settings-group', 'sticpa_scp_host_url');
    register_setting('sugar_crm_portal-settings-group', 'sticpa_scp_rest_url');
    register_setting('sugar_crm_portal-settings-group', 'sticpa_scp_username');
    register_setting('sugar_crm_portal-settings-group', 'sticpa_scp_password');
    register_setting('sugar_crm_portal-settings-group', 'sticpa_scp_module');
    register_setting('sugar_crm_portal-settings-group', 'sticpa_scp_area_url');
    register_setting('sugar_crm_portal-settings-group', 'sticpa_scp_case_per_page');
    register_setting('sugar_crm_portal-settings-group', 'sticpa_scp_sugar_crm_version');
    register_setting('sugar_crm_portal-settings-group', 'sticpa_android_sha256');
}

function sugar_crm_portal_settings_page()
{
    ; // Admin side page options
    ?>
        <div class='wrap'>
            <h2><?=__('SinergiaCRM Private Area Settings', 'sticpa');?></h2>

            <form method='post' action='options.php'>
                <?php settings_fields('sugar_crm_portal-settings-group');?>
                <?php do_settings_sections('sugar_crm_portal-settings-group');?>
                <table class='form-table'>
                    <tr valign='top'>
                        <th scope='row'><?=__('Portal Name', 'sticpa');?></th>
                        <td><input type='text'  class='regular-text' value="<?php echo get_option('sticpa_scp_name'); ?>" name='sticpa_scp_name'></td>
                    </tr>

                    <tr valign='top'>
                        <th scope='row'><?=__('Host URL', 'sticpa');?></th>
                        <td>
                            <input type='text'  class='regular-text' value="<?php echo get_option('sticpa_scp_host_url'); ?>" name='sticpa_scp_host_url'>
                            <p class="description"><?=__('CRM URL, ex: https://example.sinergiacrm.org', 'sticpa');?><p>
                        </td>
                    </tr>

                    <tr valign='top'>
                        <th scope='row'><?=__('REST URL', 'sticpa');?></th>
                        <td>
                            <input type='text'  class='regular-text' value="<?php echo get_option('sticpa_scp_rest_url'); ?>" name='sticpa_scp_rest_url'>
                            <p class="description"><?=__('URL API connection, ex: https://example.sinergiacrm.org/custom/service/v4_1_SticCustom/rest.php', 'sticpa');?><p>
                        </td>
                    </tr>

                    <tr valign='top'>
                        <th scope='row'><?=__('Username', 'sticpa');?></th>
                        <td><input type='text' value="<?php echo get_option('sticpa_scp_username'); ?>" name='sticpa_scp_username'></td>
                    </tr>

                    <tr valign='top'>
                        <th scope='row'><?=__('Password', 'sticpa');?></th>
                        <td><input type='password' value="<?php echo get_option('sticpa_scp_password'); ?>" name='sticpa_scp_password'></td>
                    </tr>

                    <tr valign='top'>
                        <th scope='row'>Module</th>
                        <!-- <td><input value="<?php echo get_option('sticpa_scp_module'); ?>" name='sticpa_scp_module'></td> -->
                        <td>
                            <?php echo build_dropdown_modules(); ?>
                        </td>
                    </tr>
                    <tr valign='top'>
                        <th scope='row'><?=__('URL del área privada', 'sticpa');?></th>
                        <td>
                            <input type='text' class='regular-text' value="<?php echo get_option('sticpa_scp_area_url'); ?>" name='sticpa_scp_area_url'>
                            <p class="description"><?=__('Página pública donde está el shortcode. Se usa para construir los enlaces de acceso, ej: https://comunica.movimientoconsolacion.com/area-privada/', 'sticpa');?></p>
                        </td>
                    </tr>

                    <tr valign='top'>
                        <th scope='row'><?=__('Huella SHA-256 de la app Android', 'sticpa');?></th>
                        <td>
                            <input type='text' class='regular-text' value="<?php echo esc_attr(get_option('sticpa_android_sha256')); ?>" name='sticpa_android_sha256'>
                            <p class="description"><?=__('Necesaria para que los enlaces de los correos abran la app en Android. Play Console → Setup → App integrity → App signing → «SHA-256 certificate fingerprint». Formato AA:BB:…:99, varias separadas por comas. En iOS no hace falta nada.', 'sticpa');?></p>
                        </td>
                    </tr>

                    <tr>
                      <th scope='row'><?=__('Shortcode', 'sticpa');?></th>
                      <td>
                        <p><?=__('In order to show the private area, please insert this shortcode in a page', 'sticpa');?>: <code>[sinergiacrm-private-area]</code></p>
                        <p><?=__('Also, follow the documentation at', 'sticpa');?> <a href="https://wikisuite.sinergiacrm.org/index.php?title=Plugin_Wordpress_para_gesti%C3%B3n_de_%C3%81rea_Privada" target="_blank">https://wikisuite.sinergiacrm.org/index.php?title=Plugin_Wordpress_para_gesti%C3%B3n_de_%C3%81rea_Privada</a></p>
                      </td>

                    </tr>
                </table>
                <?php submit_button();?>
            </form>
        </div>
    <?php

    if (class_exists('SugarRestApiCall')) {
        $objSCP = SugarRestApiCall::getObjSCP();
        if ($objSCP->login() != null) {
            ?>
                    <div class='updated settings-error' id='setting-error-settings_updated'>
                        <p><strong><?=__('Successful connection', 'sticpa');?></strong></p>
                    </div>
                <?php
} else {
            ?>
                    <div class='error settings-error' id='setting-error-settings_updated'>
                        <p><strong><?=__('Connection not successful. Please check REST URL, Username and Password', 'sticpa');?></strong></p>
                    </div>
                <?php
}
    } else {
        ?>
                <div class='error settings-error' id='setting-error-settings_updated'>
                    <p><strong><?=__('Connection not successful. Please check REST URL, Username and Password', 'sticpa');?></strong></p>
                </div>
            <?php
}

    // Tools: bulk token generation, find user, view token, "log in as".
    if (function_exists('sticpa_render_admin_tools')) {
        sticpa_render_admin_tools();
    }
}

function build_dropdown_modules()
{
    $module = get_option('sticpa_scp_module');

    if ($module == 'Contacts') {
        ?> <select value="<?php echo $module; ?>" name='sticpa_scp_module'>
          <option value="" >  </option>
          <option value="Contacts" selected > <?php _e('Contacts', 'sticpa');?> </option>
          <option value="Accounts" > <?php _e('Accounts', 'sticpa');?> </option>
          <option value="Any"> <?php _e('Contacts or Accounts', 'sticpa');?> </option>
          </select> <?php
  } else if ($module == 'Accounts') {
          ?> <select value="<?php echo $module; ?>" name='sticpa_scp_module'>
          <option value="" >  </option>
          <option value="Contacts" > <?php _e('Contacts', 'sticpa');?> </option>
          <option value="Accounts" selected ><?php _e('Accounts', 'sticpa');?></option>
          <option value="Any" > <?php _e('Contacts or Accounts', 'sticpa');?> </option>
          </select> <?php
  } else if ($module == 'Any') {
          ?> <select value="<?php echo $module; ?>" name='sticpa_scp_module'>
          <option value="" >  </option>
          <option value="Contacts"><?php _e('Contacts', 'sticpa');?></option>
          <option value="Accounts" > <?php _e('Accounts', 'sticpa');?> </option>
          <option value="Any" selected> <?php _e('Contacts or Accounts', 'sticpa');?> </option>
          </select> <?php
  }  else {
    ?> <select value="" name='sticpa_scp_module'>
    <option value="" >  </option>
    <option value="Contacts"><?php _e('Contacts', 'sticpa');?></option>
    <option value="Accounts" > <?php _e('Accounts', 'sticpa');?> </option>
    <option value="Any" > <?php _e('Contacts or Accounts', 'sticpa');?> </option>
    </select> <?php
}
}

/**
 * Devuelve un icono SVG inline (stroke "currentColor") para usar en el área.
 * Mantiene el markup limpio y evita dependencias de fuentes de iconos.
 */
function sticpa_icon($name, $class = '')
{
    $paths = array(
        'user'     => "<circle cx='12' cy='8' r='4'/><path d='M4 21v-1a8 8 0 0 1 16 0v1'/>",
        'lock'     => "<rect x='4' y='11' width='16' height='10' rx='2'/><path d='M8 11V7a4 4 0 0 1 8 0v4'/>",
        'eye'      => "<path d='M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z'/><circle cx='12' cy='12' r='3'/>",
        'eye-off'  => "<path d='M9.9 4.24A10.6 10.6 0 0 1 12 4c6.5 0 10 7 10 7a16.6 16.6 0 0 1-3 3.7M6.2 6.2A16.4 16.4 0 0 0 2 11s3.5 7 10 7a10.5 10.5 0 0 0 4.3-.9'/><path d='M3 3l18 18'/>",
        'mail'     => "<rect x='3' y='5' width='18' height='14' rx='2'/><path d='m3 7 9 6 9-6'/>",
        'sparkles' => "<path d='M12 3l1.8 4.5L18 9l-4.2 1.5L12 15l-1.8-4.5L6 9l4.2-1.5L12 3Z'/><path d='M19 14l.9 2.3L22 17l-2.1.7L19 20l-.9-2.3L16 17l2.1-.7L19 14Z'/>",
        'send'     => "<path d='M22 2 11 13'/><path d='M22 2 15 22l-4-9-9-4 20-7Z'/>",
        'switch'   => "<path d='M17 2l4 4-4 4'/><path d='M3 11V9a4 4 0 0 1 4-4h14'/><path d='M7 22l-4-4 4-4'/><path d='M21 13v2a4 4 0 0 1-4 4H3'/>",
        'logout'   => "<path d='M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4'/><path d='M16 17l5-5-5-5'/><path d='M21 12H9'/>",
        'help'     => "<circle cx='12' cy='12' r='10'/><path d='M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3'/><path d='M12 17h.01'/>",
        'chevron'  => "<path d='m6 9 6 6 6-6'/>",
        'more'     => "<path d='M5 12h.01M12 12h.01M19 12h.01'/>",
        'shield'   => "<path d='M12 3l8 3v6c0 4.5-3.2 7.7-8 9-4.8-1.3-8-4.5-8-9V6l8-3Z'/><path d='m9 12 2 2 4-4'/>",
        'arrow'    => "<path d='M5 12h14'/><path d='m13 6 6 6-6 6'/>",
        'calendar' => "<rect x='3' y='4' width='18' height='18' rx='2'/><path d='M16 2v4M8 2v4M3 10h18'/>",
        'check'    => "<path d='M9 11l3 3L22 4'/><path d='M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11'/>",
        'doc'      => "<path d='M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z'/><path d='M14 2v6h6M9 13h6M9 17h6'/>",
    );
    $inner = $paths[$name] ?? '';
    return "<svg class='" . esc_attr($class) . "' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'>" . $inner . "</svg>";
}

/**
 * Metadatos (icono SVG interno + descripción) de cada sección del área privada.
 * Fuente ÚNICA usada por el menú (menu.php) y la pantalla de bienvenida
 * (pages/single_stic_home.php). Para añadir una sección nueva basta con sumar su
 * clave aquí; si no está, se usa un icono/descr. por defecto (nunca se rompe).
 *
 * @return array{icon:string,desc:string}
 */
function sticpa_section_meta($key)
{
    $map = array(
        'single_stic_home' => array(
            'desc' => __('Vuelve a tu página de inicio.', 'sticpa'),
            'icon' => "<path d='M3 11l9-8 9 8'/><path d='M5 10v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V10'/><path d='M9 21v-6h6v6'/>",
        ),
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
        'single_stic_tutor_profile' => array(
            'desc' => __('Tus datos como familiar: contacto, dirección y medio de pago.', 'sticpa'),
            'icon' => "<circle cx='12' cy='8' r='4'/><path d='M4 21v-1a8 8 0 0 1 16 0v1'/>",
        ),
        'single_stic_profile_selection' => array(
            'desc' => __('Elige a qué participante quieres ver.', 'sticpa'),
            'icon' => "<path d='M16 21v-2a4 4 0 0 0-8 0v2'/><circle cx='12' cy='7' r='4'/><path d='M22 21v-2a4 4 0 0 0-3-3.87'/>",
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
        'custom_html' => array(
            'desc' => __('Información adicional.', 'sticpa'),
            'icon' => "<circle cx='12' cy='12' r='10'/><path d='M12 16v-4M12 8h.01'/>",
        ),
        'single_stic_comunica_perfil' => array(
            'desc' => __('Tus datos personales, de contacto, MCM, salud y RGPD.', 'sticpa'),
            'icon' => "<circle cx='12' cy='8' r='4'/><path d='M4 21v-1a8 8 0 0 1 16 0v1'/>",
        ),
        'single_stic_comunica_monitor' => array(
            'desc' => __('Tu formación, certificados y datos de monitor/a.', 'sticpa'),
            'icon' => "<path d='M22 10 12 5 2 10l10 5 10-5Z'/><path d='M6 12v5c0 1 2 3 6 3s6-2 6-3v-5'/>",
        ),
        'single_stic_comunica_laico' => array(
            'desc' => __('Tu etapa, grupo y datos como laico/a.', 'sticpa'),
            'icon' => "<path d='M12 2v20M5 8h14M5 8l7-4 7 4'/>",
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

/** Devuelve el SVG completo del icono de una sección (envoltura sobre sticpa_section_meta). */
function sticpa_section_icon($key, $class = '')
{
    $meta = sticpa_section_meta($key);
    return "<svg class='" . esc_attr($class) . "' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'>" . $meta['icon'] . "</svg>";
}

/**
 * Saludo según la hora del día ("¡Buenos días!"). Un detalle pequeño, pero es
 * la diferencia entre que la pantalla te salude y que te pida credenciales.
 */
function sticpa_greeting()
{
    $hour = (int) (function_exists('current_time') ? current_time('G') : date('G'));
    if ($hour < 6)  { return __('¡Buenas noches!', 'sticpa'); }
    if ($hour < 14) { return __('¡Buenos días!', 'sticpa'); }
    if ($hour < 21) { return __('¡Buenas tardes!', 'sticpa'); }
    return __('¡Buenas noches!', 'sticpa');
}

function sugar_crm_portal_login_form($html = "", $mode = 'magic')
{
    $scp_name = get_option('sticpa_scp_name');
    // Saludo cercano + UNA línea de contexto. Antes había tres cosas diciendo lo
    // mismo (kicker "ÁREA PRIVADA" + "Hola de nuevo" + "Accede a tu área privada
    // de X"), que ocupaban media pantalla de móvil para no aportar nada.
    $title = sticpa_greeting();
    $subtitle = $scp_name != null
        ? sprintf(__('Tu área privada de %s.', 'sticpa'), $scp_name)
        : __('Tu área privada.', 'sticpa');

    // URL de retorno para el enlace mágico (debe llevar un '?' para que el handler
    // pueda añadir '&success=true' al redirigir de vuelta a esta misma pantalla).
    $base_url = strtok($_SERVER['REQUEST_URI'], '?');
    $return_url = $base_url . '?stic_auth=1';

    // ---- Panel de bienvenida (solo en pantallas anchas, ver §4.b del CSS) ----
    // En escritorio la tarjeta se parte en dos: aquí se cuenta qué hay dentro,
    // que es lo que da ganas de entrar; el formulario va al lado. En móvil este
    // bloque no se pinta (display:none) y manda el formulario.
    $perks = array(
        array('calendar', __('Las actividades y sus fechas', 'sticpa')),
        array('check',    __('Tus inscripciones, al día', 'sticpa')),
        array('doc',      __('Documentos siempre a mano', 'sticpa')),
    );
    $perksHtml = '';
    foreach ($perks as $perk) {
        $perksHtml .= "<li><span class='stic-auth-perk-ico'>" . sticpa_icon($perk[0]) . "</span>"
            . "<span class='stic-auth-perk-text'>" . esc_html($perk[1]) . "</span></li>";
    }
    $html .= "
        <aside class='stic-auth-aside' aria-hidden='true'>
            <div class='stic-auth-aside-logo'>" . sticpa_icon('shield') . "</div>
            <p class='stic-auth-aside-claim'>" . esc_html__('Todo lo tuyo, en un sitio', 'sticpa') . "</p>
            <ul class='stic-auth-perks'>" . $perksHtml . "</ul>
        </aside>";

    $html .= "<div class='stic-auth-panel'>";

    // Cabecera de marca.
    $html .= "
        <div class='stic-auth-brand'>
            <div class='stic-auth-logo'>" . sticpa_icon('shield') . "</div>
            <div>
                <h3>" . esc_html($title) . "</h3>
                <p class='stic-auth-sub'>" . esc_html($subtitle) . "</p>
            </div>
        </div>";

    // Mensaje genérico tras pedir un enlace mágico (no revela si el email existe).
    $magicMsg = "";
    if (isset($_REQUEST['success']) && $_REQUEST['success'] == true) {
        $magicMsg = "<span class='success' role='status'>" . __('¡Listo! Si tu correo está registrado, ya tienes el enlace en tu bandeja de entrada. 📩', 'sticpa') . "</span>";
    }

    // Selector de idioma (opcional, según plugin de traducción activo).
    $languageHtml = "";
    if (function_exists('pll_the_languages')) {
        $languageSelector = pll_the_languages(array('dropdown' => 1,'show_flags'=>1,'show_names'=>0,'hide_current'=>1, 'echo' => 0));
        $languageHtml = "
        <li class='input_login'>
            <label>" . __('Language', 'sticpa') . ": </label>
            <span>".$languageSelector."</span>
        </li>";
    } elseif (shortcode_exists('wpml_language_selector_widget')) {
        $languageHtml = "
        <li class='input_login'>
            <label>" . __('Language', 'sticpa') . ": </label>
            <div class='wpml-switcher'>".do_shortcode('[wpml_language_selector_widget]')."</div>
        </li>";
    }

    // Selector de módulo (solo cuando la config permite Contacts o Accounts).
    $moduleSelectHTML = "";
    if (get_option('sticpa_scp_module') == "Any") {
        $moduleSelectHTML = "
        <li class='input_login'>
            <label>" . __('Login as', 'sticpa') . ": </label>
            <span class='stic-field'>
                <select name='scp_module' id='stic-module'>
                    <option value='Contacts'>" . __('Contact', 'sticpa') . "</option>
                    <option value='Accounts'>" . __('Account', 'sticpa') . "</option>
                </select>
            </span>
        </li>";
    }

    // data-mode controla qué vista se muestra primero (CSS); el JS la alterna.
    $mode = ($mode === 'password') ? 'password' : 'magic';

    $html .= "<div class='stic-auth' data-mode='" . esc_attr($mode) . "'>";

    /* ---------- TABS: elegir cómo entrar (enlace mágico / contraseña) ---------- */
    $magicSelected = ($mode === 'password') ? 'false' : 'true';
    $passwordSelected = ($mode === 'password') ? 'true' : 'false';
    $html .= "
        <div class='stic-auth-tabs' role='tablist' aria-label='" . esc_attr__('Forma de acceso', 'sticpa') . "'>
            <button type='button' class='stic-auth-tab' data-auth-toggle='magic' role='tab' id='stic-auth-tab-magic' aria-controls='stic-auth-panel-magic' aria-selected='{$magicSelected}'>"
                . sticpa_icon('sparkles') . "<span>" . __('Por correo', 'sticpa') . "</span>
            </button>
            <button type='button' class='stic-auth-tab' data-auth-toggle='password' role='tab' id='stic-auth-tab-password' aria-controls='stic-auth-panel-password' aria-selected='{$passwordSelected}'>"
                . sticpa_icon('lock') . "<span>" . __('Contraseña', 'sticpa') . "</span>
            </button>
        </div>";

    /* ---------- VISTA 1: ENLACE MÁGICO (por defecto) ---------- */
    $html .= "
        <div class='stic-auth-view stic-auth-magic' id='stic-auth-panel-magic' role='tabpanel' aria-labelledby='stic-auth-tab-magic'>
            " . $magicMsg . "
            <p class='stic-auth-help'>" . __('Escribe tu correo y te mandamos un código y un enlace para entrar. Sin contraseñas ni líos.', 'sticpa') . "</p>
            <form action='" . site_url() . "/wp-admin/admin-post.php' method='post' class='stic-loading-form'
                  data-loading-text='" . esc_attr__('Preparando tu acceso…', 'sticpa') . "'
                  data-loading-sub='" . esc_attr__('En unos segundos lo tienes en el correo.', 'sticpa') . "'>
                <ul>
                    <li class='input_login'>
                        <label for='stic-magic-email'>" . __('Tu correo', 'sticpa') . "</label>
                        <span class='stic-field'>
                            <span class='stic-field-icon'>" . sticpa_icon('mail') . "</span>
                            <input type='email' class='input-text' name='forgot-password-email-address' id='stic-magic-email' autocomplete='email' inputmode='email' placeholder='" . esc_attr__('nombre@correo.com', 'sticpa') . "' required>
                        </span>
                        <details class='stic-hint'>
                            <summary>" . sticpa_icon('help', 'stic-hint-icon') . "<span>" . __('¿Qué correo debo poner?', 'sticpa') . "</span>" . sticpa_icon('chevron', 'stic-hint-chevron') . "</summary>
                            <div class='stic-hint-body'>
                                <p><strong>" . __('Familias de MIC y COM', 'sticpa') . ":</strong> " . __('el correo del familiar (no el del participante).', 'sticpa') . "</p>
                                <p><strong>" . __('Miembros del MCM', 'sticpa') . "</strong> " . __('(monitores, COM, LC): tu correo propio.', 'sticpa') . "</p>
                            </div>
                        </details>
                    </li>
                    <li class='stic-send'>
                        <input type='hidden' name='action' value='sticpa_send_access'>
                        <input type='hidden' name='scp_current_url' value='" . esc_attr($return_url) . "'>
                        <button type='submit' class='stic-btn-magic'>
                            <span class='stic-btn-magic-icon'>" . sticpa_icon('send') . "</span>
                            <span>" . __('Enviarme el acceso', 'sticpa') . "</span>
                        </button>
                    </li>
                </ul>
            </form>
        </div>";

    /* ---------- VISTA 2: USUARIO + CONTRASEÑA ---------- */
    $html .= "
        <div class='stic-auth-view stic-auth-login' id='stic-auth-panel-password' role='tabpanel' aria-labelledby='stic-auth-tab-password'>
            <p class='stic-auth-help'>" . __('Solo si ya creaste una contraseña. Tu usuario es tu DNI.', 'sticpa') . "</p>
            <form name='stic-login-form' id='stic-login-form' class='stic-loading-form' action='' method='post'
                  data-loading-text='" . esc_attr__('Un momento…', 'sticpa') . "'
                  data-loading-sub='" . esc_attr__('Comprobando tu acceso de forma segura.', 'sticpa') . "'>
                <ul>
                    " . $languageHtml . "
                    " . $moduleSelectHTML . "
                    <li class='input_login'>
                        <label for='stic-username'>" . __('Usuario', 'sticpa') . "</label>
                        <span class='stic-field'>
                            <span class='stic-field-icon'>" . sticpa_icon('user') . "</span>
                            <input type='text' class='input-text' name='scp_username' id='stic-username' autocomplete='username' required>
                        </span>
                    </li>
                    <li class='input_login'>
                        <label for='stic-password'>" . __('Contraseña', 'sticpa') . "</label>
                        <span class='stic-field'>
                            <span class='stic-field-icon'>" . sticpa_icon('lock') . "</span>
                            <input type='password' class='input-text' name='scp_password' id='stic-password' autocomplete='current-password' required>
                            <button type='button' class='stic-pass-toggle' data-pass-toggle='stic-password' aria-label='" . esc_attr__('Mostrar contraseña', 'sticpa') . "'>" . sticpa_icon('eye') . "</button>
                        </span>
                    </li>
                    <li class='actions_login'>
                        <span><input type='submit' name='scp_login_form_submit' id='stic-login-form-submit' value='" . esc_attr__('Iniciar sesión', 'sticpa') . "'></span>
                    </li>
                </ul>
            </form>
        </div>";

    $html .= "</div>"; // .stic-auth

    // Registro (común a ambas vistas).
    $html .= "
        <p class='stic-auth-links'>"
        . __('¿Aún no tienes acceso?', 'sticpa') . " <a href='?internalpage=single_stic_signup'>" . __('Te contamos cómo', 'sticpa') . "</a>
        </p>";

    $html .= "</div>"; // .stic-auth-panel

    return $html;
}

/**
 * Pantalla "ya te hemos mandado el acceso", con el formulario del código de 6
 * cifras. Se llega aquí después de pedir acceso (`?sticpa_code=1`).
 *
 * Dos presentaciones, misma capacidad:
 *
 *  · Dentro de la app MCM el código va ABIERTO y es lo primero que se ve. Es el
 *    caso en el que el enlace del correo falla más (ver `inc/stic-otp.php`), y
 *    además es donde más duele: la sesión de la app dura un año, así que esto
 *    se hace una vez y ya.
 *  · En navegador el enlace del correo funciona bien, así que manda el mensaje
 *    de "míralo en tu correo" y el código queda detrás de un botón pequeño.
 *
 * El desplegable es un <details> nativo a propósito: sin JS sigue abriéndose, y
 * el lector de pantalla ya sabe contarlo.
 */
function sticpa_access_code_form($html = "")
{
    $base_url = strtok($_SERVER['REQUEST_URI'], '?');
    $return_url = $base_url . '?stic_auth=1';
    $appMode = function_exists('sticpa_is_app_mode') && sticpa_is_app_mode();

    // Solo sirve para pre-rellenar y para enseñar a dónde se mandó. Si no hay
    // nada (se pidió en otro dispositivo), se pide el correo a mano.
    $pending = isset($_SESSION['sticpa_otp_email']) ? (string) $_SESSION['sticpa_otp_email'] : '';
    $masked = $pending !== '' ? sticpa_otp_mask_email($pending) : '';

    $error = isset($_REQUEST['otp_error']) ? sanitize_key($_REQUEST['otp_error']) : '';
    $errorMsg = '';
    if ($error === 'bad') {
        $errorMsg = __('Ese código no es correcto o ha caducado. Comprueba el último correo que te hemos enviado.', 'sticpa');
    } elseif ($error === 'locked') {
        $errorMsg = __('Has fallado demasiadas veces. Espera un rato antes de volver a probar con el código, o entra con el botón del correo, que sigue funcionando.', 'sticpa');
    } elseif ($error === 'throttled') {
        $errorMsg = __('Has pedido acceso varias veces seguidas. Espera unos minutos y revisa mientras tu bandeja de entrada (y la carpeta de spam).', 'sticpa');
    } elseif ($error === 'crm') {
        $errorMsg = __('El código era correcto, pero no hemos podido cargar tu ficha. Vuelve a pedir el acceso, por favor.', 'sticpa');
    }

    $html .= "
        <aside class='stic-auth-aside' aria-hidden='true'>
            <div class='stic-auth-aside-logo'>" . sticpa_icon('shield') . "</div>
            <p class='stic-auth-aside-claim'>" . esc_html__('Ya casi estás dentro', 'sticpa') . "</p>
        </aside>";

    $html .= "<div class='stic-auth-panel'>";

    $html .= "
        <div class='stic-auth-brand'>
            <div class='stic-auth-logo'>" . sticpa_icon('mail') . "</div>
            <div>
                <h3>" . esc_html__('Mira tu correo', 'sticpa') . "</h3>
                <p class='stic-auth-sub'>" . ($masked !== ''
                    ? sprintf(esc_html__('Te lo hemos enviado a %s', 'sticpa'), esc_html($masked))
                    : esc_html__('Si tu correo está registrado, ya lo tienes en tu bandeja.', 'sticpa')) . "</p>
            </div>
        </div>";

    if ($errorMsg !== '') {
        $html .= "<span class='error' role='alert'>" . esc_html($errorMsg) . "</span>";
    } elseif (!$appMode) {
        $html .= "<span class='success' role='status'>" . esc_html__('¡Listo! Abre el correo y pulsa el botón para entrar. 📩', 'sticpa') . "</span>";
    }

    /* ---------- Formulario del código ---------- */
    // maxlength 7 y no 6: al pegar «123 456» desde el correo cabe el espacio.
    // El servidor se queda solo con los dígitos.
    $codeForm = "
        <form action='" . site_url() . "/wp-admin/admin-post.php' method='post' class='stic-loading-form stic-code-form'
              data-loading-text='" . esc_attr__('Comprobando tu código…', 'sticpa') . "'
              data-loading-sub='" . esc_attr__('Un segundo y estás dentro.', 'sticpa') . "'>
            <label class='stic-code-label' for='stic-otp-code'>" . esc_html__('Código de 6 cifras', 'sticpa') . "</label>
            <input type='text' id='stic-otp-code' name='sticpa_otp_code' class='stic-code-input" . ($error === 'bad' ? " is-wrong" : "") . "'
                   inputmode='numeric' autocomplete='one-time-code' maxlength='7'
                   placeholder='000 000' aria-describedby='stic-code-hint' required" . ($appMode ? " autofocus" : "") . ">
            <p class='stic-code-hint' id='stic-code-hint'>" . sprintf(
                esc_html__('Caduca en %d minutos.', 'sticpa'),
                (int) round(sticpa_otp_ttl() / MINUTE_IN_SECONDS)
            ) . "</p>";

    if ($pending !== '') {
        $codeForm .= "<input type='hidden' name='sticpa_otp_email' value='" . esc_attr($pending) . "'>";
    } else {
        // Se pidió el código en otro sitio (típico: correo en el ordenador, app
        // en el móvil). Necesitamos saber de quién es el código.
        $codeForm .= "
            <label class='stic-code-label' for='stic-otp-email'>" . esc_html__('Tu correo', 'sticpa') . "</label>
            <span class='stic-field'>
                <span class='stic-field-icon'>" . sticpa_icon('mail') . "</span>
                <input type='email' class='input-text' id='stic-otp-email' name='sticpa_otp_email'
                       autocomplete='email' inputmode='email' placeholder='" . esc_attr__('nombre@correo.com', 'sticpa') . "' required>
            </span>";
    }

    $codeForm .= "
            <input type='hidden' name='action' value='sticpa_verify_code'>
            <input type='hidden' name='scp_current_url' value='" . esc_attr($return_url) . "'>
            <button type='submit' class='stic-btn-magic'>
                <span class='stic-btn-magic-icon'>" . sticpa_icon('check') . "</span>
                <span>" . esc_html__('Entrar', 'sticpa') . "</span>
            </button>
        </form>";

    if ($appMode) {
        $html .= "<div class='stic-code'>"
            . "<p class='stic-auth-help'>" . esc_html__('Escribe el código que te hemos mandado y entras directo.', 'sticpa') . "</p>"
            . $codeForm . "</div>";
    } else {
        $html .= "
            <details class='stic-code-reveal'" . ($errorMsg !== '' ? " open" : "") . ">
                <summary>" . sticpa_icon('lock', 'stic-hint-icon') . "<span>"
                    . esc_html__('¿Prefieres introducir el código?', 'sticpa') . "</span>"
                    . sticpa_icon('chevron', 'stic-hint-chevron') . "</summary>
                <div class='stic-code'>" . $codeForm . "</div>
            </details>";
    }

    /* ---------- Reenvío / volver ---------- */
    if ($pending !== '') {
        $html .= "
            <form action='" . site_url() . "/wp-admin/admin-post.php' method='post' class='stic-code-resend'>
                <input type='hidden' name='action' value='sticpa_send_access'>
                <input type='hidden' name='forgot-password-email-address' value='" . esc_attr($pending) . "'>
                <input type='hidden' name='scp_current_url' value='" . esc_attr($return_url) . "'>
                <span>" . esc_html__('¿No te llega?', 'sticpa') . "</span>
                <button type='submit'>" . esc_html__('Envíamelo otra vez', 'sticpa') . "</button>
            </form>";
    }

    $html .= "
        <p class='stic-auth-links'>
            <a href='" . esc_url($return_url) . "'>" . esc_html__('Usar otro correo', 'sticpa') . "</a>
        </p>";

    $html .= "</div>"; // .stic-auth-panel

    return $html;
}

function modify_plugin_locale_defaults($locale, $domain) {
    $locale = 'ca_ES';
    return $locale;
}

    

function sugar_crm_portal_check_user_and_login($html = "")
{
    if (isset($_REQUEST['scp_login_form_submit']) == true) {
        $scp_module = getDestinationModule();
        $scp_username = $_REQUEST['scp_username'];
        $scp_password = $_REQUEST['scp_password'];


        $objSCP = SugarRestApiCall::getObjSCP();

        $isLogin = $objSCP->PortalLogin($scp_username, $scp_password, $scp_module);
        if ((isset($isLogin->entry_list[0]) && $isLogin->entry_list[0] != null) && ($scp_username != null) && ($scp_password != null)) {
            $_SESSION['scp_module'] = $scp_module;
            $_SESSION['scp_user_id'] = $isLogin->entry_list[0]->id;
            $_SESSION['scp_user_contact_name'] = $isLogin->entry_list[0]->name_value_list->name->value;
            $_SESSION['scp_account_id'] = isset($isLogin->entry_list[0]->name_value_list->account_id) ? $isLogin->entry_list[0]->name_value_list->account_id->value : null;  
            $_SESSION['scp_user_account_name'] = $isLogin->entry_list[0]->name_value_list->stic_pa_username_c->value;
            $_SESSION['scp_user_assigned_user_id'] = $isLogin->entry_list[0]->name_value_list->assigned_user_id->value;
            $relationshipTypes = array();
            if (defined('RELATIONSHIP_TUTOR_TYPES')) {
                $relationshipTypes = RELATIONSHIP_TUTOR_TYPES;
                $_SESSION['scp_user_adult'] = check_user_adult($_SESSION['scp_user_id'], $relationshipTypes);
            } else {
                $_SESSION['scp_user_adult'] = true;
            }
            if (function_exists('sticpa_store_comunica_role')) {
                sticpa_store_comunica_role($isLogin->entry_list[0], $scp_module);
            }
            $html .= sugar_crm_portal_index();
        } else {
            // Login fallido: reabrimos directamente en la vista de usuario/contraseña.
            $html .= "<div class='stic-auth-shell'" . sticpa_theme_attr() . "><div class='stic-login-form stic-form'>";
            $html .= sugar_crm_portal_login_form("", 'password');
            $html .= "<span class='error' role='alert'>" . __('Username and/or password are not correct.', 'sticpa') . "</span>";
            $html .= "</div>" . sticpa_appearance_switch_html() . "</div>";

        }

    } elseif (isset($_REQUEST['sticpa_code'])) {
        // Acaba de pedir acceso: pantalla "mira tu correo" + código de 6 cifras.
        $html .= "<div class='stic-auth-shell'" . sticpa_theme_attr() . "><div class='stic-login-form stic-form'>";
        $html .= sticpa_access_code_form();
        $html .= "</div>" . sticpa_appearance_switch_html() . "</div>";
    } else {
        // Vista inicial: por defecto enlace mágico; 'password' si se pide con ?mode=password.
        $mode = (isset($_REQUEST['mode']) && $_REQUEST['mode'] === 'password') ? 'password' : 'magic';
        $html .= "<div class='stic-auth-shell'" . sticpa_theme_attr() . "><div class='stic-login-form stic-form'>";
        $html .= sugar_crm_portal_login_form("", $mode);
        if (isset($_REQUEST['signup']) && $_REQUEST['signup'] == true) {
            $html .= "<span class='success'>" . __('You have successfully signed up.', 'sticpa') . ".</span>";
        }
        $html .= "</div>" . sticpa_appearance_switch_html() . "</div>";
    }
    return $html;
}

function check_user_adult($userId, $relationshipTypes = array()) {
    $objSCP = SugarRestApiCall::getObjSCP();
    if (empty($relationshipTypes)) {
        return false;
    }
    $query = "((stic_personal_environment.start_date <= DATE(NOW()) AND (stic_personal_environment.end_date >= DATE(NOW()) OR stic_personal_environment.end_date IS NULL)) AND stic_personal_environment.relationship_type in (";

    foreach($relationshipTypes as $key => $type) {
        if ($key) {
            $query .= ',';
        }
        $query.= "'".$type ."'";
    }
    $query .= "))";
    $params = array(
        'module_name' => 'Contacts',
        "module_id" => $userId,
        "link_field_name" => 'stic_personal_environment_contacts_1',
        "related_module_query" => $query,
        "related_fields" => array('id'), 
        "related_module_link_name_to_fields_array" => array(),
        "deleted" => 0, //show or not deleted elements (usually 0)
        "order_by" => "",
        "offset" => "",
        "limit" => 0,
    );
    
    $getRelatedElements = $objSCP->getRelatedElementsForLoggedUser($params);
    if (empty($getRelatedElements)) {
        return true;
    }
    return false;
}

/**
 * Sanea el parámetro ?internalpage y devuelve la ruta del archivo de pages/ a
 * incluir, o '' si no es válido. IMPRESCINDIBLE: internalpage viene del
 * navegador y sin esta comprobación permitiría incluir archivos arbitrarios
 * del servidor (path traversal, p. ej. ../../wp-config).
 */
function sticpa_resolve_page_file($page)
{
    $page = (string) $page;
    // Solo minúsculas, números y guion bajo: es el formato de todos los archivos de pages/.
    if ($page === '' || !preg_match('/^[a-z0-9_]+$/', $page)) {
        return '';
    }
    $file = plugin_dir_path(__FILE__) . 'pages/' . $page . '.php';
    return file_exists($file) ? $file : '';
}

function sugar_crm_portal_index($html = "")
{
    // index
    $current_url = explode('?', $_SERVER['REQUEST_URI'], 2);
    $current_url = $current_url[0];

    $objSCP = SugarRestApiCall::getObjSCP();

    $html .= menu();

    if (!isset($_SESSION['scp_tutor_user_id']) && !isset($_REQUEST['internalpage'])) {
        if (isset($_SESSION['scp_user_adult']) && $_SESSION['scp_user_adult']) {
            // Landing tras el login: pantalla de bienvenida con accesos a secciones.
            $currentPage = 'single_stic_home';
        } else {
            $currentPage = 'single_stic_profile_selection';
        }
    } else {
        // Con sesión de tutor pero sin página pedida (URL "pelada"): a la home.
        $currentPage = $_REQUEST['internalpage'] ?? 'single_stic_home';
    }
    $pageFile = sticpa_resolve_page_file($currentPage);
    if ($pageFile !== '') {
        ob_start();
        include $pageFile;
        $returned = ob_get_contents();
        $html .= $returned;
        ob_end_clean();

    }
    // El control de apariencia va al final del contenido, dentro del contenedor
    // del área (así hereda los tokens del tema) y fuera de .stic-tab-content.
    $html .= "</div>" . sticpa_appearance_switch_html() . "
    </div>";


    return $html;
}

function sugar_crm_portal_signup($html = "")
{
    $current_url = explode('?', $_SERVER['REQUEST_URI'], 2);
    $current_url = $current_url[0];

    $html .='<div>';
    //We include the corresponding form based on the content of $_REQUEST
    $pageFile = sticpa_resolve_page_file($_REQUEST['internalpage'] ?? '');
    if ($pageFile !== '') {
        ob_start();
        require_once $pageFile;
        $returned = ob_get_contents();
        ob_end_clean();
        $html .= $returned;
    }
    $html .= "</div>
        </div>";

    return $html;

}

add_shortcode('sinergiacrm-private-area', 'sinergiacrm_private_area_shortcode'); // add shortcode [sinergiacrm-private-area]

/**
 * El área privada está detrás de login: no debe indexarse en buscadores.
 * Marca noindex/nofollow en cualquier página que contenga el shortcode.
 */
add_filter('wp_robots', 'sticpa_private_area_robots');
function sticpa_private_area_robots($robots)
{
    if (is_singular()) {
        $post = get_post();
        if ($post && has_shortcode($post->post_content, 'sinergiacrm-private-area')) {
            $robots['noindex'] = true;
            $robots['nofollow'] = true;
            unset($robots['max-image-preview']);
        }
    }
    return $robots;
}
function sinergiacrm_private_area_shortcode()
{
    // Load js only when shortcode is present
    dcms_insertar_js();

    if (isset($_SESSION['scp_user_id']) == true) {
        $content .= sugar_crm_portal_index();
    } else {
        if (isset($_REQUEST['internalpage']) && $_REQUEST['internalpage'] == 'single_stic_signup') {
            $content .= sugar_crm_portal_signup();

        } else {
            // Ya no hay pantalla de "he olvidado mi contraseña": se entra con el
            // código o el enlace del correo (pestaña "Por correo" del login) y,
            // una vez dentro, quien quiera contraseña se la pone en su perfil.
            // Un ?internalpage=stic_forgot_password antiguo cae aquí, en el login.
            $content .= sugar_crm_portal_check_user_and_login();
        }
    }
    return $content;
}

// El modo app (?app=1) y el tema claro/oscuro del área viven juntos en
// inc/stic-theme.php (se incluye arriba): sticpa_is_app_mode(),
// sticpa_theme_pref(), sticpa_theme_attr() y sticpa_appearance_switch_html().

/**
 * Duración de la sesión (cookie + recolección en servidor).
 *
 * Por defecto 1 año. Es una ventana DESLIZANTE: se renueva en cada visita
 * (ver más abajo), así que mientras el usuario entre al menos una vez al año
 * su sesión no caduca nunca. Ideal para el área embebida en la app (Expo) y
 * también para quien entra por web de forma esporádica.
 *
 * Se puede ajustar sin tocar código con el filtro `sticpa_session_ttl`.
 */
function sticpa_session_ttl()
{
    return (int) apply_filters('sticpa_session_ttl', YEAR_IN_SECONDS);
}

add_action('init', 'sugar_crm_portal_start_session', 1); // start session
function sugar_crm_portal_start_session()
{
    if (session_id()) {
        return; // sesión ya iniciada en esta petición
    }

    $ttl = sticpa_session_ttl();

    // La sesión debe sobrevivir en servidor al menos tanto como la cookie; si no,
    // el recolector de basura de PHP borraría los datos aunque la cookie siga viva.
    // (Nota: algunos hostings gestionan la limpieza de sesiones por su cuenta y
    //  pueden ignorar este ajuste; si ocurre, habría que usar un save_path propio.)
    if ((int) ini_get('session.gc_maxlifetime') < $ttl) {
        @ini_set('session.gc_maxlifetime', (string) $ttl);
    }

    // Cookie de sesión de larga duración (en vez de "hasta cerrar el navegador").
    $secure = is_ssl();
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => $ttl,
            'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax', // permite volver al área desde enlaces externos
        ));
    } else {
        session_set_cookie_params($ttl, '/', '', $secure, true);
    }

    session_start();

    // Ventana DESLIZANTE: PHP no reenvía la cookie de sesión si el navegador ya
    // trae una válida, así que la caducidad no se movería. La reenviamos nosotros
    // para que el año cuente desde la última vez que entró.
    //
    // Pero NO en cada petición: eso añadía un Set-Cookie a todas las respuestas
    // del sitio (también a las que no son del área) para mover una caducidad de
    // un año. Con reenviarla una vez al día la ventana sigue siendo deslizante a
    // todos los efectos y las respuestas quedan más limpias.
    $lastRefresh = isset($_SESSION['sticpa_cookie_refreshed']) ? (int) $_SESSION['sticpa_cookie_refreshed'] : 0;
    $needsRefresh = (time() - $lastRefresh) > DAY_IN_SECONDS;
    if ($needsRefresh && !headers_sent()) {
        $_SESSION['sticpa_cookie_refreshed'] = time();
        $params  = session_get_cookie_params();
        $expires = time() + $ttl;
        $path    = !empty($params['path']) ? $params['path'] : '/';
        if (PHP_VERSION_ID >= 70300) {
            setcookie(session_name(), session_id(), array(
                'expires'  => $expires,
                'path'     => $path,
                'domain'   => $params['domain'],
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => !empty($params['samesite']) ? $params['samesite'] : 'Lax',
            ));
        } else {
            setcookie(session_name(), session_id(), $expires, $path, $params['domain'], $secure, true);
        }
    }
}

if (isset($_REQUEST['logout'])) // logout
{
    add_action('init', 'sugar_crm_portal_louout', 1);
    function sugar_crm_portal_louout()
    {
        // TODO lo que identifica o describe a quien había entrado. Ojo al añadir
        // una clave de sesión nueva: si no se limpia aquí, la siguiente persona
        // que entre en el MISMO navegador la hereda — y en familias el móvil se
        // comparte. Aquí están también las cachés en sesión (rol, participantes
        // disponibles, aviso del certificado) precisamente por eso.
        $sessionKeysToClear = array(
            'scp_user_id',
            'scp_tutor_user_id',
            'scp_tutor_user_contact_name',
            'scp_account_id',
            'scp_user_account_name',
            'scp_user_contact_name',
            'scp_user_securitygroups',
            'scp_user_assigned_user_id',
            'scp_user_adult',
            'scp_tutor_is_user',
            'scp_module',
            // Cachés por usuario (ver inc/stic-comunica-roles.php y menu.php).
            'scp_role',
            'scp_relationship_raw',
            'scp_available_profiles',
            'scp_is_familia',
            'scp_ds_pending',
            // Sesión del CRM y su marca de tiempo (van en pareja).
            'api_session_id',
            'api_session_time',
        );
        foreach ($sessionKeysToClear as $sessionKey) {
            unset($_SESSION[$sessionKey]);
        }


        $redirect_url = explode('?', $_SERVER['REQUEST_URI'], 2);
        $redirect_url = $redirect_url[0];
        wp_redirect($redirect_url);
        exit;
    }
}

/**
 * ¿Estamos en una página que lleva el shortcode del área privada?
 * (Los estilos, scripts y el preload de la tipografía solo se encolan ahí, para
 * no ensuciar el resto de la web.)
 */
function sticpa_page_has_area_shortcode()
{
    global $post;
    return is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'sinergiacrm-private-area');
}

/**
 * Preload de la tipografía del cuerpo. Inter está AUTOALOJADA en fonts/ (ver los
 * @font-face al principio de css/stic-base.css): ya no hay preconnect a Google
 * porque ya no se sale del dominio. El preload sirve para que el .woff2 no espere
 * a que el navegador descubra la regla dentro de un CSS de 130 KB.
 */
add_action('wp_head', 'sticpa_preload_font', 2);
function sticpa_preload_font()
{
    if (!sticpa_page_has_area_shortcode()) {
        return;
    }
    printf(
        "<link rel='preload' href='%s' as='font' type='font/woff2' crossorigin>\n",
        esc_url(plugins_url('fonts/inter-latin-var.woff2', __FILE__))
    );
}

add_action('wp_enqueue_scripts', 'sugar_crm_portal_style_and_script'); // add custom style and script
function sugar_crm_portal_style_and_script()
{
    global $post;

    // only loads css if the shortcode is present, not polluting the rest of the site
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'sinergiacrm-private-area')) {
        // Versión = fecha de modificación del archivo: cada deploy rompe la caché
        // del navegador automáticamente (antes '3.8' fijo dejaba CSS antiguo cacheado).
        $ver = function ($rel) {
            $path = plugin_dir_path(__FILE__) . $rel;
            return file_exists($path) ? filemtime($path) : null;
        };
        // CSS de librerías CONDICIONAL por página (plan 010): en este hook
        // $_REQUEST['internalpage'] ya está disponible, y las páginas que usan
        // cada lib solo se alcanzan pidiéndolas explícitamente por URL (sin
        // internalpage se sirve home/login/selección, que no usan ninguna).
        // Se mantienen aquí (y no en dcms_insertar_js) para conservar el orden
        // de cascada: SIEMPRE antes de custom-style.css, que las tematiza.
        $page = function_exists('sticpa_current_internal_page') ? sticpa_current_internal_page() : '';

        // La tipografía (Inter) va AUTOALOJADA: sus @font-face están al principio
        // de css/stic-base.css y los .woff2 en fonts/. Antes se pedía a
        // fonts.googleapis.com, que además encadenaba un segundo origen para los
        // archivos: dos saltos DNS+TLS delante del primer pintado.
        // Capa BASE consolidada (UI-15: ex stic-style + stic-modern-style, en ese orden).
        wp_enqueue_style('stic-base', plugins_url('css/stic-base.css', __FILE__), array(), $ver('css/stic-base.css'));
        if (strpos($page, 'single_') === 0 && $page !== 'single_stic_activities_calendar') {
            wp_enqueue_style('stic-multiselect', plugins_url('css/selectize.css', __FILE__), array('stic-base'), $ver('css/selectize.css'));
        }
        if ($page === 'single_stic_activities_calendar') {
            wp_enqueue_style('fullcalendar', plugins_url('js/fullcalendar/lib/main.min.css', __FILE__), array(), $ver('js/fullcalendar/lib/main.min.css'));
        }
        if (strpos($page, 'list_') === 0) {
            // CSS de DataTables vendorizado (plan 010): misma versión 1.12.1 que
            // js/jquery.dataTables.min.js; antes venía del CDN en mitad del body.
            wp_enqueue_style('stic-datatables', plugins_url('css/vendor/jquery.dataTables.min.css', __FILE__), array('stic-base'), $ver('css/vendor/jquery.dataTables.min.css'));
        }
        // custom-style.css is loaded LAST on purpose so it can override/enhance everything above
        wp_enqueue_style('custom-style', plugins_url('css/custom-style.css', __FILE__), array('stic-base'), $ver('css/custom-style.css'));

        // Modo app (?app=1): esconder el header/footer del tema en la WebView.
        if (function_exists('sticpa_is_app_mode') && sticpa_is_app_mode()) {
            wp_add_inline_style('custom-style', sticpa_app_mode_css());
        }
    }

}

register_activation_hook(__FILE__, 'scp_folder');
function scp_folder()
{
    $upload_dir = wp_upload_dir();
    $upload_scp_uploads = $upload_dir['basedir'] . "/stic-uploads";
    if (!is_dir($upload_scp_uploads)) {
        wp_mkdir_p($upload_scp_uploads);
    }
}

function scp_deleteDirectory($dir)
{
    if (!file_exists($dir)) {
        return true;
    }

    if (!is_dir($dir)) {
        return unlink($dir);
    }

    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }

        if (!scp_deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }

    }

    return rmdir($dir);
}

register_uninstall_hook(__FILE__, 'sugar_crm_portal_uninstall'); // uninstall plug-in
function sugar_crm_portal_uninstall()
{
    delete_option('sticpa_scp_name');
    delete_option('sticpa_scp_host_url');
    delete_option('sticpa_scp_rest_url');
    delete_option('sticpa_scp_username');
    delete_option('sticpa_scp_password');
    delete_option('sticpa_scp_module');
    delete_option('sticpa_scp_area_url');
    delete_option('sticpa_magic_secret');

    $upload_dir = wp_upload_dir();
    $upload_scp_uploads = $upload_dir['basedir'] . "/stic-uploads";

    if (is_dir($upload_scp_uploads)) {
        scp_deleteDirectory($upload_scp_uploads);
    }
}
