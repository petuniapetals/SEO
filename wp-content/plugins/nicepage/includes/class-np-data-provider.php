<?php
defined('ABSPATH') or die;

require_once dirname(__FILE__) . '/class-np-shortcodes.php';
require_once dirname(__FILE__) . '/class-np-data-product.php';
require_once dirname(__FILE__) . '/class-np-data-replacer.php';

class NpDataProvider {

    public $page_id;
    public $preview;
    public $saveAndPublish;
    public $language;

    /**
     * NpDataProvider constructor.
     *
     * @param int|string $page_id        Page Id
     * @param bool|null  $preview        Need or not preview version. Default - $_REQUEST['isPreview']
     * @param bool|null  $saveAndPublish Need or not saveAndPublish page. Default - $_REQUEST['saveAndPublish']
     */
    public function __construct($page_id = 0, $preview = null, $saveAndPublish = true) {
        $this->page_id = $page_id;
        $this->language = isset($_GET['lang']) ? $_GET['lang'] : false;

        if (is_bool($preview)) {
            $this->preview = $preview;
        } else {
            $this->preview = isset($_REQUEST['isPreview']) && ($_REQUEST['isPreview'] === 'true' || $_REQUEST['isPreview'] === '1');
        }
        if (is_bool($saveAndPublish)) {
            $this->saveAndPublish = $saveAndPublish;
        } else {
            $this->saveAndPublish = isset($_REQUEST['saveAndPublish']) && ($_REQUEST['saveAndPublish'] === 'true' || $_REQUEST['saveAndPublish'] === '1');
        }
        $this->_doBackward();
    }

    /**
     * Returns true if page have Nicepage content
     *
     * @return bool
     */
    public function isNp() {
        return !!$this->_getPostMeta('_np_html') || !!$this->_getPostMeta('_np_html_auto_save');
    }

    /**
     * Returns true if now preview in editor
     *
     * @return bool
     */
    public function isPreview() {
        return !!$this->preview;
    }

    /**
     * Returns true if page content is empty
     *
     * @return bool
     */
    public function isEmpty() {
        $post = get_post($this->page_id);
        if (!$post) {
            return true;
        }
        return $post->post_content === '';
    }

    /**
     * Returns true is page will be converted, false if it will be edited
     *
     * @return bool
     */
    public function isConvertRequired() {
        return !$this->isNp() && !$this->isEmpty();
    }

    /**
     * Wrapper for update_post_meta with wp_slash
     * need to neutralize wp_unslash($meta_value) in update_metadata function
     *
     * @param string $meta_key
     * @param string $meta_value
     */
    private function _updatePostMeta($meta_key, $meta_value) {
        $meta_value = wp_slash($meta_value);
        if ($this->preview) {
            $meta_key .= '_preview';
        }
        if (!$this->preview && !$this->saveAndPublish) {
            $meta_key .= '_auto_save';
        }
        update_post_meta($this->page_id, $meta_key, $meta_value);
    }

    /**
     * Get Post Meta
     *
     * @param string $meta_key Meta Key
     *
     * @return mixed
     */
    private function _getPostMeta($meta_key) {
        if ($this->preview) {
            $result = get_post_meta($this->page_id, $meta_key . '_preview', true);
            if ($result) {
                return $result;
            }
        }
        if (!$this->preview && !$this->saveAndPublish) {
            $result = get_post_meta($this->page_id, $meta_key . '_auto_save', true);
            if ($result) {
                return $result;
            }
        }
        return get_post_meta($this->page_id, $meta_key, true);
    }

    /**
     * Remove Post Meta
     *
     * @param string $meta_key Meta Key
     */
    private function _removePostMeta($meta_key) {
        if ($this->preview) {
            $meta_key .= '_preview';
        }
        if (!$this->preview && $this->saveAndPublish) {
            $meta_key .= '_auto_save';
        }
        delete_post_meta($this->page_id, $meta_key);
    }

    /**
     * Get page password protection
     */
    public function getPasswordProtection() {
        $post = get_post($this->page_id);
        $result = $post ? $post->post_password : '';
        return $result;
    }

    /**
     * Set page password protection
     *
     * @param string $value
     */
    public function setPasswordProtection($value) {
        if ($value || $value == '') {
            wp_update_post(array('ID' => $this->page_id, 'post_password' => $value));
        }
    }

    /**
     * Get page password protection
     */
    public function getPasswordProtectionData() {
        $content = get_option('passwordProtectNp');
        if ($content) {
            $content = $this->fixImagePaths($content);
            return json_decode($content, true);
        }
        return '';
    }

    /**
     * Set page password protection
     *
     * @param string $data
     */
    public function setPasswordProtectionData($data) {
        update_option('passwordProtectNp', json_encode($data));
    }

    /**
     * Update password protection in the editor
     *
     * @param string $content Content
     *
     * @return mixed
     */
    public function updateDataPassword($content) {
        $passwordProtection = $this->getPasswordProtection();
        if (false === strpos($content, 'data-password')) {
            $content = preg_replace('/<body/', '<body data-password="' . $passwordProtection . '" ', $content);
        } else {
            $content = preg_replace('/data-password="[\s\S]*?"/', 'data-password="' . $passwordProtection . '"', $content);
        }
        return $content;
    }

    /**
     * Get header/footer/passwordProtect translations
     *
     * @param $item
     * @param $name
     *
     * @return string
     */
    public function getTranslation($item, $name) {
        $content = $item['php'];
        if ($this->language) {
            $optionName = $this->language . '_' . $name . 'Np';
            if (get_option($optionName)) {
                $content = get_option($optionName);
            }
            if (get_option('np_default_lang') && $this->language === get_option('np_default_lang')) {
                $content = $item['php'];
            }
        }
        $content = $this->fixImagePaths($content);
        return $content;
    }

    /**
     * Get header/footer/passwordProtect translations
     *
     * @param $html
     * @param $name
     * @param $lang
     */
    public function setTranslation($html, $name, $lang) {
        $optionName = $lang . '_' . $name . 'Np';
        update_option($optionName, $html);
    }

    /**
     * Get editable header
     */
    public function getNpHeader() {
        $headerOptionName = 'headerNp';
        if ($this->preview) {
            $headerOptionName .= '_preview';
        }
        if (!$this->preview && !$this->saveAndPublish) {
            $headerOptionName .= '_auto_save';
        }
        $content = get_option($headerOptionName);
        $content = !$content ? get_option('headerNp', true) : $content;
        $content = $this->fixImagePaths($content);
        return $content;
    }

    /**
     * Get editable footer
     */
    public function getNpFooter() {
        $footerOptionName = 'footerNp';
        if ($this->preview) {
            $footerOptionName .= '_preview';
        }
        if (!$this->preview && !$this->saveAndPublish) {
            $footerOptionName .= '_auto_save';
        }
        $content = get_option($footerOptionName);
        $content = !$content ? get_option('footerNp', true) : $content;
        $content = $this->fixImagePaths($content);
        return $content;
    }

    /**
     * Set editable header
     *
     * @param string $data
     */
    public function setNpHeader($data) {
        $headerOptionName = 'headerNp';
        if ($this->preview) {
            $headerOptionName .= '_preview';
        }
        if (!$this->preview && !$this->saveAndPublish) {
            $headerOptionName .= '_auto_save';
        }
        update_option($headerOptionName, $data);
    }

    /**
     * Set editable footer
     *
     * @param string $data
     */
    public function setNpFooter($data) {
        $footerOptionName = 'footerNp';
        if ($this->preview) {
            $footerOptionName .= '_preview';
        }
        if (!$this->preview && !$this->saveAndPublish) {
            $footerOptionName .= '_auto_save';
        }
        update_option($footerOptionName, $data);
    }

    /**
     * Remove editable header
     */
    private function _removeNpHeader() {
        $headerOptionName = 'headerNp';
        if ($this->preview) {
            $headerOptionName .= '_preview';
        }
        if (!$this->preview && $this->saveAndPublish) {
            $headerOptionName .= '_auto_save';
        }
        delete_option($headerOptionName);
    }

    /**
     * Remove editable footer
     */
    private function _removeNpFooter() {
        $footerOptionName = 'footerNp';
        if ($this->preview) {
            $footerOptionName .= '_preview';
        }
        if (!$this->preview && $this->saveAndPublish) {
            $footerOptionName .= '_auto_save';
        }
        delete_option($footerOptionName);
    }

    /**
     * Get page html
     * This html used only in Nicepage editor
     *
     * @return string
     */
    public function getPageHtml() {
        $return = $this->_getPostMeta('_np_html');
        $return = $this->fixImagePaths($return);
        $return = $this->updateDataPassword($return);
        return $return ? $return : '';
    }

    /**
     * Set page html
     *
     * @param string $html
     */
    public function setPageHtml($html) {
        $html = $this->replaceImagePaths($html);
        $html = wp_encode_emoji($html);
        $this->_updatePostMeta('_np_html', $html);
    }

    /**
     * Get stub when invalid products json
     *
     * @return array
     */
    public function getProjectProductsFiles() {
        return array();
    }

    /**
     * Get products json
     * This json used in the payment products buttons
     *
     * @return array[]
     */
    public function getProductsJson() {
        $return = json_encode(get_option('_products_json'));
        $return = json_decode($this->fixImagePaths($return), true);
        return $return ? $return : array('products' => array ());
    }

    /**
     * Save products json for payment products buttons
     *
     * @param $json
     */
    public function saveProductsJson($json) {
        update_option('_products_json', $json);
    }

    /**
     * Create unique id for product from productsJson
     *
     * @param string $content Content
     * @param array  $ids     Old ids with value = new ids
     *
     * @return mixed
     */
    public function replaceProductButtonIds($content, $ids) {
        if (false !== strpos($content, 'u-payment-button')) {
            foreach ($ids as $old_id => $new_id) {
                $content = str_replace('data-product-id="' . $old_id . '"', 'data-product-id="' . $new_id . '"', $content);
            }
        }
        return $content;
    }

    /**
     * Backup page content before "turn to NP"
     *
     * @param $html
     */
    public function setPageContent($html) {
        $this->_updatePostMeta('_page_content', $html);
    }

    /**
     * Get base page content
     *
     * @return string
     */
    public function getPageContent() {
        $return = $this->_getPostMeta('_page_content');
        return $return ? $return : '';
    }

    /**
     * Turn base page to NP
     *
     * @return bool
     */
    public function turnToNp() {
        $post = get_post($this->page_id);
        if (!$post) {
            return false;
        }
        $this->setPageContent($post->post_content);
        $this->setPageHtml('');
        return true;
    }

    /**
     * Get page publish-html
     * This html used in live site
     *
     * @return string
     */
    public function getPagePublishHtml() {
        $meta_name = '_np_publish_html';
        global $post;
        $post_id = isset($post->ID) ? $post->ID : get_the_ID();
        if ($this->language) {
            if (get_option('np_default_lang') && $this->language !== get_option('np_default_lang')) {
                if (metadata_exists('post', $post_id, $this->language . '_np_publish_html')) {
                    $meta_name = $this->language . '_np_publish_html';
                }
            }
        }
        $return = $this->_getPostMeta($meta_name);
        $return = $this->fixImagePaths($return);
        return $return ? $return : '';
    }

    /**
     * Set page publish-html
     *
     * @param string $html
     */
    public function setPagePublishHtml($html) {
        $html = $this->replaceImagePaths($html);
        $html = wp_encode_emoji($html);
        $this->_updatePostMeta('_np_publish_html', $html);
    }

    /**
     * Set page publish-html translations
     *
     * @param array $publish_html_translations
     */
    public function setPagePublishHtmlTranslations($publish_html_translations) {
        if ($publish_html_translations) {
            foreach ($publish_html_translations as $lang => $publish_html_translation) {
                $publish_html_translation = $this->replaceImagePaths($publish_html_translation);
                $publish_html_translation = wp_encode_emoji($publish_html_translation);
                $this->_updatePostMeta($lang . '_np_publish_html', $publish_html_translation);
            }
        }
    }

    /**
     * Set header/footer publish-html
     *
     * @param string $html
     *
     * @return string $html
     */
    public function setHeaderFooterPublishHtml($html) {
        return $html;
    }

    /**
     * Get page styles (css rules without <style> tag)
     *
     * @return string
     */
    public function getPageHead() {
        $return = $this->_getPostMeta('_np_head');
        $return = $this->fixImagePaths($return);
        return $return ? $return : '';
    }

    /**
     * Set page styles
     *
     * @param string $head
     */
    public function setPageHead($head) {
        $head = $this->replaceImagePaths($head);
        $this->_updatePostMeta('_np_head', $head);
    }

    /**
     * Get page fonts (string with <link> tags)
     *
     * @return string
     */
    public function getPageFonts() {
        $return = $this->_getPostMeta('_np_fonts');
        $return = str_replace('|', urlencode('|'), $return);
        return $return ? $return : '';
    }

    /**
     * Set page fonts
     *
     * @param string $html
     */
    public function setPageFonts( $html) {
        $this->_updatePostMeta('_np_fonts', $html);
    }

    /**
     * Get page backlink html
     *
     * @return string
     */
    public function getPageBacklink() {
        $return = $this->_getPostMeta('_np_backlink');
        return $return ? $return : '';
    }

    /**
     * Set page backlink html
     *
     * @param string $html
     */
    public function setPageBacklink($html) {
        $this->_updatePostMeta('_np_backlink', $html);
    }

    /**
     * Get page body class (space separated string of classes)
     *
     * @return string
     */
    public function getPageBodyClass() {
        $return = $this->_getPostMeta('_np_body_class');
        if (!$return) {
            $return = '';
        }
        if (strpos($return, '-mode') === false) {
            $return = $return . ' u-xl-mode';
        }
        return $return;
    }

    /**
     * Get page body styles (space separated string of classes)
     *
     * @return string
     */
    public function getPageBodyStyle() {
        $return = $this->_getPostMeta('_np_body_style');
        if (!$return) {
            $return = '';
        }
        $return = $this->fixImagePaths($return);
        return $return;
    }

    /**
     * Get page body data-bg for backgroung body image
     *
     * @return string
     */
    public function getPageBodyDataBg() {
        $return = $this->_getPostMeta('_np_body_data_bg');
        if (!$return) {
            $return = '';
        }
        $return = $this->fixImagePaths($return);
        $return = str_replace('"', "", $return);
        return $return;
    }

    /**
     * Get hide header flag
     *
     * @return string
     */
    public function getHideHeader() {
        $return = $this->_getPostMeta('_np_hide_header');
        if (!$return || $return === 'false') {
            $return = false;
        } else {
            $return = true;
        }
        return $return;
    }

    /**
     * Set hide header flag
     *
     * @param string $value
     */
    public function setHideHeader($value) {
        $this->_updatePostMeta('_np_hide_header', ($value == false || $value === 'false') ? 'false' : 'true');
    }

    /**
     * Get hide header flag
     *
     * @return string
     */
    public function getHideFooter() {
        $return = $this->_getPostMeta('_np_hide_footer');
        if (!$return || $return === 'false') {
            $return = false;
        } else {
            $return = true;
        }
        return $return;
    }

    /**
     * Set hide footer flag
     *
     * @param string $value
     */
    public function setHideFooter($value) {
        $this->_updatePostMeta('_np_hide_footer', ($value == false || $value === 'false') ? 'false' : 'true');
    }

    /**
     * Get hide backtotop flag
     *
     * @return string
     */
    public function getHideBackToTop() {
        $return = $this->_getPostMeta('_np_hide_backtotop');
        if (!$return) {
            $return = false;
        }
        return $return;
    }

    /**
     * Set hide backtotop flag
     *
     * @param string $value
     */
    public function setHideBackToTop($value) {
        $this->_updatePostMeta('_np_hide_backtotop', ($value == false || $value === 'false') ? false : true);
    }

    /**
     * Set page body class
     *
     * @param string $class
     */
    public function setPageBodyClass($class) {
        $this->_updatePostMeta('_np_body_class', $class);
    }

    /**
     * Set page body styles
     *
     * @param string $styles
     */
    public function setPageBodyStyle($styles) {
        $styles = $this->replaceImagePaths($styles);
        $this->_updatePostMeta('_np_body_style', $styles);
    }

    /**
     * Set page body data-bg for body backround image
     *
     * @param string $url
     */
    public function setPageBodyDataBg($url) {
        $url = $this->replaceImagePaths($url);
        $this->_updatePostMeta('_np_body_data_bg', $url);
    }

    /**
     * Get page meta description
     * Usage: <meta name="description" content="$description">
     *
     * @return string
     */
    public function getPageDescription() {
        $return = $this->_getPostMeta('page_description');
        return $return ? $return : '';
    }

    /**
     * Set page meta description
     *
     * @param string $description
     */
    public function setPageDescription($description) {
        $this->_updatePostMeta('page_description', $description);
    }

    /**
     * Get page canonical
     *
     * @return string
     */
    public function getPageCanonical() {
        $return = $this->_getPostMeta('page_canonical');
        return $return ? $return : '';
    }

    /**
     * Set page canonical
     *
     * @param string $canonical
     */
    public function setPageCanonical($canonical) {
        $this->_updatePostMeta('page_canonical', $canonical);
    }

    /**
     * Get page meta keywords
     * Usage: <meta name="keywords" content="$keywords">
     *
     * @return string
     */
    public function getPageKeywords() {
        $return = $this->_getPostMeta('page_keywords');
        return $return ? $return : '';
    }

    /**
     * Set page meta keywords
     *
     * @param string $keywords
     */
    public function setPageKeywords($keywords) {
        $this->_updatePostMeta('page_keywords', $keywords);
    }

    /**
     * Get page meta tags
     *
     * @return string
     */
    public function getPageMetaTags() {
        $return = $this->_getPostMeta('page_metaTags');
        return $return ? $return : '';
    }

    /**
     * Set page meta tags
     *
     * @param string $meta_tags
     */
    public function setPageMetaTags($meta_tags) {
        $this->_updatePostMeta('page_metaTags', $meta_tags);
    }

    /**
     * Get page og tags
     *
     * @return string
     */
    public function getPageOgTags() {
        $return = json_decode($this->_getPostMeta('page_ogTags'), true);
        return $return ? $return : '';
    }

    /**
     * Set page og tags
     *
     * @param string $og_tags
     */
    public function setPageOgTags($og_tags) {
        $og_tags['image'] = $this->replaceImagePaths($og_tags['image']);
        $this->_updatePostMeta('page_ogTags', json_encode($og_tags, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Set page meta generator
     *
     * @param string $metaGeneratorContent
     */
    function setPageMetaGenerator($metaGeneratorContent) {
        $this->_updatePostMeta('page_metaGeneratorContent', $metaGeneratorContent);
    }

    /**
     * Get page meta generator
     *
     * @return string
     */
    public function getPageMetaGenerator() {
        return $this->_getPostMeta('page_metaGeneratorContent');
    }

    /**
     * Set page meta referrer
     *
     * @param string $metaReferrer
     */
    function setPageMetaReferrer($metaReferrer) {
        $this->_updatePostMeta('page_metaReferrer', $metaReferrer);
    }

    /**
     * Get page meta referrer
     *
     * @return string
     */
    public function getPageMetaReferrer() {
        return $this->_getPostMeta('page_metaReferrer');
    }

    /**
     * Get page custom head html
     *
     * @return string
     */
    public function getPageCustomHeadHtml() {
        $return = $this->_getPostMeta('page_customHeadHtml');
        return $return ? $return : '';
    }

    /**
     * Set page custom head html
     *
     * @param string $custom_head_html
     */
    public function setPageCustomHeadHtml($custom_head_html) {
        $this->_updatePostMeta('page_customHeadHtml', $custom_head_html);
    }

    /**
     * Get page title
     * Usage: <title>$title</title>
     *
     * @return string
     */
    public function getPageTitleInBrowser() {
        $return = $this->_getPostMeta('page_title');
        return $return ? $return : '';
    }

    /**
     * Set page title
     *
     * @param string $title
     */
    public function setPageTitleInBrowser($title) {
        $this->_updatePostMeta('page_title', $title);
    }

    /**
     * Get forms data
     *
     * @return string
     */
    public function getFormsData() {
        $return = $this->_getPostMeta('formsData');
        return $return ? $return : '';
    }

    /**
     * Set forms data
     *
     * @param string $data
     */
    public function setFormsData($data) {
        $this->_updatePostMeta('formsData', $data);
    }

    /**
     * Get dialogs data
     *
     * @return string
     */
    public function getDialogsData() {
        $return = $this->_getPostMeta('dialogs');
        return $return ? $return : '';
    }

    /**
     * Set dialogs data
     *
     * @param string $data
     */
    public function setDialogsData($data) {
        $this->_updatePostMeta('dialogs', $data);
    }


    /**
     * Set publish dialogs
     *
     * @param string $data Data
     */
    public function setPublishDialogs($data) {
        if ($data && count($data) > 0) {
            foreach ($data as $dialog) {
                $name = isset($dialog['name']) ? $dialog['name'] : '';
                NpForms::updateForms($name, 'dialogs', $dialog['publishHtml']);
            }
            $publishDialogs = json_encode($data);
        } else {
            $publishDialogs = '';
        }
        NpMeta::update('publishDialogs', $publishDialogs);
    }

    /**
     * Get active publish dialogs
     *
     * @param string $html   Html
     * @param string $header Header
     * @param string $footer Footer
     *
     * @return string
     */
    public function getActivePublishDialogs($html, $header = '', $footer = '') {
        $result = '';

        $addedAnchors = array();
        if ($header && isset($header['dialogs']) && $header['dialogs']) {
            $headerDialogs = json_decode($header['dialogs'], true);
            foreach ($headerDialogs as $headerDialog) {
                if (strpos($html, $headerDialog['sectionAnchorId']) !== false && !in_array($headerDialog['sectionAnchorId'], $addedAnchors)) {
                    $name = isset($headerDialog['name']) ? $headerDialog['name'] : '';
                    $headerDialog['publishHtml'] = Nicepage::processContent($headerDialog['publishHtml'], array('templateName' => 'dialogs', 'dialogName' => $name));
                    $result .= $headerDialog['publishHtml'] . '<style>' . $headerDialog['publishCss'] . '</style>';
                    array_push($addedAnchors, $headerDialog['sectionAnchorId']);
                }
            }
        }

        if ($footer && isset($footer['dialogs']) && $footer['dialogs']) {
            $footerDialogs = json_decode($footer['dialogs'], true);
            foreach ($footerDialogs as $footerDialog) {
                if (strpos($html, $footerDialog['sectionAnchorId']) !== false && !in_array($footerDialog['sectionAnchorId'], $addedAnchors)) {
                    $name = isset($footerDialog['name']) ? $footerDialog['name'] : '';
                    $footerDialog['publishHtml'] = Nicepage::processContent($footerDialog['publishHtml'], array('templateName' => 'dialogs', 'dialogName' => $name));
                    $result .= $footerDialog['publishHtml'] . '<style>' . $footerDialog['publishCss'] . '</style>';
                    array_push($addedAnchors, $footerDialog['sectionAnchorId']);
                }
            }
        }

        $pageDialogs = $this->getDialogsData();
        if ($pageDialogs) {
            foreach ($pageDialogs as $pageDialog) {
                if (strpos($html, $pageDialog['sectionAnchorId']) !== false && !in_array($pageDialog['sectionAnchorId'], $addedAnchors)) {
                    $name = isset($pageDialog['name']) ? $pageDialog['name'] : '';
                    $pageDialog['publishHtml'] = Nicepage::processContent($pageDialog['publishHtml'], array('templateName' => 'dialogs', 'dialogName' => $name));
                    $result .= $pageDialog['publishHtml'] . '<style>' . $pageDialog['publishCss'] . '</style>';
                    array_push($addedAnchors, $pageDialog['sectionAnchorId']);
                }
            }
        }

        $publishDialogJson = NpMeta::get('publishDialogs');
        if ($publishDialogJson) {
            $publishDialogs = json_decode($publishDialogJson, true);
            foreach ($publishDialogs as $dialog) {
                $name = isset($dialog['name']) ? $dialog['name'] : '';
                $dialog['publishHtml'] = Nicepage::processContent($dialog['publishHtml'], array('templateName' => 'dialogs', 'dialogName' => $name));
                if ($dialog['showOn'] == 'timer' || $dialog['showOn'] == 'page_exit' ) {
                    if (isset($dialog['showOnList']) && in_array($this->page_id, $dialog['showOnList'])) {
                        $result .= $dialog['publishHtml'] . '<style>' . $dialog['publishCss'] . '</style>';
                    }
                } else {
                    if (strpos($html, $dialog['sectionAnchorId']) !== false && !in_array($dialog['sectionAnchorId'], $addedAnchors)) {
                        $result .= $dialog['publishHtml'] . '<style>' . $dialog['publishCss'] . '</style>';
                    }
                }
            }
        }
        return $this->fixImagePaths($result);
    }

    /**
     * Add dialog to body
     *
     * @param string $html   Html
     * @param string $header Header
     * @param string $footer Footer
     *
     * @return mixed
     */
    public function addPublishDialogToBody($html, $header = '', $footer = '') {
        $publishDialogs = $this->getActivePublishDialogs($html, $header, $footer);
        if ($publishDialogs) {
            global $post;
            $publishDialogs = Nicepage::processFormCustomPhp($publishDialogs, $post->ID);
            $html = str_replace('</body>', $publishDialogs . '</body>', $html);
        }
        return $html;
    }

    /**
     * Get site style CSS
     *
     * @return string
     */
    public function getStyleCss() {
        global $post;
        $data_provider = np_data_provider($post->ID);
        $css_parts = NpMeta::get('site_style_css_parts');
        if ($css_parts) {
            $css_parts = json_decode($css_parts, true);
            $ids_json_str = $this->_getPostMeta('_np_site_style_css_used_ids');
            if ($ids_json_str === false || $ids_json_str === '') {
                $publishHtml = $this->getPagePublishHtml();
                if (strpos($publishHtml, 'u-dialog-link') !== false) {
                    $pageDialogs = $this->getDialogsData();
                    if ($pageDialogs) {
                        foreach ($pageDialogs as $pageDialog) {
                            if (isset($pageDialog['publishHtml'])) {
                                $publishHtml .= $pageDialog['publishHtml'];
                            }
                        }
                    }
                }
                $this->_updateUsedIds($css_parts, $publishHtml);
                $ids_json_str = $this->_getPostMeta('_np_site_style_css_used_ids');
            }
            $used_ids = json_decode($ids_json_str, true);

            $header_footer_json_str = NpMeta::get('header_footer_css_used_ids');
            $header_footer_css_used_ids = $header_footer_json_str ? json_decode($header_footer_json_str, true) : array();

            $cookies_json_str = NpMeta::get('cookies_css_used_ids');
            $cookies_css_used_ids = $cookies_json_str ? json_decode($cookies_json_str, true) : array();

            $result = '';

            foreach ($css_parts as $part) {
                if ($part['type'] !== 'color'
                    || !empty($used_ids[$part['id']])
                    || !empty($header_footer_css_used_ids[$part['id']])
                    || !empty($cookies_css_used_ids[$part['id']])
                ) {
                    $result .= $part['css'];
                }
            }
            $result = $data_provider->fixImagePaths($result);

            if (strpos($result, '--theme-sheet') === false) {
                $result .=<<<VARS
.u-body {
    --theme-sheet-width-xl: 1140px;
    --theme-sheet-width-lg: 940px;
    --theme-sheet-width-md: 720px;
    --theme-sheet-width-sm: 540px;
    --theme-sheet-width-xs: 340px;
    --theme-sheet-width-xxl: 1320px;
}
VARS;
            }

            return $result;
        }
        // for old versions:

        $css = NpMeta::get('site_style_css');
        if (!$css) {
            $css = '';
        }
        if (substr($css, 0, 6) === '<style') {
            // backward compatibility
            $css = preg_replace('#</?style[^>]*>#', '', $css);
            $css = $css . file_get_contents(APP_PLUGIN_PATH . 'assets/css/nicepage-dynamic.css');
        }
        $css = $data_provider->fixImagePaths($css);
        return $css;
    }

    /**
     * Save site CSS
     * Save CSS id's used in this page
     *
     * @param string $styles
     * @param string $publish_html
     * @param string $publishHeaderFooter
     * @param string $publishCookiesSection
     */
    public function setStyleCss($styles, $publish_html, $publishHeaderFooter = '', $publishCookiesSection = '') {
        $split = preg_split('#(\/\*begin-color [^*]+\*\/[\s\S]*?\/\*end-color [^*]+\*\/)#', $styles, -1, PREG_SPLIT_DELIM_CAPTURE);
        $parts = array();
        foreach ($split as &$part) {
            $part = trim($part);
            if (!$part) {
                continue;
            }

            if (preg_match('#\/\*begin-color ([^*]+)\*\/#', $part, $m)) {
                $id = 'color_' . $m[1];
                $parts[] = array(
                    'type' => 'color',
                    'id' => $id,
                    'css' => $part,
                );
            } else {
                $parts[] = array(
                    'type' => '',
                    'css' => $part,
                );
            }
        }

        NpMeta::update('site_style_css_parts', json_encode($parts));
        NpMeta::update('site_style_css', ''); // clear old field
        if ($publishHeaderFooter) {
            $used_ids = array();
            foreach ($parts as &$part) {
                if (isset($part['id']) && strpos($publishHeaderFooter, preg_replace('#^color_#', '', $part['id'])) !== false) {
                    $used_ids[$part['id']] = true;
                }
            }
            NpMeta::update('header_footer_css_used_ids', json_encode($used_ids));
        }
        if ($publishCookiesSection) {
            $cookies_used_ids = array();
            foreach ($parts as &$part) {
                if (isset($part['id']) && strpos($publishCookiesSection, preg_replace('#^color_#', '', $part['id'])) !== false) {
                    $cookies_used_ids[$part['id']] = true;
                }
            }
            NpMeta::update('cookies_css_used_ids', json_encode($cookies_used_ids));
        }
        if ($this->page_id) {
            $this->_updateUsedIds($parts, $publish_html);
        }
    }

    /**
     * Update cache for used style id's
     *
     * @param array  $style_parts
     * @param string $publish_html
     */
    private function _updateUsedIds($style_parts, $publish_html) {
        $used_ids = array();
        foreach ($style_parts as &$part) {
            if (isset($part['id']) && strpos($publish_html, preg_replace('#^color_#', '', $part['id'])) !== false) {
                $used_ids[$part['id']] = true;
            }
        }
        $this->_updatePostMeta('_np_site_style_css_used_ids', json_encode($used_ids));
    }

    /**
     * Set site settings
     *
     * @param array|string $settings
     */
    public function setSiteSettings($settings) {
        if ($settings && is_string($settings)) {
            $settings = json_decode($settings, true);
        }
        if (empty($settings)) {
            return;
        }
        if (isset($settings['disableOpenGraph']) && $settings['disableOpenGraph']) {
            $enableOpenGraph = $settings['disableOpenGraph'] === 'true' ? 0 : 1;
            set_theme_mod('seo_og', $enableOpenGraph);
        }
        if (isset($settings['langs'])) {
            $supported_langs = array();
            foreach ($settings['langs'] as $lang) {
                $supported_langs[] = $lang['name'];
            }
            update_option('np_supported_langs', json_encode($supported_langs));
        }
        if (isset($settings['lang'])) {
            update_option('np_default_lang', $settings['lang']);
        }
        update_option('np_hide_backlink', _arr($settings, 'showBrand') === 'false');
        NpMeta::update('site_settings', wp_json_encode($settings));
        NpImportNotice::replaceCaptchaKeysContact7Form();
    }

    /**
     * Get site settings
     *
     * @param null $assoc Get assoc array if true
     *
     * @return false|string
     */
    public static function getSiteSettings($assoc = null) {
        $site_settings = json_decode(NpMeta::get('site_settings'), $assoc);
        if (!$site_settings) {
            $site_settings = json_decode('{}', $assoc);
        }
        return $site_settings;
    }

    /**
     * Clear post meta props
     *
     * @param bool $needRemove
     */
    public function clear($needRemove = true) {
        if ($needRemove) {
            $this->_removePostMeta('_np_html');
            $this->_removePostMeta('_np_publish_html');
            $this->_removePostMeta('_np_head');
            $this->_removePostMeta('_np_fonts');
            $this->_removePostMeta('_np_backlink');
            $this->_removePostMeta('_np_body_class');
            $this->_removePostMeta('_np_body_style');
            $this->_removePostMeta('_np_hide_header');
            $this->_removePostMeta('_np_hide_footer');
            $this->_removePostMeta('_np_site_style_css_used_ids');
            $this->_removePostMeta('page_description');
            $this->_removePostMeta('page_keywords');
            $this->_removePostMeta('page_metaTags');
            $this->_removePostMeta('page_customHeadHtml');
            $this->_removePostMeta('page_title');
            $this->_removeNpHeader();
            $this->_removeNpFooter();
        }
    }

    /**
     * Backward for nicepage meta values
     */
    private function _doBackward() {
        if (get_post_meta($this->page_id, '_upage_sections_html', true)) {
            foreach (array('html', 'publish_html', 'head', 'fonts', 'backlink', 'body_class') as $prop) {
                $old_meta_key = "_upage_sections_$prop";
                $new_meta_key = "_np_$prop";
                $meta_value = get_post_meta($this->page_id, $old_meta_key, true);
                update_post_meta($this->page_id, $new_meta_key, $meta_value);
                delete_post_meta($this->page_id, $old_meta_key);
            }
            update_post_meta($this->page_id, '_np_template', get_post_meta($this->page_id, '_upage_template', true));
            update_post_meta($this->page_id, '_np_forms', get_post_meta($this->page_id, '_upage_forms', true));
        }
    }

    /**
     * Fix image paths
     *
     * @param string $content Content
     *
     * @return mixed
     */
    public function fixImagePaths($content) {
        return str_replace('[[site_path_live]]', get_site_url(), $content);
    }

    /**
     * Replace image paths to placeholder
     *
     * @param string $content Content
     *
     * @return mixed
     */
    public function replaceImagePaths($content) {
        $siteUrlWithoutProtocol = preg_split('#http[s]?#', get_site_url());
        if (isset($siteUrlWithoutProtocol[1])) {
            $content = str_replace('https' . $siteUrlWithoutProtocol[1], '[[site_path_live]]', $content);
            $content = str_replace('http' . $siteUrlWithoutProtocol[1], '[[site_path_live]]', $content);
        } else {
            $content = str_replace(get_site_url(), '[[site_path_live]]', $content);
        }
        return $content;
    }
}

if (!function_exists('np_data_provider')) {
    /**
     * Construct NpDataProvider object
     *
     * @param int|string $post_id        Post Id
     * @param bool|null  $preview        Need or not preview version. Default - $_REQUEST['isPreview']
     * @param bool|null  $saveAndPublish Need or not autoSave page. Default - $_REQUEST['saveAndPublish']
     *
     * @return NpDataProvider
     */
    function np_data_provider($post_id = 0, $preview = null, $saveAndPublish = true)
    {
        return new NpDataProvider($post_id, $preview, $saveAndPublish);
    }
} else {
    sleep(1);
    /**
     * Construct NpDataProvider object
     *
     * @param int|string $post_id        Post Id
     * @param bool|null  $preview        Need or not preview version. Default - $_REQUEST['isPreview']
     * @param bool|null  $saveAndPublish Need or not autoSave page. Default - $_REQUEST['saveAndPublish']
     *
     * @return NpDataProvider
     */
    function np_data_provider($post_id = 0, $preview = null, $saveAndPublish = true)
    {
        return new NpDataProvider($post_id, $preview, $saveAndPublish);
    }
}
