<?php
defined('ABSPATH') or die;

require_once dirname(__FILE__) . '/class-np-shortcodes.php';
require_once dirname(__FILE__) . '/class-np-svg-uploader.php';
require_once dirname(__FILE__) . '/class-np-grid-helper.php';

class Nicepage {

    /**
     * Filter on the_content
     *
     * @param string $content
     *
     * @return string
     */
    public static function theContentFilter($content) {
        $post = get_post();
        //if $content not post content we need only return
        if ($content === '' && ($post && $post->post_content !== '')) {
            return $content;
        }
        if ($post) {
            $sections_html = self::html($post->ID);
            if ($sections_html) {
                // filter content across gutenberg blocks filters
                if (function_exists('do_blocks') && function_exists('has_blocks') && has_blocks($sections_html)) {
                    $sections_html = do_blocks($sections_html);
                }
                if (function_exists('w123cf_widget_text_filter')) {
                    $sections_html = w123cf_widget_text_filter($sections_html);
                }
                $content = $sections_html;
            }
        }
        return $content;
    }

    /**
     * Html preg_replace_callback callback
     *
     * @param array $code_php
     *
     * @return string
     */
    private static function _phpReplaceHtml($code_php) {
        if (stripos($code_php[1], '<?php') === 0 && stripos($code_php[1], '?>') === strlen($code_php[1])-2) {
            $code_php[1] = str_replace("<?php", "", $code_php[1]);
            $code_php[1] = str_replace("?>", "", $code_php[1]);
            ob_start();
            eval($code_php[1]);
            $string = ob_get_contents();
            ob_end_clean();
            $code_php[1] = $string;
        } elseif (stripos($code_php[1], '<?php') === 0 && stripos($code_php[1], '?>') !== strlen($code_php[1])-2 OR stripos($code_php[1], '<?php') !== 0 && stripos($code_php[1], '<?php') !== false) {
            /* For more than one opening and closing php tags and attempts to insert html */
            preg_match_all("/(<\?([\s\S]+?)?>)/", $code_php[1], $matches);
            $code_php[1] = "";
            foreach ($matches[0] as &$element_php) {
                $code_php[1] = $code_php[1].$element_php;
            }
            $code_php[1] = str_replace("<?php", "", $code_php[1]);
            $code_php[1] = str_replace("?>", "", $code_php[1]);
            ob_start();
            eval($code_php[1]);
            $string = ob_get_contents();
            ob_end_clean();
            $code_php[1] = $string;
        }
        return $code_php[1];
    }

    /**
     * Get processed publishHtml for page
     *
     * @param string|int $post_id
     *
     * @return string
     */
    public static function html($post_id) {
        if (! post_password_required($post_id)) {
            $sections_html = np_data_provider($post_id)->getPagePublishHtml();
        } else {
            $sections_html = '';
        }

        if ($sections_html) {
            $sections_html = self::processFormCustomPhp($sections_html, $post_id);
            $sections_html = self::processContent($sections_html, array('isPublic' => false));
            if (self::isAutoResponsive($post_id)) {
                $sections_html = self::_getAutoResponsiveScript($post_id) . $sections_html;
            }
            if (!self::isNpTheme()) {
                $template_page = NpMetaOptions::get($post_id, 'np_template');
                if ($template_page == "html") {
                    $sections_html = '<div class="' . implode(' ', self::bodyClassFilter(array())) . '" style="' . self::bodyStyleFilter() . '" ' . self::bodyDataBgFilter() . '>' . $sections_html . "</div>";
                } else {
                    $sections_html = '<div class="nicepage-container"><div class="' . implode(' ', self::bodyClassFilter(array())) . '" style="' . self::bodyStyleFilter() . '" ' . self::bodyDataBgFilter() . '>' . $sections_html . "</div></div>";
                }
            }
        }

        return $sections_html;
    }

    /**
     * Filter on body_class
     *
     * Add page classes to <body>
     *
     * @param string[] $classes
     *
     * @return string[]
     */
    public static function bodyClassFilter($classes) {
        if (self::isHtmlQuery()) {
            return $classes;
        }

        $post = get_post();
        $post_id = isset($post->ID) ? $post->ID : 0;
        $data_provider = np_data_provider($post_id);
        if ($post && $data_provider->isNp()) {
            $class = $data_provider->getPageBodyClass();
            if ($class && is_singular()) {
                $classes[] = $class;

                if (self::isAutoResponsive($post_id)) {
                    $initial_mode = self::_getInitialResponsiveMode($post_id);
                    foreach (array_reverse(self::$responsiveModes) as $mode) {
                        $classes[] = self::$responsiveBorders[$mode]['CLASS'];

                        if ($mode === $initial_mode) {
                            break;
                        }
                    }
                }
            }
        }
        return $classes;
    }

    /**
     * Filter on body style
     *
     * Add page style attribute to <body>
     *
     * @return string
     */
    public static function bodyStyleFilter() {
        $post = get_post();
        if ($post) {
            $style = np_data_provider($post->ID)->getPageBodyStyle();
            return $style && is_singular() ? $style : '';
        }
        return '';
    }

    /**
     * Filter on body style
     *
     * Add page style attribute to <body>
     *
     * @return string
     */
    public static function bodyDataBgFilter() {
        $post = get_post();
        if ($post) {
            $dataBg = np_data_provider($post->ID)->getPageBodyDataBg();
            return $dataBg && is_singular() ? "data-bg='" . $dataBg . "'" : '';
        }
        return '';
    }

    /**
     * Action on wp_footer
     * Print backlink html
     */
    public static function wpFooterAction() {
        $post = get_post();
        if (!$post) {
            global $post;
        }
        $post_id = isset($post->ID) ? $post->ID : 0;
        $data_provider = np_data_provider($post_id);
        $is_np_page = $data_provider->isNp();
        // if not our theme code need render only on the our pages
        $renderPages = self::isNpTheme() ? ($is_np_page || is_single() || is_home()) : $is_np_page;
        if ($post && $renderPages) {
            $backlink = $data_provider->getPageBacklink();
            if ($backlink && get_option('np_hide_backlink') || isset($GLOBALS['theme_backlink'])) {
                // back compat for old versions
                // backlink's html isn't empty even np_hide_backlink is true
                $backlink = str_replace('u-backlink', 'u-backlink u-hidden', $backlink);
            }

            $bodyClass = implode(' ', self::bodyClassFilter(array()));
            $bodyStyle = self::bodyStyleFilter();
            $template = '<div class="nicepage-container"><div class="' . $bodyClass . '" style="' . $bodyStyle . '">{content}</div></div>';

            $sections_html = $data_provider->getPagePublishHtml();
            $cookiesConsent = NpMeta::get('cookiesConsent') ? json_decode(NpMeta::get('cookiesConsent'), true) : '';
            if ($cookiesConsent && (!$cookiesConsent['hideCookies'] || $cookiesConsent['hideCookies'] === 'false') && $sections_html && !self::isNpTheme()) {
                $cookiesConsent['publishCookiesSection'] = $data_provider->fixImagePaths($cookiesConsent['publishCookiesSection']);
                echo str_replace('{content}', $cookiesConsent['publishCookiesSection'], $template);
            }

            $hideBackToTop = $data_provider->getHideBackToTop();
            if (!$hideBackToTop && $data_provider->isNp()) {
                echo str_replace('{content}', NpMeta::get('backToTop'), $template);
            }

            $template_page = NpMetaOptions::get($post_id, 'np_template');
            if ($template_page !== "html") {
                $publishDialogs = $data_provider->getActivePublishDialogs($sections_html);
                $publishDialogs = self::processContent($publishDialogs);
                echo str_replace('{content}', $publishDialogs . $backlink, $template);
            } else {
                echo str_replace('{content}', $backlink, $template);
            }
        }
    }

    /**
     * Function for publish_html postprocessing
     *
     * @param string $content
     * @param array  $params
     *
     * @return mixed|string
     **/
    public static function processContent($content, $params = array()) {
        $isPublic = isset($params['isPublic']) ? $params['isPublic'] : true;
        if ($isPublic) {
            $content = self::processControls($content);
        }
        $content = self::_processGoogleMaps($content);
        $content = self::_processForms($content, $params);
        $content = self::_prepareShortcodes($content);
        $content = self::_prepareCustomPhp($content);
        $content = self::_processBlogPost($content);
        $content = self::_processShop($content);
        $content = do_shortcode($content);
        $content = self::processPositionsWithIcons($content);
        $content = NpWidgetsImporter::processLink($content);
        if (strpos($content, 'none-post-image') !== false) {
            $content = str_replace('u-blog-post', 'u-blog-post u-invisible', $content);
            $content = str_replace('u-products-item', 'u-products-item u-invisible', $content);
        }
        return $content;
    }

    /**
     * @param string $content
     * @param string $pageId
     */
    public static function processFormCustomPhp($content, $pageId) {
        if ($pageId) {
            $plgDir = dirname(plugins_url('', __FILE__));
            $formFile = $plgDir . '/templates/form.php';
            $content = preg_replace(
                '/(<form[^>]*action=[\'\"]+)\[\[form\-(.*?)\]\]([\'\"][^>]*source=[\'\"]customphp)/',
                '$1' . $formFile . '?id=' . $pageId . '&formId=$2$3',
                $content
            );
        }
        return $content;
    }

    /**
     * Process custom php controls
     *
     * @param string $content
     *
     * @return string
     */
    private static function _prepareCustomPhp($content) {
        if (stripos($content, 'data-custom-php') !== false) {
            $content = preg_replace_callback('/data-custom-php="([^"]+)"([^>]*)>/', 'self::_phpReplacePublishHtml', $content);
        }
        return preg_replace_callback('/<!--custom_php-->([\s\S]+?)<!--\/custom_php-->/', 'Nicepage::_phpReplaceHtml', $content);
    }

    /**
     * _replaceCustomPhpPubishHtml preg_replace_callback callback
     *
     * @param array $code_php
     *
     * @return string
     */
    private static function _phpReplacePublishHtml($code_php) {
        $code_php[1] = str_replace("&quot;", "'", $code_php[1]);
        return $code_php[2].">".$code_php[1];
    }

    private static $_formIdx;
    private static $_formsSources;

    /**
     * Process product / products / cart
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _processShop($content) {
        return NpShopDataReplacer::process($content);
    }

    /**
     * Process blog / post
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _processBlogPost($content) {
        return NpBlogPostDataReplacer::process($content);
    }

    /**
     * Process forms
     *
     * @param string $content
     * @param array  $params
     *
     * @return string
     */
    private static function _processForms($content, $params) {
        global $post;
        self::$_formIdx = 0;
        self::$_formsSources = NpForms::getPageForms($post->ID, $params);
        return preg_replace_callback(NpForms::$formRe, 'Nicepage::_processForm', $content);
    }

    /**
     * Convert HTML-placeholders into shortcodes
     *
     * @param string $content
     *
     * @return string
     */
    private static function _prepareShortcodes($content) {
        $content = preg_replace('#<!--(\/?)(position|block|block_header|block_header_content|block_content_content)-->#', '[$1np_$2]', $content);
        return $content;
    }

    /**
     * Process form
     * Callback for preg_replace_callback
     *
     * @param array $match
     *
     * @return string
     */
    private static function _processForm($match) {
        $form_html = $match[0];
        $form_id = isset(self::$_formsSources[self::$_formIdx]['id']) ? self::$_formsSources[self::$_formIdx]['id'] : 0;

        $return = NpForms::getHtml($form_id, $form_html);
        if (self::$_formIdx === 0) {
            $return = NpForms::getScriptsAndStyles() . "\n" . $return;
        }
        self::$_formIdx++;
        return $return;
    }

    /**
     * Filter on template_include
     * Switch to 'html' or 'html-header-footer' template
     *
     * @param string $template_path
     *
     * @return string
     */
    public static function templateFilter($template_path) {
        global $post;
        if ($post && is_singular() && np_data_provider($post->ID)->isNp()) {
            $np_template = NpMetaOptions::get($post->ID, 'np_template');
            $np_template = apply_filters('nicepage_template', $np_template, $post->ID, $template_path);
            if ($np_template) {
                $template_path = dirname(__FILE__) . "/../templates/$np_template.php";
            }
        }
        return $template_path;
    }

    /**
     * Check is it query for getting dummy page
     * Dummy page - it's a page without Nicepage styles
     * used for getting real typography properties from theme
     *
     * @return bool
     */
    public static function isHtmlQuery() {
        return !empty($_GET['np_html']);
    }

    /**
     * Add cookies confirm code
     */
    public static function addCookiesConfirmCode()
    {
        global $post;
        $post_id = !isset($post->ID)? get_the_ID() : $post->ID;
        $sections_html = np_data_provider($post_id)->getPagePublishHtml();
        $cookiesConsent = NpMeta::get('cookiesConsent') ? json_decode(NpMeta::get('cookiesConsent'), true) : '';
        if ($cookiesConsent && (!$cookiesConsent['hideCookies'] || $cookiesConsent['hideCookies'] === 'false') && $sections_html && !self::isNpTheme()) {
            echo $cookiesConsent['cookieConfirmCode'];
        }
    }

    /**
     * Action on wp_head
     */
    public static function addHeadStyles() {
        if (self::isHtmlQuery() || !is_singular()) {
            return;
        }

        $post_id = isset($post->ID) ? $post->ID : get_the_ID();
        $data_provider = np_data_provider($post_id);
        $siteSettings = $data_provider->getSiteSettings();
        echo $data_provider->getPageFonts();

        $styles = $data_provider->getPageHead();
        if (self::isAutoResponsive($post_id)) {
            $styles = preg_replace('#\/\*RESPONSIVE_MEDIA\*\/([\s\S]*?)\/\*\/RESPONSIVE_MEDIA\*\/#', '', $styles);
        } else {
            $styles = preg_replace('#\/\*RESPONSIVE_CLASS\*\/([\s\S]*?)\/\*\/RESPONSIVE_CLASS\*\/#', '', $styles);
        }

        if (self::isNpTheme()) {
            echo "<style>\n$styles</style>\n";
        } else {
            global $post;
            $template_page = NpMetaOptions::get($post->ID, 'np_template');
            if ($template_page != "html") {
                echo  "<style>\n".preg_replace_callback('/([^{}]+)\{[^{}]+?\}/', 'self::addContainerForConflictStyles', $styles)."</style>\n";
            } else {
                echo "<style>\n$styles</style>\n";
            }
        }

        $description = $data_provider->getPageDescription();
        if (isset($siteSettings->description) && $siteSettings->description && strpos($description, $siteSettings->description) === false) {
            if ($description !== '') {
                $description = $siteSettings->description . ', ' . $description;
            } else {
                $description = $siteSettings->description;
            }

        }
        if ($description) {
            echo "<meta name=\"description\" content=\"$description\">\n";
        }

        $keywords = $data_provider->getPageKeywords();
        if (isset($siteSettings->keywords) && $siteSettings->keywords && strpos($keywords, $siteSettings->keywords) === false) {
            if ($keywords !== '') {
                $keywords = $siteSettings->keywords . ', ' . $keywords;
            } else {
                $keywords = $siteSettings->keywords;
            }
        }
        if ($keywords) {
            echo "<meta name=\"keywords\" content=\"$keywords\">\n";
        }

        $meta_tags = $data_provider->getPageMetaTags();
        if ($meta_tags) {
            echo $meta_tags . "\n";
        }

        $meta_generator = isset($GLOBALS['meta_generator']) ? $GLOBALS['meta_generator'] : $data_provider->getPageMetaGenerator();
        if ($meta_generator && $data_provider->isNp() && NpMetaOptions::get($post_id, 'np_template') === 'html') {
            echo '<meta name="generator" content="' . $meta_generator . '" />' . "\n";
        }

        $meta_referrer = isset($GLOBALS['meta_referrer']) ? $GLOBALS['meta_referrer'] : $data_provider->getPageMetaReferrer();
        if ($meta_referrer && $data_provider->isNp() && NpMetaOptions::get($post_id, 'np_template') === 'html') {
            echo '<meta name="referrer" content="origin" />' . "\n";
        }

        $customHeadHtml = $data_provider->getPageCustomHeadHtml();
        if ($customHeadHtml) {
            echo $customHeadHtml . "\n";
        }
    }

    /**
     * Action on wp_head
     */
    public static function addHeadStyles2() {
        if (self::isHtmlQuery()) {
            return;
        }

        $post_id = get_the_ID();
        $data_provider = np_data_provider($post_id);

        if (is_singular() && $data_provider->isNp()) {
            $site_style_css = $data_provider->getStyleCss();
            if ($site_style_css) {
                if (self::isNpTheme()) {
                    $site_style_css = preg_replace('#<style.*?(typography|font-scheme|color-scheme)="Theme [\s\S]*?<\/style>#', '', $site_style_css);
                } else {
                    global $post;
                    $template_page = NpMetaOptions::get($post->ID, 'np_template');
                    if ($template_page != "html") {
                        $site_style_css = preg_replace_callback('/([^{}]+)\{[^{}]+?\}/', 'self::addContainerForConflictStyles', $site_style_css);
                    }
                }
                echo "<style>$site_style_css</style>\n";
            }
        }
    }
    /**
     * Add container for conflict styles
     *
     * @param array $match
     *
     * @return string
     */
    public static function addContainerForConflictStyles($match) {
        $selectors = $match[1];
        $parts = explode(',', $selectors);
        $newSelectors = implode(
            ',',
            array_map(
                function ($part) {
                    if (!preg_match('/html|body|sheet|keyframes/', $part)) {
                        return ' .nicepage-container ' . $part;
                    } else {
                        return $part;
                    }
                },
                $parts
            )
        );
        return str_replace($selectors, $newSelectors, $match[0]);
    }

    /**
     * Add viewport meta tag
     */
    public static function addViewportMeta() {
        if (self::isHtmlQuery()) {
            return;
        }
        echo <<<SCRIPT
<script>
    if (!document.querySelector("meta[name='viewport")) {
        var vpMeta = document.createElement('meta');
        vpMeta.name = "viewport";
        vpMeta.content = "width=device-width, initial-scale=1.0";
        document.getElementsByTagName('head')[0].appendChild(vpMeta);
    }
</script>
SCRIPT;
    }

    /**
     * Add site meta tags
     */
    public static function addSiteMetaTags() {
        if (self::isHtmlQuery()) {
            return;
        }
        $post_id = get_the_ID();
        $data_provider = np_data_provider($post_id);
        $siteSettings = $data_provider->getSiteSettings();
        if (isset($siteSettings->metaTags) && $siteSettings->metaTags) {
            echo $siteSettings->metaTags;
        }
    }

    /**
     * Add site custom css
     */
    public static function addSiteCustomCss() {
        if (self::isHtmlQuery()) {
            return;
        }
        $post_id = get_the_ID();
        $data_provider = np_data_provider($post_id);
        $siteSettings = $data_provider->getSiteSettings();
        if (isset($siteSettings->customCss) && $siteSettings->customCss) {
            echo '<style>' . $siteSettings->customCss . '</style>';
        }
    }

    /**
     * Add site custom html
     */
    public static function addSiteCustomHtml() {
        if (self::isHtmlQuery()) {
            return;
        }
        $post_id = get_the_ID();
        $data_provider = np_data_provider($post_id);
        $siteSettings = $data_provider->getSiteSettings();
        if (isset($siteSettings->headHtml) && $siteSettings->headHtml) {
            echo $siteSettings->headHtml;
        }
    }

    /**
     * Add site analytic
     */
    public static function addSiteAnalytic() {
        if (self::isHtmlQuery()) {
            return;
        }
        $post_id = get_the_ID();
        $data_provider = np_data_provider($post_id);
        $siteSettings = $data_provider->getSiteSettings();
        if (isset($GLOBALS['googleAnalyticsMarker']) && !$GLOBALS['googleAnalyticsMarker'] && isset($siteSettings->analyticsCode)) {
            echo $siteSettings->analyticsCode;
        }
    }

    /**
     * Check if need to enable auto-responsive
     *
     * @param string|int $page_id
     *
     * @return bool
     */
    public static function isAutoResponsive($page_id) {
        if (self::isNpTheme()) {
            return false;
        }
        if (NpMetaOptions::get($page_id, 'np_template') === 'html') {
            return false;
        }
        return !!NpSettings::getOption('np_auto_responsive');
    }

    /**
     * Filter on single_post_title
     *
     * @param string  $title
     * @param WP_Post $post
     *
     * @return string
     */
    public static function singlePostTitleFilter($title, $post) {
        $post_id = $post->ID;
        $custom_title = np_data_provider($post_id)->getPageTitleInBrowser();
        if ($custom_title) {
            $title = $custom_title;
        }
        return $title;
    }

    /**
     * Action on wp_enqueue_scripts
     */
    public static function addScriptsAndStylesAction() {
        if (!self::isNpTheme()) {
            $pagePost = is_single();
            $pageBlog = is_home();
            if ($pagePost || $pageBlog) {
                wp_register_style("froala-style", APP_PLUGIN_URL . 'assets/css/froala.css', array(), APP_PLUGIN_VERSION);
                wp_enqueue_style("froala-style");
            }
        }
        global $post;
        $post_id = !isset($post->ID)? get_the_ID() : $post->ID;
        if (self::isHtmlQuery() || !np_data_provider($post_id)->isNp()) {
            return;
        }

        if (NpSettings::getOption('np_include_jquery')) {
            wp_register_script("nicepage-jquery", APP_PLUGIN_URL . 'assets/js/jquery.js', array(), APP_PLUGIN_VERSION);
            wp_enqueue_script("nicepage-jquery");

            wp_register_script("nicepage-script", APP_PLUGIN_URL . 'assets/js/nicepage.js', array('nicepage-jquery'), APP_PLUGIN_VERSION);
        } else {
            wp_register_script("nicepage-script", APP_PLUGIN_URL . 'assets/js/nicepage.js', array('jquery'), APP_PLUGIN_VERSION);
        }
        wp_enqueue_script("nicepage-script");

        if (self::isNpTheme()) {
            wp_register_style("nicepage-style", APP_PLUGIN_URL . 'assets/css/nicepage.css', array(), APP_PLUGIN_VERSION);
            wp_enqueue_style("nicepage-style");
        } else {
            $template_page = NpMetaOptions::get($post_id, 'np_template');
            if ($template_page == "html") {
                wp_register_style("nicepage-style", APP_PLUGIN_URL . 'assets/css/nicepage.css', array(), APP_PLUGIN_VERSION);
                wp_enqueue_style("nicepage-style");
            } else {
                wp_register_style("nicepage-style", APP_PLUGIN_URL . 'assets/css/page-styles.css', array(), APP_PLUGIN_VERSION);
                wp_enqueue_style("nicepage-style");
            }
        }

        if (is_singular()) {
            if (self::isAutoResponsive($post_id)) {
                wp_register_style("nicepage-responsive", APP_PLUGIN_URL . 'assets/css/responsive.css', APP_PLUGIN_VERSION);
                wp_enqueue_style("nicepage-responsive");
            } else {
                wp_register_style("nicepage-media", APP_PLUGIN_URL . 'assets/css/media.css', APP_PLUGIN_VERSION);
                wp_enqueue_style("nicepage-media");
            }
        }
        $base_upload_dir = wp_upload_dir();
        $customFontsFilePath = $base_upload_dir['basedir'] . '/nicepage-fonts/fonts_' . $post_id . '.css';
        if (file_exists($customFontsFilePath)) {
            $customFontsFileHref = $base_upload_dir['baseurl'] . '/nicepage-fonts/fonts_' . $post_id . '.css';
            wp_register_style("nicepage-custom-fonts", $customFontsFileHref, APP_PLUGIN_VERSION);
            wp_enqueue_style("nicepage-custom-fonts");
        }
    }

    public static $responsiveModes = array('XS', 'SM', 'MD', 'LG', 'XL');
    public static $responsiveBorders = array(
        'XL' => array(
            'CLASS' => 'u-xl',
            'MAX' => 1000000,
        ),
        'LG' => array(
            'CLASS' => 'u-lg',
            'MAX' => 1199,
        ),
        'MD' => array(
            'CLASS' => 'u-md',
            'MAX' => 991,
        ),
        'SM' => array(
            'CLASS' => 'u-sm',
            'MAX' => 767,
        ),
        'XS' => array(
            'CLASS' => 'u-xs',
            'MAX' => 575,
        ),
    );

    /**
     * Get initial responsive mode using $GLOBALS['content_width']
     *
     * @param string|int $post_id
     *
     * @return mixed|string
     */
    private static function _getInitialResponsiveMode($post_id) {
        if (!self::isAutoResponsive($post_id)) {
            return 'XL';
        }
        if (NpMetaOptions::get($post_id, 'np_template')) {
            return 'XL';
        }

        global $content_width;
        if (!isset($content_width) || !$content_width) {
            return 'XL';
        }

        $width = (int) $content_width;

        foreach (self::$responsiveModes as $mode) {
            if ($width <= self::$responsiveBorders[$mode]['MAX']) {
                return $mode;
            }
        }
        return 'XL';
    }

    /**
     * Auto-responsive script
     *
     * @param int|string $post_id
     *
     * @return string
     */
    private static function _getAutoResponsiveScript($post_id) {
        ob_start();
        ?>
        <script>
            (function ($) {
                var ResponsiveCms = window.ResponsiveCms;
                if (!ResponsiveCms) {
                    return;
                }
                ResponsiveCms.contentDom = $('script:last').parent();
                ResponsiveCms.prevMode = <?php echo wp_json_encode(self::_getInitialResponsiveMode($post_id)); ?>;

                if (typeof ResponsiveCms.recalcClasses === 'function') {
                    ResponsiveCms.recalcClasses();
                }
            })(jQuery);
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Action on init
     */
    public static function initAction() {
        if (self::isNpTheme()) {
            add_filter('body_class', 'Nicepage::bodyClassFilter');
            add_filter('add_body_style_attribute', 'Nicepage::bodyStyleFilter');
            add_filter('add_body_data_attributes', 'Nicepage::bodyDataBgFilter');
        }
    }

    /**
     * Check is it Nicepage theme
     *
     * @return bool
     */
    public static function isNpTheme() {
        if (self::$_themeSettings === null) {
            self::$_themeSettings = apply_filters('np_theme_settings', array());
        }
        return !!self::$_themeSettings;
    }
    private static $_themeSettings = null;

    /**
     * Initialize svg upload with sizes
     */
    public static function svgUploaderInitialization() {
        new NpSvgUploader();
    }

    public static $controlName = '';
    /**
     * Process all custom controls on the header
     *
     * @param string $content content
     *
     * @return mixed
     */
    public static function processControls($content) {
        $controls = array('headline', 'logo', 'menu', 'search', 'position', 'headerImage', 'widget', 'shortCode', 'login', 'languageLink');
        foreach ($controls as $value) {
            $options_placeholder = $value === 'menu' && strpos($content, 'np_menu_json') !== false  ? 'np_menu_json' : 'np_json';
            self::$controlName = $value;
            $content =  preg_replace_callback(
                '/<\!--np_' . $value . '--><!--' . $options_placeholder . '-->([\s\S]+?)<\!--\/' . $options_placeholder . '-->([\s\S]*?)<\!--\/np_' . $value . '-->/',
                function ($matches) {
                    $controlProps = json_decode(trim($matches[1]), true);
                    $controlTemplate = $matches[2];
                    ob_start();
                    include APP_PLUGIN_PATH . '/includes/controls/'. Nicepage::$controlName . '/' . Nicepage::$controlName . '.php';
                    return ob_get_clean();
                },
                $content
            );
        }
        return $content;
    }

    /**
     * Process icons in the positions
     *
     * @param string $content content
     *
     * @return string
     */
    public static function processPositionsWithIcons($content) {
        //for icons and images in the widget content
        if (preg_match('/<\!--np_position_icons_json-->([\s\S]+?)<\!--\/np_position_icons_json-->/', $content, $iconsProps)) {
            $iconsProps = json_decode(trim(html_entity_decode($iconsProps[1])), true);
            if ($iconsProps && count($iconsProps) > 0) {
                for ($i = 0; $i < count($iconsProps); $i++) {
                    $content = preg_replace('/{image_src_' . $i . '}/', get_template_directory_uri() . '/images/' . $iconsProps[$i], $content);
                }
            }
            $content = preg_replace('/<\!--np_position_icons_json-->([\s\S]+?)<\!--\/np_position_icons_json-->/', '', $content);
        }
        return $content;
    }

    /**
     * Add recaptcha script when not contact 7 plugin
     */
    public static function enableRecapcha() {
        if (self::isHtmlQuery()) {
            return;
        }
        $site_settings = json_decode(NpMeta::get('site_settings'));
        if (isset($site_settings->captchaScript) && !isset($GLOBALS['npThemeRecaptcha'])) {
            echo $site_settings->captchaScript;
        }
    }

    /**
     * Filter <title> on the all pages
     *
     * @return mixed|string
     */
    public static function frontEndTitleFilter() {
        $title = '';
        $id = get_the_ID();
        if ($id) {
            $seoTitle = get_post_meta($id, 'page_title', true);
            if ($seoTitle && $seoTitle !== '') {
                $title = $seoTitle;
            }
        }
        return $title;
    }

    /**
     *  Remove meta generator wordpress
     */
    public static function removeCmsMetaGenerator() {
        $post_id = get_the_ID();
        $data_provider = np_data_provider($post_id);
        $meta_generator = $data_provider->getPageMetaGenerator();
        if ($meta_generator || isset($GLOBALS['meta_generator']) && $GLOBALS['meta_generator']) {
            remove_action('wp_head', 'wp_generator');
        }
    }

    /**
     * Filter canonical url
     *
     * @param string  $canonical_url
     * @param WP_Post $post
     *
     * @return string $canonical_url
     */
    public static function filter_canonical($canonical_url, $post){
        $data_provider = np_data_provider($post->ID);
        $canonical = $data_provider->getPageCanonical();
        $canonical_url = $canonical ? $canonical : $canonical_url;
        return $canonical_url;
    }

    /**
     * Output woo cart
     *
     * @param string $template      Template
     * @param string $template_name Template name
     * @param string $template_path Template path
     *
     * @return string
     */
    public static function miniCart($template, $template_name = '', $template_path = '') {
        $basename = basename($template);

        if ($basename !== 'mini-cart.php') {
            return $template;
        }

        $referer = wp_get_raw_referer();
        if ($referer && ($pageId = url_to_postid($referer)) === 0) {
            return $template;
        }

        if (NpMetaOptions::get($pageId, 'np_template') === 'html') {
            $template = trailingslashit(plugin_dir_path(__FILE__)) . 'controls/cart/mini-cart.php';
        }

        return $template;
    }

    /**
     * Add site gtm in header
     */
    public static function addSiteGtmInHeader() {
        if (self::isHtmlQuery()) {
            return;
        }
        $post_id          = get_the_ID();
        $data_provider    = np_data_provider($post_id);
        $siteSettings     = $data_provider->getSiteSettings();
        $googleTagManager = isset($siteSettings->googleTagManager) && $siteSettings->googleTagManager ? $siteSettings->googleTagManager : '';
        if ((isset($GLOBALS['gtmMarker']) && !$GLOBALS['gtmMarker'] || !isset($GLOBALS['gtmMarker'])) && $googleTagManager
            && isset($siteSettings->googleTagManagerCode) && $siteSettings->googleTagManagerCode
        ) {
            echo $siteSettings->googleTagManagerCode;
        }
    }

    /**
     * Add site intlTelInput in header
     */
    public static function addIntlTelInput() {
        if (!isset($GLOBALS['meta_tel_input'])) {
            $assets = APP_PLUGIN_URL . 'assets/intlTelInput/';
            $customMetaTag = '<meta data-intl-tel-input-cdn-path="' . $assets . '" />';
            echo $customMetaTag;
        }
    }

    /**
     * Add site gtm in body
     */
    public static function addSiteGtmInBody() {
        if (self::isHtmlQuery()) {
            return;
        }
        $post_id          = get_the_ID();
        $data_provider    = np_data_provider($post_id);
        $siteSettings     = $data_provider->getSiteSettings();
        $googleTagManager = isset($siteSettings->googleTagManager) && $siteSettings->googleTagManager ? $siteSettings->googleTagManager : '';
        if ((isset($GLOBALS['gtmMarker']) && !$GLOBALS['gtmMarker'] || !isset($GLOBALS['gtmMarker'])) && $googleTagManager
            && isset($siteSettings->googleTagManagerCodeNoScript) && $siteSettings->googleTagManagerCodeNoScript
        ) { ?>
            <script>
                jQuery(document).ready(function () {
                    jQuery(document).find('body').prepend(`<?php echo $siteSettings->googleTagManagerCodeNoScript; ?>`)
                });
            </script>
            <?php
        }
    }

    /**
     * Add nicepage images to sitemap Rank Math plugin
     *
     * @param array $images Array with images
     * @param int   $id     Page id
     *
     * @return array $images
     */
    public static function rankMathSiteMapFilter( $images, $id ){
        if (empty($images)) {
            $data_provider = np_data_provider($id);
            $pagePublishHtml = $data_provider->getPagePublishHtml();
            $pagePublishHtml = Nicepage::theContentFilter($pagePublishHtml);
            if ($pagePublishHtml !== '') {
                preg_match_all('/<img [^>]+>/', $pagePublishHtml, $matches);
                $elements = isset($matches[0]) ? $matches[0] : array();
                for ($i = 0; $i < count($elements); $i++) {
                    if ($elements[$i] !== '') {
                        if (preg_match_all('/[[:space:]](img|src|data-src|alt)="([^"]+)"/', $elements[$i], $matchesElement)) {
                            if (isset($matchesElement) && isset($matchesElement[2]) && isset($matchesElement[2][1])) {
                                $images[] = array(
                                    'src' => $matchesElement[2][1],
                                    'alt' => isset($matchesElement[2][0]) ? $matchesElement[2][0] : ''
                                );
                            }
                        }
                    }
                }
            }
        }
        return $images;
    }

    /**
     * Filter html of password protect page form
     *
     * @param string $output
     *
     * @return string $output
     */
    public static function password_protect_template_filter($output) {
        $post = get_post();
        remove_filter('the_content', 'wpautop');
        $script = <<<SCRIPT
<script>
    setTimeout(function() {
        jQuery('.u-password-control form').submit(function () {
            var passwordInput = jQuery('input[name="password"]');
            var passwordHashInput = jQuery('input[name="password_hash"]');
            var passwordHash = sha256.create().update(passwordInput.val()).digest().toHex();
            passwordHashInput.val(passwordHash);
            return true;
        });
    }, 0);
</script>
SCRIPT;
        $post_id = isset($post->ID) ? $post->ID : 0;
        $data_provider = np_data_provider($post_id);
        $passwordProtectionItem = $data_provider->getPasswordProtectionData();
        if ($passwordProtectionItem) {
            $publishPasswordProtection = $data_provider->getTranslation($passwordProtectionItem, 'passwordProtect');
            $publishPasswordProtection = Nicepage::processFormCustomPhp($publishPasswordProtection, false);
            $publishPasswordProtection = Nicepage::processContent($publishPasswordProtection);
            $action = APP_PLUGIN_URL . 'includes/templates/passwordProtect/action.php';
            $publishPasswordProtection = str_replace('[[action]]', $action, $publishPasswordProtection);
            $publishPasswordProtection = str_replace('[[method]]', 'post', $publishPasswordProtection);
            $publishPasswordProtection = str_replace('[[id]]', $post_id, $publishPasswordProtection);
            if ($passwordProtectionItem) {
                $output = $publishPasswordProtection . $passwordProtectionItem['styles'];
            }
        } else {
            include APP_PLUGIN_PATH . '/includes/templates/passwordProtect/default-template.php';
        }
        $output .= $script;
        return $output;
    }

    /**
     *  Register our menu locations for extra plugin translations / other themes
     */
    public static function registerPluginLocations() {
        $locations = get_option('np_menu_locations') ? json_decode(get_option('np_menu_locations'), true) : array();
        $registered_locations = get_registered_nav_menus();
        foreach ($locations as $location => $name) {
            if (isset($registered_locations[$location])) {
                unset($locations[$location]);
            }
        }
        if (count($locations) > 0) {
            register_nav_menus(
                $locations
            );
        }
    }

    /**
     * Backward - add https protocol to google maps url when site with http
     *
     * @param string $content
     *
     * @return string
     */
    private static function _processGoogleMaps($content) {
        if (!NpAdminActions::isSSL() && strpos($content, 'src="//maps.google.com/maps') !== false) {
            $content = str_replace('src="//maps.google.com/maps', 'src="https://maps.google.com/maps', $content);
        }
        return $content;
    }

    /**
     * Set lang attr to html by country
     *
     * @param $output
     * @param $doctype
     *
     * @return mixed|string
     */
    public static function modifyLanguageAttributesByCountry($output, $doctype) {
        $site_settings = json_decode(NpMeta::get('site_settings'));
        if (isset($site_settings->lang) && $site_settings->lang) {
            $langAttr = $site_settings->lang;
            if (isset($site_settings->country) && $site_settings->country) {
                $langAttr .= '-' . $site_settings->country;
            }
            $output = 'lang="' . $langAttr . '"';
        }
        return $output;
    }

    /**
     * Add og tags to np page
     */
    public static function add_meta_open_graph() {
        $post = get_post();
        if (!$post) {
            global $post;
        }
        $post_id = isset($post->ID) ? $post->ID : 0;
        $data_provider = np_data_provider($post_id);
        $siteSettings = $data_provider->getSiteSettings();
        $siteOpenGraph = isset($siteSettings->disableOpenGraph) && $siteSettings->disableOpenGraph === 'true' ? 0 : 1;
        if (Nicepage::isNpTheme()) {
            if ($siteOpenGraph === 0) {
                remove_action('wp_head', 'theme_og_meta_tags', 5);
            } else {
                if ($data_provider->isNp()) {
                    remove_action('wp_head', 'theme_og_meta_tags', 5);
                    plugin_og_meta_tags();
                }
            }
        } else {
            if ($siteOpenGraph === 1) {
                plugin_og_meta_tags();
            }
        }
    }
}

add_filter('the_password_form', 'Nicepage::password_protect_template_filter');
add_filter('woocommerce_locate_template', 'Nicepage::miniCart');
add_action('init', 'Nicepage::initAction');
add_action('init', 'Nicepage::registerPluginLocations');
add_filter('the_content', 'Nicepage::theContentFilter');
add_filter('get_canonical_url', 'Nicepage::filter_canonical', 10, 2);
add_action('wp_enqueue_scripts', 'Nicepage::addScriptsAndStylesAction', 9); // add before theme styles
add_filter('pre_get_document_title', 'Nicepage::frontEndTitleFilter');
add_action('wp_head', 'Nicepage::add_meta_open_graph', 0);
add_action('wp_head', 'Nicepage::removeCmsMetaGenerator', 0);
add_action('wp_head', 'Nicepage::addHeadStyles');
add_action('wp_head', 'Nicepage::addCookiesConfirmCode');
add_action('wp_head', 'Nicepage::addHeadStyles2', 1003);
add_action('wp_head', 'Nicepage::addViewportMeta', 1004);
add_action('wp_head', 'Nicepage::addSiteMetaTags', 1005);
add_action('wp_head', 'Nicepage::addSiteCustomCss', 1006);
add_action('wp_head', 'Nicepage::addSiteCustomHtml', 1007);
add_action('wp_head', 'Nicepage::addSiteAnalytic', 1008);
add_action('wp_head', 'Nicepage::enableRecapcha', 1009);
add_action('wp_head', 'Nicepage::addSiteGtmInHeader', 1010);
add_action('wp_head', 'Nicepage::addIntlTelInput', 1011);
add_action('wp_footer', 'Nicepage::addSiteGtmInBody');
add_filter('template_include', 'Nicepage::templateFilter');
add_filter('single_post_title', 'Nicepage::singlePostTitleFilter', 9, 2);
add_action('admin_init', 'Nicepage::svgUploaderInitialization');
add_action('admin_init', 'NpImport::redirectToPluginWizard');
add_action(
    'in_admin_header', function () {
        $pagename = get_admin_page_title();
        if ($pagename !== APP_PLUGIN_WIZARD_NAME) {
            return;
        }
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
        wp_enqueue_style('pwizard-style', APP_PLUGIN_URL . 'importer/assets/css/pwizard-admin-style.css', array(), '');
    }, 1000
);
add_action('wp_footer', 'Nicepage::wpFooterAction');
// For seo siteMap Rank Math and Yoast Seo plugins
add_filter('rank_math/sitemap/urlimages', 'Nicepage::rankMathSiteMapFilter', 10, 2);
add_filter('wpseo_sitemap_urlimages', 'Nicepage::rankMathSiteMapFilter', 10, 2);
add_filter('language_attributes', 'Nicepage::modifyLanguageAttributesByCountry', 10, 2);
if (Nicepage::isHtmlQuery()) {
    show_admin_bar(false);
}