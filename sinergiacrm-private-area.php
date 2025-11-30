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

function sugar_crm_portal_login_form($html = "")
{
    // login form
    $scp_name = get_option('sticpa_scp_name');
    if ($scp_name != null) {
        $html .= "<h3>".__('Sign in', 'sticpa')."</h3>";
    } else {
        $html .= "<h3>Private Area</h3>";
    }
    $languageHtml = "";
    if (function_exists('pll_the_languages')) {
        $languageSelector = pll_the_languages(array('dropdown' => 1,'show_flags'=>1,'show_names'=>0,'hide_current'=>1, 'echo' => 0));

        $languageHtml = "
        <li class='input_login'>
            <label>" . __('Language', 'sticpa') . ": </label>
            <span>".$languageSelector."</span>
        </li>
        ";
    } elseif (shortcode_exists('wpml_language_selector_widget')) {
        $languageHtml = "
        <li class='input_login'>
            <label>" . __('Language', 'sticpa') . ": </label>
            <div class='wpml-switcher'>".do_shortcode('[wpml_language_selector_widget]')."
            </div>
        </li>
        ";
    }
    $moduleSelectHTML = "";
    if(get_option('sticpa_scp_module') == "Any") {
        $moduleSelectHTML = "
        <li class='input_login'>
            <label>" . __('Login as', 'sticpa') . ": </label>
            <select name='scp_module' id='stic-module'>
                <option value='Contacts'>" . __('Contact', 'sticpa') . "</option>
                <option value='Accounts'>" . __('Account', 'sticpa') . "</option>
            </select>
        </li>

        ";
    }
    $html .= "
        <form name='stic-login-form' id='stic-login-form' action='' method='post'>
            <ul>
                ".$languageHtml."
                ".$moduleSelectHTML."
                <li class='input_login'>
                    <label>" . __('Username', 'sticpa') . ":</label>
                    <span><input type='text' class='input-text' name='scp_username' id='stic-username' required></span>
                </li>

                <li class='input_login'>
                    <label>" . __('Password', 'sticpa') . ":</label>
                    <span><input type='password' class='input-text' name='scp_password' id='stic-password' required></span>
                </li>
                <li class='actions_login'>
                    <span>
                        <input type='submit' name='scp_login_form_submit' id='stic-login-form-submit' value='" . __('Start session', 'sticpa') . "'>
                    </span>
                       <span class='left'>
                            <a href='?internalpage=single_stic_signup'>" . __('Are you NOT registered? Click here.', 'sticpa') . "</a> <br />
                            <a href='?internalpage=stic_forgot_password'>" . __('Forgot your password? Click here.', 'sticpa') . "</a>
                        </span>
                </li>
            </ul>
        </form>";
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
            $html .= sugar_crm_portal_index();
        } else {
            $html .= "<div class='stic-login-form stic-form'>";
            $html .= sugar_crm_portal_login_form();
            $html .= "<span class='error'>" . __('Username and/or password are not correct.', 'sticpa') . "</span>";
            $html .= "</div>";

        }

    } else {
        $scp_password = $_REQUEST['scp_password'] ?? null;
        $html .= "<div class='stic-login-form stic-form'>";
        $html .= sugar_crm_portal_login_form();
        if (isset($_REQUEST['signup']) && $_REQUEST['signup'] == true) {
            $html .= "<span class='success'>" . __('You have successfully signed up.', 'sticpa') . ".</span>";
        }
        $html .= "</div>";
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
    // forgot password
    $current_url = explode('?', $_SERVER['REQUEST_URI'], 2);
    $current_url = $current_url[0] . '?internalpage=stic_forgot_password';
    
    $html .= "<div class='stic-entry-header'>
            <h3>" . __('Password', 'sticpa') . "</h3><br>";

    if (isset($_REQUEST['success']) && $_REQUEST['success'] == true) {
        $html .= "<span class='success'>" . __('Your password was successfully sent.', 'sticpa') . "</span>";
    }

    if (isset($_REQUEST['error']) && $_REQUEST['error'] == 1) {
        $html .= "<span class='error'>" . __('Failed to send your password. Please, try it again later or contact the administrator.', 'sticpa') . "</span>";
    }

    if (isset($_REQUEST['error']) && $_REQUEST['error'] == 2) {
        $html .= "<span class='error'>" . __('Your email address does not match.', 'sticpa') . "</span>";
    }

    if (isset($_REQUEST['error']) && $_REQUEST['error'] == 3) {
        $html .= "<span class='error'>" . __('Your username does not exist.', 'sticpa') . "</span>";
    }
    
    $moduleSelectHTML = "";
    if(getDestinationModule() == "Any") {
        $moduleSelectHTML = "
        <li class='input_login'>
            <label>" . __('Select your user type', 'sticpa') . ": </label>
            <select name='scp_module' id='stic-module'>
                <option value='Contacts' > " . __('Contact', 'sticpa') . " </option>
                <option value='Accounts' > " . __('Account', 'sticpa') . " </option>
            </select>
        </li>
        ";
    }

    $html .= "
        <div class='stic-form stic-form-two-col'>
            <form action='" . site_url() . "/wp-admin/admin-post.php' method='post'>
                <ul>
                ".$moduleSelectHTML."
                    <li class='field_signup'>
                                <label>" . __('Enter your username', 'sticpa') . ":</label>
                                <span><input class='input-text' type='text' name='forgot-password-username' id='forgot-password-username' required /> </span>

                    </li>
                    <li class='field_signup'>
                                <label>" . __('Enter your email address', 'sticpa') . ":</label>
                                <span><input class='input-text' type='email' name='forgot-password-email-address' id='forgot-password-email-address' required /> </span>

                    </li>
                    <li class='stic-send'>
                    <input type='hidden' name='action' value='stic_forgot_password'><!--updated on 09-nov-2015-->
                                <input type='hidden' name='scp_current_url' value='" . $current_url . "'>
                                <span class='desc'><input type='submit' value='" . __('Submit', 'sticpa') . "' /></span>
                    </li>
                </ul>
            </form>
        </div>";
        $html .= "</div>";

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
            $currentPage = defaultMenuElement();
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
        wp_enqueue_style('stic-style', plugins_url('css/stic-style.css', __FILE__));
        wp_enqueue_style('stic-multiselect', plugins_url('css/selectize.css', __FILE__));
        wp_enqueue_style('custom-style', plugins_url('css/custom-style.css', __FILE__));
        wp_enqueue_style('stic-modern-style', plugins_url('css/stic-modern-style.css', __FILE__));
        wp_enqueue_style('fullcalendar', plugins_url('js/fullcalendar/lib/main.css', __FILE__));
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

    $upload_dir = wp_upload_dir();
    $upload_scp_uploads = $upload_dir['basedir'] . "/stic-uploads";

    if (is_dir($upload_scp_uploads)) {
        scp_deleteDirectory($upload_scp_uploads);
    }
}
