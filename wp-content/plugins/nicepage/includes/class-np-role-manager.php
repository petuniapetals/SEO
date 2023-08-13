<?php
defined('ABSPATH') or die;

class NpRoleManager extends NpSettings {

    public static $options = array();
    public static $excluded_roles = array();
    public static $defaultOptions;

    /**
     * Initialize options and defaults
     */
    public static function init() {
        self::$options[] = array (
            'name' => '',
            'desc' => 'The list shows the Roles having access to edit pages (<b>edit_pages</b>).',
            'type' => 'heading',
        );
        foreach ( get_editable_roles() as $role_slug => $role_data ) {
            $role = get_role($role_slug);
            if ('administrator' === $role_slug) {
                continue;
            }
            self::$defaultOptions['np_' . $role_slug . '_options'] = 'default';
            if (!isset($role->capabilities['edit_pages'])) {
                self::$excluded_roles[] = ucfirst($role_slug);
                continue;
            }
            self::$options[] = array (
                'id'   => 'np_' . $role_slug . '_options',
                'name' => ucfirst($role_slug),
                'type' => 'radio',
                'options' => array (
                    'default' => 'Design pages and edit content',
                    'contentEditOnly' => 'Edit content only',
                    'editorNoAccess' => 'No access to editor',
                ),
            );
        }
        self::$options[] = array (
            'name' => '',
            'desc' => 'The <b>' . implode(", ", self::$excluded_roles) . '</b> Roles do not have permission to edit pages.',
            'type' => 'heading',
        );
    }

    /**
     * Get Nicepage option value.
     * Returns default value if option is not set.
     *
     * @param string $name
     *
     * @return mixed|false
     */
    public static function getOption($name) {
        $result = get_option($name);
        if ($result === false) {
            $result = _arr(self::$defaultOptions, $name);
        }
        return $result;
    }

    /**
     * Print settings admin-page
     */
    public static function roleManagerPage() {
        echo '<style>
            #np_options_form .form-table {
                margin-bottom: 30px;
            }
            .radio-p {
                margin-top: 1px!important;
            }
            .radio-p input {
                margin-top: -3px!important;
            }
        </style>';
        ?>
        <div class="wrap">
        <div id="icon-themes" class="icon32"><br /></div>
        <h2><?php _e('Role Manager', 'nicepage'); ?></h2>
        <?php
        if (isset($_REQUEST['Submit'])) {
            foreach (self::$options as $value) {
                $id = _arr($value, 'id');
                $val = stripslashes(_arr($_REQUEST, $id, ''));
                $type = _arr($value, 'type');
                switch ($type) {
                case 'checkbox':
                    $val = $val ? 1 : 0;
                    break;
                case 'numeric':
                    $val = (int)$val;
                    break;
                }
                update_option($id, $val);
            }
            echo '<div id="message" class="updated fade"><p><strong>' . __('Settings saved.', 'nicepage') . '</strong></p></div>' . "\n";
        }
        if (isset($_REQUEST['Reset'])) {
            foreach (self::$options as $value) {
                delete_option(_arr($value, 'id'));
            }
            echo '<div id="message" class="updated fade"><p><strong>' . __('Settings restored.', 'nicepage') . '</strong></p></div>' . "\n";
        }
        echo '<form method="post" id="np_role_options_form">' . "\n";
        $in_form_table = false;
        $dependent_fields = array();
        $op_by_id = array();
        $used_when = __('Used when <strong>"%s"</strong> is enabled', 'nicepage');

        foreach (self::$options as $op) {
            $id = _arr($op, 'id');
            $type = _arr($op, 'type');
            $name = _arr($op, 'name');
            $desc = _arr($op, 'desc');
            $script = _arr($op, 'script');
            $depend = _arr($op, 'depend');
            $show = _arr($op, 'show', true);

            if (is_bool($show) && !$show || is_callable($show) && !call_user_func($show)) {
                continue;
            }

            $op_by_id[$id] = $op;
            if ($depend) {
                $dependent_fields[] = array($depend, $id);
                $desc = (!$desc ? '' : $desc . '<br />') . sprintf($used_when, _arr(_arr($op_by_id, $depend), 'name', 'section'));
            }
            if ($type == 'heading') {
                if ($in_form_table) {
                    echo '</table>' . "\n";
                    $in_form_table = false;
                }
                echo '<h3>' . $name . '</h3>' . "\n";
                if ($desc) {
                    echo "\n" . '<p>' . $desc .  '</p>' . "\n";
                }
            } else {
                if (!$in_form_table) {
                    echo '<table class="form-table">' . "\n";
                    $in_form_table = true;
                }
                echo '<tr valign="top">' . "\n";
                echo '<th scope="row">' . $name . '</th>' . "\n";
                echo '<td>' . "\n";
                $val = self::getOption($id);
                self::printOptionControl($op, $val);
                if ($desc) {
                    echo '<span>' . $desc . '</span>' . "\n";
                }
                if ($script) {
                    echo '<script>' . $script . '</script>' . "\n";
                }
                echo '</td>' . "\n";
                echo '</tr>' . "\n";
            }
        }
        if ($in_form_table) {
            echo '</table>' . "\n";
        }
        echo "<script>\r\n";
        for ($i = 0; $i < count($dependent_fields); $i++) {
            echo "makeDependentField('{$dependent_fields[$i][0]}', '{$dependent_fields[$i][1]}');" . PHP_EOL;
        }
        ?>
        jQuery('#np_role_options_form').bind('submit', function() {
        jQuery('input, textarea', this).each(function() {
        jQuery(this).removeAttr('disabled').removeClass('disabled');
        });
        });
        </script>
    <p class="submit">
        <input name="Submit" type="submit" class="button-primary" value="<?php echo esc_attr(__('Save Changes', 'nicepage')) ?>" />
        <input name="Reset" type="submit" class="button-secondary" value="<?php echo esc_attr(__('Reset to Default', 'nicepage')) ?>" />
        </p>
        </form>
        <?php do_action('np_options'); ?>
        <p>Please use one of the following plugins, <a target="_blank" href="https://wordpress.org/plugins/user-role-editor/">User Role Editor</a> or <a target="_blank" href="https://wordpress.org/plugins/capability-manager-enhanced/">PublishPress Capabilities</a>, to modify the Role permissions.</p>
    </div>
        <?php
    }
}

if (is_admin()) {
    NpRoleManager::init();
}