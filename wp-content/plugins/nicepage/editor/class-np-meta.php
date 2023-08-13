<?php
defined('ABSPATH') or die;

class NpMeta {

    public static $baseTableName = 'nicepage_meta';
    public static $_cache = array();

    /**
     * Initialize method
     */
    public static function init() {
        global $wpdb;
        $wpdb->nicepage_meta = $wpdb->prefix . self::$baseTableName;
        include_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $tableCache = wp_cache_get('np_meta_table');
        if (!$tableCache) {
            $np_meta_table = $wpdb->get_var("SHOW TABLES LIKE '$wpdb->nicepage_meta'");
            wp_cache_set('np_meta_table', $np_meta_table);
            $tableCache = $np_meta_table;
        }
        if ($tableCache !== $wpdb->nicepage_meta) {
            // first load = add table to database
            self::updateTable();
        }
    }

    /**
     * Get nicepage_meta value
     *
     * @param string $meta_key
     *
     * @return string|false
     */
    public static function get($meta_key) {
        global $wpdb;
        $wpCache = wp_cache_get('all_np_meta');
        if (!$wpCache) {
            $all_np_meta = $wpdb->get_results("SELECT * FROM $wpdb->nicepage_meta");
            wp_cache_set('all_np_meta', $all_np_meta);
            $wpCache = $all_np_meta;
        }
        if (!isset(self::$_cache[$meta_key])) {
            self::$_cache[$meta_key] = '';
            $keys = array_map(
                function ($e) {
                    return is_object($e) ? $e->meta_key : $e['meta_key'];
                }, $wpCache
            );
            $keyIndex = array_search($meta_key, $keys);
            if ($keyIndex !== false) {
                if (isset($wpCache[$keyIndex]->meta_value)) {
                    self::$_cache[$meta_key] = $wpCache[$keyIndex]->meta_value;
                }
            }
        }
        return self::$_cache[$meta_key];
    }

    /**
     * Update nicepage_meta value
     *
     * @param string $meta_key
     * @param string $meta_value
     */
    public static function update($meta_key, $meta_value) {
        global $wpdb;

        // TODO: may be optimize using UPDATE
        $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->nicepage_meta WHERE meta_key=%s", $meta_key));
        $wpdb->query($wpdb->prepare("INSERT INTO $wpdb->nicepage_meta (meta_key, meta_value) VALUES (%s, %s)", $meta_key, $meta_value));
        self::$_cache[$meta_key] = $meta_value;
    }

    /**
     * Create nicepage_meta table if it not exists
     */
    public static function updateTable() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $wpdb->nicepage_meta (
          id mediumint(9) NOT NULL AUTO_INCREMENT,
          meta_key varchar(255) NOT NULL,
          meta_value longtext DEFAULT '' NOT NULL,
          PRIMARY KEY  (id)
        ) $charset_collate;";

        dbDelta($sql);
    }
}
NpMeta::init();