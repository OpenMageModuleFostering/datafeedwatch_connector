<?php

class DataFeedWatch_Connector_Model_Datafeedwatch_Api extends Mage_Catalog_Model_Product_Api
{
    // category
    const CATEGORY_NAME_FIELD = 'name';
    const CATEGORY_SEPARATOR = ' > ';
    public $categories = array();

    public $storeId = 0;
    public $storeRootCategoryId = 2;
    public $storeCategories = array();

    /* has been tested with this EE version and works completely */
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

    /* API method */
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

    /* API method */
    public function product_ids($options = array())
    {
        if (!array_key_exists('page', $options)) {
            $options['page'] = 1;
        }

        if (!array_key_exists('per_page', $options)) {
            $options['per_page'] = 100;
        }

        $collection = $this->_prepareCollection($options);

        return $collection->getAllIds($options['per_page'], $options['page']);
    }

    /* API method */
    public function version()
    {
        return (string)Mage::getConfig()->getNode('modules/DataFeedWatch_Connector')->version;
    }

    /* API method */
    public function product_count($options = array())
    {

        $collection = $this->_prepareCollection($options);

        $apiHelper = Mage::helper('api');
        if (method_exists($apiHelper, 'parseFilters')) {
            $filters = $apiHelper->parseFilters($options, $this->_filtersMap);
        } else {
            /* added to support older releases without parseFilters */
            $dataFeedWatchHelper = Mage::helper('connector');
            $filters = $dataFeedWatchHelper->parseFiltersReplacement($options, $this->_filtersMap);
        }

        try {
            foreach ($filters as $field => $value) {
                //ignore status when flat catalog is enabled
                if ($field == 'status' && Mage::helper('catalog/product_flat')->isEnabled()) {
                    continue;
                }
                $fieldToIgnore = array('store', 'page', 'per_page');
                //ignore fields when flat catalog is not enabled
                if (in_array($field, $fieldToIgnore) && !Mage::helper('catalog/product_flat')->isEnabled()) {
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

    /* API method */
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

        $collection = $this->_prepareCollection($options);

        $collection->addAttributeToSelect('*')
            ->joinAttribute('visibility', 'catalog_product/visibility', 'entity_id', null, 'inner', $this->storeId)
            ->setPage($options['page'], $options['per_page']);

        // clear options that are not filters
        unset($options['page']);
        unset($options['per_page']);
        unset($options['store']);


        /* set current store manually so we get specific store url returned in getBaseUrl */
        $this->storeRootCategoryId = Mage::app()->getStore($this->storeId)->getRootCategoryId();
        $storeCategoriesCollection = Mage::getResourceModel('catalog/category_collection');
        $storeCategoriesCollection->addAttributeToSelect('name')
            ->addAttributeToSelect('is_active')
            ->addPathsFilter('%/' . $this->storeRootCategoryId);

        $baseUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);

        foreach ($storeCategoriesCollection as $storeCategory) {
            $this->storeCategories[] = $storeCategory->getId();
        }

        /*@TODO: check if we still use this, or we can rely on storeCategories*/
        Mage::helper('connector')->loadCategories();
        $this->categories = Mage::helper('connector')->getCategories();
        $this->productCategories = Mage::helper('connector')->getProductCategories();

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
                //ignore status when flat catalog is enabled, as flat catalog does not have status
                if ($field == 'status' && Mage::helper('catalog/product_flat')->isEnabled()) {
                    continue;
                }
                $collection->addFieldToFilter($field, $value);
            }
        } catch (Mage_Core_Exception $e) {
            $this->_fault('filters_invalid', $e->getMessage());
        }

        $result = array();
        $price_keys = array('price', 'special_price');

        foreach ($collection as $product) {

            //re-setters
            $parent_id = null;
            $parent_sku = null;
            $parent_url = null;
            $configurable = false;

            if ($this->storeId) {
                $product = Mage::getModel('catalog/product')->setStoreId($this->storeId)->load($product->getId());
            } else {
                $product = Mage::getModel('catalog/product')->load($product->getId());
            }

            $product_result = array( // Basic product data
                'product_id' => $product->getId(),
                'sku' => $product->getSku(),
                'product_type' => $product->getTypeId()
            );

            $selected_attributes = $this->synced_fields();
            foreach ($product->getAttributes() as $attribute) {

                /* ignore excluded attributes */
                if(array_key_exists($attribute->getAttributeCode(), Mage::helper('connector')->getExcludedAttributes())){
                    continue;
                }

                /* only use user-selected fields from DataFeedWatch -> Settings + required attributes */
                if (in_array($attribute->getAttributeCode(),array_merge($selected_attributes,Mage::helper('connector')->getRequiredAttributes()))) {
                    $value = $product->getData($attribute->getAttributeCode());
                    if (!empty($value)) {
                        if (in_array($attribute->getAttributeCode(), $price_keys)) {
                            $value = sprintf("%.2f", round(trim($attribute->getFrontend()->getValue($product)), 2));
                        } else {
                            $value = trim($attribute->getFrontend()->getValue($product));
                        }
                    }
                    $product_result[$attribute->getAttributeCode()] = $value;
                } else {
                    Mage::log('attr_code: '.$attribute->getAttributeCode().' was not synced',null,'datafeedwatch_connector.log');
                }
            }

            /* get product main image file */
            $imageUrl = (string)$product->getMediaConfig()->getMediaUrl($product->getData('image'));
            $imageTmpArr = explode('.', $imageUrl);
            $countImgArr = count($imageTmpArr);
            if (empty($imageUrl) || $imageUrl == '' || !isset($imageUrl) || $countImgArr < 2) {
                $imageUrl = (string)Mage::helper('catalog/image')->init($product, 'image');
            }
            $product_result['image_url'] = $imageUrl;

            /* get product Url */
            if (method_exists($mageObject, 'getEdition') && Mage::getEdition() == Mage::EDITION_ENTERPRISE && Mage::getVersionInfo() >= $this->_supportedEnterprise) {
                $product_result['product_url'] = $product->getProductUrl();
            } else {
                $product_result['product_url_rewritten'] = $baseUrl . Mage::helper('connector')->getRewrittenProductUrl($product,null,$this->storeId);
                $product_result['product_url'] = $baseUrl . $product->getUrlPath();
            }

            /* Parent product logic,rewrite attributes with parent values */
            if ($product->getTypeId() == "simple") {
                $parentIds = Mage::getModel('catalog/product_type_grouped')->getParentIdsByChild($product->getId());
                if (!$parentIds) {
                    $parentIds = Mage::getModel('catalog/product_type_configurable')->getParentIdsByChild($product->getId());
                    if (isset($parentIds[0])) {
                        $configurable = true;
                    }
                }

                if (isset($parentIds[0])) {
                    $parent_product = Mage::getModel('catalog/product')->load($parentIds[0]);
                    while (!$parent_product->getId()) {
                        if (count($parentIds) > 1) {
                            //parent not found, remove and retry with next one
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
                    if (method_exists($mageObject, 'getEdition')
                        && Mage::getEdition() == Mage::EDITION_ENTERPRISE
                        && Mage::getVersionInfo() >= $this->_supportedEnterprise)
                    {
                        $parent_url = $parent_product->getProductUrl();
                    } else {
                        $parent_url = $baseUrl . $parent_product->getUrlPath();
                    }

                }
            }

            $product_result['parent_id'] = $parent_id;
            $product_result['parent_sku'] = $parent_sku;
            $product_result['parent_url'] = $parent_url;
            if ($parent_id && $configurable && $product->getVisibility() == Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE) {
                //rewrite to prepare array of fields to overwrite with parent values
                $productAttributes = Mage::helper('connector')->getProductAttributes($parent_product);

                // get child product visibility
                $visibilityStatuses = Mage_Catalog_Model_Product_Visibility::getOptionArray();
                if (isset($visibilityStatuses[$product->getVisibility()])) {
                    $productAttributes['visibility'] = $visibilityStatuses[$product->getVisibility()];
                } else {
                    $productAttributes['visibility'] = null;
                }
            } else {
                $productAttributes = Mage::helper('connector')->getProductAttributes($product);
            }

            /* TODO: simplify this */
            if (count($productAttributes)) {
                foreach ($productAttributes as $key => $value) {
                    /*
                    use child values,
                    except description, short_description, product_url
                    and except when value (doesn't exist||is empty) in child
                    also, use parent image_url if(only if) it's empty in child
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

            $product_result = Mage::helper('connector')->addStockInfoToResult($product,$product_result);
            // add some parent attributes
            $categoriesData = array(
                'store_categories' => $this->storeCategories,
                'categories' => $this->categories,
            );
            if ($parent_id && $configurable && ($product->getVisibility() == Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE)) {
                $product_result = Mage::helper('connector')->addProductDynamicAttributesToResult($product,$product_result, $parent_product, $categoriesData);
            } else {
                $product_result = Mage::helper('connector')->addProductDynamicAttributesToResult($product,$product_result, null, $categoriesData);
            }

            /* @TODO: move to helper*/
            // get simple product price with Super Attributes Prices Values
            if ( $product->getTypeId() == "simple" ) {
                // which is child of some parent product
                if ( ! empty( $parent_id ) && gettype( $parent_product ) == 'object') {
                    if($parent_product->getTypeInstance(true) instanceof Mage_Catalog_Model_Product_Type_Configurable) {
                        // get all configurable attributes

                        if ($parent_product) {
                            $attributes = $parent_product->getTypeInstance(true)->getConfigurableAttributes($parent_product);
                        }
                        // array to keep the price differences for each attribute value
                        $pricesByAttributeValues = array();
                        // base price of the configurable product
                        $basePrice = $parent_product->getFinalPrice();
                        // loop through the attributes and get the price adjustments specified in the configurable product admin page
                        foreach ($attributes as $attribute) {
                            $prices = $attribute->getPrices();
                            foreach ($prices as $price) {
                                if ($price['is_percent']) {
                                    $pricesByAttributeValues[$price['value_index']] = (float)$price['pricing_value'] * $basePrice / 100;
                                } else {
                                    $pricesByAttributeValues[$price['value_index']] = (float)$price['pricing_value'];
                                }
                            }
                        }

                        $totalPrice = $basePrice;
                        // loop through the configurable attributes
                        foreach ($attributes as $attribute) {
                            // get the value for a specific attribute for a simple product
                            $value = $product->getData($attribute->getProductAttribute()->getAttributeCode());
                            // add the price adjustment to the total price of the simple product
                            if (isset($pricesByAttributeValues[$value])) {
                                $totalPrice += $pricesByAttributeValues[$value];
                            }
                        }
                        $_taxHelper = Mage::helper('tax');

                        $product_result['variant_name'] = $product->getName();
                        $product_result['variant_price'] = $totalPrice;
                        $product_result['variant_price_with_tax'] = $_taxHelper->getPrice($product, $product->getPrice(), 2);
                        $product_result['variant_special_price_with_tax'] = $_taxHelper->getPrice($product, $product->getSpecialPrice(), 2);
                    } else {
                        // item has a parent becaus it extends Mage_Catalog_Model_Product_Type_Grouped
                        // it has no effect on price modifiers, however, so we ignore it
                    }
                }
            }

            $product_result = $this->updatePriceIncludingRules($product,$product_result);

            $result[] = $product_result;

        }
        return $result;
    }

    /* API method */
    public function gmt_offset(){
        // get timezone offset in GMT
        $timeZone = new DateTimeZone(Mage::getStoreConfig('general/locale/timezone'));
        $time     = new DateTime('now', $timeZone);
        $offset   = (int)($timeZone->getOffset($time) / 3600);

        return $offset;
    }

    public function updatePriceIncludingRules($product,$product_result){

        $finalPrice = Mage::getModel('catalogrule/rule')->calcProductPriceRule($product,$product->getPrice());

        if($finalPrice){
            $product_result['price'] = sprintf("%.2f", round($finalPrice, 2));
        }


        return $product_result;
    }

    /* API Method */
    public function synced_fields(){
        $additional = array();
        if(Mage::getStoreConfig('datafeedwatch/settings/attributes')){
            $additional = Zend_Serializer::unserialize(Mage::getStoreConfig('datafeedwatch/settings/attributes'));
        }

        return $additional;
    }

    private function _prepareCollection($options){
        $attributeModel = Mage::getModel('eav/entity_attribute');
        $attributeId = $attributeModel->getIdByCode('catalog_product', 'ignore_datafeedwatch');

        if ($attributeId) {
            $collection = Mage::getModel('catalog/product')->getCollection()
                ->addAttributeToFilter(array(
                        array('attribute'=>'ignore_datafeedwatch', 'neq'=> 1),
                        array('attribute'=>'ignore_datafeedwatch', 'null'=> true),
                    ),
                    '',
                    'left'
                )
            ;
        } else {
            $collection = Mage::getModel('catalog/product')->getCollection();
        }


        if (array_key_exists('store', $options)) {

            //convert store code to store id
            if (!is_numeric($options['store'])) {
                $options['store'] = Mage::app()->getStore($options['store'])->getId();
            }

            if ($options['store']) {
                $this->storeId = $options['store'];
                Mage::app()->setCurrentStore($this->storeId);

                //reinitialize collection because flat catalog settings may have changed
                if ($attributeId) {
                    $collection = Mage::getModel('catalog/product')->getCollection()
                        ->addAttributeToFilter(array(
                                array('attribute'=>'ignore_datafeedwatch', 'neq'=> 1),
                                array('attribute'=>'ignore_datafeedwatch', 'null'=> true),
                            ),
                            '',
                            'left'
                        )
                    ;
                } else {
                    $collection = Mage::getModel('catalog/product')->getCollection();
                }

                $collection->addStoreFilter($this->storeId);

            } else {
                //use default solution
                $collection->addStoreFilter($this->_getStoreId($options['store']));
            }
        }

        if (array_key_exists('status', $options)) {
            if($options['status'] == Mage_Catalog_Model_Product_Status::STATUS_DISABLED) {
                $collection->addAttributeToFilter('status', 0);
            } elseif($options['status'] == Mage_Catalog_Model_Product_Status::STATUS_ENABLED) {
                $collection->addAttributeToFilter('status', 1);
            }
        }

        return $collection;
    }
}