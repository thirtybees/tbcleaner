<?php
/**
 * 2007-2016 PrestaShop
 *
 * Thirty Bees is an extension to the PrestaShop e-commerce software developed by PrestaShop SA
 * Copyright (C) 2017-2024 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    Thirty Bees <modules@thirtybees.com>
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2017-2024 thirty bees
 * @copyright 2007-2016 PrestaShop SA
 * @license   https://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  PrestaShop is an internationally registered trademark & property of PrestaShop SA
 */

if (!defined('_CAN_LOAD_FILES_') || !defined('_TB_VERSION_')) {
    exit;
}

/**
 * Class TbCleaner
 */
class TbCleaner extends Module
{
    /**
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function __construct()
    {
        $this->name = 'tbcleaner';
        $this->tab = 'administration';
        $this->version = '2.1.0';
        $this->author = 'thirty bees';
        $this->need_instance = false;

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('thirty bees cleaner');
        $this->description = $this->l('Check and fix functional integrity constraints and remove default data');
    }

    /**
     * @return string
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function getContent()
    {
        $html = '<h2>'.$this->l('Be really careful with this tool - There is no possible rollback!').'</h2>';
        if (Tools::isSubmit('submitCheckAndFix')) {
            $logs = self::checkAndFix();
            if (count($logs)) {
                $conf = $this->l('The following queries recovered broken data:').'<br /><ul>';
                foreach ($logs as $query => $entries) {
                    $conf .= '<li>'.Tools::htmlentitiesUTF8($query).'<br />'.sprintf($this->l('%d line(s)'), $entries).'</li>';
                }
                $conf .= '</ul>';
            } else {
                $conf = $this->l('Nothing that needs to be fixed');
            }
            $html .= $this->displayConfirmation($conf);
        } elseif (Tools::isSubmit('submitCleanAndOptimize')) {
            $logs = self::cleanAndOptimize();
            if (count($logs)) {
                $conf = $this->l('The following queries successfully cleaned your database:').'<br /><ul>';
                foreach ($logs as $query => $entries) {
                    $conf .= '<li>'.Tools::htmlentitiesUTF8($query).'<br />'.sprintf($this->l('%d line(s)'), $entries).'</li>';
                }
                $conf .= '</ul>';
            } else {
                $conf = $this->l('Nothing that needs to be cleaned');
            }
            $html .= $this->displayConfirmation($conf);
        } elseif (Tools::getValue('submitTruncateCatalog') && Tools::getValue('checkTruncateCatalog')) {
            self::truncate('catalog');
            $html .= $this->displayConfirmation($this->l('Catalog truncated'));
        } elseif (Tools::getValue('submitTruncateSales') && Tools::getValue('checkTruncateSales')) {
            self::truncate('sales');
            $html .= $this->displayConfirmation($this->l('Orders and customers truncated'));
        }

        $html .= '
		<script type="text/javascript">
			$(document).ready(function(){
				$("#submitTruncateCatalog").click(function(){
					if ($(\'#checkTruncateCatalog_on\').attr(\'checked\') != "checked")
					{
						alert(\''.addslashes(html_entity_decode($this->l('Please read the disclaimer and click "Yes" above'))).'\');
						return false;
					}
					if (confirm(\''.addslashes(html_entity_decode($this->l('Are you sure that you want to delete all catalog data?'))).'\'))
						return true;
					return false;
				});
				$("#submitTruncateSales").click(function(){
					if ($(\'#checkTruncateSales_on\').attr(\'checked\') != "checked")
					{
						alert(\''.addslashes(html_entity_decode($this->l('Please read the disclaimer and click "Yes" above'))).'\');
						return false;
					}
					if (confirm(\''.addslashes(html_entity_decode($this->l('Are you sure that you want to delete all sales data?'))).'\'))
						return true;
					return false;
				});
			});
		</script>';

        return $html.$this->renderForm();
    }

    /**
     * @return array
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public static function checkAndFix()
    {
        $db = Db::getInstance();
        $logs = [];

        // Remove doubles in the configuration
        $filteredConfiguration = [];
        $result = $db->ExecuteS('SELECT * FROM '._DB_PREFIX_.'configuration');
        foreach ($result as $row) {
            $key = $row['id_shop_group'].'-|-'.$row['id_shop'].'-|-'.$row['name'];
            if (in_array($key, $filteredConfiguration)) {
                $query = 'DELETE FROM '._DB_PREFIX_.'configuration WHERE id_configuration = '.(int) $row['id_configuration'];
                static::executeStatement($query, $logs);
            } else {
                $filteredConfiguration[] = $key;
            }
        }
        unset($filteredConfiguration);

        // Remove inexisting or monolanguage configuration value from configuration_lang
        $query = 'DELETE FROM `'._DB_PREFIX_.'configuration_lang`
        WHERE `id_configuration` NOT IN (SELECT `id_configuration` FROM `'._DB_PREFIX_.'configuration`)
        OR `id_configuration` IN (SELECT `id_configuration` FROM `'._DB_PREFIX_.'configuration` WHERE name IS NULL OR name = "")';

        static::executeStatement($query, $logs);

        // Simple Cascade Delete
        $queries = self::getCheckAndFixQueries();

        $queries = self::bulle($queries);
        foreach ($queries as $queryArray) {
            // If this is a module and the module is not installed, we continue
            if (isset($queryArray[4]) && !Module::isInstalled($queryArray[4])) {
                continue;
            }

            $query = 'DELETE FROM `'._DB_PREFIX_.$queryArray[0].'` WHERE `'.$queryArray[1].'` NOT IN (SELECT `'.$queryArray[3].'` FROM `'._DB_PREFIX_.$queryArray[2].'`)';
            static::executeStatement($query, $logs);
        }

        // _lang table cleaning
        $tables = Db::getInstance()->executeS('SHOW TABLES LIKE "'.preg_replace('/([%_])/', '\\$1', _DB_PREFIX_).'%_\\_lang"');
        foreach ($tables as $table) {
            $tableLang = current($table);
            $table = str_replace('_lang', '', $tableLang);
            $idTable = 'id_'.preg_replace('/^'._DB_PREFIX_.'/', '', $table);

            $query = 'DELETE FROM `'.bqSQL($tableLang).'` WHERE `'.bqSQL($idTable).'` NOT IN (SELECT `'.bqSQL($idTable).'` FROM `'.bqSQL($table).'`)';
            static::executeStatement($query, $logs);

            $query = 'DELETE FROM `'.bqSQL($tableLang).'` WHERE `id_lang` NOT IN (SELECT `id_lang` FROM `'._DB_PREFIX_.'lang`)';
            static::executeStatement($query, $logs);
        }

        // _shop table cleaning
        $tables = Db::getInstance()->executeS('SHOW TABLES LIKE "'.preg_replace('/([%_])/', '\\$1', _DB_PREFIX_).'%_\\_shop"');
        foreach ($tables as $table) {
            $tableShop = current($table);
            $table = str_replace('_shop', '', $tableShop);
            $idTable = 'id_'.preg_replace('/^'._DB_PREFIX_.'/', '', $table);

            if (in_array($tableShop, [_DB_PREFIX_.'carrier_tax_rules_group_shop'])) {
                continue;
            }

            $query = 'DELETE FROM `'.bqSQL($tableShop).'` WHERE `'.bqSQL($idTable).'` NOT IN (SELECT `'.bqSQL($idTable).'` FROM `'.bqSQL($table).'`)';
            static::executeStatement($query, $logs);

            $query = 'DELETE FROM `'.bqSQL($tableShop).'` WHERE `id_shop` NOT IN (SELECT `id_shop` FROM `'._DB_PREFIX_.'shop`)';
            static::executeStatement($query, $logs);
        }

        // stock_available
        $query = 'DELETE FROM `'._DB_PREFIX_.'stock_available` WHERE `id_shop` NOT IN (SELECT `id_shop` FROM `'._DB_PREFIX_.'shop`) AND `id_shop_group` NOT IN (SELECT `id_shop_group` FROM `'._DB_PREFIX_.'shop_group`)';
        static::executeStatement($query, $logs);

        Category::regenerateEntireNtree();

        // @Todo: Remove attachment files, images...
        Image::clearTmpDir();
        self::clearAllCaches();

        return $logs;
    }

    /**
     * Helper method to execute sql statement
     *
     * @param string $query
     * @param string[]|null $logs
     */
    public static function executeStatement($query, &$logs = null)
    {
        try {
            $db = Db::getInstance();
            if ($db->execute($query)) {
                if ($affectedRows = $db->Affected_Rows()) {
                    if (is_array($logs)) {
                        $logs[$query] = $affectedRows;
                    }
                }
            }
        } catch (Exception $e) {
            /** @var AdminController $controller */
            $controller = Context::getContext()->controller;
            $controller->errors[] = $e->getMessage();
        }
    }

    /**
     * @return array[]
     */
    public static function getCheckAndFixQueries()
    {
        return [
            // 0 => DELETE FROM __table__, 1 => WHERE __id__ NOT IN, 2 => NOT IN __table__, 3 => __id__ used in the "NOT IN" table, 4 => module_name
            ['access', 'id_profile', 'profile', 'id_profile'],
            ['accessory', 'id_product_1', 'product', 'id_product'],
            ['accessory', 'id_product_2', 'product', 'id_product'],
            ['address_format', 'id_country', 'country', 'id_country'],
            ['attribute', 'id_attribute_group', 'attribute_group', 'id_attribute_group'],
            ['carrier_group', 'id_carrier', 'carrier', 'id_carrier'],
            ['carrier_group', 'id_group', 'group', 'id_group'],
            ['carrier_zone', 'id_carrier', 'carrier', 'id_carrier'],
            ['carrier_zone', 'id_zone', 'zone', 'id_zone'],
            ['cart_cart_rule', 'id_cart', 'cart', 'id_cart'],
            ['cart_product', 'id_cart', 'cart', 'id_cart'],
            ['cart_rule_carrier', 'id_cart_rule', 'cart_rule', 'id_cart_rule'],
            ['cart_rule_carrier', 'id_carrier', 'carrier', 'id_carrier'],
            ['cart_rule_combination', 'id_cart_rule_1', 'cart_rule', 'id_cart_rule'],
            ['cart_rule_combination', 'id_cart_rule_2', 'cart_rule', 'id_cart_rule'],
            ['cart_rule_country', 'id_cart_rule', 'cart_rule', 'id_cart_rule'],
            ['cart_rule_country', 'id_country', 'country', 'id_country'],
            ['cart_rule_group', 'id_cart_rule', 'cart_rule', 'id_cart_rule'],
            ['cart_rule_group', 'id_group', 'group', 'id_group'],
            ['cart_rule_product_rule_group', 'id_cart_rule', 'cart_rule', 'id_cart_rule'],
            ['cart_rule_product_rule', 'id_product_rule_group', 'cart_rule_product_rule_group', 'id_product_rule_group'],
            ['cart_rule_product_rule_value', 'id_product_rule', 'cart_rule_product_rule', 'id_product_rule'],
            ['category_group', 'id_category', 'category', 'id_category'],
            ['category_group', 'id_group', 'group', 'id_group'],
            ['category_product', 'id_category', 'category', 'id_category'],
            ['category_product', 'id_product', 'product', 'id_product'],
            ['cms', 'id_cms_category', 'cms_category', 'id_cms_category'],
            ['cms_block', 'id_cms_category', 'cms_category', 'id_cms_category', 'blockcms'],
            ['cms_block_page', 'id_cms', 'cms', 'id_cms', 'blockcms'],
            ['cms_block_page', 'id_cms_block', 'cms_block', 'id_cms_block', 'blockcms'],
            ['connections', 'id_shop_group', 'shop_group', 'id_shop_group'],
            ['connections', 'id_shop', 'shop', 'id_shop'],
            ['connections_page', 'id_connections', 'connections', 'id_connections'],
            ['connections_page', 'id_page', 'page', 'id_page'],
            ['connections_source', 'id_connections', 'connections', 'id_connections'],
            ['customer', 'id_shop_group', 'shop_group', 'id_shop_group'],
            ['customer', 'id_shop', 'shop', 'id_shop'],
            ['customer_group', 'id_group', 'group', 'id_group'],
            ['customer_group', 'id_customer', 'customer', 'id_customer'],
            ['customer_message', 'id_customer_thread', 'customer_thread', 'id_customer_thread'],
            ['customer_thread', 'id_shop', 'shop', 'id_shop'],
            ['customization', 'id_cart', 'cart', 'id_cart'],
            ['customization_field', 'id_product', 'product', 'id_product'],
            ['customized_data', 'id_customization', 'customization', 'id_customization'],
            ['delivery', 'id_shop', 'shop', 'id_shop'],
            ['delivery', 'id_shop_group', 'shop_group', 'id_shop_group'],
            ['delivery', 'id_carrier', 'carrier', 'id_carrier'],
            ['delivery', 'id_zone', 'zone', 'id_zone'],
            ['editorial', 'id_shop', 'shop', 'id_shop', 'editorial'],
            ['favorite_product', 'id_product', 'product', 'id_product', 'favoriteproducts'],
            ['favorite_product', 'id_customer', 'customer', 'id_customer', 'favoriteproducts'],
            ['favorite_product', 'id_shop', 'shop', 'id_shop', 'favoriteproducts'],
            ['feature_product', 'id_feature', 'feature', 'id_feature'],
            ['feature_product', 'id_product', 'product', 'id_product'],
            ['feature_value', 'id_feature', 'feature', 'id_feature'],
            ['group_reduction', 'id_group', 'group', 'id_group'],
            ['group_reduction', 'id_category', 'category', 'id_category'],
            ['homeslider', 'id_shop', 'shop', 'id_shop', 'homeslider'],
            ['homeslider', 'id_homeslider_slides', 'homeslider_slides', 'id_homeslider_slides', 'homeslider'],
            ['hook_module', 'id_hook', 'hook', 'id_hook'],
            ['hook_module', 'id_module', 'module', 'id_module'],
            ['hook_module_exceptions', 'id_hook', 'hook', 'id_hook'],
            ['hook_module_exceptions', 'id_module', 'module', 'id_module'],
            ['hook_module_exceptions', 'id_shop', 'shop', 'id_shop'],
            ['image', 'id_product', 'product', 'id_product'],
            ['message', 'id_cart', 'cart', 'id_cart'],
            ['message_readed', 'id_message', 'message', 'id_message'],
            ['message_readed', 'id_employee', 'employee', 'id_employee'],
            ['module_access', 'id_profile', 'profile', 'id_profile'],
            ['module_preference', 'id_employee', 'employee', 'id_employee'],
            ['orders', 'id_shop', 'shop', 'id_shop'],
            ['orders', 'id_shop_group', 'group_shop', 'id_shop_group'],
            ['order_carrier', 'id_order', 'orders', 'id_order'],
            ['order_cart_rule', 'id_order', 'orders', 'id_order'],
            ['order_detail', 'id_order', 'orders', 'id_order'],
            ['order_detail_tax', 'id_order_detail', 'order_detail', 'id_order_detail'],
            ['order_history', 'id_order', 'orders', 'id_order'],
            ['order_invoice', 'id_order', 'orders', 'id_order'],
            ['order_invoice_payment', 'id_order', 'orders', 'id_order'],
            ['order_invoice_tax', 'id_order_invoice', 'order_invoice', 'id_order_invoice'],
            ['order_return', 'id_order', 'orders', 'id_order'],
            ['order_return_detail', 'id_order_return', 'order_return', 'id_order_return'],
            ['order_slip', 'id_order', 'orders', 'id_order'],
            ['order_slip_detail', 'id_order_slip', 'order_slip', 'id_order_slip'],
            ['pack', 'id_product_pack', 'product', 'id_product'],
            ['pack', 'id_product_item', 'product', 'id_product'],
            ['page', 'id_page_type', 'page_type', 'id_page_type'],
            ['page_viewed', 'id_shop', 'shop', 'id_shop'],
            ['page_viewed', 'id_shop_group', 'shop_group', 'id_shop_group'],
            ['page_viewed', 'id_date_range', 'date_range', 'id_date_range'],
            ['product_attachment', 'id_attachment', 'attachment', 'id_attachment'],
            ['product_attachment', 'id_product', 'product', 'id_product'],
            ['product_attribute', 'id_product', 'product', 'id_product'],
            ['product_attribute_combination', 'id_product_attribute', 'product_attribute', 'id_product_attribute'],
            ['product_attribute_combination', 'id_attribute', 'attribute', 'id_attribute'],
            ['product_attribute_image', 'id_image', 'image', 'id_image'],
            ['product_attribute_image', 'id_product_attribute', 'product_attribute', 'id_product_attribute'],
            ['product_carrier', 'id_product', 'product', 'id_product'],
            ['product_carrier', 'id_shop', 'shop', 'id_shop'],
            ['product_carrier', 'id_carrier_reference', 'carrier', 'id_reference'],
            ['product_country_tax', 'id_product', 'product', 'id_product'],
            ['product_country_tax', 'id_country', 'country', 'id_country'],
            ['product_country_tax', 'id_tax', 'tax', 'id_tax'],
            ['product_download', 'id_product', 'product', 'id_product'],
            ['product_group_reduction_cache', 'id_product', 'product', 'id_product'],
            ['product_group_reduction_cache', 'id_group', 'group', 'id_group'],
            ['product_sale', 'id_product', 'product', 'id_product'],
            ['product_supplier', 'id_product', 'product', 'id_product'],
            ['product_supplier', 'id_supplier', 'supplier', 'id_supplier'],
            ['product_tag', 'id_product', 'product', 'id_product'],
            ['product_tag', 'id_tag', 'tag', 'id_tag'],
            ['range_price', 'id_carrier', 'carrier', 'id_carrier'],
            ['range_weight', 'id_carrier', 'carrier', 'id_carrier'],
            ['referrer_cache', 'id_referrer', 'referrer', 'id_referrer'],
            ['referrer_cache', 'id_connections_source', 'connections_source', 'id_connections_source'],
            ['search_index', 'id_product', 'product', 'id_product'],
            ['search_word', 'id_lang', 'lang', 'id_lang'],
            ['search_word', 'id_shop', 'shop', 'id_shop'],
            ['shop_url', 'id_shop', 'shop', 'id_shop'],
            ['specific_price_priority', 'id_product', 'product', 'id_product'],
            ['stock', 'id_warehouse', 'warehouse', 'id_warehouse'],
            ['stock', 'id_product', 'product', 'id_product'],
            ['stock_available', 'id_product', 'product', 'id_product'],
            ['stock_mvt', 'id_stock', 'stock', 'id_stock'],
            ['tax_rule', 'id_country', 'country', 'id_country'],
            ['warehouse_carrier', 'id_warehouse', 'warehouse', 'id_warehouse'],
            ['warehouse_carrier', 'id_carrier', 'carrier', 'id_carrier'],
            ['warehouse_product_location', 'id_product', 'product', 'id_product'],
            ['warehouse_product_location', 'id_warehouse', 'warehouse', 'id_warehouse'],
        ];
    }

    /**
     * @param array $array
     *
     * @return array
     */
    protected static function bulle($array)
    {
        $sorted = false;
        $size = count($array);
        while (!$sorted) {
            $sorted = true;
            for ($i = 0; $i < $size - 1; ++$i) {
                for ($j = $i + 1; $j < $size; ++$j) {
                    if ($array[$i][2] == $array[$j][0]) {
                        $tmp = $array[$i];
                        $array[$i] = $array[$j];
                        $array[$j] = $tmp;
                        $sorted = false;
                    }
                }
            }
        }

        return $array;
    }

    /**
     * @return void
     */
    protected static function clearAllCaches()
    {
        $index = file_exists(_PS_TMP_IMG_DIR_.'index.php') ? file_get_contents(_PS_TMP_IMG_DIR_.'index.php') : '';
        Tools::deleteDirectory(_PS_TMP_IMG_DIR_, false);
        file_put_contents(_PS_TMP_IMG_DIR_.'index.php', $index);
        Context::getContext()->smarty->clearAllCache();
    }

    /**
     * @return array
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public static function cleanAndOptimize()
    {
        $logs = [];

        $query = '
		DELETE FROM `'._DB_PREFIX_.'cart`
		WHERE id_cart NOT IN (SELECT id_cart FROM `'._DB_PREFIX_.'orders`)
		AND date_add < "'.pSQL(date('Y-m-d', strtotime('-1 month'))).'"';

        static::executeStatement($query, $logs);

        $query = '
		DELETE FROM `'._DB_PREFIX_.'cart_rule`
		WHERE (
			active = 0
			OR quantity = 0
			OR date_to < "'.pSQL(date('Y-m-d')).'"
		)
		AND date_add < "'.pSQL(date('Y-m-d', strtotime('-1 month'))).'"';

        static::executeStatement($query, $logs);

        $parents = Db::getInstance()->ExecuteS('SELECT DISTINCT id_parent FROM '._DB_PREFIX_.'tab');
        foreach ($parents as $parent) {
            $children = Db::getInstance()->ExecuteS('SELECT id_tab FROM '._DB_PREFIX_.'tab WHERE id_parent = '.(int) $parent['id_parent'].' ORDER BY IF(class_name IN ("AdminHome", "AdminDashboard"), 1, 2), position ASC');
            $i = 1;
            foreach ($children as $child) {
                $query = 'UPDATE '._DB_PREFIX_.'tab SET position = '.(int) ($i++).' WHERE id_tab = '.(int) $child['id_tab'].' AND id_parent = '.(int) $parent['id_parent'];
                static::executeStatement($query, $logs);
            }
        }

        return $logs;
    }

    /**
     * @param string $case
     *
     * @return void
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function truncate($case)
    {
        static::executeStatement('SET FOREIGN_KEY_CHECKS = 0;');

        switch ($case) {
            case 'catalog':
                $idHome = Configuration::getMultiShopValues('PS_HOME_CATEGORY');
                $idRoot = Configuration::getMultiShopValues('PS_ROOT_CATEGORY');
                static::executeStatement('DELETE FROM `'._DB_PREFIX_.'category` WHERE id_category NOT IN ('.implode(',', array_map('intval', $idHome)).', '.implode(',', array_map('intval', $idRoot)).')');
                static::executeStatement('DELETE FROM `'._DB_PREFIX_.'category_lang` WHERE id_category NOT IN ('.implode(',', array_map('intval', $idHome)).', '.implode(',', array_map('intval', $idRoot)).')');
                static::executeStatement('DELETE FROM `'._DB_PREFIX_.'category_shop` WHERE id_category NOT IN ('.implode(',', array_map('intval', $idHome)).', '.implode(',', array_map('intval', $idRoot)).')');

                foreach (scandir(_PS_CAT_IMG_DIR_) as $dir) {
                    if (preg_match('/^[0-9]+(-(.*))?\.jpg$/', $dir)) {
                        unlink(_PS_CAT_IMG_DIR_.$dir);
                    }
                }
                $tables = self::getCatalogRelatedTables();
                foreach ($tables as $table) {
                    static::executeStatement('TRUNCATE TABLE `'._DB_PREFIX_.bqSQL($table).'`');
                }
                static::executeStatement('DELETE FROM `'._DB_PREFIX_.'address` WHERE id_manufacturer > 0 OR id_supplier > 0 OR id_warehouse > 0');

                Image::deleteAllImages(_PS_PROD_IMG_DIR_);
                if (!file_exists(_PS_PROD_IMG_DIR_)) {
                    mkdir(_PS_PROD_IMG_DIR_);
                }
                foreach (scandir(_PS_MANU_IMG_DIR_) as $dir) {
                    if (preg_match('/^[0-9]+(-(.*))?\.jpg$/', $dir)) {
                        unlink(_PS_MANU_IMG_DIR_.$dir);
                    }
                }
                foreach (scandir(_PS_SUPP_IMG_DIR_) as $dir) {
                    if (preg_match('/^[0-9]+(-(.*))?\.jpg$/', $dir)) {
                        unlink(_PS_SUPP_IMG_DIR_.$dir);
                    }
                }
                break;

            case 'sales':
                $tables = self::getSalesRelatedTables();

                $modulesTables = [
                    'sekeywords'    => ['sekeyword'],
                    'pagesnotfound' => ['pagenotfound'],
                    'statsmodule'   => ['sekeyword', 'pagenotfound'],
                    'paypal'        => ['paypal_customer', 'paypal_order'],
                ];

                foreach ($modulesTables as $name => $moduleTables) {
                    if (Module::isInstalled($name)) {
                        $tables = array_merge($tables, $moduleTables);
                    }
                }

                foreach ($tables as $table) {
                    static::executeStatement('TRUNCATE TABLE `'._DB_PREFIX_.bqSQL($table).'`');
                }
                static::executeStatement('DELETE FROM `'._DB_PREFIX_.'address` WHERE id_customer > 0');
                static::executeStatement('DELETE FROM `'._DB_PREFIX_.'employee_notification`');

                break;
        }

        self::clearAllCaches();

        static::executeStatement('SET FOREIGN_KEY_CHECKS = 1;');
    }

    /**
     * @return string[]
     */
    public static function getCatalogRelatedTables()
    {
        return [
            'product',
            'product_shop',
            'feature_product',
            'product_lang',
            'category_product',
            'product_tag',
            'tag',
            'image',
            'image_lang',
            'image_shop',
            'specific_price',
            'specific_price_priority',
            'product_carrier',
            'cart_product',
            'product_attachment',
            'product_country_tax',
            'product_download',
            'product_group_reduction_cache',
            'product_sale',
            'product_supplier',
            'warehouse_product_location',
            'stock',
            'stock_available',
            'stock_mvt',
            'customization',
            'customization_field',
            'supply_order_detail',
            'attribute_impact',
            'product_attribute',
            'product_attribute_shop',
            'product_attribute_combination',
            'product_attribute_image',
            'attribute_impact',
            'attribute_lang',
            'attribute_group',
            'attribute_group_lang',
            'attribute_group_shop',
            'attribute_shop',
            'product_attribute',
            'product_attribute_shop',
            'product_attribute_combination',
            'product_attribute_image',
            'stock_available',
            'manufacturer',
            'manufacturer_lang',
            'manufacturer_shop',
            'supplier',
            'supplier_lang',
            'supplier_shop',
            'customization',
            'customization_field',
            'customization_field_lang',
            'customized_data',
            'feature',
            'feature_lang',
            'feature_product',
            'feature_shop',
            'feature_value',
            'feature_value_lang',
            'pack',
            'search_index',
            'search_word',
            'specific_price',
            'specific_price_priority',
            'specific_price_rule',
            'specific_price_rule_condition',
            'specific_price_rule_condition_group',
            'stock',
            'stock_available',
            'stock_mvt',
            'warehouse',
        ];
    }

    /**
     * @return string[]
     */
    public static function getSalesRelatedTables()
    {
        return [
            'customer',
            'cart',
            'cart_product',
            'connections',
            'connections_page',
            'connections_source',
            'customer_group',
            'customer_message',
            'customer_message_sync_imap',
            'customer_thread',
            'guest',
            'mail',
            'message',
            'message_readed',
            'orders',
            'order_carrier',
            'order_cart_rule',
            'order_detail',
            'order_detail_tax',
            'order_history',
            'order_invoice',
            'order_invoice_payment',
            'order_invoice_tax',
            'order_message',
            'order_message_lang',
            'order_payment',
            'order_return',
            'order_return_detail',
            'order_slip',
            'order_slip_detail',
            'page',
            'page_type',
            'page_viewed',
            'product_sale',
            'referrer_cache',
        ];
    }

    /**
     * @return string
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function renderForm()
    {
        $fieldsForm1 = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Catalog'),
                    'icon'  => 'icon-cogs',
                ],
                'input'  => [
                    [
                        'type'    => 'switch',
                        'is_bool' => true,
                        'label'   => $this->l('I understand that all the catalog data will be removed without possible rollback: products, features, categories, tags, images, prices, attachments, scenes, stocks, attribute groups and values, manufacturers, suppliers...'),
                        'name'    => 'checkTruncateCatalog',
                        'values'  => [
                            [
                                'id'    => 'checkTruncateCatalog_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id'    => 'checkTruncateCatalog_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Delete catalog'),
                    'class' => 'btn btn-default pull-right',
                    'name'  => 'submitTruncateCatalog',
                    'id'    => 'submitTruncateCatalog',
                ],
            ],
        ];

        $fieldsForm2 = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Orders and customers'),
                    'icon'  => 'icon-cogs',
                ],
                'input'  => [
                    [
                        'type'    => 'switch',
                        'is_bool' => true,
                        'label'   => $this->l('I understand that all the orders and customers will be removed without possible rollback: customers, carts, orders, connections, guests, messages, stats...'),
                        'name'    => 'checkTruncateSales',
                        'values'  => [
                            [
                                'id'    => 'checkTruncateSales_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id'    => 'checkTruncateSales_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Delete orders & customers'),
                    'class' => 'btn btn-default pull-right',
                    'name'  => 'submitTruncateSales',
                    'id'    => 'submitTruncateSales',
                ],
            ],
        ];

        $fieldsForm3 = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Functional integrity constraints'),
                    'icon'  => 'icon-cogs',
                ],
                'submit' => [
                    'title' => $this->l('Check & fix'),
                    'class' => 'btn btn-default pull-right',
                    'name'  => 'submitCheckAndFix',
                ],
            ],
        ];
        $fieldsForm4 = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Database cleaning'),
                    'icon'  => 'icon-cogs',
                ],
                'submit' => [
                    'title' => $this->l('Clean & Optimize'),
                    'class' => 'btn btn-default pull-right',
                    'name'  => 'submitCleanAndOptimize',
                ],
            ],
        ];

        /** @var AdminController $controller */
        $controller = $this->context->controller;

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->id = (int) Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages'    => $controller->getLanguages(),
            'id_language'  => $this->context->language->id,
        ];

        return $helper->generateForm([$fieldsForm1, $fieldsForm2, $fieldsForm3, $fieldsForm4]);
    }

    /**
     * @return int[]
     */
    public function getConfigFieldsValues()
    {
        return ['checkTruncateSales' => 0, 'checkTruncateCatalog' => 0];
    }
}
