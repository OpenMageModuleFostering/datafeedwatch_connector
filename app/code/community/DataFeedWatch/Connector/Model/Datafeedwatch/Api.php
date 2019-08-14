<?php

class DataFeedWatch_Connector_Model_Datafeedwatch_Api extends Mage_Catalog_Model_Product_Api
{

    const STOCK_ITEM_MODEL = 'cataloginventory/stock_item';
    const CATALOG_PRODUCT_MODEL = 'catalog/product';

    // category
    const CATALOG_CATEGORY_MODEL = 'catalog/category';
    const CATEGORY_NAME_FIELD = 'name';
    const CATEGORY_SEPARATOR = ' > ';
    public $categories = array();

    public $storeId = 0;
    public $storeRootCategoryId = 2;
    public $storeCategories = array();

    protected $_supportedEnterprise = array(
        'major' => '1',
        'minor' => '13',
        'revision' => '0',
        'patch' => '2',
        'stability' => '',
        'number' => '',
    );

    public function __construct()
    {
        $this->productCategories = array();
        ini_set('memory_limit', '1024M');
    }

    public function version()
    {
        return (string)Mage::getConfig()->getNode('modules/DataFeedWatch_Connector')->version;
    }

    public function product_count($options = array())
    {
        $collection = Mage::getModel(self::CATALOG_PRODUCT_MODEL)
            ->getCollection();

        if (array_key_exists('store', $options)) {
            //convert store code to store id
            if (!is_numeric($options['store'])) {
                $options['store'] = Mage::app()->getStore($options['store'])->getId();
            }

            if ($options['store']) {
                $collection->addStoreFilter($options['store']);
            } else {
                //use default solution
                $collection->addStoreFilter($this->_getStoreId($options['store']));
            }

            unset($options['store']);
        }

        $apiHelper = Mage::helper('api');
        if (method_exists($apiHelper, 'parseFilters')) {
            $filters = $apiHelper->parseFilters($options, $this->_filtersMap);
        } else {
            $dataFeedWatchHelper = Mage::helper('connector');
            $filters = $dataFeedWatchHelper->parseFiltersReplacement($options, $this->_filtersMap);
        }

        try {
            foreach ($filters as $field => $value) {
                //ignore status when flat catalog is enabled
                if ($field == 'status' && Mage::getStoreConfig('catalog/frontend/flat_catalog_product') == 1) {
                    continue;
                }
                $collection->addFieldToFilter($field, $value);
            }
        } catch (Mage_Core_Exception $e) {
            $this->_fault('filters_invalid', $e->getMessage());
        }

        $numberOfProducts = 0;
        if (!empty($collection)) {
            $numberOfProducts = $collection->getSize();
        }

        return round($numberOfProducts);
    }

    public function products($options = array())
    {
        $mageObject = new Mage;
        $this->_versionInfo = Mage::getVersionInfo();


        if (!array_key_exists('page', $options)) {
            $options['page'] = 1;
        }

        if (!array_key_exists('per_page', $options)) {
            $options['per_page'] = 100;
        }

        $collection = Mage::getModel('catalog/product')->getCollection();

        if (array_key_exists('store', $options)) {
            //convert store code to store id
            if (!is_numeric($options['store'])) {
                $options['store'] = Mage::app()->getStore($options['store'])->getId();
            }

            if ($options['store']) {
                $this->storeId = $options['store'];
                Mage::app()->setCurrentStore($this->storeId);

                //reinitialize collection because flat catalog settings may have changed
                $collection = Mage::getModel('catalog/product')->getCollection();
                $collection->addStoreFilter($this->storeId);
            } else {
                //use default solution
                $collection->addStoreFilter($this->_getStoreId($options['store']));
            }

        }

        $collection->addAttributeToSelect('*')
            ->setPage($options['page'], $options['per_page']);

        /* set current store manually so we get specific store url returned in getBaseUrl */
        $this->storeRootCategoryId = Mage::app()->getStore()->getRootCategoryId();
        $storeCategoriesCollection = Mage::getModel('catalog/category')->getCollection()
            ->addAttributeToSelect('name')
            ->addAttributeToSelect('is_active')
            ->addPathsFilter('%/' . $this->storeRootCategoryId);

        foreach ($storeCategoriesCollection as $storeCategory) {
            $this->storeCategories[] = $storeCategory->getId();
        }

        // clear options that are not filters
        unset($options['page']);
        unset($options['per_page']);
        unset($options['store']);

        $baseUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
        $imageBaseURL = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA) . self::CATALOG_PRODUCT_MODEL;

        $this->_loadCategories();

        /** @var $apiHelper Mage_Api_Helper_Data */
        $apiHelper = Mage::helper('api');
        if (method_exists($apiHelper, 'parseFilters')) {
            $filters = $apiHelper->parseFilters($options, $this->_filtersMap);
        } else {
            $dataFeedWatchHelper = Mage::helper('connector');
            $filters = $dataFeedWatchHelper->parseFiltersReplacement($options, $this->_filtersMap);
        }


        try {
            foreach ($filters as $field => $value) {
                //ignore status when flat catalog is enabled
                if ($field == 'status' && Mage::getStoreConfig('catalog/frontend/flat_catalog_product') == 1) {
                    continue;
                }
                $collection->addFieldToFilter($field, $value);
            }
        } catch (Mage_Core_Exception $e) {
            $this->_fault('filters_invalid', $e->getMessage());
        }

        $result = array();
        $product_cache = array();
        $price_keys = array('price', 'special_price');

        foreach ($collection as $product) {
            if ($this->storeId) {
                $product = Mage::getModel('catalog/product')->setStoreId($this->storeId)->load($product->getId());
            } else {
                $product = Mage::getModel('catalog/product')->load($product->getId());
            }
            $parent_id = null;
            $parent_sku = null;
            $parent_url = null;
            $configrable = false;

            if ($product->getTypeId() == "simple") {
                $parentIds = Mage::getModel('catalog/product_type_grouped')->getParentIdsByChild($product->getId());
                if (!$parentIds) {
                    $parentIds = Mage::getModel('catalog/product_type_configurable')->getParentIdsByChild($product->getId());
                    if (isset($parentIds[0])) {
                        $configrable = true;
                    }
                }
                if (isset($parentIds[0])) {
                    //$parent_id = Mage::getModel('catalog/product')->load($parentIds[0])->getId();

                    $parent_product = Mage::getModel('catalog/product')->load($parentIds[0]);
                    while (!$parent_product->getId()) {
                        if (count($parentIds) > 1) {
                            //parent nt found, remove and rty wth next one
                            array_shift($parentIds);
                            $parent_product = Mage::getModel('catalog/product')->load($parentIds[0]);
                        } else {
                            break;
                        }
                    }

                    if ($parent_product->getStatus() == Mage_Catalog_Model_Product_Status::STATUS_DISABLED
                        && $product->getVisibility() == Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE
                    ) {
                        continue;
                    }
                    $parent_id = $parent_product->getId();
                    $parent_sku = $parent_product->getSku();

                    //parent_url
                    if (method_exists($mageObject, 'getEdition') && Mage::getEdition() == Mage::EDITION_ENTERPRISE && Mage::getVersionInfo() >= $this->_supportedEnterprise) {
                        $parent_url = $parent_product->getProductUrl();
                    } else {
                        $parent_url = $baseUrl . $parent_product->getUrlPath();
                    }

                }
            }

            $product_result = array( // Basic product data
                'product_id' => $product->getId(),
                'sku' => $product->getSku(),
                'product_type' => $product->getTypeId()
            );

            $product_result['parent_id'] = $parent_id;
            $product_result['parent_sku'] = $parent_sku;
            $product_result['parent_url'] = $parent_url;

            foreach ($product->getAttributes() as $attribute) {

                if (!array_key_exists($attribute->getAttributeCode(), $this->_notNeededFields())) {
                    $value = $product->getData($attribute->getAttributeCode());
                    if (!empty($value)) {
                        if (in_array($attribute->getAttributeCode(), $price_keys)) {
                            $value = sprintf("%.2f", round(trim($attribute->getFrontend()->getValue($product)), 2));
                        } else {
                            $value = trim($attribute->getFrontend()->getValue($product));
                        }
                    }
                    $product_result[$attribute->getAttributeCode()] = $value;
                }
            }

            $imageUrl = (string)$product->getMediaConfig()->getMediaUrl($product->getData('image'));
            $imageTmpArr = explode('.', $imageUrl);
            $countImgArr = count($imageTmpArr);
            if (empty($imageUrl) || $imageUrl == '' || !isset($imageUrl) || $countImgArr < 2) {
                $imageUrl = (string)Mage::helper('catalog/image')->init($product, 'image');
            }


            if (method_exists($mageObject, 'getEdition') && Mage::getEdition() == Mage::EDITION_ENTERPRISE && Mage::getVersionInfo() >= $this->_supportedEnterprise) {
                $product_result['product_url'] = $product->getProductUrl();
            } else {
                $product_result['product_url'] = $baseUrl . $product->getUrlPath();
            }

            $product_result['image_url'] = $imageUrl;

            $tmpPrices = array();
            if ($parent_id && $configrable && $product->getVisibility() == Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE) {
                $tmpPrices = $this->getDisplayPrice($parent_id);
            } else {
                $tmpPrices = $this->getDisplayPrice($product);
            }

            if (count($tmpPrices)) {
                foreach ($tmpPrices as $key => $value) {
                    /*
                    use child values,
                    except description, short_description, product_url
                    and except when value (doesn't exist||is empty) in child
                    also, use parent image_url if it's empty in child
                    */
                    if (!array_key_exists($key, $product_result) || !$product_result[$key] || in_array($key, array('description', 'short_description', 'product_url', 'image_url'))) {
                        if ($key == 'image_url'
                            && !stristr($product_result[$key], '.jpg')
                            && !stristr($product_result[$key], '.png')
                            && !stristr($product_result[$key], '.jpeg')
                            && !stristr($product_result[$key], '.gif')
                            && !stristr($product_result[$key], '.bmp')
                        ) {
                            //overwrite record image_url with parent's value when child doesn't have correct image url
                            $product_result[$key] = $value;
                        } elseif ($key != 'image_url') {
                            //overwrite description,short_description and product_url
                            $product_result[$key] = $value;
                        }
                    }
                }
            }

            $inventoryStatus = Mage::getModel(self::STOCK_ITEM_MODEL)->loadByProduct($product);
            if (!empty($inventoryStatus)) {
                $product_result['quantity'] = (int)$inventoryStatus->getQty();
                $product_result['is_in_stock'] = $inventoryStatus->getIsInStock() == '1' ? 1 : 0;
            }
            $result[] = $product_result;

        }
        return $result;
    }

    private function _loadCategories()
    {

        $parentId = 1;

        /* @var $tree Mage_Catalog_Model_Resource_Eav_Mysql4_Category_Tree */
        $tree = Mage::getResourceSingleton('catalog/category_tree')->load();
        $root = $tree->getNodeById($parentId);

        if ($root && $root->getId() == 1) {
            $root->setName(Mage::helper('catalog')->__('Root'));
        }

        $collection = Mage::getModel('catalog/category')->getCollection()
            ->addAttributeToSelect('name')
            ->addAttributeToSelect('is_active');

        $tree->addCollectionData($collection, true);

        return $this->_nodeToArray($root);
    }

    /**
     * Convert node to array
     *
     * @param Varien_Data_Tree_Node $node
     * @return array
     */
    private function _nodeToArray(Varien_Data_Tree_Node $node)
    {
        $children = $node->getChildren();
        if (!empty($children)) {
            foreach ($children as $child) {
                $this->_nodeToArray($child);
            }
        }

        $this->categories[$node->getId()] = array(
            'category_id' => $node->getId(),
            'parent_id' => $node->getParentId(),
            'name' => $node->getName(),
            'is_active' => $node->getIsActive()
        );
    }

    private function _buildCategoryPath($category_id, &$path = array())
    {
        $this->productCategories[] = $category_id;
        $category = $this->categories[$category_id];

        if ($category['parent_id'] != '0') {
            $this->_buildCategoryPath($category['parent_id'], $path);
        }

        if ($category['is_active'] == '1') {
            $path[] = $category['name'];
        }

        return $path;
    }

    private function _notNeededFields()
    {
        return array(
            'type' => 0,
            'type_id' => 0,
            'set' => 0,
            'categories' => 0,
            'websites' => 0,
            'old_id' => 0,
            'news_from_date' => 0,
            'news_to_date' => 0,
            'category_ids' => 0,
            'required_options' => 0,
            'has_options' => 0,
            'image_label' => 0,
            'small_image_label' => 0,
            'thumbnail_label' => 0,
            'created_at' => 0,
            'updated_at' => 0,
            'group_price' => 0,
            'tier_price' => 0,
            'msrp_enabled' => 0,
            'minimal_price' => 0,
            'msrp_display_actual_price_type' => 0,
            'msrp' => 0,
            'enable_googlecheckout' => 0,
            'is_recurring' => 0,
            'recurring_profile' => 0,
            'custom_design' => 0,
            'custom_design_from' => 0,
            'custom_design_to' => 0,
            'custom_layout_update' => 0,
            'page_layout' => 0,
            'options_container' => 0,
            'gift_message_available' => 0,
            'url_key' => 0,
            'url_path' => 0,
            'image' => 0,
            'small_image' => 0,
            'thumbnail' => 0,
            'media_gallery' => 0,
            'gallery' => 0,
            'entity_type_id' => 0,
            'attribute_set_id' => 0,
            'entity_id' => 0
        );
    }

    private function getDisplayPrice($product_id)
    {
        $mageObject = new Mage;
        if (!$product_id) {
            return 0;
        }

        $prices = array();

        if ($product_id instanceof Mage_Catalog_Model_Product) {
            $product = $product_id;
        } else {
            if ($product_id < 1) {
                return 0;
            }
            $product = Mage::getModel('catalog/product')->setStoreId($this->storeId)->load($product_id);
        }

        $store_code = Mage::app()->getStore()->getCode();
        $_taxHelper = Mage::helper('tax');
        // Get Currency Code
        $bas_curncy_code = Mage::app()->getStore()->getBaseCurrencyCode();
        $cur_curncy_code = Mage::app()->getStore($store_code)->getCurrentCurrencyCode();

        $allowedCurrencies = Mage::getModel('directory/currency')
            ->getConfigAllowCurrencies();
        $currencyRates = Mage::getModel('directory/currency')
            ->getCurrencyRates($bas_curncy_code, array_values($allowedCurrencies));

        $prices['price_with_tax'] = $_finalPriceInclTax = $_taxHelper->getPrice($product, $product->getPrice(), 2); //$product['price'];
        $prices['price'] = $_taxHelper->getPrice($product, $product->getPrice(), NULL);
        $prices['special_price'] = 0;
        $prices['special_price_with_tax'] = 0;
        $prices['special_from_date'] = '';
        $prices['special_to_date'] = '';

        $prices['description'] = $product->getDescription();
        $prices['short_description'] = $product->getShortDescription();


        $baseUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);

        if (method_exists($mageObject, 'getEdition') && Mage::getEdition() == Mage::EDITION_ENTERPRISE && Mage::getVersionInfo() >= $this->_supportedEnterprise) {
            $product_result['product_url'] = $product->getProductUrl();
        } else {
            $product_result['product_url'] = $baseUrl . $product->getUrlPath();
        }

        //Setting image
        $imageUrl = (string)$product->getMediaConfig()->getMediaUrl($product->getData('image'));
        $imageTmpArr = explode('.', $imageUrl);
        $countImgArr = count($imageTmpArr);
        if (empty($imageUrl) || $imageUrl == '' || !isset($imageUrl) || $countImgArr < 2) {
            $imageUrl = (string)Mage::helper('catalog/image')->init($product, 'image');
        }
        $prices['image_url'] = $imageUrl;

        $additional_images = $product->getMediaGalleryImages();
        if (count($additional_images) > 0) {
            $i = 1;
            foreach ($additional_images as $images) {
                if ($images->getUrl() != $prices['image_url'])
                    $prices['additional_image_url' . $i++] = $images->getUrl();
            }
        }

        $specialTmpPrice = $product->getSpecialPrice();

        if ($specialTmpPrice && (strtotime(date('Y-m-d H:i:s')) < strtotime($product['special_to_date'])
                || empty($product['special_to_date']))
        ) {
            $prices['special_price'] = $_taxHelper->getPrice($product, $product->getSpecialPrice(), NULL);
            $prices['special_price_with_tax'] = $_taxHelper->getPrice($product, $product->getSpecialPrice(), 2);
            $prices['special_from_date'] = $product['special_from_date'];
            $prices['special_to_date'] = $product['special_to_date'];
            //round($product->getSpecialPrice(), 2);
        }

        if ($bas_curncy_code != $cur_curncy_code
            && array_key_exists($bas_curncy_code, $currencyRates)
            && array_key_exists($cur_curncy_code, $currencyRates)
        ) {
            if ($prices['special_price'] && (strtotime(date('Y-m-d H:i:s')) < strtotime($product['special_to_date'])
                    || empty($product['special_to_date']))
            ) {
                $prices['special_price_with_tax'] = Mage::helper('directory')->currencyConvert($prices['special_price_with_tax'], $bas_curncy_code, $cur_curncy_code);
                $prices['special_price'] = Mage::helper('directory')->currencyConvert($prices['special_price'], $bas_curncy_code, $cur_curncy_code);
            }

            $prices['price_with_tax'] = Mage::helper('directory')->currencyConvert($_finalPriceInclTax, $bas_curncy_code, $cur_curncy_code);
            $prices['price'] = Mage::helper('directory')->currencyConvert($prices['price'], $bas_curncy_code, $cur_curncy_code);
        }

        // Getting Additional information
        $attributes = $product->getAttributes();
        //$attrs = $product->getTypeInstance(true)->getConfigurableAttributesAsArray($product);
        foreach ($attributes as $attribute) {
            if ($attribute->getIsUserDefined()) { //&& $attribute->getIsVisibleOnFront()
                if (!array_key_exists($attribute->getAttributeCode(), $this->_notNeededFields())) {
                    $value = $product->getData($attribute->getAttributeCode());
                    if (!empty($value)) {
                        $value = trim($attribute->getFrontend()->getValue($product));
                    }
                    $prices[$attribute->getAttributeCode()] = $value;
                }
            }
        }

        // categories
        $category_id = $product->getCategoryIds();
        if (empty($category_id)) {
            $prices['category_name'] = '';
            $prices['category_parent_name'] = '';
            $prices['category_path'] = '';
        } else {
            rsort($category_id);
            $this->productCategories = array();
            $index = '';
            foreach ($category_id as $key => $cate) {
//                if(in_array($cate, $this->productCategories))
//                    continue;

                if (!in_array($cate, $this->storeCategories))
                    continue;

                $category = $this->categories[$cate];
                $prices['category_name' . $index] = $category['name'];
                $prices['category_parent_name' . $index] = $this->categories[$category['parent_id']]['name'];
                $prices['category_path' . $index] = implode(' > ', $this->_buildCategoryPath($category['category_id']));
                if ($index == '')
                    $index = 1;
                else
                    $index = $index + 1;
            }
        }

        return $prices;
    }

    public function stores()
    {
        foreach (Mage::app()->getWebsites() as $website) {
            foreach ($website->getGroups() as $group) {
                $stores = $group->getStores();
                foreach ($stores as $store) {
                    $returned[$store->getCode()] = array(
                        'Website' => $website->getName(),
                        'Store' => $group->getName(),
                        'Store View' => $store->getName(),
                    );
                }
            }
        }
        return $returned;
    }


}