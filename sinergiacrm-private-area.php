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

/* Log to File
 * Description: Log into system php error log, usefull for Ajax and stuff that FirePHP doesn't catch
 */
function my_log_file($msg, $name = '')
{
    // Print the name of the calling function if $name is left empty
    $trace = debug_backtrace();
    $name = ('' == $name) ? $trace[1]['function'] : $name;

    $error_dir = './wordpress.log';
    $msg = print_r($msg, true);
    date_default_timezone_set('Europe/Andorra');
    $now = date('d/m/Y H:i:s', time());
    $log = $now . " | " . $name . "  |  " . $msg . "\n";
    error_log($log, 3, $error_dir);
}

//function to easy debug
function debug($v, $n)
{
    echo '<pre style="font-size:10px;border:1px solid red;">';

    echo '<b>___' . $n . '________________________</b><br>';
    print_r($v);
    echo '</pre>';
}

function sticpa_load_languages()
{
    $text_domain = 'sticpa';
    $path_languages = basename(dirname(__FILE__)) . '/languages/';
    load_plugin_textdomain($text_domain, false, $path_languages);
}

include plugin_dir_path(__FILE__) . 'inc/stic-action.php';
include plugin_dir_path(__FILE__) . 'inc/stic-magic-login.php';
include plugin_dir_path(__FILE__) . 'inc/stic-comunica-roles.php';

add_action('admin_menu', 'sugar_crm_portal_create_menu');

// Add JS script for form management
// Don't add the action to avoid including the js in all the pages of the site. Instead it is loaded when the shortcode is applied
// add_action("wp_enqueue_scripts", "dcms_insertar_js");
function dcms_insertar_js()
{
    wp_register_script('sugarcrm', plugin_dir_url(__FILE__) . 'js/iban.js', array('jquery'), '1', true);
    wp_enqueue_script('sugarcrm');
    wp_register_script('fullcalendar', plugin_dir_url(__FILE__) . 'js/fullcalendar/lib/main.js', array('jquery'), '1', true);
    wp_enqueue_script('fullcalendar');
    wp_register_script('fullcalendar-locale', plugin_dir_url(__FILE__) . 'js/fullcalendar/lib/locales-all.min.js', array('jquery'), '1', true);
    wp_enqueue_script('fullcalendar-locale');
    wp_register_script('sugarcrm-own', plugin_dir_url(__FILE__) . 'js/stic-utils.js', array('jquery'), '1', true);
    wp_enqueue_script('sugarcrm-own');
    wp_register_script('custom-utils', plugin_dir_url(__FILE__) . 'js/custom-utils.js', array('jquery'), '1', true);
    wp_enqueue_script('custom-utils');
    // UI helpers: overlay de carga + toggle de contraseña (sin dependencias)
    wp_register_script('stic-ui', plugin_dir_url(__FILE__) . 'js/stic-ui.js', array(), '1.0', true);
    wp_enqueue_script('stic-ui');
    // We use only one file for plugin literals, so although theoretically we should call this function twice (one efor each js), we only call it once.
    wp_localize_script('sugarcrm-own', 'stic_script_vars', getSticScriptVars());
    wp_register_script('multiselect', plugin_dir_url(__FILE__) . 'js/selectize.min.js', array('jquery'), '1', true);
    wp_enqueue_script('multiselect');
    wp_register_script('datatables', plugin_dir_url(__FILE__) . 'js/jquery.dataTables.min.js', array('jquery'), '1', true);
    wp_enqueue_script('datatables');
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
            'desc' => __('Tus datos como tutor/a.', 'sticpa'),
            'icon' => "<circle cx='12' cy='8' r='4'/><path d='M4 21v-1a8 8 0 0 1 16 0v1'/>",
        ),
        'single_stic_profile_selection' => array(
            'desc' => __('Cambia de perfil.', 'sticpa'),
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
            'desc' => __('Consulta y edita tus datos personales y de contacto.', 'sticpa'),
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

function sugar_crm_portal_login_form($html = "", $mode = 'magic')
{
    $scp_name = get_option('sticpa_scp_name');
    $title = __('Hola de nuevo', 'sticpa');
    $subtitle = $scp_name != null
        ? sprintf(__('Accede a tu área privada de %s.', 'sticpa'), $scp_name)
        : __('Accede a tu área privada.', 'sticpa');

    // URL de retorno para el enlace mágico (debe llevar un '?' para que el handler
    // pueda añadir '&success=true' al redirigir de vuelta a esta misma pantalla).
    $base_url = strtok($_SERVER['REQUEST_URI'], '?');
    $return_url = $base_url . '?stic_auth=1';

    // Cabecera de marca.
    $html .= "
        <div class='stic-auth-brand'>
            <div class='stic-auth-logo'>" . sticpa_icon('shield') . "</div>
            <div>
                <p class='stic-auth-kicker'>" . __('Área privada', 'sticpa') . "</p>
                <h3>" . esc_html($title) . "</h3>
                <p class='stic-auth-sub'>" . esc_html($subtitle) . "</p>
            </div>
        </div>";

    // Mensaje genérico tras pedir un enlace mágico (no revela si el email existe).
    $magicMsg = "";
    if (isset($_REQUEST['success']) && $_REQUEST['success'] == true) {
        $magicMsg = "<span class='success'>" . __('Si tu email está registrado, te hemos enviado un enlace de acceso. Revisa tu bandeja de entrada. 📩', 'sticpa') . "</span>";
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

    /* ---------- VISTA 1: ENLACE MÁGICO (por defecto) ---------- */
    $html .= "
        <div class='stic-auth-view stic-auth-magic'>
            " . $magicMsg . "
            <p class='stic-auth-help'>
                <span class='stic-sparkle' aria-hidden='true'>" . sticpa_icon('sparkles') . "</span>
                " . __('Introduce tu email y te enviamos un enlace para entrar sin contraseña.', 'sticpa') . "
            </p>
            <form action='" . site_url() . "/wp-admin/admin-post.php' method='post' class='stic-loading-form'
                  data-loading-text='" . esc_attr__('Enviando tu enlace de acceso…', 'sticpa') . "'
                  data-loading-sub='" . esc_attr__('En unos segundos lo tendrás en tu correo.', 'sticpa') . "'>
                <ul>
                    <li class='input_login'>
                        <label for='stic-magic-email'>" . __('Tu correo electrónico', 'sticpa') . "</label>
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
                        <input type='hidden' name='action' value='stic_forgot_password'>
                        <input type='hidden' name='scp_current_url' value='" . esc_attr($return_url) . "'>
                        <button type='submit' class='stic-btn-magic'>
                            <span class='stic-btn-magic-icon'>" . sticpa_icon('send') . "</span>
                            <span>" . __('Enviar enlace de acceso', 'sticpa') . "</span>
                        </button>
                    </li>
                </ul>
            </form>
            <p class='stic-auth-switch'>
                <a href='?mode=password' data-auth-toggle='password'>" . __('¿Tienes una contraseña? Inicia sesión', 'sticpa') . "</a>
            </p>
        </div>";

    /* ---------- VISTA 2: USUARIO + CONTRASEÑA ---------- */
    $html .= "
        <div class='stic-auth-view stic-auth-login'>
            <form name='stic-login-form' id='stic-login-form' class='stic-loading-form' action='' method='post'
                  data-loading-text='" . esc_attr__('Verificando tus datos…', 'sticpa') . "'
                  data-loading-sub='" . esc_attr__('Estamos comprobando tu acceso de forma segura.', 'sticpa') . "'>
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
            <p class='stic-auth-switch'>
                <a href='?mode=magic' data-auth-toggle='magic'>" . sticpa_icon('sparkles', 'stic-inline-icon') . " " . __('Prefiero entrar con un enlace mágico', 'sticpa') . "</a>
            </p>
        </div>";

    $html .= "</div>"; // .stic-auth

    // Registro (común a ambas vistas).
    $html .= "
        <p class='stic-auth-links' style='text-align:center;margin-top:1.1rem;font-size:0.92rem;color:var(--gray-500);'>"
        . __('¿Todavía no tienes cuenta?', 'sticpa') . " <a href='?internalpage=single_stic_signup'>" . __('Consulta cómo conseguirlo', 'sticpa') . "</a>
        </p>";

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
            $html .= "<div class='stic-auth-shell'><div class='stic-login-form stic-form'>";
            $html .= sugar_crm_portal_login_form("", 'password');
            $html .= "<span class='error'>" . __('Username and/or password are not correct.', 'sticpa') . "</span>";
            $html .= "</div></div>";

        }

    } else {
        // Vista inicial: por defecto enlace mágico; 'password' si se pide con ?mode=password.
        $mode = (isset($_REQUEST['mode']) && $_REQUEST['mode'] === 'password') ? 'password' : 'magic';
        $html .= "<div class='stic-auth-shell'><div class='stic-login-form stic-form'>";
        $html .= sugar_crm_portal_login_form("", $mode);
        if (isset($_REQUEST['signup']) && $_REQUEST['signup'] == true) {
            $html .= "<span class='success'>" . __('You have successfully signed up.', 'sticpa') . ".</span>";
        }
        $html .= "</div></div>";
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

function sugar_crm_portal_forgot_password($html = "")
{
    // Passwordless access: the user enters their email and we send a magic link.
    $current_url = explode('?', $_SERVER['REQUEST_URI'], 2);
    $current_url = $current_url[0] . '?internalpage=stic_forgot_password';

    $html .= "<div class='stic-auth-shell'><div class='stic-forgotpas-form stic-form'>";

    $html .= "
        <div class='stic-auth-brand'>
            <div class='stic-auth-logo'>" . sticpa_icon('sparkles') . "</div>
            <div>
                <p class='stic-auth-kicker'>" . __('Acceso sin contraseña', 'sticpa') . "</p>
                <h3>" . __('Enlace mágico', 'sticpa') . "</h3>
                <p class='stic-auth-sub'>" . __('Sin contraseñas que recordar.', 'sticpa') . "</p>
            </div>
        </div>";

    if (isset($_REQUEST['success']) && $_REQUEST['success'] == true) {
        // Mensaje genérico a propósito (no revela si el email existe o no).
        $html .= "<span class='success'>" . __('Si tu dirección de email está registrada, recibirás un enlace de acceso en breve. Revisa tu bandeja de entrada.', 'sticpa') . "</span>";
    }

    if (isset($_REQUEST['error']) && $_REQUEST['error'] == 1) {
        $html .= "<span class='error'>" . __('Algo ha ido mal. Inténtalo de nuevo más tarde o contacta con el administrador.', 'sticpa') . "</span>";
    }

    $html .= "
            <p class='stic-auth-help'>" . __('Introduce tu dirección de email y te enviaremos un enlace para acceder a tu área privada sin contraseña.', 'sticpa') . "</p>
            <form action='" . site_url() . "/wp-admin/admin-post.php' method='post' class='stic-loading-form'
                  data-loading-text='" . esc_attr__('Enviando tu enlace de acceso…', 'sticpa') . "'
                  data-loading-sub='" . esc_attr__('En unos segundos lo tendrás en tu correo.', 'sticpa') . "'>
                <ul>
                    <li class='field_signup'>
                        <label for='forgot-password-email-address'>" . __('Introduce tu dirección de email', 'sticpa') . "</label>
                        <span class='stic-field'>
                            <span class='stic-field-icon'>" . sticpa_icon('mail') . "</span>
                            <input class='input-text' type='email' name='forgot-password-email-address' id='forgot-password-email-address' autocomplete='email' required />
                        </span>
                    </li>
                    <li class='stic-send'>
                        <input type='hidden' name='action' value='stic_forgot_password'>
                        <input type='hidden' name='scp_current_url' value='" . $current_url . "'>
                        <input type='submit' value='" . __('Envíame el enlace de acceso', 'sticpa') . "' />
                    </li>
                </ul>
            </form>
            <p class='stic-auth-links' style='text-align:center;margin-top:1.25rem;font-size:0.92rem;'>
                <a href='?'>" . __('← Volver al inicio de sesión', 'sticpa') . "</a>
            </p>";
    $html .= "</div></div>";

    return $html;
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
        $currentPage = $_REQUEST['internalpage'];
    }
    if (!$currentPage == '') {
        ob_start();
        include plugin_dir_path(__FILE__) . 'pages/' . $currentPage . '.php';
        $returned = ob_get_contents();
        $html .= $returned;
        ob_end_clean();

    }
    $html .= "</div>
    </div>";


    return $html;
}

function sugar_crm_portal_signup($html = "")
{
    $current_url = explode('?', $_SERVER['REQUEST_URI'], 2);
    $current_url = $current_url[0];

    $html .='<div>';
    //We include the corresponding form based on the content of $_REQUEST
    if (!$_REQUEST['internalpage'] == '') {
        ob_start();
        require_once plugin_dir_path(__FILE__) . 'pages/' . $_REQUEST['internalpage'] . '.php';
        $returned = ob_get_contents();
        ob_end_clean();
        $html .= $returned;
    }
    $html .= "</div>
        </div>";

    return $html;

}

add_shortcode('sinergiacrm-private-area', 'sinergiacrm_private_area_shortcode'); // add shortcode [sinergiacrm-private-area]
function sinergiacrm_private_area_shortcode()
{
    // Load js only when shortcode is present
    dcms_insertar_js();

    if (isset($_SESSION['scp_user_id']) == true) {
        $content .= sugar_crm_portal_index();
    } else {
        if (isset($_REQUEST['internalpage']) && $_REQUEST['internalpage'] == 'single_stic_signup') {
            $content .= sugar_crm_portal_signup();

        } else if (isset($_REQUEST['internalpage']) && $_REQUEST['internalpage'] == 'stic_forgot_password') {
            $content .= sugar_crm_portal_forgot_password();
        } else {
            $content .= sugar_crm_portal_check_user_and_login();
        }
    }
    return $content;
}

add_action('init', 'sugar_crm_portal_start_session', 1); // start session
function sugar_crm_portal_start_session()
{
    if (!session_id()) {
        session_start();
    }
}

if (isset($_REQUEST['logout']) == 'true') // logout
{
    add_action('init', 'sugar_crm_portal_louout', 1);
    function sugar_crm_portal_louout()
    {
        unset($_SESSION['scp_user_id']);
        unset($_SESSION['scp_tutor_user_id']);
        unset($_SESSION['scp_tutor_user_contact_name']);
        unset($_SESSION['scp_account_id']);
        unset($_SESSION['scp_user_account_name']);
        unset($_SESSION['scp_user_contact_name']);
        unset($_SESSION['api_session_id']);
        unset($_SESSION['scp_user_securitygroups']);
        unset($_SESSION['scp_user_assigned_user_id']);
        unset($_SESSION['scp_user_adult']);
        unset($_SESSION['scp_tutor_is_user']);
        unset($_SESSION['scp_module']);
        
        $redirect_url = explode('?', $_SERVER['REQUEST_URI'], 2);
        $redirect_url = $redirect_url[0];
        wp_redirect($redirect_url);
        exit;
    }
}

add_action('wp_enqueue_scripts', 'sugar_crm_portal_style_and_script'); // add custom style and script
function sugar_crm_portal_style_and_script()
{
    global $post;

    // only loads css if the shortcode is present, not polluting the rest of the site
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'sinergiacrm-private-area')) {
        // Modern typography (Inter) loaded from Google Fonts
        wp_enqueue_style('stic-google-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap', array(), null);
        wp_enqueue_style('stic-style', plugins_url('css/stic-style.css', __FILE__));
        wp_enqueue_style('stic-multiselect', plugins_url('css/selectize.css', __FILE__));
        wp_enqueue_style('stic-modern-style', plugins_url('css/stic-modern-style.css', __FILE__));
        wp_enqueue_style('fullcalendar', plugins_url('js/fullcalendar/lib/main.css', __FILE__));
        // custom-style.css is loaded LAST on purpose so it can override/enhance everything above
        wp_enqueue_style('custom-style', plugins_url('css/custom-style.css', __FILE__), array('stic-modern-style'), '3.0');
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
