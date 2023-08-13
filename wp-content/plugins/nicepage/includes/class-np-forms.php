<?php
defined('ABSPATH') or die;

class NpFormFields {
    public $fields = array();

    /**
     * Parse fields from Nicepage publishHtml
     *
     * @param string $form_html
     */
    public function parseFromHtml($form_html) {
        preg_match_all('#<(input|textarea|select)([^>]*)>#', $form_html, $matches);

        $radioButtons = array();
        $checkboxIds = array();
        $checkboxElements = array();
        for ($i = 0; $i < count($matches[0]); $i++) {
            $attrs = $matches[2][$i];
            if (!preg_match('#name="([^"]*)#', $attrs, $m) || strpos($attrs, 'type="hidden"') !== false) {
                continue;
            }
            $name = $m[1];

            if ($name === 'name') { // see detect_unavailable_names
                $name = 'name1';
            }

            if ($matches[1][$i] === 'select') {
                $selectRegExp = '#<select [\s\S]+? name=["|\']' . $name . '["|\']([^>]*)>([\s\S]+?)<\/select>#';
                preg_match_all($selectRegExp, $form_html, $matchesSelect);
                $optionHtml = preg_replace('/data-calc=["\'][\s\S]*?["\'] ?/', '', $matchesSelect[2][0]);
                $optionHtml = preg_replace('/ +?selected="[\s\S]+?"/', '', $optionHtml);
                preg_match_all('#<option value=[\'|"]([\s\S]+?)[\'|"] *?>#', $optionHtml, $matchesOption);
            }

            $required = strpos($attrs, 'required') !== false;
            $multiple = strpos($attrs, 'multiple') !== false;

            $field = array(
                'required' => $required,
                'name' => $name,
            );
            if ($matches[1][$i] === 'select') {
                $field['option'] = $matchesOption[1];
                $field['multiple'] = $multiple;
                $field['type'] = 'select';
            }
            if (strpos($attrs, 'type="radio"') !== false) {
                preg_match('#value=["|\']([\s\S]+?)["|\']#', $attrs, $matchesValue);
                $field['value'] = $matchesValue[1];
                if (!array_key_exists($name, $radioButtons)) {
                    $this->fields[] = array();
                    $radioButtons[$name] = array(
                        'type' => 'radio',
                        'name' => $field['name'],
                        'default' => 'default:1',
                        'option' => array($field['value']),
                        'index' => count($this->fields) - 1,

                    );
                } else {
                    array_push($radioButtons[$name]['option'], $field['value']);
                }
            } else if (strpos($attrs, 'type="checkbox"') !== false) {
                preg_match('#value=["|\']([\s\S]+?)["|\']#', $attrs, $matchesValue);
                preg_match('#id=["|\']([\s\S]+?)["|\']#', $attrs, $matchesId);
                $field['value'] = $matchesValue[1];
                $checkboxId = isset($matchesId[1]) ? $matchesId[1] : 0;
                if (!array_key_exists($checkboxId, $checkboxIds)) {
                    //$this->fields[] = array();
                    if (!isset($checkboxElements[$name])) {
                        $checkboxElements[$name] = array();
                    }
                    $checkboxIds[$checkboxId] = $checkboxId;
                    $counter = count($checkboxElements[$name]);
                    $checkboxElements[$name][$counter] = array(
                        'type' => 'checkbox',
                        'name' => str_replace('[]', '', $field['name']),
                        'value' => $field['value'],
                    );
                }
            } else if (strpos($attrs, 'type="file"') !== false) {
                $field['type'] = "file";
                preg_match('#accept=["|\']([\s\S]*?)["|\']#', $attrs, $matchesValue);
                $field['accept'] = isset($matchesValue[1]) && $matchesValue[1] ? $matchesValue[1] : 'ALL';
                if (strpos($field['name'], '[') !== false) {
                    $field['name'] = str_replace(array('[', ']'), array('',''), $field['name']);
                }
                $this->fields[] = $field;
            } else {
                $this->fields[] = $field;
            }
        }
        foreach ($radioButtons as $key=> $radio) {
            $this->fields[$radio['index']] = $radio;
        }
        foreach ($checkboxElements as $key=> $checkbox) {
            if (count($checkbox) > 1) {
                $checkboxValues = array();
                foreach ($checkbox as $id=> $item) {
                    $checkboxValues[] = $item['value'];
                    if ($id + 1 === count($checkbox)) {
                        //last checkbox group element
                        $item['value'] = $checkboxValues;
                        $this->fields[] = $item;
                    }
                }
            } else {
                //single checkbox element
                $this->fields[] = $checkbox[0];
            }
        }
    }

    /**
     * Convert to contact7 format
     *
     * @return string
     */
    public function toString() {
        $result = '';
        foreach ($this->fields as $field) {
            $type = isset($field['type']) ? $field['type'] : 'text';
            if (isset($field['option'])) {
                $optionStr = '';
                foreach ($field['option'] as $option) {
                    $optionStr .= ' "' . $option . '"';
                }

                $tagName = _arr(self::$_nameTags, $field['name'], $type);
                $required = isset($field['required']) && $field['required'] ? '*' : '';
                $multiple = isset($field['multiple']) && $field['multiple'] ? ' multiple' : '';
                $default = isset($field['default']) ? (' ' . $field['default']) : '';
                $result .= sprintf("[%s%s %s%s%s%s]\n", $tagName, $required, $field['name'], $multiple, $default, $optionStr);
            } else if ($type === 'file') {
                if (isset(self::$formats[$field['accept']])) {
                    $allowed_formats = self::$formats[$field['accept']] ? ' filetypes:' . self::$formats[$field['accept']] : '';
                } else {
                    // custom formats
                    $allowed_formats = ' filetypes:' . str_replace(array(',', '.'), array('|', ''), $field['accept']);
                }
                $max_file_size = ' limit:10485760'; // 10mb
                $result .= sprintf("[%s%s %s%s%s]\n", _arr(self::$_nameTags, $field['name'], $field['type']), $field['required'] ? '*' : '', $field['name'], $max_file_size, $allowed_formats);
            } else if ($type === 'checkbox') {
                $valuesStr = '';
                if (isset($field['value'])) {
                    foreach ($field['value'] as $value) {
                        $valuesStr .= ' "' . $value . '"';
                    }
                }
                $result .= sprintf("[%s%s %s%s]\n", _arr(self::$_nameTags, $field['name'], $field['type']), $field['required'] ? '*' : '', $field['name'], $valuesStr);
            } else {
                $result .= sprintf("[%s%s %s]\n", _arr(self::$_nameTags, $field['name'], $type), $field['required'] ? '*' : '', $field['name']);
            }
        }
        $result .= "[submit]\n";
        return $result;
    }

    /**
     * Check for existing field with such name
     *
     * @param string $name
     *
     * @return bool
     */
    public function hasField($name) {
        foreach ($this->fields as $field) {
            if ($field['name'] === $name) {
                return true;
            }
        }
        return false;
    }

    private static $_nameTags = array(
        'email' => 'email',
        'tel' => 'tel',
        'message' => 'textarea',
        'select' => 'select',
        'radio' => 'radio',
        'file' => 'file',
        'checkbox' => 'checkbox',
    );

    public static $formats = array (
        'IMAGES' => 'bmp|dng|eps|gif|jpg|jpeg|png|ps|raw|svg|tga|tif|tiff',
        'DOCUMENTS' => 'ai|cdr|csv|doc|docb|docx|dot|dotx|dwg|eps|epub|fla|gpx|ical|icalendar|ics|ifb|indd|ipynb|key|kml|kmz|mobi|mtf|mtx|numbers|odg|odp|ods|odt|otp|ots|ott|oxps|pages|pdf|pdn|pkg|pot|potx|pps|ppsx|ppt|pptx|psd|pub|rtf|sldx|txt|vcf|xcf|xls|xlsx|xlt|xltx|xlw|xps|zip',
        'VIDEO' => '3gp|avi|divx|flv|m1v|m2ts|m4v|mkv|mov|mp4|mpe|mpeg|mpg|mxf|ogv|vob.webm|wmv|xvid',
        'AUDIO' => 'aac|aif|aiff|flac|m4a|mp3|wav|wma',
        'ALL' => 'bmp|dng|eps|gif|jpg|jpeg|png|ps|raw|svg|tga|tif|tiff|ai|cdr|csv|doc|docb|docx|dot|dotx|dwg|eps|epub|fla|gpx|ical|icalendar|ics|ifb|indd|ipynb|key|kml|kmz|mobi|mtf|mtx|numbers|odg|odp|ods|odt|otp|ots|ott|oxps|pages|pdf|pdn|pkg|pot|potx|pps|ppsx|ppt|pptx|psd|pub|rtf|sldx|txt|vcf|xcf|xls|xlsx|xlt|xltx|xlw|xps|zip|3gp|avi|divx|flv|m1v|m2ts|m4v|mkv|mov|mp4|mpe|mpeg|mpg|mxf|ogv|vob.webm|wmv|xvid|aac|aif|aiff|flac|m4a|mp3|wav|wma',
    );
}


class NpForms {
    public static $formRe = '#<form[^>]*?source="contact7"[\s\S]*?<\/form>#';

    /**
     * Update forms data sources
     * Create new if needed
     *
     * @param string|int  $post_id
     * @param string      $templateName
     * @param string|bool $templateContent
     *
     * @return array form data sources array
     */
    public static function updateForms($post_id, $templateName = '', $templateContent = false) {
        if (!class_exists('WPCF7_ContactForm')) {
            return array();
        }
        $optionName = 'np_forms';
        if ($templateName === 'header' || $templateName === 'footer' || $templateName === 'dialogs') {
            $optionName = $optionName . '_' . $templateName;
            if ($templateName === 'dialogs') {
                $dialogName = $post_id;
                $post_id = 0;
                $optionName = $optionName . '_' . $dialogName;
            }
            $prev_data_sources = get_option($optionName);
        } else {
            $prev_data_sources = NpMetaOptions::get($post_id, $optionName);
        }
        $content = $templateContent ? $templateContent : np_data_provider($post_id)->getPagePublishHtml();
        preg_match_all(self::$formRe, $content, $matches);
        $count = count($matches[0]);

        $data_sources = array();

        for ($i = 0; $i < $count; $i++) {
            $form_html = $matches[0][$i];

            $form_id = isset($prev_data_sources[$i]['id']) ? $prev_data_sources[$i]['id'] : 0;
            $contact_form = null;

            if ($form_id) {
                $contact_form = wpcf7_contact_form($form_id);
            }

            if (!$form_id || !$contact_form) {
                $form_id = 0;
                $contact_form = WPCF7_ContactForm::get_template();
                if ($templateName === 'header') {
                    $form_title = sprintf(__('Header Nicepage Form: %s', 'nicepage'), get_post($post_id)->post_title);
                } else if ($templateName === 'footer') {
                    $form_title = sprintf(__('Footer Nicepage Form: %s', 'nicepage'), get_post($post_id)->post_title);
                } else if ($templateName === 'dialogs') {
                    $form_title = sprintf(__('Dialog Nicepage Form: %s', 'nicepage'), $dialogName);
                } else {
                    $form_title = sprintf(__('Nicepage Form: %s', 'nicepage'), get_post($post_id)->post_title);
                }
                if ($count > 1) {
                    $form_title .= ' (' . ($i + 1) . ')';
                }
                $contact_form->set_title($form_title);
            }

            $fields = new NpFormFields();
            $fields->parseFromHtml($form_html);

            $properties = $contact_form->get_properties();
            $properties['form'] = $fields->toString();

            if (!$form_id) {
                $defaultMail = array(
                    '"[your-subject]"',
                    "Subject: [your-subject]\n",
                    '[your-email]',
                    '[your-name]',
                    '[your-message]',
                );

                $actualMail = array(
                    __('feedback', 'nicepage'),
                    '',
                    '[email]',
                    $fields->hasField('name1') ? '[name1]' : '',
                );

                $customFields = '';
                $attachmentsFields = '';
                foreach ($fields->fields as $field) {
                    if ($field['name'] !== 'email' && $field['name'] !== 'name1') {
                        $customField = $fields->hasField($field['name']) ? '[' . $field['name'] . ']' : '';
                        $customFields .= $field['name'] . ': ' . $customField . ', ';
                    }
                    if (isset($field['type']) && $field['type'] === 'file') {
                        $attachmentsFields .= '[' . $field['name'] . ']';
                    }
                }
                $actualMail[] = $customFields;

                foreach (array('mail', 'mail_2') as $mail_key) {
                    foreach ($properties[$mail_key] as $key => &$prop) {
                        if (is_string($prop)) {
                            if ($key === 'attachments') {
                                $prop = $attachmentsFields;
                            } else {
                                $prop = str_replace(
                                    $defaultMail,
                                    $actualMail,
                                    $prop
                                );
                            }
                        }
                    }
                }
            }
            $contact_form->set_properties($properties);

            $form_id = $contact_form->save();

            $data_sources[] = array(
                'id' => $form_id,
            );
        }

        if ($templateName === 'header' || $templateName === 'footer' || $templateName === 'dialogs') {
            update_option($optionName, $data_sources);
        } else {
            NpMetaOptions::update($post_id, $optionName, $data_sources);
        }
        return $data_sources;
    }

    /**
     * Get form data sources for post
     *
     * @param string|int $post_id
     * @param array      $params
     *
     * @return array
     */
    public static function getPageForms($post_id, $params) {
        $templateName = isset($params['templateName']) ? $params['templateName'] : '';
        $dialogName = isset($params['dialogName']) ? $params['dialogName'] : '';
        $optionName = 'np_forms';
        if ($templateName === 'header' || $templateName === 'footer' || $templateName === 'dialogs') {
            $optionName = $optionName . '_' . $templateName;
            if ($templateName === 'dialogs') {
                $optionName = $optionName . '_' . $dialogName;
            }
            $data_sources = get_option($optionName);
        } else {
            $data_sources = NpMetaOptions::get($post_id, $optionName);
        }
        if (!$data_sources) {
            $data_sources = array();
        }
        return $data_sources;
    }

    public static $_formHtml;

    /**
     * Filter on wpcf7_form_elements
     * Replace default contact7 fields with Nicepage fields
     *
     * @param string $html
     *
     * @return string
     */
    public static function _formElementsFilter($html) {
        $fields_html = preg_replace('#<form[^>]*>#', '', self::$_formHtml);
        $fields_html = str_replace('</form>', '', $fields_html);
        preg_match('/type="file"[\s\S]*?name="([\s\S]*?)"/', $fields_html, $matches);
        $file_input_name = isset($matches[1]) ? $matches[1] : '';
        if (strpos($file_input_name, '[') !== false) {
            $file_input_name = str_replace(array('[', ']'), array('',''), $file_input_name);
            $fields_html = str_replace($matches[1], $file_input_name, $fields_html);
        }
        $html = str_replace(
            array(
                'name="name"',
                'u-input ',
                'u-form-submit',
                'u-form-group',
                'u-file-group',
                'u-btn-submit ',
                'accept="IMAGES"',
                'accept="DOCUMENTS"',
                'accept="VIDEO"',
                'accept="AUDIO"',
                'multiple="multiple"',
            ),
            array(
                'name="name1"',
                'u-input wpcf7-form-control ',
                'u-form-submit wpcf7-form-control',
                'u-form-group wpcf7-form-control-wrap',
                'u-file-group wpcf7-form-control-wrap ' . $file_input_name,
                'u-btn-submit wpcf7-submit ',
                'accept=".jpg,.jpeg,.png,.gif"',
                'accept=".pdf,.doc,.docx,.ppt,.pptx,.odt"',
                'accept=".avi,.mov,.mp4,.mpg,.wmv"',
                'accept=".ogg,.m4a,.mp3,.wav"',
                '',
            ),
            $fields_html
        );
        $html .= '<input type="hidden" name="_contact7_backend" value="1">';
        return $html;
    }

    /**
     * Process Nicepage form html
     *
     * @param string|int $form_id
     * @param string     $form_raw_html
     *
     * @return string
     */
    public static function getHtml($form_id, $form_raw_html) {
        if (function_exists('wpcf7_contact_form') && $form_id && ($contact_form = wpcf7_contact_form($form_id))) {
            self::$_formHtml = $form_raw_html;

            add_filter('wpcf7_form_elements', 'NpForms::_formElementsFilter', 9);
            add_filter('wpcf7_form_novalidate', '__return_false');

            $form_class = '';
            $form_style = '';
            if (preg_match('#<form.*?class="([^"]*)#', $form_raw_html, $m)) {
                $form_class = $m[1];
            }
            if (preg_match('#<form.*?style="([^"]*)#', $form_raw_html, $s)) {
                $form_style = $s[1];
            }
            $form_html = $contact_form->form_html(array('html_class' => $form_class . ' u-form-custom-backend'));
            if (strpos($form_raw_html, 'redirect="true"') !== false && preg_match('#redirect-address="([^"]*)"#', $form_raw_html, $m)) {
                $form_html = str_replace('<form', '<form redirect-address="' . $m[1] . '"', $form_html);
            }
            if ($form_style) {
                $form_html = str_replace('<form', '<form style="' . $form_style . '"', $form_html);
            }

            remove_filter('wpcf7_form_elements', 'NpForms::_formElementsFilter', 9);
            remove_filter('wpcf7_form_novalidate', '__return_false');
        } else {
            $form_html = preg_replace('#action="[^"]*#', 'action="#', $form_raw_html);
        }
        return $form_html;
    }

    /**
     * Common scripts and styles for all forms
     *
     * @return string
     */
    public static function getScriptsAndStyles() {
        ob_start(); ?>
        <script>
            function onSuccess(event) {
                if (typeof window.serviceRequest !== 'undefined') {
                    window.serviceRequest(jQuery(this).find('form'));
                }
                var msgContainer = jQuery(event.currentTarget).find('.wpcf7-response-output');
                msgContainer.removeClass('u-form-send-error').addClass('u-form-send-message u-form-send-success');
                msgContainer.show();
                var redirectAddress = jQuery(event.currentTarget).find('[redirect-address]').attr('redirect-address');
                if (redirectAddress) {
                    setTimeout(function () {
                        location.replace(redirectAddress);
                    }, 2000);
                }
            }
            function onError(event) {
                var msgContainer = jQuery(event.currentTarget).find('.wpcf7-response-output');
                msgContainer.removeClass('u-form-send-success').addClass('u-form-send-message u-form-send-error');
                msgContainer.show();
            }

            jQuery('body')
                .on('wpcf7mailsent',   '.u-form .wpcf7', onSuccess)
                .on('wpcf7invalid',    '.u-form .wpcf7', onError)
                .on('wpcf7:unaccepted', '.u-form .wpcf7', onError)
                .on('wpcf7spam',       '.u-form .wpcf7', onError)
                .on('wpcf7:aborted',    '.u-form .wpcf7', onError)
                .on('wpcf7mailfailed', '.u-form .wpcf7', onError);
        </script>
        <style>
            .u-form .wpcf7-response-output {
                /*position: relative !important;*/
                margin: 0 !important;
                bottom: -70px!important;
            }
            .u-form .wpcf7 .ajax-loader {
                margin-left: -24px;
                margin-right: 0;
            }
        </style><?php
        return ob_get_clean();
    }

    /**
     * Filter on wpcf7_ajax_json_echo
     * Replace selectors for Nicepage forms
     *
     * @param array $items
     *
     * @return array
     */
    public static function _ajaxJsonEchoFilter($items) {
        if (isset($_POST['_contact7_backend']) && !empty($items['invalids'])) {
            foreach ($items['invalids'] as &$invalid) {
                $invalid['into'] = str_replace('span.wpcf7-form-control-wrap.', 'div.u-form-group.u-form-', $invalid['into']);
            }
        }
        return $items;
    }
}

add_filter('wpcf7_ajax_json_echo', 'NpForms::_ajaxJsonEchoFilter');