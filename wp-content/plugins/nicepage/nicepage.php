<?php
/*
Plugin Name: Nicepage
Plugin URI: https://nicepage.com/
Description: Design websites with any images and texts in seconds!
Text Domain: nicepage
Version: 5.15.1
Author: Nicepage https://www.nicepage.com
Author URI: https://nicepage.com/
*/
defined('ABSPATH') or die;

$nicepage_plugin_data = get_file_data(
    __FILE__,
    array('Version' => 'Version')
);
$name_plugin_np = 'Nicepage';
$folder_name = 'nicepage';

$whiteLabelJson = get_option('whiteLabelName');
if (!$whiteLabelJson) {
    // Set active white label options
    update_option('whiteLabelName', json_encode(array('name' => $name_plugin_np, 'folder' => $folder_name)));
    $active_folder_name = $folder_name;
    $active_white_label_name = $name_plugin_np;
} else {
    // Get active white label options
    $whiteLabelOptions = json_decode($whiteLabelJson);
    $active_folder_name = isset($whiteLabelOptions->folder) ? $whiteLabelOptions->folder : $folder_name;
    $active_white_label_name = isset($whiteLabelOptions->name) ? $whiteLabelOptions->name : $name_plugin_np;
}

if (!file_exists(ABSPATH . 'wp-content/plugins/' . $active_folder_name) OR $active_white_label_name == null OR strtolower($active_white_label_name) == strtolower($name_plugin_np)) {
    register_activation_hook(__FILE__, 'NpImportNotice::resetImportNotice');
    register_activation_hook(__FILE__, 'NpImport::activation');
    register_activation_hook(__FILE__, 'NpImport::importCustomParameters');

    define('APP_PLUGIN_NAME', $name_plugin_np);
    define('APP_PLUGIN_URL', plugin_dir_url(__FILE__));
    define('APP_PLUGIN_PATH', plugin_dir_path(__FILE__));
    define('APP_PLUGIN_VERSION', $nicepage_plugin_data['Version']);
    define('APP_PLUGIN_WIZARD_NAME', 'Plugin Wizard');

    include_once dirname(__FILE__) . '/functions.php';
    include_once dirname(__FILE__) . '/editor/class-np-editor.php';
    include_once dirname(__FILE__) . '/importer/class-np-import.php';
    include_once dirname(__FILE__) . '/includes/class-np-settings.php';
    include_once dirname(__FILE__) . '/includes/class-np-role-manager.php';
    include_once dirname(__FILE__) . '/includes/translations/class-np-multi-languages.php';
    include_once dirname(__FILE__) . '/updater/class-np-updater.php';
    register_deactivation_hook(__FILE__, 'NpImportNotice::addImportNoticeOption');
    register_deactivation_hook(__FILE__, 'NpImportNotice::removePluginDatabaseTable');
    register_deactivation_hook(__FILE__, 'NpImportNotice::restartThemeImportContent');
} else {
    if ($name_plugin_np != $active_white_label_name) {
        add_action('admin_init', 'same_plugin_off');
        /**
         * Same plugin off
         */
        function same_plugin_off() {
            deactivate_plugins(plugin_basename(__FILE__));
        }
        add_action('admin_notices', 'same_plugin_error_notice');
        /**
         * Same plugin notice
         */
        function same_plugin_error_notice(){
            $name_plugin_np = 'Nicepage';
            $whiteLabelJson = get_option('whiteLabelName');
            $whiteLabelOptions = json_decode($whiteLabelJson);
            $active_white_label_name = isset($whiteLabelOptions->name) ? $whiteLabelOptions->name : $name_plugin_np;
            echo ("<div class=\"error\"><p>Unable to activate <strong>".$name_plugin_np."</strong> plugin, because plugin <strong>".$active_white_label_name."</strong> is already activated. Please deactivate <strong>".$active_white_label_name."</strong> first.</p></div>");
        }
    }
}
