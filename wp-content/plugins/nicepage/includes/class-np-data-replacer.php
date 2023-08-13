<?php
defined('ABSPATH') or die;

require_once dirname(__FILE__) . '/class-np-grid-helper.php';

class NpShopDataReplacer {

    public static $post;
    public static $postId = 0;
    public static $posts;
    public static $productVariationId = 0;
    public static $product;
    public static $productData;

    /**
     * NpShopDataReplacer process.
     *
     * @param string $content
     *
     * @return string $content
     */
    public static function process($content) {
        if (function_exists('wc_get_product')) {
            global $post;
            global $current_post_object;
            $current_post_object = $post;
            $content = self::_processProducts($content);
            $content = self::_processCartControl($content);
            $post = $current_post_object;
        }
        return $content;
    }

    /**
     * Process products
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _processProducts($content) {
        $content = self::_processProductsListControl($content);
        $content = self::_processProductControl($content);
        return $content;
    }

    public static $typeControl;

    /**
     * Process Product List Control
     *
     * @param string $content Page content
     *
     * @return string|string[]|null
     */
    private static function _processProductsListControl($content) {
        return preg_replace_callback(
            '/<\!--products-->([\s\S]+?)<\!--\/products-->/',
            function ($productsMatch) {
                $productsHtml = $productsMatch[1];
                $productsOptions = array();
                if (preg_match('/<\!--products_options_json--><\!--([\s\S]+?)--><\!--\/products_options_json-->/', $productsHtml, $matches)) {
                    $productsOptions = json_decode($matches[1], true);
                    $productsHtml = str_replace($matches[0], '', $productsHtml);
                }
                $productsSourceType = isset($productsOptions['type']) ? $productsOptions['type'] : '';
                if ($productsSourceType === 'Tags') {
                    $productsSource = 'tags:' . (isset($productsOptions['tags']) && $productsOptions['tags'] ? $productsOptions['tags'] : '');
                } else if ($productsSourceType === 'products-featured') {
                    $productsSource = 'featured';
                } else {
                    $productsSource = isset($productsOptions['source']) && $productsOptions['source'] ? $productsOptions['source'] : false;
                }
                $productsCount = isset($productsOptions['count']) ? $productsOptions['count'] : '';
                global $products_control_query;
                // if $productsSource == false - get last posts
                $products_control_query = NpAdminActions::getWpQuery($productsSource, $productsCount, 'product');
                self::$typeControl = 'products';
                $productsHtml = self::_processProductItem($productsHtml);

                $productsGridProps = isset($productsOptions['gridProps']) ? $productsOptions['gridProps'] : array();
                $productsHtml .= GridHelper::buildGridAutoRowsStyles($productsGridProps, count($products_control_query->posts));

                return $productsHtml;
            },
            $content
        );
    }

    /**
     * Process Product control
     *
     * @param string $content Page content
     *
     * @return string|string[]|null
     */
    private static function _processProductControl($content) {
        return preg_replace_callback(
            '/<\!--product-->([\s\S]+?)<\!--\/product-->/',
            function ($productMatch) {
                $productHtml = $productMatch[1];
                $productOptions = array();
                if (preg_match('/<\!--product_options_json--><\!--([\s\S]+?)--><\!--\/product_options_json-->/', $productHtml, $matches)) {
                    $productOptions = json_decode($matches[1], true);
                    $productHtml = str_replace($matches[0], '', $productHtml);
                }
                $productsSource = isset($productOptions['source']) && $productOptions['source'] ? $productOptions['source'] : false;
                // if $productsSource == false - get last posts
                if ($productsSource) {
                    self::$posts = NpAdminActions::getPost($productsSource);
                } else {
                    self::$posts = NpAdminActions::getPosts($productsSource, 1, 'product');
                }
                if (count(self::$posts) < 1) {
                    return ''; // remove cell, if post is missing
                }
                self::$post = array_shift(self::$posts);
                self::$postId = self::$post->ID;
                self::$productData = np_data_product(self::$postId);
                self::$product = self::$productData['product'];
                self::$typeControl = 'product';
                return self::_replaceProductItemControls($productHtml, true);
            },
            $content
        );
    }

    public static $tabItemIndex = 0;
    public static $tabContentIndex = 0;

    /**
     * Process product item
     *
     * @param string $content Page content
     *
     * @return string|string[]|null
     */
    private static function _processProductItem($content) {
        $reProductItem = '/<\!--product_item-->([\s\S]+?)<\!--\/product_item-->/';
        preg_match_all($reProductItem, $content, $matches, PREG_SET_ORDER);
        $allTemplates = count($matches);
        if ($allTemplates > 0) {
            $allProductsHtml = '';
            global $products_control_query;
            if (method_exists($products_control_query, 'have_posts')) {
                if (count($products_control_query->posts) < 1) {
                    return ''; // remove cell, if products is missing
                }
                $i = 0;
                while($products_control_query->have_posts()) :
                    $tmplIndex = $i % $allTemplates;
                    $productItemHtml = $matches[$tmplIndex][1];
                    $products_control_query->the_post();
                    self::$post = $products_control_query->post;;
                    self::$postId = self::$post->ID;
                    self::$productData = np_data_product(self::$postId);
                    if (count(self::$productData) > 0) {
                        self::$product = self::$productData['product'];
                        $allProductsHtml .= self::_replaceProductItemControls($productItemHtml);
                    }
                    $i++;
                endwhile;
            }
        }
        $content = preg_replace('/<!--product_item-->([\s\S]+)<!--\/product_item-->/', $allProductsHtml, $content);
        $content = NpAdminActions::processPagination($content, 'products');
        return $content;
    }

    /**
     * Replace placeholder for product item controls
     *
     * @param string $content
     * @param bool   $single
     *
     * @return string $content
     */
    private static function _replaceProductItemControls($content, $single = false) {
        $content = self::_replaceTitle($content);
        $content = self::_replaceContent($content);
        $content = self::_replaceImage($content);
        $content = self::_replaceButton($content);
        $content = self::_replacePrice($content);
        $content = self::_replaceGallery($content);
        $content = self::_replaceVariations($content);
        $content = self::_replaceTabs($content);
        $content = self::_replaceQuantity($content);
        if ($single) {
            $content = self::_addProductSchema($content);
        }
        return $content;
    }

    /**
     * Replace placeholder for product title
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replaceTitle($content) {
        return preg_replace_callback(
            '/<!--product_title-->([\s\S]+?)<!--\/product_title-->/',
            function ($titleMatch) {
                $titleHtml = $titleMatch[1];
                $titleHtml = self::_replaceTitleUrl($titleHtml);
                $titleHtml = self::_replaceTitleContent($titleHtml);
                return $titleHtml;
            },
            $content
        );
    }

    /**
     * Replace placeholder for product title content
     *
     * @param string $content title html
     *
     * @return string $content
     */
    private static function _replaceTitleContent($content) {
        $productTitle = self::$productData['title'];
        $productTitle = $productTitle ? $productTitle : self::$post->post_title;
        if (isset($productTitle) && $productTitle != '') {
            $content = preg_replace('/<!--product_title_content-->([\s\S]+?)<!--\/product_title_content-->/', $productTitle, $content);
        }
        return $content;
    }

    /**
     * Replace placeholder for product title url
     *
     * @param string $content title html
     *
     * @return string $content
     */
    private static function _replaceTitleUrl($content) {
        $postUrl = get_permalink(self::$postId);
        $postUrl = $postUrl ? $postUrl : '#';
        if ($postUrl) {
            $content = preg_replace('/href=[\'|"][\s\S]+?[\'|"]/', 'href="' . $postUrl . '"', $content);
        }
        return $content;
    }

    /**
     * Replace placeholder for product content
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replaceContent($content) {
        return preg_replace_callback(
            '/<!--product_content-->([\s\S]+?)<!--\/product_content-->/',
            function ($textMatch) {
                $textHtml = $textMatch[1];
                $productContent = self::$productData['desc'];
                if (isset($productContent) && $productContent != '') {
                    $stock_html = wc_get_stock_html(self::$product);
                    if ($stock_html && $stock_html !== '') {
                        $productContent .= '</br>' . $stock_html;
                    }
                    $textHtml = preg_replace('/<!--product_content_content-->([\s\S]+?)<!--\/product_content_content-->/', $productContent, $textHtml);
                }
                return $textHtml;
            },
            $content
        );
    }
    /**
     * Replace placeholder for product image
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replaceImage($content) {
        return preg_replace_callback(
            '/<!--product_image-->([\s\S]+?)<!--\/product_image-->/',
            function ($imageMatch) {
                $imageHtml = $imageMatch[1];
                $url = self::$productData['image_url'];
                if (!$url) {
                    return '<div class="none-post-image" style="display: none;"></div>';
                }
                $isBackgroundImage = strpos($imageHtml, '<div') !== false ? true : false;
                $link = get_permalink(self::$postId);
                if ($isBackgroundImage) {
                    $imageHtml = str_replace('<div', '<div data-product-control="' . $link . '"', $imageHtml);
                    if (strpos($imageHtml, 'data-bg') !== false) {
                        $imageHtml = preg_replace('/(data-bg=[\'"])([\s\S]+?)([\'"])/', '$1url(' . $url . ')$3', $imageHtml);
                    } else {
                        $imageHtml = str_replace('<div', '<div' . ' style="background-image:url(' . $url . ')"', $imageHtml);
                    }
                } else {
                    $imageHtml = preg_replace('/(src=[\'"])([\s\S]+?)([\'"])/', '$1' . $url . '$3 style="cursor:pointer;" data-product-control="' . $link . '"', $imageHtml);
                }
                return $imageHtml;
            },
            $content
        );
    }

    /**
     * Replace placeholder for product button add to cart
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replaceButton($content) {
        return preg_replace_callback(
            '/<!--product_button-->([\s\S]+?)<!--\/product_button-->/',
            function ($buttonMatch) {
                $button_html = $buttonMatch[1];
                $controlOptions = array();
                if (preg_match('/<\!--options_json--><\!--([\s\S]+?)--><\!--\/options_json-->/', $button_html, $matches)) {
                    $controlOptions = json_decode($matches[1], true);
                    $button_html = str_replace($matches[0], '', $button_html);
                }
                $goToProduct = false;
                if (isset($controlOptions['clickType']) && $controlOptions['clickType'] === 'go-to-page') {
                    $goToProduct = true;
                }
                $buttonText = sprintf(__('%s', 'woocommerce'), self::$productData['add_to_cart_text']);
                if (self::$typeControl === "products") {
                    $button_html = preg_replace('/href=[\'|"][\s\S]*?[\'|"]/', 'href="%s"', $button_html);
                    $button_html = preg_replace('/<!--product_button_content-->([\s\S]+?)<!--\/product_button_content-->/', '%s', $button_html);
                }
                if ($goToProduct) {
                    $button_html = sprintf(
                        $button_html,
                        get_permalink(self::$postId),
                        $buttonText
                    );
                } else {
                    if (self::$typeControl === "products") {
                        $button_html = preg_replace('/class=[\'|"]([\s\S]+?)[\'|"]/', 'class="$1 %s"', $button_html);
                        $button_html = str_replace('href', 'data-quantity="1" data-product_id="%s" data-product_sku="%s" href', $button_html);
                    }
                    $button_html = NpDataProduct::getProductButtonHtml($button_html, self::$product, self::$typeControl, $controlOptions);
                    if (self::$typeControl === "product" && self::$productData['type'] !== "variable") {
                        global $product;
                        $product = self::$productData['product'];
                        ob_start();
                        woocommerce_template_single_add_to_cart();
                        $form = ob_get_clean();
                        $form = preg_replace('/<p class="stock.+">[\s\S]+?<\/p>/', '', $form);
                        return $form = preg_replace('/(<form[\s\S]*?>)([\s\S]+?)(<\/form>)/', '$1' . $button_html . '<div style="display:none;">$2</div>' . '$3', $form);
                    }
                }
                return $button_html;
            },
            $content
        );
    }

    /**
     * Replace placeholder for product price
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replacePrice($content) {
        return preg_replace_callback(
            '/<!--product_price-->([\s\S]+?)<!--\/product_price-->/',
            function ($priceHtml) {
                $priceHtml = $priceHtml[1];
                $price = self::$productData['price'];
                $price_old = self::$productData['price_old'];
                if ($price_old == $price) {
                    $price_old = '';
                }
                $priceHtml = preg_replace('/<!--product_old_price_content-->([\s\S]*?)<!--\/product_old_price_content-->/', $price_old, $priceHtml);
                return preg_replace('/<!--product_regular_price_content-->([\s\S]+?)<!--\/product_regular_price_content-->/', $price, $priceHtml);
            },
            $content
        );
    }

    /**
     * Replace placeholder for product gallery
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replaceGallery($content) {
        return preg_replace_callback(
            '/<!--product_gallery-->([\s\S]+?)<!--\/product_gallery-->/',
            function ($galleryMatch) {
                $galleryHtml = $galleryMatch[1];
                $galleryData = array();
                $attachment_ids = self::$productData['gallery_images_ids'];
                foreach ($attachment_ids as $attachment_id) {
                    array_push($galleryData, wp_get_attachment_url($attachment_id));
                }

                if (count($galleryData) < 1) {
                    return '';
                }

                $controlOptions = array();
                if (preg_match('/<\!--options_json--><\!--([\s\S]+?)--><\!--\/options_json-->/', $galleryHtml, $matches)) {
                    $controlOptions = json_decode($matches[1], true);
                    $galleryHtml = str_replace($matches[0], '', $galleryHtml);
                }

                $maxItems = -1;
                if (isset($controlOptions['maxItems']) && $controlOptions['maxItems']) {
                    $maxItems = (int) $controlOptions['maxItems'];
                }

                if ($maxItems !== -1 && count($galleryData) > $maxItems) {
                    $galleryData = array_slice($galleryData, 0, $maxItems);
                }

                $galleryItemRe = '/<\!--product_gallery_item-->([\s\S]+?)<\!--\/product_gallery_item-->/';
                preg_match($galleryItemRe, $galleryHtml, $galleryItemMatch);
                $galleryItemHtml = str_replace('u-active', '', $galleryItemMatch[1]);

                $galleryThumbnailRe = '/<\!--product_gallery_thumbnail-->([\s\S]+?)<\!--\/product_gallery_thumbnail-->/';
                $galleryThumbnailHtml = '';
                if (preg_match($galleryThumbnailRe, $galleryHtml, $galleryThumbnailMatch)) {
                    $galleryThumbnailHtml = $galleryThumbnailMatch[1];
                }

                $newGalleryItemListHtml = '';
                $newThumbnailListHtml = '';
                foreach ($galleryData as $key => $img) {
                    $newGalleryItemHtml = $key == 0 ? str_replace('u-gallery-item', 'u-gallery-item u-active', $galleryItemHtml) : $galleryItemHtml;
                    $newGalleryItemListHtml .= preg_replace('/(src=[\'"])([\s\S]+?)([\'"])/', '$1' . $img . '$3', $newGalleryItemHtml);
                    if ($galleryThumbnailHtml) {
                        $newThumbnailHtml = preg_replace('/data-u-slide-to=([\'"])([\s\S]+?)([\'"])/', 'data-u-slide-to="' . $key . '"', $galleryThumbnailHtml);
                        $newThumbnailListHtml .= preg_replace('/(src=[\'"])([\s\S]+?)([\'"])/', '$1' . $img . '$3', $newThumbnailHtml);
                    }
                }

                $galleryParts = preg_split($galleryItemRe, $galleryHtml, -1, PREG_SPLIT_NO_EMPTY);
                $newGalleryHtml = $galleryParts[0] . $newGalleryItemListHtml . $galleryParts[1];

                $newGalleryParts = preg_split($galleryThumbnailRe, $newGalleryHtml, -1, PREG_SPLIT_NO_EMPTY);
                return $newGalleryParts[0] . $newThumbnailListHtml . $newGalleryParts[1];
            },
            $content
        );
    }

    /**
     * Replace placeholder for product variations
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replaceVariations($content) {
        return preg_replace_callback(
            '/<!--product_variations-->([\s\S]+?)<!--\/product_variations-->/',
            function ($product_variations) {
                $productVariationsHtml = $product_variations[1];
                $productVariationsHtml = str_replace('u-product-variations ', 'u-product-variations variations ', $productVariationsHtml);
                preg_match('/<!--product_variation-->([\s\S]+?)<!--\/product_variation-->/', $productVariationsHtml, $productVariations);
                $firstVariationHtml = $productVariations[0];
                $productVariationsHtml = preg_replace('/<!--product_variation-->([\s\S]+)<!--\/product_variation-->/', $firstVariationHtml, $productVariationsHtml);
                $newVariationHtml = '';
                $productAttributes = self::$productData['attributes'];
                $product_type = self::$productData['type'];
                if ($product_type == 'variable') {
                    $variation_attributes = self::$productData['variations_attributes'];
                    if (count($variation_attributes) > 0) {
                        foreach ($variation_attributes as $name => $variation_attribute) {
                            $doubleVariationHtml = $firstVariationHtml;
                            $optionsHtml = '<option value="">' . __('Choose an option', 'woocommerce') . '</option>';
                            $productAttribute = $productAttributes[strtolower($name)] ? $productAttributes[strtolower($name)] : $productAttributes[wc_attribute_taxonomy_slug($name)];
                            $variation_title = $productAttribute['name'];
                            $variation_options = $productAttribute['options'];
                            $select_id = strtolower($variation_title);
                            if (isset($productAttribute['id']) && $productAttribute['id'] > 0) {
                                $attribute = NpDataProduct::getProductAttribute($productAttribute['id']);
                                $variation_options = $productAttribute->get_terms();
                                $variation_title = NpDataProduct::getProductVariationTitle($attribute, $productAttribute);
                            }
                            $doubleVariationHtml = self::_replaceVariationLabel($doubleVariationHtml, $variation_title);
                            $doubleVariationHtml = preg_replace('/for=[\'"][\s\S]+?[\'"]/', 'for="' . $select_id . '"', $doubleVariationHtml);
                            $doubleVariationHtml = preg_replace('/(select[\s\S]+?id=[\'"])([\s\S]+?)([\'"])/', '$1' . $select_id . '$3' . ' name="attribute_' . $select_id . '" data-attribute_name="attribute_' . $select_id . '" data-show_option_none="yes"', $doubleVariationHtml);
                            preg_match('/<!--product_variation_option-->([\s\S]+?)<!--\/product_variation_option-->/', $doubleVariationHtml, $productOptions);
                            $firstOptionHtml = $productOptions[0];
                            if (is_array($variation_options)) {
                                foreach ($variation_options as $variation_option) {
                                    $optionsHtml = self::_constructVariationOptions($firstOptionHtml, $variation_option, $optionsHtml);
                                }
                            }
                            $doubleVariationHtml = self::_replaceVariationOptionHtml($doubleVariationHtml, $optionsHtml);
                            $newVariationHtml .= self::_replaceVariationSelectContent($doubleVariationHtml, $optionsHtml);
                            self::$productVariationId++;
                        }
                    }
                    $productVariationsHtml = self::_replaceDefaultVariationsHtml($productVariationsHtml, $newVariationHtml);
                    global $product;
                    $product = self::$productData['product'];
                    $productVariationsHtml = str_replace('u-form-select-wrapper', 'u-form-select-wrapper value', $productVariationsHtml);
                    if (self::$productData['product']->is_in_stock() && self::$productData['product']->is_purchasable()) {
                        return $form = self::_constructFormWithVariations($productVariationsHtml);
                    } else {
                        return $productVariationsHtml = '';
                    }
                } else {
                    $productVariationsHtml = '';
                }
                return $productVariationsHtml;
            },
            $content
        );
    }

    /**
     * Replace label product variation
     *
     * @param string $content
     * @param string $variation_title
     *
     * @return string $content
     */
    private static function _replaceVariationLabel($content, $variation_title) {
        return preg_replace('/<!--product_variation_label_content-->([\s\S]*?)<!--\/product_variation_label_content-->/', $variation_title, $content);
    }

    /**
     * Construct variation options
     *
     * @param string $firstOptionHtml
     * @param string $variation_option
     * @param string $optionsHtml
     *
     * @return string $optionsHtml
     */
    private static function _constructVariationOptions($firstOptionHtml, $variation_option, $optionsHtml) {
        $variation_option_title = NpDataProduct::getProductVariationOptionTitle($variation_option);
        $doubleOptionHtml = $firstOptionHtml;
        $doubleOptionHtml = preg_replace('/value=[\'"][\s\S]+?[\'"]/', 'value="' . $variation_option_title . '"', $doubleOptionHtml);
        $doubleOptionHtml = self::_replaceVariationOptionContent($doubleOptionHtml, $variation_option_title);
        $optionsHtml .= $doubleOptionHtml;
        return $optionsHtml;
    }

    /**
     * Replace default option content for select product variation
     *
     * @param string $content
     * @param string $optionTitle
     *
     * @return string $content
     */
    private static function _replaceVariationOptionContent($content, $optionTitle) {
        return preg_replace('/<!--product_variation_option_content-->([\s\S]+?)<!--\/product_variation_option_content-->/', $optionTitle, $content);
    }

    /**
     * Replace default option html for select product variation
     *
     * @param string $content
     * @param string $option
     *
     * @return string $content
     */
    private static function _replaceVariationOptionHtml($content, $option) {
        return preg_replace('/<!--product_variation_option-->([\s\S]+)<!--\/product_variation_option-->/', $option, $content);
    }

    /**
     * Replace default options for select product variation
     *
     * @param string $content
     * @param string $option
     *
     * @return string $content
     */
    private static function _replaceVariationSelectContent($content, $option) {
        return preg_replace('/<!--product_variation_select_content-->([\s\S]*?)<!--\/product_variation_select_content-->/', $option, $content);
    }

    /**
     * Replace default variations html
     *
     * @param string $content
     * @param string $variations html
     *
     * @return string $content
     */
    private static function _replaceDefaultVariationsHtml($content, $variations) {
        return preg_replace('/<!--product_variation-->([\s\S]*?)<!--\/product_variation-->/', $variations, $content);
    }

    /**
     * Replace placeholder for variations html
     *
     * @param string $content html variations
     *
     * @return string $content form variations
     */
    private static function _constructFormWithVariations($content) {
        ob_start();
        woocommerce_template_single_add_to_cart();
        $form = ob_get_clean();
        $add_to_cart = '<div class="single_variation_wrap-np" style="display: none">
			<div class="woocommerce-variation single_variation" style="display: none;"></div>
			<div class="woocommerce-variation-add-to-cart variations_button woocommerce-variation-add-to-cart-enabled">
	
		<div class="quantity">
		<input type="number" class="input-text qty text" step="1" min="1" max="" name="quantity" value="1" size="4" placeholder="" inputmode="numeric">
			</div>
			<button type="submit" class="np-submit single_add_to_cart_button button alt disabled wc-variation-selection-needed">Add to cart</button>
	<input type="hidden" name="add-to-cart" value="'. self::$postId .'">
	<input type="hidden" name="product_id" value="' . self::$postId .'">
	<input type="hidden" name="variation_id" class="variation_id" value="0">
</div>
		</div>';
        return preg_replace('/(<form[\s\S]*?>)([\s\S]+?)(<\/form>)/', '$1' . $content . $add_to_cart . '$3', $form);
    }

    /**
     * Replace placeholder for product tabs
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replaceTabs($content) {
        return preg_replace_callback(
            '/<!--product_tabs-->([\s\S]+?)<!--\/product_tabs-->/',
            function ($product_tabs) {
                $productTabsHtml = $product_tabs[1];
                $productTabsHtml = self::_replaceTabItem($productTabsHtml);
                $productTabsHtml = self::_replaceTabPane($productTabsHtml);
                return $productTabsHtml;
            },
            $content
        );
    }

    /**
     * Replace placeholder for product tab item
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replaceTabItem($content) {
        return preg_replace_callback(
            '/<!--product_tabitem-->([\s\S]+?)<!--\/product_tabitem-->/',
            function ($productTabsHtml) {
                $productTabsHtml = $productTabsHtml[1];
                if (isset(self::$productData['tabs'][self::$tabItemIndex])) {
                    $productTabsHtml = self::_replaceTabItemTitle($productTabsHtml);
                } else {
                    return '';
                }
                self::$tabItemIndex++;
                return $productTabsHtml;
            },
            $content
        );
    }

    /**
     * Replace placeholder for product tab item title
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replaceTabItemTitle($content) {
        $title = sprintf(__('%s', 'woocommerce'), self::$productData['tabs'][self::$tabItemIndex]['title']);
        return preg_replace('/<!--product_tabitem_title-->([\s\S]*)<!--\/product_tabitem_title-->/', $title, $content);
    }

    /**
     * Replace placeholder for product tab panel
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replaceTabPane($content) {
        return preg_replace_callback(
            '/<!--product_tabpane-->([\s\S]+?)<!--\/product_tabpane-->/',
            function ($productTabsHtml) {
                $productTabsHtml = $productTabsHtml[1];
                if (isset(self::$productData['tabs'][self::$tabContentIndex])) {
                    $productTabsHtml = self::_replaceTabPaneContent($productTabsHtml);
                } else {
                    return '';
                }
                self::$tabContentIndex++;
                return $productTabsHtml;
            },
            $content
        );
    }

    /**
     * Replace placeholder for product tab panel content
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replaceTabPaneContent($content) {
        return preg_replace('/<!--product_tabpane_content-->([\s\S]*)<!--\/product_tabpane_content-->/', self::$productData['tabs'][self::$tabContentIndex]['content'], $content);
    }

    /**
     * Replace placeholder for quantity
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replaceQuantity($content) {
        return preg_replace_callback(
            '/<!--product_quantity-->([\s\S]+?)<!--\/product_quantity-->/',
            function ($quantityHtml) {
                $quantityHtml = $quantityHtml[1];
                $quantityHtml = self::_replaceQuantityLabel($quantityHtml);
                $quantityHtml = self::_replaceQuantityInput($quantityHtml);
                return $quantityHtml;
            },
            $content
        );
    }

    /**
     * Add product schema for seo
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _addProductSchema($content) {
        $meta = self::$productData['meta'] ? self::$productData['meta'] : get_post_meta(self::$product->get_id());
        $stock = isset($meta['_stock_status'][0]) && $meta['_stock_status'][0] == 'instock' ? 'InStock' : 'OutOfStock';
        if (self::$product->regular_price!= null) {
            $price = self::$product->regular_price;
        } elseif (self::$product->price!= null) {
            $price = self::$product->price;
        }
        if (($price > self::$product->sale_price) && (self::$product->sale_price!= null)) {
            $price = self::$product->sale_price;
        }
        $product_description = self::$productData['desc'] != null ? self::$productData['desc'] : '';
        $image = self::$productData['image_url'] ? self::$productData['image_url'] : '';
        global $current_post_object;
        $product_json = array(
            "@context" => "http://schema.org",
            "@type" => "Product",
            "name" => get_the_title(self::$postId),
            "image" => $image,
            "offers" => array(
                "@type" => "Offer",
                "priceCurrency" => get_woocommerce_currency(),
                "price" => $price,
                "itemCondition" => "http://schema.org/NewCondition",
                "availability" => "http://schema.org/" . $stock,
                "url" => get_permalink($current_post_object->ID),
                "description" => $product_description,
            ),
        );
        $content .= '<script type="application/ld+json">' . json_encode($product_json) . "</script>\n";
        return $content;
    }

    /**
     * Replace placeholder for quantity label
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replaceQuantityLabel($content) {
        return preg_replace('/<!--product_quantity_label_content-->([\s\S]*?)<!--\/product_quantity_label_content-->/', esc_html__('Quantity', 'woocommerce'), $content);
    }

    /**
     * Replace placeholder for quantity input
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replaceQuantityInput($content) {
        return preg_replace_callback(
            '/<\!--product_quantity_input-->([\s\S]+?)<\!--\/product_quantity_input-->/',
            function ($quantityHtml) {
                $quantityHtml = $quantityHtml[1];
                preg_match('/<input[\s\S]+?class=[\'"]([\s\S]+?)[\'"]/', $quantityHtml, $quantityClasses);
                $max = self::$productData['product']->get_max_purchase_quantity();
                $quantityHtml = '<input 
	    class="' . $quantityClasses[1] . '" 
	    type="text" 
	    value="1" 
	    step="' . esc_attr(apply_filters('woocommerce_quantity_input_step', '1', self::$productData['product'])) . '" 
	    min="' . esc_attr(self::$productData['product']->get_min_purchase_quantity()) . '" 
	    max="' . esc_attr(0 < $max ? $max : '') . '"
	    title="' . esc_attr_x('Qty', 'Product quantity input tooltip', 'woocommerce') . '" 
	    size="4" 
	    pattern="[0-9]+">';
                return $quantityHtml;
            },
            $content
        );
    }

    /**
     * Process cart for WooCommerce
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _processCartControl($content) {
        $content = preg_replace_callback(
            '/<\!--shopping_cart-->([\s\S]+?)<\!--\/shopping_cart-->/',
            function ($shoppingCartMatch) {
                $shoppingCartHtml = $shoppingCartMatch[1];

                if (!isset(WC()->cart)) {
                    return $shoppingCartHtml;
                }

                $shoppingCartHtml = self::_replace_cart_url($shoppingCartHtml);
                $shoppingCartHtml = self::_replace_cart_count($shoppingCartHtml);
                $script = <<<SCRIPT
<script type="text/javascript">
        if (window.sessionStorage) {
            window.sessionStorage.setItem('wc_cart_created', '');
        }
    </script>
SCRIPT;

                $cartParentOpen = '<div>';
                if (preg_match('/<a[\s\S]+?class=[\'"]([\s\S]+?)[\'"]/', $shoppingCartHtml, $matches)) {
                    $cartParentOpen = '<div class="' . $matches[1] . '">';
                    $shoppingCartHtml = str_replace($matches[1], '', $shoppingCartHtml);
                }
                $cart_open = '<div class="widget_shopping_cart_content">';
                $cart_close = '</div>';
                return $script . $cartParentOpen . $cart_open . $shoppingCartHtml . $cart_close . '</div>';
            },
            $content
        );
        return $content;
    }

    /**
     * Replace shipping cart url
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replace_cart_url($content) {
        return preg_replace('/(\s+href=[\'"])([\s\S]+?)([\'"])/', '$1' . wc_get_cart_url() . '$3', $content);
    }

    /**
     * Replace shipping cart count
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replace_cart_count($content) {
        return preg_replace_callback(
            '/<\!--shopping_cart_count-->([\s\S]+?)<\!--\/shopping_cart_count-->/',
            function () {
                $count = WC()->cart->get_cart_contents_count();
                return isset($count) ? $count : 0;
            },
            $content
        );
    }
}

class NpBlogPostDataReplacer {

    public static $_post;
    public static $_posts;
    public static $_postId = 0;
    public static $_postType = 'full';

    /**
     * NpBlogPostDataReplacer process.
     *
     * @param string $content
     *
     * @return string $content
     */
    public static function process($content) {
        $content = self::_processBlogControl($content);
        $content = self::_processPostControl($content);
        return $content;
    }

    /**
     * Process blog controls
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _processBlogControl($content) {
        $content = preg_replace_callback(
            '/<\!--blog-->([\s\S]+?)<\!--\/blog-->/',
            function ($blogMatch) {
                $blogHtml = $blogMatch[1];
                $blogOptions = array();
                if (preg_match('/<\!--blog_options_json--><\!--([\s\S]+?)--><\!--\/blog_options_json-->/', $blogHtml, $matches)) {
                    $blogOptions = json_decode($matches[1], true);
                    $blogHtml = str_replace($matches[0], '', $blogHtml);
                }
                $blogSourceType = isset($blogOptions['type']) ? $blogOptions['type'] : '';
                if ($blogSourceType === 'Tags') {
                    $blogSource = 'tags:' . (isset($blogOptions['tags']) && $blogOptions['tags'] ? $blogOptions['tags'] : '');
                } else {
                    $blogSource = isset($blogOptions['source']) && $blogOptions['source'] ? $blogOptions['source'] : false;
                }
                $blogPostCount = isset($blogOptions['count']) ? $blogOptions['count'] : '';
                global $blog_control_query;
                $posts = isset($blog_control_query->posts) ? $blog_control_query->posts : array();
                // if $blogSource == false - get last posts in the WP_Query
                $blog_control_query = NpAdminActions::getWpQuery($blogSource, $blogPostCount);
                $blogHtml = self::_processPost($blogHtml, 'intro');

                $blogGridProps = isset($blogOptions['gridProps']) ? $blogOptions['gridProps'] : array();
                $blogHtml .= GridHelper::buildGridAutoRowsStyles($blogGridProps, count($posts));

                return $blogHtml;
            },
            $content
        );
        return $content;
    }

    /**
     * Process post control - Full control
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _processPostControl($content) {
        $content = preg_replace_callback(
            '/<\!--post_details-->([\s\S]+?)<\!--\/post_details-->/',
            function ($postMatch) {
                $postHtml = $postMatch[1];
                $postOptions = array();
                if (preg_match('/<\!--post_details_options_json--><\!--([\s\S]+?)--><\!--\/post_details_options_json-->/', $postHtml, $matches)) {
                    $postOptions = json_decode($matches[1], true);
                    $postHtml = str_replace($matches[0], '', $postHtml);
                }
                $postSource = isset($postOptions['source']) && $postOptions['source'] ? $postOptions['source'] : false;
                NpBlogPostDataReplacer::$_posts = NpAdminActions::getPosts($postSource, 1);
                if (count(NpBlogPostDataReplacer::$_posts) < 1) {
                    return ''; // remove cell, if post is missing
                }
                NpBlogPostDataReplacer::$_post = array_shift(NpBlogPostDataReplacer::$_posts);
                NpBlogPostDataReplacer::$_postId = NpBlogPostDataReplacer::$_post->ID;
                return self::blogPostProcess($postHtml, 'full');
            },
            $content
        );
        return $content;
    }

    /**
     * Process post controls - Control parts
     *
     * @param string $content
     * @param string $type
     *
     * @return string $content
     */
    private static function _processPost($content, $type='full') {
        $reBlogPost = '/<\!--blog_post-->([\s\S]+?)<\!--\/blog_post-->/';
        preg_match_all($reBlogPost, $content, $matches, PREG_SET_ORDER);
        $allTemplates = count($matches);
        if ($allTemplates > 0) {
            $allPostsHtml = '';
            global $blog_control_query;
            if ($blog_control_query && method_exists($blog_control_query, 'have_posts')) {
                global $post;
                $current_post = $post;
                $i = 0;
                while($blog_control_query->have_posts()) :
                    $blog_control_query->the_post();
                    if (count($blog_control_query->posts) < 1) {
                        return ''; // remove cell, if post is missing
                    }
                    NpBlogPostDataReplacer::$_post = $blog_control_query->post;
                    $tmplIndex = $i % $allTemplates;
                    $postHtml = $matches[$tmplIndex][0];
                    NpBlogPostDataReplacer::$_postId = NpBlogPostDataReplacer::$_post->ID;
                    if (strpos(NpBlogPostDataReplacer::$_post->post_title, '$') !== false) {
                        NpBlogPostDataReplacer::$_post->post_title = str_replace('$', '[[$]]', NpBlogPostDataReplacer::$_post->post_title);
                    }
                    if (strpos(NpBlogPostDataReplacer::$_post->post_content, '$') !== false) {
                        NpBlogPostDataReplacer::$_post->post_content = str_replace('$', '[[$]]', NpBlogPostDataReplacer::$_post->post_content);
                    }
                    $allPostsHtml .= self::blogPostProcess($postHtml, $type);
                    $i++;
                endwhile;
                $post = $current_post;
            }
        }
        $content = preg_replace('/<!--blog_post-->([\s\S]+)<!--\/blog_post-->/', $allPostsHtml, $content);
        $content = NpAdminActions::processPagination($content);
        if (strpos($content, '[[$]]') !== false) {
            $content = str_replace('[[$]]', '$', $content);
        }
        return $content;
    }

    /**
     * Process with post controls for blog control
     *
     * @param string $content
     * @param string $type
     *
     * @return string $content
     */
    public static function blogPostProcess($content, $type='full') {
        NpBlogPostDataReplacer::$_postType = $type;
        $content = preg_replace_callback(
            '/<!--blog_post-->([\s\S]+?)<!--\/blog_post-->/',
            function ($content) {
                $content[1] = self::_replace_blog_post_header($content[1]);
                $content[1] = self::_replace_blog_post_content($content[1]);
                $content[1] = self::_replace_blog_post_image($content[1]);
                $content[1] = self::_replace_blog_post_readmore($content[1]);
                $content[1] = self::_replace_blog_post_metadata($content[1]);
                $content[1] = self::_replace_blog_post_tags($content[1]);
                return $content[1];
            },
            $content
        );
        return $content;
    }

    /**
     * Replace blog post header
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replace_blog_post_header($content) {
        return preg_replace_callback(
            '/<!--blog_post_header-->([\s\S]+?)<!--\/blog_post_header-->/',
            function ($content) {
                $postTitle = NpBlogPostDataReplacer::$_post->post_title;
                $postUrl = get_permalink(NpBlogPostDataReplacer::$_postId);
                $postUrl = $postUrl ? $postUrl : '#';
                if ($postUrl) {
                    $content[1] = preg_replace('/href=[\'|"][\s\S]+?[\'|"]/', 'href="' . $postUrl . '"', $content[1]);
                    if (isset($postTitle) && $postTitle != '') {
                        $content[1] = preg_replace('/<!--blog_post_header_content-->([\s\S]+?)<!--\/blog_post_header_content-->/', $postTitle, $content[1]);
                    }
                }
                return $content[1];
            },
            $content
        );
    }

    /**
     * Replace blog post content
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replace_blog_post_content($content) {
        return preg_replace_callback(
            '/<!--blog_post_content-->([\s\S]+?)<!--\/blog_post_content-->/',
            function ($content) {
                $postContent = NpBlogPostDataReplacer::$_postType === 'full' ? NpBlogPostDataReplacer::$_post->post_content : plugin_trim_long_str(NpAdminActions::getTheExcerpt(NpBlogPostDataReplacer::$_post->ID), 150);
                if (isset($postContent) && $postContent != '') {
                    $content[1] = preg_replace('/<!--blog_post_content_content-->([\s\S]+?)<!--\/blog_post_content_content-->/', $postContent, $content[1]);
                }
                return $content[1];
            },
            $content
        );
    }

    /**
     * Replace blog post image
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replace_blog_post_image($content) {
        return preg_replace_callback(
            '/<!--blog_post_image-->([\s\S]+?)<!--\/blog_post_image-->/',
            function ($content) {
                $imageHtml = $content[1];
                $thumb_id = get_post_thumbnail_id(NpBlogPostDataReplacer::$_postId);
                $image_alt = '';
                if ($thumb_id) {
                    $url = get_attached_file($thumb_id);
                    $image_alt = get_post_meta($thumb_id, '_wp_attachment_image_alt', true);
                } else {
                    preg_match('/<img[\s\S]+?src=[\'"]([\s\S]+?)[\'"] [\s\S]+?>/', NpBlogPostDataReplacer::$_post->post_content, $regexResult);
                    if (count($regexResult) < 1) {
                        return '<div class="none-post-image" style="display: none;"></div>';
                    }
                    $url = $regexResult[1];
                }
                $isBackgroundImage = strpos($imageHtml, '<div') !== false ? true : false;
                $uploads = wp_upload_dir();
                $url = str_replace($uploads['basedir'], $uploads['baseurl'], $url);
                if ($isBackgroundImage) {
                    if (strpos($imageHtml, 'data-bg') !== false) {
                        $imageHtml = preg_replace('/(data-bg=[\'"])([\s\S]+?)([\'"])/', '$1url(' . $url . ')$3', $imageHtml);
                    } else {
                        if (preg_match('/url\(([\s\S]+?)\)/', $imageHtml, $imageUrl) && isset($imageUrl[1])) {
                            $imageHtml = str_replace($imageUrl[1], $url, $imageHtml);
                        }
                    }
                } else {
                    $imageHtml = preg_replace('/(src=[\'"])([\s\S]+?)([\'"])/', '$1' . $url . '$3', $imageHtml);
                }
                if ($image_alt) {
                    $imageHtml = preg_replace('/(alt=[\'"])([\s\S]*?)([\'"])/', '$1' . $image_alt . '$3', $imageHtml);
                }
                if (isset(NpBlogPostDataReplacer::$_postType) && NpBlogPostDataReplacer::$_postType === 'intro') {
                    preg_match('/class=[\'"]([\s\S]+?)[\'"]/', $imageHtml, $imgClasses);
                    if (strpos($imageHtml, '<img') !== false) {
                        $imgClasses[1] = str_replace('u-preserve-proportions', '', $imgClasses[1]);
                        return '<a class="' . $imgClasses[1] . '" href="' . get_permalink() . '">' . $imageHtml . '</a>';
                    } else {
                        $imageHtml = str_replace('<div', '<div data-href="' . get_permalink() . '"', $imageHtml);
                    }
                }
                return $imageHtml;
            },
            $content
        );
    }

    /**
     * Replace blog post readmore
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replace_blog_post_readmore($content) {
        return preg_replace_callback(
            '/<!--blog_post_readmore-->([\s\S]+?)<!--\/blog_post_readmore-->/',
            function ($content) {
                $buttonHtml = preg_replace('/href=[\'|"][\s\S]+?[\'|"]/', 'href="' . get_permalink(NpBlogPostDataReplacer::$_postId) . '"', $content[1]);
                return preg_replace_callback(
                    '/<!--blog_post_readmore_content-->([\s\S]+?)<!--\/blog_post_readmore_content-->/',
                    function ($buttonHtmlMatches) {
                        $text = 'Read More';
                        if (preg_match('/<\!--options_json--><\!--([\s\S]+?)--><\!--\/options_json-->/', $buttonHtmlMatches[1], $matches)) {
                            $controlOptions = json_decode($matches[1], true);
                            $text = isset($controlOptions['content']) && $controlOptions['content'] ? $controlOptions['content'] : $text;
                        }
                        return translate($text, 'nicepage');
                    },
                    $buttonHtml
                );
            },
            $content
        );
    }

    /**
     * Replace blog post metadata
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replace_blog_post_metadata($content) {
        return preg_replace_callback(
            '/<!--blog_post_metadata-->([\s\S]+?)<!--\/blog_post_metadata-->/',
            function ($content) {
                $content[1] = self::_replace_blog_post_metadata_author($content[1]);
                $content[1] = self::_replace_blog_post_metadata_date($content[1]);
                $content[1] = self::_replace_blog_post_metadata_category($content[1]);
                $content[1] = self::_replace_blog_post_metadata_comments($content[1]);
                $content[1] = self::_replace_blog_post_metadata_edit($content[1]);
                return $content[1];
            },
            $content
        );
    }

    /**
     * Replace blog post metadata author
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replace_blog_post_metadata_author($content) {
        return preg_replace_callback(
            '/<!--blog_post_metadata_author-->([\s\S]+?)<!--\/blog_post_metadata_author-->/',
            function ($content) {
                $authorId = NpBlogPostDataReplacer::$_post->post_author;
                $authorName = get_the_author_meta('display_name', $authorId);
                $authorLink = get_author_posts_url($authorId);
                if ($authorName == '') {
                    $authorName = 'User';
                    $authorLink = '#';
                }
                $link = '<a class="url u-textlink" href="' . $authorLink . '" title="' . esc_attr(sprintf(__('View all posts by %s', 'nicepage'), $authorName)) . '"><span class="fn n">' . $authorName . '</span></a>';
                return $content[1] = preg_replace('/<!--blog_post_metadata_author_content-->([\s\S]+?)<!--\/blog_post_metadata_author_content-->/', $link, $content[1]);
            },
            $content
        );
    }

    /**
     * Replace blog post metadata date
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replace_blog_post_metadata_date($content) {
        return preg_replace_callback(
            '/<!--blog_post_metadata_date-->([\s\S]+?)<!--\/blog_post_metadata_date-->/',
            function ($content) {
                $postDate = get_the_date('', NpBlogPostDataReplacer::$_postId);
                return $content[1] = preg_replace('/<!--blog_post_metadata_date_content-->([\s\S]+?)<!--\/blog_post_metadata_date_content-->/', $postDate, $content[1]);
            },
            $content
        );
    }

    /**
     * Replace blog post metadata category
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replace_blog_post_metadata_category($content) {
        return preg_replace_callback(
            '/<!--blog_post_metadata_category-->([\s\S]+?)<!--\/blog_post_metadata_category-->/',
            function ($content) {
                $postCategories = str_replace(
                    '<a',
                    '<a class="u-textlink"',
                    get_the_category_list(_x(', ', 'Used between list items, there is a space after the comma.', 'nicepage'), '', NpBlogPostDataReplacer::$_postId)
                );
                return $content[1] = preg_replace('/<!--blog_post_metadata_category_content-->([\s\S]+?)<!--\/blog_post_metadata_category_content-->/', $postCategories, $content[1]);
            },
            $content
        );
    }

    /**
     * Replace blog post metadata comments
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replace_blog_post_metadata_comments($content) {
        return preg_replace_callback(
            '/<!--blog_post_metadata_comments-->([\s\S]+?)<!--\/blog_post_metadata_comments-->/',
            function ($content) {
                $link = '<a class="u-textlink" href="' . get_comments_link(NpBlogPostDataReplacer::$_postId) . '">' . sprintf(__('Comments (%d)', 'nicepage'), (int)get_comments_number(NpBlogPostDataReplacer::$_postId)) . '</a>';
                return $content[1] = preg_replace('/<!--blog_post_metadata_comments_content-->([\s\S]+?)<!--\/blog_post_metadata_comments_content-->/', $link, $content[1]);
            },
            $content
        );
    }


    /**
     * Replace blog post metadata edit
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replace_blog_post_metadata_edit($content) {
        return preg_replace_callback(
            '/<!--blog_post_metadata_edit-->([\s\S]+?)<!--\/blog_post_metadata_edit-->/',
            function ($content) {
                $link = '<a href="' . get_edit_post_link(NpBlogPostDataReplacer::$_postId) . '">'. translate('Edit') . '</a>';
                return $content[1] = preg_replace('/<!--blog_post_metadata_edit_content-->([\s\S]+?)<!--\/blog_post_metadata_edit_content-->/', $link, $content[1]);
            },
            $content
        );
    }

    /**
     * Replace blog post tags
     *
     * @param string $content
     *
     * @return string $content
     */
    private static function _replace_blog_post_tags($content) {
        return preg_replace_callback(
            '/<!--blog_post_tags-->([\s\S]+?)<!--\/blog_post_tags-->/',
            function ($content) {
                $tags = get_the_tag_list('', _x(', ', 'Used between list items, there is a space after the comma.', 'nicepage'), '', NpBlogPostDataReplacer::$_postId);
                $tags = $tags ? $tags : '';
                $content[1] = preg_replace('/<!--blog_post_tags_content-->([\s\S]+?)<!--\/blog_post_tags_content-->/', $tags, $content[1]);
                return $content[1];
            },
            $content
        );
    }
}