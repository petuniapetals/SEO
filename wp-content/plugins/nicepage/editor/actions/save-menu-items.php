<?php
defined('ABSPATH') or die;

class NpSaveMenuItemsAction extends NpAction {

    public static $lang;
    public static $currentMenuId = 0;
    public static $menuLangId;
    public static $menuLangParents = array();
    public static $menuLangConnexions = array();
    public static $menuLocations = array();

    /**
     * Process action entrypoint
     *
     * @return array
     *
     * @throws Exception
     */
    public static function process() {

        include_once dirname(__FILE__) . '/chunk.php';

        $saveType = isset($_REQUEST['saveType']) ? $_REQUEST['saveType'] : '';
        $request = array();
        switch($saveType) {
        case 'base64':
            $request = array_merge($_REQUEST, json_decode(base64_decode($_REQUEST['data']), true));
            break;
        case 'chunks':
            $chunk = new NpChunk();
            $ret = $chunk->save(NpSavePageAction::getChunkInfo($_REQUEST));
            if (is_array($ret)) {
                return NpSavePageAction::response(array($ret));
            }
            if ($chunk->last()) {
                $result = $chunk->complete();
                if ($result['status'] === 'done') {
                    $request = array_merge($_REQUEST, json_decode(base64_decode($result['data']), true));
                } else {
                    $result['result'] = 'error';
                    return NpSavePageAction::response(array($result));
                }
            } else {
                return NpSavePageAction::response('processed');
            }
            break;
        default:
            $request = stripslashes_deep($_REQUEST);
        }

        if (!isset($request['menuData'])) {
            return array(
                'status' => 'error',
                'type' => 'CmsSaveServerError',
                'message' => 'No menu data to save',
            );
        }
        self::$lang = 'default';
        NpAdminActions::getMenuItems();
        $menuData = $request['menuData'];
        $menuData['menuItems'] = json_decode($menuData['menuItems'], true);
        $menuOptions = $menuData['menuOptions'];
        $menuData['siteMenuId'] = isset($menuOptions['siteMenuId']) && $menuOptions['siteMenuId'] != '' ? $menuOptions['siteMenuId'] : NpAdminActions::getMenuId();
        $menuData['originalIds'] = isset(NpAdminActions::$_menuIds) ? NpAdminActions::$_menuIds : array();
        $menuData['extraIds'] = isset(NpAdminActions::$_extraMenuIds) ? NpAdminActions::$_extraMenuIds : array();
        $menu = wp_get_nav_menu_object($menuData['siteMenuId']);
        $old_items_ids = $menu ? get_objects_in_term($menu->term_id, 'nav_menu') : false;
        $new_items_ids = self::saveMenu($menuData, $old_items_ids);
        self::$menuLangId = array(
            'siteMenuId' => (int)self::$currentMenuId,
        );
        $result = array(
            'result' => 'done',
            'menuOptions' => array(
                'menuIds' => $new_items_ids,
            )
        );
        $result = self::menuTranslationsProcess($menuData, $menuOptions, $result);
        return $result;
    }

    /**
     * Menu translations process
     *
     * @param array $menuData
     * @param array $menuOptions
     * @param array $result
     *
     * @return array $result
     */
    public static function menuTranslationsProcess($menuData, $menuOptions, $result) {
        if (isset($menuOptions['translationsMenuId'])) {
            self::$menuLangId['translationsMenuId'] = array(); // all menu ids
            $result['menuOptions']['translationsMenuIds'] = array(); // menu items ids
            $menuTranslations = NpAdminActions::getMenuTranslations();
            $all_locations = get_option('np_menu_locations') ? json_decode(get_option('np_menu_locations'), true) : array();
            $registeredLocations = get_nav_menu_locations();
            $need_rewrite = false;
            foreach ($menuTranslations as $index => $translation) {
                foreach ($translation as $lang => $translation_menu_id) {
                    if (!isset($menuOptions['translationsMenuId'][$lang]) && $lang !== 'default') {
                        unset($all_locations[$translation_menu_id]);
                        unset($registeredLocations[$translation_menu_id]);
                        unset($menuTranslations[$index][$lang]);
                        $need_rewrite = true;
                    }
                }
            }
            if ($need_rewrite) {
                update_option('np_menu_locations', json_encode($all_locations));
                NpAdminActions::setMenuTranslations($menuTranslations);
                set_theme_mod('nav_menu_locations', $registeredLocations);
            }
            foreach ($menuOptions['translationsMenuId'] as $lang => $translation_menu_id ) {
                self::$lang = $lang;
                if (isset(NpAdminActions::$langs_items)) {
                    NpAdminActions::$_menuIds = array();
                    NpAdminActions::$_extraMenuIds = array();
                    $translation_menu_items = isset(NpAdminActions::$langs_items[$lang]) ? NpAdminActions::$langs_items[$lang] : array();
                    NpAdminActions::buildMenuItems($translation_menu_items);
                }
                $menuData['siteMenuId'] = $translation_menu_id;
                $menuData['originalIds'] = isset(NpAdminActions::$_menuIds) ? NpAdminActions::$_menuIds : array();
                $menuData['extraIds'] = isset(NpAdminActions::$_extraMenuIds) ? NpAdminActions::$_extraMenuIds : array();
                self::$menuLangId['translationsMenuId'][self::$lang] = (int)$translation_menu_id;
                $menu = wp_get_nav_menu_object($menuData['siteMenuId']);
                $old_items_ids = $menu ? get_objects_in_term($menu->term_id, 'nav_menu') : false;
                $new_items_ids = self::saveMenu($menuData, $old_items_ids);
                $result['menuOptions']['translationsMenuIds'][$lang] = $new_items_ids;
            }
            if (isset(self::$menuLangConnexions)) {
                NpAdminActions::setMenuTranslations(self::$menuLangConnexions);
            }
            if (isset(self::$menuLocations)) {
                update_option('np_menu_locations', json_encode(self::$menuLocations));
            }
            $result['menuTranslationsData'] = array(
                'menuOptions' => self::$menuLangId,
                'menuItems' => NpAdminActions::getMenuItems(),
            );
        }
        return $result;
    }

    /**
     * Save menu in editor
     *
     * @param array $menuData
     * @param array $old_items_ids ids old items for delete
     *
     * @return array $new_items_ids
     */
    public static function saveMenu($menuData, $old_items_ids) {
        if (isset($menuData['siteMenuId']) && $menuData['siteMenuId'] > 0 && is_array($old_items_ids)) {
            if (isset($menuData['menuItems']) && is_array($menuData['menuItems'])) {
                $new_items_ids = self::_updateMenuElements($menuData, $menuData['siteMenuId']);
                if (isset($old_items_ids) && !empty($old_items_ids) && $new_items_ids) {
                    self::_removeMenuItems($old_items_ids);
                }
            }
            self::$currentMenuId = $menuData['siteMenuId'];
        } else {
            $menu_name = _arr($menuData, 'caption', 'Menu');
            $menu_new_id = self::_addMenu($menu_name);
            if (is_int($menu_new_id) && isset($menuData['menuItems']) && is_array($menuData['menuItems'])) {
                $new_items_ids = self::_addMenuElements($menuData, $menu_new_id);
                if ($new_items_ids) {
                    self::_setMenuArea($menu_new_id, $menuData);
                }
                self::$currentMenuId = $menu_new_id;
            }
        }
        self::saveMenuLangConnexions($menuData);
        return $new_items_ids;
    }

    /**
     * Save menu connexions with languages and all menu locations
     *
     * @param array $menuData
     */
    public static function saveMenuLangConnexions($menuData) {
        if (self::$lang === 'default') {
            if (!isset(self::$menuLangConnexions[$menuData['siteMenuId']])) {
                self::$menuLangConnexions[$menuData['siteMenuId']] = array();
            }
            self::$menuLangParents[] = $menuData['siteMenuId'];
        }
        $index = self::$menuLangParents[0];
        $locations = get_nav_menu_locations();
        $registeredLocations = get_registered_nav_menus();
        self::$currentMenuId = self::$currentMenuId ?: $menuData['siteMenuId'];
        $locationId = array_search(self::$currentMenuId, $locations);
        if ($locationId) {
            self::$menuLangConnexions[$index][self::$lang] = $locationId;
            if (isset($registeredLocations[$locationId])) {
                self::$menuLocations[$locationId] = $registeredLocations[$locationId];
            }
        }
    }

    /**
     * @param string $menu_name
     *
     * @return string $menu_new_id
     */
    private static function _addMenu($menu_name) {
        // generate unique name
        for ($i = 0; ; $i++) {
            $new_name = $menu_name . ($i ? ' #' . $i : '');
            $_possible_existing = get_term_by('name', $new_name, 'nav_menu');
            if (!$_possible_existing || is_wp_error($_possible_existing) || !isset($_possible_existing->term_id)) {
                $menu_name = $new_name;
                break;
            }
        }
        return $menu_new_id = wp_update_nav_menu_object(0, array('menu-name' => $menu_name));
    }

    /**
     * Translate menu item to the current language
     *
     * @param $menu_item
     *
     * @return mixed
     */
    public static function menuItemTranslate($menu_item) {
        if (isset(self::$lang) && self::$lang !== 'default') {
            if (isset($menu_item['langs']['data-lang-' . self::$lang])) {
                $item_translation = json_decode($menu_item['langs']['data-lang-' . self::$lang]);
                $menu_item['name'] = isset($item_translation->content) ? $item_translation->content : $menu_item['name'];
                $menu_item['href'] = isset($item_translation->href) ? $item_translation->href : $menu_item['href'];
            }
        }
        return $menu_item;
    }

    /**
     * @param array $menuData
     * @param int   $menu_id
     *
     * @return array|bool $new_items_ids
     */
    private static function _addMenuElements($menuData, $menu_id) {
        $order = 0;
        $id_map = array();
        $new_items_ids = array();
        foreach ($menuData['menuItems'] as $menu_item_id => $menu_item) {
            $menu_item = self::menuItemTranslate($menu_item);
            $id_map[$menu_item_id] = wp_update_nav_menu_item($menu_id, 0, array());
            $menu_item['id'] = $id_map[$menu_item_id];
            $menuData['menuItems'][$menu_item_id] = $menu_item;
        }
        // add parameter parent for items
        $menuData['menuItems'] = self::setParentId($menuData['menuItems']);

        foreach ($menuData['menuItems'] as $menu_item_id => $menu_item) {
            $menu_item_data = array();
            $menu_item_caption = $menu_item['name'];
            if ($menu_item_caption) {
                $menu_item_data['menu-item-title'] = $menu_item_caption;
            }
            $menu_item_parent = $menu_item['parent'];
            if ($menu_item_parent >= 0) {
                $menu_item_data['menu-item-parent-id'] = $menu_item_parent;
            }
            if (isset($menu_item['href'])) {
                $menu_item_href = $menu_item['href'];
            }
            $menu_item_data['menu-item-position'] = ++$order;
            if (isset($menu_item_href) && $menu_item_href) {
                $menu_item_object_id = url_to_postid($menu_item_href);
                if ($menu_item_object_id && $menu_item_object_id > 0) {
                    $postItem = get_post($menu_item_object_id);
                    if ($postItem) {
                        $menu_item_data['menu-item-type'] = 'post_type';
                        $menu_item_data['menu-item-object'] = $postItem->post_type;
                        $menu_item_data['menu-item-object-id'] = $menu_item_object_id;
                    }
                } else {
                    $menu_item_data['menu-item-type'] = 'custom';
                    $menu_item_data['menu-item-url'] = $menu_item_href;
                }
            }
            $menu_item_data['menu-item-target'] = isset($menu_item['blank']) && $menu_item['blank'] ? '_blank' : '';
            $resultSave = wp_update_nav_menu_item($menu_id, $menu_item['id'], $menu_item_data);
            if (is_wp_error($resultSave)) {
                return false;
            }
            $new_items_ids[] = $menu_item['id'];
        }
        return $new_items_ids;
    }

    /**
     * Set parent for menu item
     *
     * @param array $items
     * @param array $parentIds
     *
     * @return array $items
     */
    public static function setParentId($items, $parentIds = array()) {
        $level = 0;
        foreach ($items as $index => $itemData) {
            $itemLevel = $itemData['level'];
            if ($itemLevel == 0) {
                $parentId = 0;
                $parentIds = array('0' => 0);
            } else if ($itemLevel > $level) {
                $parentId = $items[$index - 1]['id'];
                $parentIds[$itemLevel] = $parentId;
            } else {
                $parentId = $parentIds[$itemLevel];
            }
            $level = $itemLevel;
            $items[$index]['parent'] = $parentId;
        }
        return $items;
    }

    /**
     * @param array $menuData
     * @param int   $menu_id
     *
     * @return array|bool $new_items_ids
     */
    private static function _updateMenuElements($menuData, $menu_id) {
        $id_map = array();
        $new_items_ids = array();
        foreach ($menuData['menuItems'] as $menu_item_id => $menu_item) {
            $id_map[$menu_item_id] = wp_update_nav_menu_item($menu_id, 0, array());
            $menu_item['id'] = $id_map[$menu_item_id];
            $menuData['menuItems'][$menu_item_id] = $menu_item;
        }
        $oldMenuItems = wp_get_nav_menu_items($menu_id);
        $oldMenuLinks = array();
        $order = 0;

        $extraMenuItems = array();
        foreach ($oldMenuItems as $oldMenuItem) {
            if (array_search($oldMenuItem->ID, $menuData['extraIds']) !== false && array_search($oldMenuItem->url, $oldMenuLinks) !== false) {
                // do not save the item from the editor if it is already saved
                continue;
            }
            array_push($oldMenuLinks, $oldMenuItem->url);
            if (array_search($oldMenuItem->ID, $menuData['originalIds']) === false) {
                $oldId = $oldMenuItem->ID;
                $newId = wp_update_nav_menu_item($menu_id, 0, array());
                $id_map[$oldId] = $newId;
                if ($oldMenuItem->menu_item_parent > 0) {
                    $oldMenuItem->menu_item_parent = $id_map[$oldMenuItem->menu_item_parent];
                }
                $oldMenuItem->ID = $newId;
                array_push($extraMenuItems, $oldMenuItem);
                wp_delete_post($oldId);
            }
        }
        // add parameter parent for items
        $menuData['menuItems'] = self::setParentId($menuData['menuItems']);

        foreach ($extraMenuItems as $extraMenuItem) {
            $menuItem = array();
            $menuItem['name'] = isset($extraMenuItem->title) ? $extraMenuItem->title : $extraMenuItem->post_title;
            $menuItem['href'] = $extraMenuItem->url ? $extraMenuItem->url : '';
            $old_item_classes = $extraMenuItem->classes;
            if (is_array($old_item_classes)) {
                $old_item_classes = implode(' ', $old_item_classes);
            }
            $menuItem['additionalClass'] = $old_item_classes;
            $menuItem['parent'] = $extraMenuItem->menu_item_parent;
            $menuItem['id'] = $extraMenuItem->ID;
            $menuData['menuItems'][] = $menuItem;
        }

        foreach ($menuData['menuItems'] as $menu_item_id => $menu_item) {
            $menu_item = self::menuItemTranslate($menu_item);
            $new_info = false;
            $foundKey = array_search($menu_item['href'], $oldMenuLinks);
            $link_target = isset($menu_item['blank']) && $menu_item['blank'] ? '_blank' : '';
            if ($foundKey !== false) {
                $new_info = $oldMenuItems[$foundKey];
                $menu_item_data = array();
                $old_item_url = get_post_meta($new_info->ID, '_menu_item_url', true);
                $old_item_classes = get_post_meta($new_info->ID, '_menu_item_classes', true);
                if (is_array($old_item_classes)) {
                    $old_item_classes = implode(' ', $old_item_classes);
                }
                $old_item_post_meta = get_post_meta($new_info->ID);
                $new_info->ID = $menu_item['id'];

                $menu_item_data['menu-item-db-id'] = $new_info->db_id;
                $menu_item_data['menu-item-title'] = $menu_item['name'];
                $menu_item_data['menu-item-attr-title'] = $menu_item['name'];
                $menu_item_data['menu-item-parent-id'] = $menu_item['parent'];
                $menu_item_href = $menu_item['href'];
                $menu_item_object_id = url_to_postid($menu_item_href);
                if ($menu_item_object_id && $menu_item_object_id > 0) {
                    $postObject = get_post($menu_item_object_id);
                    if ($postObject) {
                        $menu_item_data['menu-item-type'] = 'post_type';
                        $menu_item_data['menu-item-object'] = $postObject->post_type;
                        $menu_item_data['menu-item-object-id'] = $menu_item_object_id;
                    }
                } else {
                    $menu_item_data['menu-item-type'] = 'custom';
                    $menu_item_data['menu-item-url'] = $menu_item_href;
                }
                $menu_item_data['menu-item-position'] = ++$order;
                $menu_item_data['menu-item-status'] = 'publish';
                $menu_item_data['menu-item-target'] = $link_target;
                $resultSave = wp_update_nav_menu_item($menu_id, $new_info->ID, $menu_item_data);
                if (is_wp_error($resultSave)) {
                    return false;
                }

                if (isset($new_info->db_id)) {
                    $new_info->db_id = $new_info->ID;
                }
                if (isset($new_info->post_name)) {
                    $new_info->post_name = (string)$new_info->ID;
                }
                if (isset($new_info->post_title)) {
                    $new_info->post_title = $menu_item['name'];
                }
                if (isset($new_info->guid)) {
                    $new_info->guid = get_home_url().'/?p='.(string)$new_info->ID;
                }
                if (isset($new_info->title)) {
                    $new_info->title = $menu_item['name'];
                }
                if (isset($new_info->menu_item_parent) && (int)$new_info->menu_item_parent !== $menu_item['parent']) {
                    $new_info->menu_item_parent = (string)$menu_item['parent'];
                }
                if (isset($new_info->url) && $new_info->url !== $menu_item['href']) {
                    $new_info->url = $menu_item['href'];
                }
                if (isset($new_info->target)) {
                    $new_info->target = $link_target;
                }
                $new_info->menu_order = $menu_item_data['menu-item-position'];
                $post_id = wp_update_post($new_info->to_array());
                if (is_wp_error($post_id)) {
                    return false;
                }

                foreach ($old_item_post_meta as $key => $value) {
                    if ($key === "_menu_item_classes") {
                        update_post_meta($post_id, $key, $old_item_classes);
                    } else if ($key === "_menu_item_target") {
                        update_post_meta($post_id, $key, $link_target);
                    } else {
                        update_post_meta($post_id, $key, $value[0]);
                    }
                }
                if ($old_item_url !== $menu_item['href']) {
                    update_post_meta($post_id, '_menu_item_url', $menu_item['href']);
                }
                update_post_meta($post_id, '_menu_item_menu_item_parent', $menu_item['parent']);
            } else {
                if (!$new_info) {
                    $menu_item_data['menu-item-title'] = $menu_item['name'];
                    $menu_item_data['menu-item-parent-id'] = $menu_item['parent'];;
                    $menu_item_href = $menu_item['href'];
                    $menu_item_object_id = url_to_postid($menu_item_href);
                    if ($menu_item_object_id && $menu_item_object_id > 0) {
                        $isAnchor = stripos($menu_item_href, '#') !== false;
                        if ($isAnchor) {
                            $menu_item_data['menu-item-type'] = 'custom';
                            $menu_item_data['menu-item-url'] = $menu_item_href;
                        } else {
                            $postObject = get_post($menu_item_object_id);
                            if ($postObject) {
                                $menu_item_data['menu-item-type'] = 'post_type';
                                $menu_item_data['menu-item-object'] = $postObject->post_type;
                                $menu_item_data['menu-item-object-id'] = $menu_item_object_id;
                            }
                        }
                    } else {
                        $menu_item_data['menu-item-type'] = 'custom';
                        $menu_item_data['menu-item-url'] = $menu_item_href;
                    }
                    $menu_item_data['menu-item-target'] = $link_target;
                    $menu_item_data['menu-item-position'] = ++$order;
                    $resultSave = wp_update_nav_menu_item($menu_id, $menu_item['id'], $menu_item_data);
                    if (is_wp_error($resultSave)) {
                        return false;
                    }
                }
            }
            $new_items_ids[] = $menu_item['id'];
        }
        return $new_items_ids;
    }

    /**
     * @param array $menu_id
     * @param array $menuData
     */
    private static function _setMenuArea($menu_id, $menuData) {
        $positions = self::registerNewPosition($menu_id, $menuData);
        if (is_string($positions) && $positions) {
            $positions = explode(',', $positions);
            $nav_menu_locations = get_nav_menu_locations();
            foreach ($positions as $position) {
                $position = trim($position);
                if ($position) {
                    $nav_menu_locations[$position] = $menu_id;
                }
            }
            set_theme_mod('nav_menu_locations', $nav_menu_locations);
        }
    }

    /**
     * @param array $items
     */
    private static function _removeMenuItems($items) {
        foreach ($items as $item) {
            wp_delete_post($item);
        }
    }

    /**
     * Get menu id from wp
     *
     * @return int|string
     */
    public static function getMenuPosition() {
        $menu_position = false;
        $locations = get_registered_nav_menus();
        $locationsKeys = array_keys($locations);
        for ($i = 0; $i < count($locationsKeys); $i++) {
            $menu_position = array_shift($locationsKeys);
            if ($menu_position) {
                break;
            }
        }
        return $menu_position;
    }

    /**
     * Register new menu location in cms
     *
     * @param int   $menu_id
     * @param array $menuData
     *
     * @return string $locationId
     */
    public static function registerNewPosition($menu_id, $menuData) {
        $locationId = 'primary-navigation-' . '1' . (self::$lang === 'default' ? '' : '-' . self::$lang);
        $locationName = 'Primary Navigation' . (self::$lang === 'default' ? '' : ' ' . strtoupper(self::$lang));
        $location = array(
            $locationId => $locationName,
        );
        register_nav_menus(
            $location
        );
        $np_locations = get_option('np_menu_locations') ? json_decode(get_option('np_menu_locations'), true) : array();
        $np_locations = array_merge($location, $np_locations);
        update_option('np_menu_locations', json_encode($np_locations));
        $menu_translations = NpAdminActions::getMenuTranslations();
        if (isset($menuData['menuOptions']['siteMenuId'])) {
            $parentId = $menuData['menuOptions']['siteMenuId'];
            if (!isset($menu_translations[$parentId])) {
                $menu_translations[$parentId] = array();
            }
            if (self::$lang !== 'default') {
                $menu_translations[$parentId][self::$lang] = $locationId;
                NpAdminActions::setMenuTranslations($menu_translations);
                self::$menuLangId['translationsMenuId'][self::$lang] = (int)$menu_id;
            }
        }
        return $locationId;
    }
}
NpAction::add('np_save_menu_items', 'NpSaveMenuItemsAction');