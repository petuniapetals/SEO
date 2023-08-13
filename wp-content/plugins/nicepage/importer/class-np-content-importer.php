<?php
defined('ABSPATH') or die;

require_once dirname(__FILE__) . '/class-np-widgets-importer.php';

class NpContentImporter {

    /**
     * @var string Data folder path
     */
    private $_path;

    /**
     * @var string Path to images in data folder
     */
    private $_imagesPath;

    /**
     * @var string Path to fonts in data folder
     */
    private $_fontsPath;

    /**
     * @var string Path to google fonts in data folder
     */
    private $_googleFontsPath;

    /**
     * @var array Content JSON
     */
    private $_data;

    /**
     * @var array List of attachment-id's that was imported
     */
    private $_importedImages = array();

    /**
     * @var array List of attachment-id's that was imported for Posts
     */
    private $_postsImportedImages = array();

    /**
     * @var array Header Footer placeholder for images
     */
    private $_headerFooterImages = null;

    /**
     * @var array placeholder ids for payment products
     */
    private $_paymentProductsIds = array();

    /**
     * @var array (post id in content.json) => (real post_id in WP) mapping
     */
    private $_newPostIds = array();

    /**
     * @var array (menu id in content.json) => (real menu_id in WP) mapping
     */
    private $_newMenuIds = array();

    private $_newTermIds = array();
    private $_addedTerms = array();
    private $_addedWidgets = array();

    private $_importedProductsIds = array();

    /**
     * @var array (vmenu widget_id) => (menu id)
     */
    public $vmenus = array();

    /**
     * @var NpWidgetsImporter
     */
    private $_widgetsImporter;

    private $_supportedTaxonomies = array(
        'category' => array('data_key' => 'Categories', 'placeholder_key' => 'category'),
        'post_tag' => array('data_key' => 'Tags', 'placeholder_key' => 'tag'),
    );

    /**
     * NpContentImporter constructor.
     *
     * @param string $path
     *
     * @throws Exception
     */
    public function __construct($path) {
        $this->_path = $path;
        $this->_imagesPath = $path . '/images';
        $this->_fontsPath = $path . '/fonts';
        $this->_googleFontsPath = $path . '/google-fonts';
        $json_path = $path . '/content.json';
        if (!file_exists($json_path)) {
            throw new Exception("Can't find content.json in zip archive");
        }

        $data = file_get_contents($json_path);
        $data = json_decode($data, true);
        if (!is_array($data)) {
            throw new Exception("Invalid json");
        }

        // change structure posts data for current pages import
        if (isset($data['Posts']) && is_array($data['Posts'])) {
            foreach ($data['Posts'] as $name => $post) {
                $post['caption'] = $post['title'];
                $post['name'] = $post['title'];
                unset($post['title']);
                $post['image'] = $post['featured'];
                unset($post['featured']);
                $post['content'] = $post['html'];
                unset($post['html']);
                $data['Posts'][$name] = $post;
            }
            $data['Posts'] = array_reverse($data['Posts']);
        }

        $this->_data = $data;

        $this->_widgetsImporter = new NpWidgetsImporter($this);
    }

    /**
     * Import posts with specified post_type
     *
     * @param string $post_type
     * @param array  $data
     */
    private function _importPostType($post_type, &$data) {
        add_filter('wp_insert_post_empty_content', '__return_false', 1000);

        $this->_newPostIds[$post_type] = array();
        $id_map = &$this->_newPostIds[$post_type];
        foreach ($data as $id => $post_data) {
            $new_id = wp_insert_post(
                array(
                    'post_type' => $post_type,
                    'post_title' => _arr($post_data, 'caption', ''),
                    'post_name' => _arr($post_data, 'name', ''),
                    'comment_status' => _arr($post_data, 'commentStatus', 'closed'),
                    'post_status' => 'publish'
                )
            );
            $post_image = _arr($post_data, 'image', '');
            if ($post_image !== '') {
                if (isset($this->_postsImportedImages[$post_image])) {
                    set_post_thumbnail($new_id, $this->_postsImportedImages[$post_image]);
                }
            }
            $id_map[$id] = $new_id;
        }
        update_option('np_page_ids', $id_map);
        remove_filter('wp_insert_post_empty_content', '__return_false', 1000);
    }

    /**
     * Update posts with specified post_type
     *
     * @param string $post_type
     * @param array  $data
     */
    private function _updatePostType($post_type, &$data) {
        $post_time = time() - count($data);
        $menu_order = 0;
        $data_provider = np_data_provider();
        foreach ($data as $id => $post_data) {
            $post_id = $this->_newPostIds[$post_type][$id];
            $post_date = gmdate('Y-m-d H:i:s', ($post_time++) + get_option('gmt_offset') * 3600);
            $content = $this->_processContent(_arr($post_data, 'content', ''));
            if ($post_type === 'post') {
                $content = $data_provider->fixImagePaths($content);
            }
            $update_data = array(
                'ID' => $post_id,
                'post_content' => str_replace('<!--CUT-->', '<!--more-->', $content),
                'post_excerpt' => $this->_processContent(_arr($post_data, 'excerpt', '')),
                'post_date' => $post_date,
                'post_date_gmt' => get_gmt_from_date($post_date),
                'menu_order' => ++$menu_order,
            );

            $parent_id = intval(_arr($this->_newPostIds[$post_type], _arr($post_data, 'parent')));
            if ($parent_id) {
                $update_data['post_parent'] = $parent_id;
            }

            // set taxonomies (categories, tags, etc)
            foreach ($this->_supportedTaxonomies as $tax => $key) {
                $terms = explode(',', _arr($post_data, strtolower($key['data_key']), ''));
                $new_term_ids = array();
                foreach ($terms as $term) {
                    list(,$term_id) = $this->parsePlaceholder($term);
                    if (isset($this->_newTermIds[$term_id])) {
                        $new_term_ids[] = $this->_newTermIds[$term_id];
                    }
                }
                if ($new_term_ids) {
                    wp_set_post_terms($post_id, $new_term_ids, $tax);
                }
            }

            // add featured image to post
            if (isset($post_data['image'])) {
                list($tag_name, $image_id) = $this->parsePlaceholder($post_data['image']);
                if ($tag_name === 'image') {
                    $attach_id = $this->_data['Images'][$image_id]['_attachId'];

                    if ($attach_id) {
                        update_post_meta($post_id, '_thumbnail_id', $attach_id);
                    }
                }
            }

            $show_in_menu = _arr($post_data, 'showInMenu', true);
            update_post_meta($post_id, '_theme_show_in_menu', $show_in_menu ? '1' : '0');

            $show_page_title = _arr($post_data, 'showPageTitle', true);
            update_post_meta($post_id, '_theme_show_page_title', $show_page_title ? '1' : '0');

            $title_in_menu = _arr($post_data, 'titleInMenu');
            if ($title_in_menu) {
                update_post_meta($post_id, '_theme_title_in_menu', $title_in_menu);
            }

            $autop = _arr($post_data, 'autop', true);
            update_post_meta($post_id, '_theme_use_wpautop', $autop ? '1' : '0');


            $page_head = _arr($post_data, 'pageHead');
            if ($page_head) {
                $page_head = $this->_processContent($page_head); // replace images
                add_post_meta($post_id, 'theme_head', $page_head);
            }

            $page_title = _arr($post_data, 'titleInBrowser');
            if ($page_title) {
                add_post_meta($post_id, 'page_title', $page_title);
            }

            $keywords = _arr($post_data, 'keywords');
            if ($keywords) {
                add_post_meta($post_id, 'page_keywords', $keywords);
            }

            $description = _arr($post_data, 'description');
            if ($description) {
                add_post_meta($post_id, 'page_description', $description);
            }

            $canonical = _arr($post_data, 'canonical');
            if ($canonical) {
                add_post_meta($post_id, 'page_canonical', $canonical);
            }

            $metaTags = _arr($post_data, 'metaTags');
            if ($metaTags) {
                add_post_meta($post_id, 'page_metaTags', $metaTags);
            }

            $ogTags = _arr($post_data, 'ogTags');
            if ($ogTags) {
                $ogTags['image'] = $this->_processContent($ogTags['image']);
                add_post_meta($post_id, 'page_ogTags', json_encode($ogTags, JSON_UNESCAPED_UNICODE));
            }

            $customHeadHtml = _arr($post_data, 'customHeadHtml');
            if (false !== $customHeadHtml) {
                add_post_meta($post_id, 'page_customHeadHtml', $customHeadHtml);
                add_post_meta($post_id, 'page_hasCustomHeadHtml', 'true');
            }

            $metaGeneratorContent = _arr($post_data, 'metaGeneratorContent');
            if (false !== $metaGeneratorContent) {
                add_post_meta($post_id, 'page_metaGeneratorContent', $metaGeneratorContent);
            }

            $metaReferrer = _arr($post_data, 'metaReferrer');
            if (false !== $metaReferrer) {
                add_post_meta($post_id, 'page_metaReferrer', $metaReferrer);
            }

            if (isset($post_data['properties'])) {
                $np_data = $post_data['properties'];

                $np_html = _arr($np_data, 'html');
                if ($np_html) {
                    $np_html = $data_provider->replaceProductButtonIds($np_html, $this->_paymentProductsIds);
                    $np_html = $data_provider->replaceImagePaths($np_html);
                    $np_html = $this->_processContent($np_html);
                    update_post_meta($post_id, '_np_html', $np_html);
                }

                $np_publish_html = _arr($np_data, 'publishHtml');
                if ($np_publish_html) {
                    $np_publish_html = $data_provider->replaceProductButtonIds($np_publish_html, $this->_paymentProductsIds);
                    $np_publish_html = $data_provider->replaceImagePaths($np_publish_html);
                    $np_publish_html = $this->_processContent($np_publish_html);
                    update_post_meta($post_id, '_np_publish_html', $np_publish_html);
                    $update_data['post_content'] = apply_filters('np_create_excerpt', $np_publish_html);
                }

                $np_publish_html_translations = _arr($np_data, 'publishHtmlTranslations');
                if ($np_publish_html_translations) {
                    foreach ($np_publish_html_translations as $lang => $np_publish_html_translation) {
                        $np_publish_html_translation = $data_provider->replaceProductButtonIds($np_publish_html_translation, $this->_paymentProductsIds);
                        $np_publish_html_translation = $data_provider->replaceImagePaths($np_publish_html_translation);
                        $np_publish_html_translation = $this->_processContent($np_publish_html_translation);
                        update_post_meta($post_id, $lang . '_np_publish_html', $np_publish_html_translation);
                    }
                }

                $np_head = _arr($np_data, 'head');
                if ($np_head) {
                    $np_head = $data_provider->replaceImagePaths($np_head);
                    $np_head = $this->_processContent($np_head);
                    update_post_meta($post_id, '_np_head', $np_head);
                }

                $np_fonts = _arr($np_data, 'fonts');
                if ($np_fonts) {
                    $fontsPath = APP_PLUGIN_URL . 'assets/css/fonts/';
                    $np_fonts = str_replace('page-fonts.css', $fontsPath . 'page-' . $post_id . '-fonts.css', $np_fonts);
                    $np_fonts = str_replace('"fonts.css', '"' . $fontsPath . 'fonts.css', $np_fonts);
                    update_post_meta($post_id, '_np_fonts', $np_fonts);
                }

                $np_backlink = _arr($np_data, 'backlink');
                if ($np_backlink) {
                    update_post_meta($post_id, '_np_backlink', $np_backlink);
                }

                $np_body_class = _arr($np_data, 'bodyClass');
                if ($np_body_class) {
                    update_post_meta($post_id, '_np_body_class', $np_body_class);
                }

                $np_body_style = $this->_processContent(_arr($np_data, 'bodyStyle'));
                if ($np_body_style) {
                    $np_body_style = $data_provider->replaceImagePaths($np_body_style);
                    update_post_meta($post_id, '_np_body_style', $np_body_style);
                }

                $np_body_data_bg = $this->_processContent(_arr($np_data, 'bodyDataBg'));
                if ($np_body_data_bg) {
                    $np_body_data_bg = $data_provider->replaceImagePaths($np_body_data_bg);
                    update_post_meta($post_id, '_np_body_data_bg', $np_body_data_bg);
                }

                $np_hide_header = $this->_processContent(_arr($np_data, 'hideHeader'));
                if ($np_hide_header) {
                    update_post_meta($post_id, '_np_hide_header', $np_hide_header);
                }

                $np_hide_footer = $this->_processContent(_arr($np_data, 'hideFooter'));
                if ($np_hide_footer) {
                    update_post_meta($post_id, '_np_hide_footer', $np_hide_footer);
                }

                $np_hide_backtotop = $this->_processContent(_arr($np_data, 'hideBackToTop'));
                if ($np_hide_backtotop) {
                    update_post_meta($post_id, '_np_hide_backtotop', $np_hide_backtotop);
                }

                $formsData = $this->_processContent(_arr($np_data, 'formsData'));
                if ($formsData) {
                    update_post_meta($post_id, 'formsData', $formsData);
                }

                $dialogsData = $this->_processContent(_arr($np_data, 'dialogs'));
                if ($dialogsData) {
                    update_post_meta($post_id, 'dialogs', json_decode($dialogsData, true));
                    if (isset($this->_data['Parameters']['publishNicePageCss'])) {
                        $dialogsData = $dialogsData === '[]' ? '' : $dialogsData;
                        $data_provider->setStyleCss($this->_processContent($this->_data['Parameters']['publishNicePageCss']), $np_publish_html . $dialogsData);
                    }
                }
                $passwordProtection = _arr($np_data, 'passwordProtection', '');
                if ($passwordProtection && $passwordProtection !== 'false') {
                    $update_data['post_password'] = $passwordProtection;
                }
            }

            // set front and blog pages
            $is_front = _arr($post_data, 'isFront');
            $is_blog = _arr($post_data, 'isBlog');
            if ($is_front) {
                update_option('show_on_front', 'page');
                update_option('page_on_front', $post_id);
            }
            if ($is_blog) {
                update_option('page_for_posts', $post_id);
            }

            wp_update_post($update_data);

            $parameters = isset($this->_data['Parameters']) ? $this->_data['Parameters'] : null;
            if ($parameters && (isset($parameters['header']) || isset($parameters['footer']))) {
                update_post_meta($post_id, '_np_template', 'html');
            } else {
                update_post_meta($post_id, '_np_template', 'html-header-footer');
            }

            if (class_exists('NpForms')) {
                if (!isset($this->_newPostIds['wpcf7_contact_form'])) {
                    $this->_newPostIds['wpcf7_contact_form'] = array();
                }
                $wpcf_ids = &$this->_newPostIds['wpcf7_contact_form'];

                $headerNp = get_option('headerNp', true);
                $footerNp = get_option('footerNp', true);
                if ($headerNp) {
                    $headerItem = json_decode($headerNp, true);
                    $publishHeader = $headerItem['php'];
                    $created_forms_header = NpForms::updateForms($post_id, 'header', $publishHeader);
                    foreach ($created_forms_header as $form) {
                        $wpcf_ids['wpcf7_' . count($wpcf_ids)] = $form['id'];
                    }
                }
                $created_forms = NpForms::updateForms($post_id);
                foreach ($created_forms as $form) {
                    $wpcf_ids['wpcf7_' . count($wpcf_ids)] = $form['id'];
                }
                if ($footerNp) {
                    $footerItem = json_decode($footerNp, true);
                    $publishFooter = $footerItem['php'];
                    $created_forms_footer = NpForms::updateForms($post_id, 'footer', $publishFooter);
                    foreach ($created_forms_footer as $form) {
                        $wpcf_ids['wpcf7_' . count($wpcf_ids)] = $form['id'];
                    }
                }
            }
        }
    }

    /**
     * Import posts
     */
    private function _importPosts() {
        $post_types = array(
            array(
                'key' => 'Posts',
                'post_type' => 'post',
            ),
            array(
                'key' => 'Pages',
                'post_type' => 'page',
            )
        );

        foreach ($post_types as $type) {
            $key = $type['key'];
            $post_type = $type['post_type'];

            if (isset($this->_data[$key])) {
                $this->_importPostType($post_type, $this->_data[$key]);
                foreach ($this->_newPostIds[$post_type] as $old_id => $new_id) {
                    $this->_replaceFrom[] = "[{$post_type}_$old_id]";
                    $this->_replaceTo[] = get_permalink($new_id);
                }
            }
        }

        foreach ($post_types as $type) {
            $key = $type['key'];
            $post_type = $type['post_type'];

            if (isset($this->_data[$key])) {
                $this->_updatePostType($post_type, $this->_data[$key]);
            }
        }
    }

    /**
     * Replace placeholders in content
     *
     * @param string $content
     * @param bool   $onlyHeaderFooter
     *
     * @return string
     */
    public function _processContent($content, $onlyHeaderFooter = false) {
        if ($onlyHeaderFooter) {
            $content = $this->_resetLinks($content);
        }
        $blogUrl = get_option('page_for_posts') ? get_permalink(get_option('page_for_posts')) : get_home_url();
        $content = preg_replace('/\[blog_(\d+)\]/', $blogUrl, $content);
        return str_replace($this->_replaceFrom, $this->_replaceTo, $content);
    }

    private $_replaceFrom = array();
    private $_replaceTo = array();

    /**
     * Import images
     */
    private function _importImages() {
        if (!isset($this->_data['Images'])) {
            return;
        }
        $base_upload_dir = wp_upload_dir();
        $images_dir = $base_upload_dir['path'];
        $data_provider = np_data_provider();
        $imported_images = array();
        foreach ($this->_data['Images'] as $id => &$image) {
            if (isset($imported_images[$image['fileName']]) && isset($imported_images[$image['fileName']]['url'])) {
                $image_url =  $imported_images[$image['fileName']]['url'];
                $attach_id =  $imported_images[$image['fileName']]['attach_id'];
            } else {
                if ($this->_headerFooterImages && array_search($id, $this->_headerFooterImages) === false) {
                    continue;
                }
                $filename = $image['fileName'];
                $filename = wp_unique_filename($images_dir, $filename);
                $image_path = $images_dir . '/' . $filename;

                NpFilesUtility::copyRecursive($this->_imagesPath . '/' . $image['fileName'], $image_path);

                $wp_filetype = wp_check_filetype($filename, null);
                $attachment = array(
                    'guid' => $base_upload_dir['url'] . '/' . $filename,
                    'post_mime_type' => $wp_filetype['type'],
                    'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
                    'post_content' => '',
                );
                $attach_id = wp_insert_attachment($attachment, $image_path);
                if (extension_loaded('imagick')) {
                    $imageData = getimagesize($image_path);
                    $width = isset($imageData[0]) ? $imageData[0] : 0;
                    $height = isset($imageData[1]) ? $imageData[1] : 0;
                    if ($width > 1600 || $height > 1600) {
                        // imagick library slower than GD library for big images
                        add_filter(
                            'wp_image_editors', function () {
                                return array('WP_Image_Editor_GD', 'WP_Image_Editor_Imagick');
                            }
                        );
                    } else {
                        add_filter(
                            'wp_image_editors', function () {
                                return array('WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' );
                            }
                        );
                    }
                }
                $attach_data = wp_generate_attachment_metadata($attach_id, $image_path);
                wp_update_attachment_metadata($attach_id, $attach_data);

                $image_url = wp_get_attachment_url($attach_id);
                $image_url = $data_provider->replaceImagePaths($image_url);
                $image['_url'] = $image_url;
                $image['_attachId'] = $attach_id;
                $imported_images[$image['fileName']] = array();
                $imported_images[$image['fileName']]['url'] = $image_url;
                $imported_images[$image['fileName']]['attach_id'] = $attach_id;
            }
            $this->_replaceFrom[] = '[image_' . $id . ']';
            $this->_replaceTo[] = $image_url;
            $this->_replaceFrom[] = '#' . $image['fileName'];
            $this->_replaceTo[] = $image_url;
            $this->_importedImages[] = $attach_id;
            $this->_postsImportedImages['[image_' . $id . ']'] = $attach_id;
        }

        $this->_replaceFrom[] = '[image_default]';
        $this->_replaceTo[] = NpAttachments::getDefaultImageUrl();
    }

    /**
     * Import fonts
     */
    private function _importFonts() {
        if (!isset($this->_data['Pages'])) {
            return;
        }
        self::_importCustomFonts();
        self::_importGoogleEmbedFonts();
    }

    /**
     *  Import custom fonts
     */
    private function _importCustomFonts() {
        if (!$this->_fontsPath) {
            return;
        }
        $base_upload_dir = wp_upload_dir();
        $base_dir = $base_upload_dir['basedir'];
        $contentFontsDir = $base_dir . '/' . 'nicepage-fonts';
        if (!file_exists($contentFontsDir)) {
            mkdir($contentFontsDir);
        }
        //copy font files
        self::_copyFiles($this->_fontsPath . '/fonts', $base_dir . '/' . 'nicepage-fonts' . '/fonts');
        //copy pages css
        self::_importPageFontsCss($this->_fontsPath, $contentFontsDir, 'custom');
    }

    /**
     * Import google font if font embed enable
     */
    private function _importGoogleEmbedFonts() {
        if (!$this->_googleFontsPath) {
            return;
        }
        $fontsFolder = APP_PLUGIN_PATH . 'assets/css/fonts';
        if (!file_exists($fontsFolder)) {
            if (false === @mkdir($fontsFolder, 0777, true)) {
                return;
            }
        }
        //copy font files
        self::_copyFiles($this->_googleFontsPath . '/fonts', $fontsFolder);
        //copy pages css
        self::_importPageFontsCss($this->_googleFontsPath, $fontsFolder, 'google');
    }

    /**
     * Copy files from $fromDir to $toDir
     *
     * @param $fromDir
     * @param $toDir
     */
    private function _copyFiles($fromDir, $toDir) {
        if (file_exists($fromDir)) {
            if (!file_exists($toDir)) {
                mkdir($toDir);
            }
            if ($handle = opendir($fromDir)) {
                while (false !== ($file = readdir($handle))) {
                    $fileSource = $fromDir . '/' . $file;
                    if ('.' == $file || '..' == $file || is_dir($fileSource)) {
                        continue;
                    }
                    copy($fileSource, $toDir . '/' . $file);
                }
                closedir($handle);
            }
        }
    }

    /**
     * Import css files with fonts connection information
     *
     * @param $fromDir
     * @param $toDir
     * @param $type
     */
    private function _importPageFontsCss($fromDir, $toDir, $type) {
        if (file_exists($fromDir)) {
            if ($handle = opendir($fromDir)) {
                while (false !== ($file = readdir($handle))) {
                    $fileSource = $fromDir . '/' . $file;
                    if ('.' == $file || '..' == $file || is_dir($fileSource)) {
                        continue;
                    }
                    $fileInfo = pathinfo($file);
                    if ($type === 'custom') {
                        $fileNameParts = explode('_', $fileInfo['filename']);
                        if (count($fileNameParts) > 1 && isset($this->_data['Pages'][$fileNameParts[1]])) {
                            $pageId = isset($this->_newPostIds['page'][$fileNameParts[1]]) ? $this->_newPostIds['page'][$fileNameParts[1]] : false;
                            if ($pageId) {
                                copy($fileSource, $toDir . '/' . str_replace($fileNameParts[1], $pageId, $file));
                            }
                        }
                    } else {
                        // google embed fonts
                        if (preg_match('/page-(\d+?)-fonts/', $fileInfo['filename'], $matches)) {
                            $npPageId = isset($matches[1]) ? $matches[1] : false;
                            if ($npPageId && isset($this->_data['Pages'][$npPageId])) {
                                $pageId = isset($this->_newPostIds['page'][$npPageId]) ? $this->_newPostIds['page'][$npPageId] : false;
                                if ($pageId) {
                                    $content = file_get_contents($fileSource);
                                    file_put_contents($toDir . '/' . str_replace($npPageId, $pageId, $file), str_replace('fonts/', '', $content));
                                }
                            }
                        } else {
                            $content = file_get_contents($fileSource);
                            file_put_contents($toDir . '/' . $file, str_replace('fonts/', '', $content));
                        }
                    }
                }
                closedir($handle);
            }
        }
    }

    /**
     * Import images
     */
    public function importImages()
    {
        $this->_importImages();
    }

    /**
     * @param string $placeholder
     *
     * @return array|bool
     */
    private function _getObjectInfoByPlaceholder($placeholder) {
        list($name, $id) = $this->parsePlaceholder($placeholder);
        if (!$name) {
            // invalid link
            return false;
        }
        if (isset($this->_newPostIds[$name][$id])) {
            return array(
                'type' => 'post_type',
                'object' => $name,
                'object_id' => $this->_newPostIds[$name][$id],
            );
        }

        if (isset($this->_newTermIds[$id])) {
            foreach ($this->_supportedTaxonomies as $tax => $key) {
                if ($key['placeholder_key'] === $name) {
                    return array(
                        'type' => 'taxonomy',
                        'object' => $tax,
                        'object_id' => $this->_newTermIds[$id],
                    );
                }
            }
        }
        return false;
    }

    /**
     * Split [name_id] placeholder into array(name, id)
     *
     * @param string $placeholder
     *
     * @return array
     */
    public function parsePlaceholder($placeholder) {
        $name = false;
        $id = false;
        if (preg_match('#\[(.*)_(\d+)\]#', $placeholder, $matches)) {
            $name = $matches[1];
            $id = $matches[2];
        }
        return array($name, $id);
    }

    /**
     * Parse hrefs
     *
     * @param array $matches Href matches
     *
     * @return string
     */
    private function _parseHref($matches)
    {
        if (strpos($matches[0], '[blog') === 0) {
            $blogUrl = get_option('page_for_posts') ? get_permalink(get_option('page_for_posts')) : get_home_url();
            return $blogUrl;
        }
        if (isset($this->_data['Pages'][ $matches[1]])) {
            return '#';
        } else {
            return $matches[0];
        }
    }

    /**
     * Reset links for default header and footer
     *
     * @param string $content Page sample content
     *
     * @return mixed
     */
    private function _resetLinks($content)
    {
        $content = preg_replace_callback('/\[page_(\d+)\]/', array( &$this, '_parseHref'), $content);
        return $content;
    }

    public static $menuLangParents = array();
    public static $menuLocations = array();

    /**
     * Import menus
     */
    private function _importMenus() {
        if (!isset($this->_data['Menus'])) {
            return;
        }
        $lang_menu_connexions = array();
        foreach ($this->_data['Menus'] as $menu_id => $menu) {

            $menu_name = _arr($menu, 'caption', 'Menu');
            // generate unique name
            for ($i = 0; ; $i++) {
                $new_name = $menu_name . ($i ? ' #' . $i : '');
                $_possible_existing = get_term_by('name', $new_name, 'nav_menu');
                if (!$_possible_existing || is_wp_error($_possible_existing) || !isset($_possible_existing->term_id)) {
                    $menu_name = $new_name;
                    break;
                }
            }

            $menu_new_id = wp_update_nav_menu_object(0, array('menu-name' => $menu_name));
            $this->_newMenuIds[$menu_id] = $menu_new_id;

            if (isset($menu['lang']) && isset($menu['parentId'])) {
                if ($menu['lang'] === 'default') {
                    $lang_menu_connexions[$menu_new_id] = array();
                    self::$menuLangParents[$menu['parentId']] = $menu_new_id;
                }
                $index = self::$menuLangParents[$menu['parentId']];
                $lang_menu_connexions[$index][$menu['lang']] = $menu['positions'];
            }
            if (isset($menu['positionsName'])) {
                self::$menuLocations[$menu['positions']] = $menu['positionsName'];
            }

            if (isset($menu['items']) && is_array($menu['items'])) {
                $id_map = array();
                foreach ($menu['items'] as $menu_item_id => $menu_item) {
                    $id_map[$menu_item_id] = wp_update_nav_menu_item($menu_new_id, 0, array());
                }

                $order = 0;
                foreach ($menu['items'] as $menu_item_id => $menu_item) {
                    $menu_item_data = array();
                    $menu_item_caption = _arr($menu_item, 'caption');
                    if ($menu_item_caption) {
                        $menu_item_data['menu-item-title'] = $menu_item_caption;
                    }
                    $menu_item_parent = _arr($menu_item, 'parent');
                    if ($menu_item_parent) {
                        $menu_item_data['menu-item-parent-id'] = $id_map[$menu_item_parent];
                    }
                    $menu_item_href = _arr($menu_item, 'href', '#');
                    if (strpos($menu_item_href, '#') === 0 && strlen($menu_item_href) > 1) {
                        $menu_item_href = str_replace($this->_replaceFrom, $this->_replaceTo, $menu_item_href);
                    }
                    $menu_item_data['menu-item-position'] = ++$order;
                    $urlParts = parse_url($menu_item_href);
                    $isAnchor = isset($urlParts['fragment']) ? $urlParts['fragment'] : false;
                    if ($menu_item_href) {
                        $href = $this->_getObjectInfoByPlaceholder($menu_item_href);
                        if ($href && !$isAnchor) {
                            $menu_item_data['menu-item-type'] = $href['type'];
                            $menu_item_data['menu-item-object'] = $href['object'];
                            $menu_item_data['menu-item-object-id'] = $href['object_id'];
                        } else {
                            $menu_item_data['menu-item-type'] = 'custom';
                            $menu_item_href = NpWidgetsImporter::processLink($menu_item_href);
                            if (strpos($menu_item_href, '[page_') !== false) {
                                $menu_item_href = '#';
                            }
                            $menu_item_data['menu-item-url'] = $menu_item_href;
                        }
                    }
                    wp_update_nav_menu_item($menu_new_id, $id_map[$menu_item_id], $menu_item_data);
                }
            }

            $positions = _arr($menu, 'positions');
            if (is_string($positions) && $positions) {
                $positions = explode(',', $positions);
                $nav_menu_locations = get_nav_menu_locations();
                foreach ($positions as $position) {
                    $position = trim($position);
                    if ($position) {
                        $nav_menu_locations[$position] = $menu_new_id;
                    }
                }
                set_theme_mod('nav_menu_locations', $nav_menu_locations);
            }

            $widgets = _arr($menu, 'widgets');
            if (is_string($widgets) && $widgets) {
                $widgets = explode(',', $widgets);
                foreach ($widgets as $widget_placeholder) {
                    $widget_placeholder = trim($widget_placeholder);
                    list(, $id) = $this->parsePlaceholder($widget_placeholder);
                    if ($id) {
                        $this->vmenus[$id] = $menu_new_id;
                    }
                }
            }
        }
        if ($lang_menu_connexions) {
            update_option('np_menu_translations', json_encode($lang_menu_connexions));
        }
        if (self::$menuLocations) {
            update_option('np_menu_locations', json_encode(self::$menuLocations));
        }
    }

    /**
     * Import taxonomies
     *
     * @param string $data_key
     * @param string $tax
     */
    private function _importTaxonomies($data_key, $tax) {
        if (!isset($this->_data[$data_key])) {
            return;
        }

        foreach ($this->_data[$data_key] as $id => $term) {
            $name = _arr($term, 'name', '');
            $caption = _arr($term, 'caption', 'Unknown');
            $description = _arr($term, 'description', '');

            if (!term_exists($caption, $tax)) {
                $inserted_term = wp_insert_term(
                    $caption,
                    $tax,
                    array(
                        'slug' => $name,
                        'description' => $description,
                    )
                );
                if (is_array($inserted_term) && isset($inserted_term['term_id'])) {
                    $this->_addedTerms[] = array(
                        'term_id' => (int)$inserted_term['term_id'],
                        'taxonomy' => $tax
                    );
                }
            }
            $exists_term = get_term_by('name', $caption, $tax);
            $this->_newTermIds[$id] = (int)$exists_term->term_id;
        }
    }

    /**
     * Import sidebars
     */
    private function _importSidebars() {
        if (!isset($this->_data['Sidebars'])) {
            return;
        }
        $this->_widgetsImporter->deactivateAllWidgets();
        $this->_addedWidgets = $this->_widgetsImporter->importSidebars($this->_data['Sidebars'], $this->_data['Widgets']);
    }

    /**
     * Import positions content
     *
     * @param bool $remove_previous_content
     */
    private function _importPositionsContent($remove_previous_content) {
        $importOptions = isset($_REQUEST['importOptions']) ? $_REQUEST['importOptions'] : array();
        $importSidebarsContent = isset($importOptions['importSidebarsContent']) && $importOptions['importSidebarsContent'] === 'false' ? false : true;
        if (!isset($this->_data['Positions'])) {
            return;
        }
        if ($importSidebarsContent) {
            $data = &$this->_data['Positions'];
            foreach ($data as $key => $value) {
                if (isset($value[0]) && $value[0] || isset($value['text1']) && $value['text1']) {
                    //for default sidebar's widgets
                    foreach ($value as $widgetName => $contentWidget) {
                        NpWidgetsImporter::importWidgetsContent($key, $contentWidget, false);
                    }
                } else {
                    // for position widget content
                    NpWidgetsImporter::importWidgetsContent($key, $value, $remove_previous_content);
                }
            }
        }
    }

    /**
     * Import products content
     *
     * @param bool $remove_previous_content
     */
    private function _importProductsContent($remove_previous_content) {
        $importOptions = isset($_REQUEST['importOptions']) ? $_REQUEST['importOptions'] : array();
        $importProductsContent = isset($importOptions['importProductsContent']) && $importOptions['importProductsContent'] === 'false' ? false : true;
        if (!isset($this->_data['Products']) || !class_exists('Woocommerce')) {
            return;
        }
        if ($importProductsContent) {
            $data = &$this->_data['Products'];
            foreach ($data as $product_data) {
                $product_id = wp_insert_post(
                    array(
                    'post_title' => isset($product_data['title']) ? $product_data['title'] : 'Product',
                    'post_type' => 'product',
                    'post_status' => 'publish',
                    'post_content' => isset($product_data['description']) ? $product_data['description'] : 'Product description',
                    )
                );

                if (is_wp_error($product_id)) {
                    throw new \Exception($product_id->get_error_message());
                }

                $product = wc_get_product($product_id);
                if ($product) {
                    // set sku
                    $sku = isset($product_data['sku']) ? (string) $product_data['sku'] : '';
                    if (!empty($sku) && !wc_product_has_unique_sku($product_id, $sku)) {
                        $sku = wc_product_generate_unique_sku($product_id, $sku);
                    }
                    $product->set_sku($sku);
                    // set regular price
                    $product->set_regular_price($product_data['price']);

                    $product_images = _arr($product_data, 'images', '');
                    $product_images_ids = array();
                    foreach ($product_images as $product_image) {
                        $image_placeholder = isset($product_image['url']) ? $product_image['url'] : '';
                        if (isset($this->_postsImportedImages[$image_placeholder])) {
                            $product_images_ids[] = $this->_postsImportedImages[$image_placeholder];
                        }
                    }
                    if (count($product_images_ids) > 0) {
                        $product->set_image_id($product_images_ids[0]);
                        $product->set_gallery_image_ids($product_images_ids);
                    }
                    $product->save();
                    $this->_importedProductsIds[] = $product_id;
                }
            }
        }
    }

    /**
     * Import site parameters
     *
     * @param bool $onlyHeaderFooter
     * @param bool $remove_previous_content
     */
    private function _importParameters($onlyHeaderFooter = false, $remove_previous_content = false) {
        if (!isset($this->_data['Parameters'])) {
            return;
        }

        $data = &$this->_data['Parameters'];

        if (!$onlyHeaderFooter) {
            if (isset($data['siteTitle'])) {
                update_option('blogname', $data['siteTitle']);
            }
            if (isset($data['siteSlogan'])) {
                update_option('blogdescription', $data['siteSlogan']);
            }
            if (!empty($data['showPostsOnFront'])) {
                update_option('show_on_front', 'posts');
            }
        }

        $data_provider = np_data_provider();
        $headerFooterHtml = '';
        if (isset($data['header'])) {
            $header = $data['header'];
            $header['php'] = $this->_processContent($header['php'], $onlyHeaderFooter);
            $header['html'] = $this->_processContent($header['html'], $onlyHeaderFooter);
            $header['styles'] = $this->_processContent($header['styles'], $onlyHeaderFooter);
            $headerFooterHtml .= $header['html'];
            if (isset($header['dialogs'])) {
                $header['dialogs'] = $this->_processContent($header['dialogs'], $onlyHeaderFooter);
                $headerFooterHtml .= $header['dialogs'];
            }
            update_option('headerNp', json_encode($header));
        }
        if (isset($data['headerTranslations'])) {
            foreach ($data['headerTranslations'] as $lang => $headerTranslation) {
                $headerTranslation = $this->_processContent($headerTranslation, $onlyHeaderFooter);
                update_option($lang . '_' . 'headerNp', $headerTranslation);
            }
        }
        if (isset($data['footer'])) {
            $footer = $data['footer'];
            $footer['php'] = $this->_processContent($footer['php'], $onlyHeaderFooter);
            $footer['html'] = $this->_processContent($footer['html'], $onlyHeaderFooter);
            $footer['styles'] = $this->_processContent($footer['styles'], $onlyHeaderFooter);
            $headerFooterHtml .= $footer['html'];
            if (isset($footer['dialogs'])) {
                $footer['dialogs'] = $this->_processContent($footer['dialogs'], $onlyHeaderFooter);
                $headerFooterHtml .= $footer['dialogs'];
            }
            update_option('footerNp', json_encode($footer));
        }
        if (isset($data['footerTranslations'])) {
            foreach ($data['footerTranslations'] as $lang => $footerTranslation) {
                $footerTranslation = $this->_processContent($footerTranslation, $onlyHeaderFooter);
                update_option($lang . '_' . 'footerNp', $footerTranslation);
            }
        }

        $publishPasswordProtection = '';
        if (isset($data['password'])) {
            $password = $data['password'];
            $password['php'] = $this->_processContent($password['php'], $onlyHeaderFooter);
            $password['html'] = $this->_processContent($password['html'], $onlyHeaderFooter);
            $password['styles'] = $this->_processContent($password['styles'], $onlyHeaderFooter);
            $publishPasswordProtection .= $password['html'];
            $data_provider->setPasswordProtectionData($password);
        }
        if (isset($data['passwordTranslations'])) {
            foreach ($data['passwordTranslations'] as $lang => $passwordTranslation) {
                $passwordTranslation = $this->_processContent($passwordTranslation, $onlyHeaderFooter);
                update_option($lang . '_' . 'passwordProtectNp', $passwordTranslation);
            }
        }
        if ($publishPasswordProtection) {
            $headerFooterHtml .= $publishPasswordProtection;
        }

        $backToTopHtml = '';
        if (isset($data['backToTop'])) {
            $backToTopHtml = $data['backToTop'];
            NpMeta::update('backToTop', $this->_processContent($backToTopHtml));
        }

        if (!empty($data['publishNicePageCss'])) {
            $data_provider->setStyleCss($this->_processContent($data['publishNicePageCss']), '', $headerFooterHtml . $backToTopHtml);
        }

        if (isset($data['cookiesConsent'])) {
            NpMeta::update('cookiesConsent', $this->_processContent(np_data_provider()->replaceImagePaths($data['cookiesConsent'])));
        }
        if (!$onlyHeaderFooter && isset($data['productsJson']) && !empty($data['productsJson'])) {
            if (!$remove_previous_content) {
                $old_products_data = $data_provider->getProductsJson();
                if (isset($old_products_data['products']) && count($old_products_data['products']) > 0) {
                    $max_product_id = 1;
                    foreach ($old_products_data['products'] as $old_product_data) {
                        if ($max_product_id < (int) $old_product_data['id']) {
                            $max_product_id = (int) $old_product_data['id'];
                        }
                    }
                    if (preg_match_all('/"id":"([\s\S]+?)"/', $data['productsJson'], $matches, PREG_SET_ORDER) !== false) {
                        for ($i = 0; $i < count($matches); $i++) {
                            $old_id = isset($matches[$i][1]) ? $matches[$i][1] : false;
                            $new_id = $max_product_id + 1;
                            if ($old_id && !array_key_exists($old_id, $this->_paymentProductsIds)) {
                                $data['productsJson'] = str_replace('"id":"' . $old_id .'"', '"id":"' . $new_id .'"', $data['productsJson']);
                                $this->_paymentProductsIds[$old_id] = $new_id;
                            }
                            $max_product_id++;
                        }
                    }
                }
            }

            $new_products_data = json_decode($this->_processContent($data['productsJson']), true);

            if (!$remove_previous_content) {
                foreach ($new_products_data as $param_name => $new_param_data) {
                    if (isset($old_products_data[$param_name])) {
                        foreach ($old_products_data[$param_name] as $old_param_data) {
                            $new_products_data[$param_name][] = $old_param_data;
                        }
                    }
                }
            }
            if ($new_products_data) {
                $data_provider->saveProductsJson($new_products_data);
            }
        }
    }

    /**
     * Import site parameters after import pages/posts
     */
    private function _importParametersAfter(){
        if (!isset($this->_data['Parameters'])) {
            return;
        }

        $data = &$this->_data['Parameters'];
        $data_provider = np_data_provider();

        $imported_pages = get_option('np_page_ids');
        $npPagesIds = $imported_pages ? array_keys($imported_pages) : '';
        $cmsPagesIds = $imported_pages ? array_values($imported_pages) : '';

        if (!empty($data['nicepageSiteSettings'])) {
            if ($npPagesIds && $cmsPagesIds) {
                $data['nicepageSiteSettings'] = str_replace($npPagesIds, $cmsPagesIds, $data['nicepageSiteSettings']);
            }
            $data_provider->setSiteSettings($this->_processContent($data['nicepageSiteSettings']));
        }

        if (!empty($data['publishDialogs'])) {
            $dialogs = json_decode($this->_processContent($data['publishDialogs']), true);
            if (class_exists('NpForms')) {
                foreach ($dialogs as $key => $dialog) {
                    $name = isset($dialog['name']) ? $dialog['name'] : '';
                    NpForms::updateForms($name, 'dialogs', $dialog['publishHtml']);
                    if ($npPagesIds && $cmsPagesIds) {
                        if (isset($dialog['showOnList'])) {
                            $dialogs[$key]['showOnList'] = str_replace($npPagesIds, $cmsPagesIds, $dialog['showOnList']);
                        }
                        $dialogs[$key]['html'] = str_replace($npPagesIds, $cmsPagesIds, $dialog['html']);
                    }
                }
            }
            $data_provider->setPublishDialogs($dialogs);
        }
    }

    /**
     * Find and set header footer images placeholders
     */
    public function setHeaderFooterImagesPlaceHolders()
    {
        $this->_headerFooterImages = array();

        if (!isset($this->_data['Parameters'])) {
            return;
        }

        $data = &$this->_data['Parameters'];

        $content = '';
        if (isset($data['header'])) {
            $content = json_encode($data['header']);
        }
        if (isset($data['footer'])) {
            $content .= json_encode($data['header']);
        }

        if (preg_match_all('/\[image_(\d+)\]/', $content, $matches, PREG_SET_ORDER) !== false) {
            for ($i = 0; $i < count($matches); $i++) {
                $placeholder = $matches[$i][0];
                $id = $matches[$i][1];
                if (!array_key_exists($placeholder, $this->_headerFooterImages)) {
                    $this->_headerFooterImages[$placeholder] = $id;
                }
            }
        }
    }

    /**
     * Import parameters
     *
     * @param bool $onlyHeaderFooter
     */
    public function importParameters($onlyHeaderFooter = false)
    {
        $this->_importParameters($onlyHeaderFooter);
    }

    /**
     * Import client mode option
     */
    public function importClientLicenseMode()
    {
        if (!isset($this->_data['Parameters'])) {
            return;
        }

        $data = &$this->_data['Parameters'];
        if (empty($data['nicepageSiteSettings'])) {
            return;
        }

        $settings = $data['nicepageSiteSettings'];
        if ($settings && is_string($settings)) {
            $settings = json_decode($settings, true);
        }

        if (empty($settings)) {
            return;
        }

        $cliendMode = _arr($settings, 'clientMode', false);

        $data_provider = np_data_provider();
        $settings = $data_provider->getSiteSettings(true);
        $settings['clientMode'] = $cliendMode;
        $data_provider->setSiteSettings($settings);
    }

    /**
     * Do import content
     *
     * @param bool $remove_previous_content
     */
    public function import($remove_previous_content = false) {
        if (function_exists("set_time_limit") == true AND @ini_get("safe_mode") == 0) {
            @set_time_limit(0);
        }
        if ($remove_previous_content) {
            $prev_data = get_option('np_imported_content');
            if (is_array($prev_data)) {
                foreach ($prev_data['posts'] as $post_id) {
                    wp_delete_post($post_id);
                }
                foreach ($prev_data['products'] as $product_id) {
                    wp_delete_post($product_id);
                }
                foreach ($prev_data['menus'] as $menu_id) {
                    wp_delete_nav_menu($menu_id);
                }
                foreach ($prev_data['terms'] as $term) {
                    wp_delete_term($term['term_id'], $term['taxonomy']);
                }
                foreach ($prev_data['widgets'] as $widget_id) {
                    $this->_widgetsImporter->deleteWidget($widget_id, true);
                }
            }
        }

        foreach ($this->_supportedTaxonomies as $tax => $key) {
            $this->_importTaxonomies($key['data_key'], $tax);
        }
        $this->_importImages();
        $this->_importParameters(false, $remove_previous_content);
        $this->_importPosts();
        $this->_importParametersAfter(); // after import pages and posts
        $this->_importFonts();
        $this->_importMenus();
        $this->_importSidebars();
        $this->_importPositionsContent($remove_previous_content);
        $this->_importProductsContent($remove_previous_content);

        $imported_posts = array();
        foreach ($this->_newPostIds as $ids) {
            $imported_posts = array_merge($imported_posts, array_values($ids));
        }
        update_option(
            'np_imported_content',
            array(
                'posts' => $imported_posts,
                'products' => $this->_importedProductsIds,
                'menus' => array_values($this->_newMenuIds),
                'images' => $this->_importedImages,
                'terms' => $this->_addedTerms,
                'widgets' => $this->_addedWidgets,
            )
        );
    }

    /**
     * Action on app_import_content
     *
     * @param string $content_dir               Content directory with content.json and images folder
     * @param bool   $remove_previously_content Do we need to remove previously imported content?
     */
    public static function doImportAction($content_dir, $remove_previously_content) {
        $error_data = '';
        try {
            $import = new self($content_dir);
            $import->import($remove_previously_content);
        } catch (Exception $e) {
            $error_data = json_encode(
                array (
                    'error' => $e->getMessage()
                )
            );
        }
        update_option('np_error_import', $error_data);
    }
}

add_action('nicepage_import_content', 'NpContentImporter::doImportAction', 10, 2);