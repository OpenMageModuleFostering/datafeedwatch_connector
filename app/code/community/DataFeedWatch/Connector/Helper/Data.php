<?php
class DataFeedWatch_Connector_Helper_Data extends Mage_Core_Helper_Abstract
{

    private $_categories;
    private $_productCategories;

    private $_required_attributes = array(
        "product_id",
        "sku",
        "product_type",
        "parent_id",
        "parent_sku",
        "parent_url",
        "name",
        "description",
        "short_description",
        "weight",
        "status",
        "visibility",
        "country_of_manufacture",
        "price",
        "special_price",
        "special_from_date",
        "special_to_date",
        "tax_class_id",
        "meta_title",
        "meta_keyword",
        "meta_description",
        "gift_wrapping_available",
        "gift_wrapping_price",
        "color",
        "occasion",
        "apparel_type",
        "sleeve_length",
        "fit",
        "size",
        "length",
        "gender",
        "product_url",
        "image_url",
        "price_with_tax",
        "special_price_with_tax",
        "additional_image_url1",
        "additional_image_url2",
        "quantity",
        "is_in_stock",
        'msrp_enabled',
        'minimal_price',
        'msrp_display_actual_price_type',
        'msrp',
    );

    private $_excluded_attributes = array(
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

    /* currency fields - to prevent multiple calls */
    private $_bas_curncy_code = null;
    private $_cur_curncy_code = null;
    private $_allowedCurrencies = null;
    private $_currencyRates = null;

    public function getRequiredAttributes(){
        return $this->_required_attributes;
    }

    public function getAttributesList(){
        $attributesList = array();
        $entityType = Mage::getModel('eav/config')->getEntityType(Mage_Catalog_Model_Product::ENTITY);
        $attributesCollection = Mage::getModel('eav/entity_attribute')->getCollection()
                                    ->addFieldToFilter('entity_type_id', array('eq' => $entityType->getEntityTypeId()));
        foreach($attributesCollection as $attribute){
            if(!in_array($attribute->getAttributeCode(),$this->_required_attributes)){
                $attributesList[$attribute->getAttributeCode()] = $attribute->getAttributeCode();
            }
        }
        return $attributesList;
    }

    /**
     * Parse filters and format them to be applicable for collection filtration
     *
     * @param null|object|array $filters
     * @param array $fieldsMap Map of field names in format: array('field_name_in_filter' => 'field_name_in_db')
     * @return array
     */
    public function parseFiltersReplacement($filters, $fieldsMap = null)
    {
        // if filters are used in SOAP they must be represented in array format to be used for collection filtration
        if (is_object($filters)) {
            $parsedFilters = array();
            // parse simple filter
            if (isset($filters->filter) && is_array($filters->filter)) {
                foreach ($filters->filter as $field => $value) {
                    if (is_object($value) && isset($value->key) && isset($value->value)) {
                        $parsedFilters[$value->key] = $value->value;
                    } else {
                        $parsedFilters[$field] = $value;
                    }
                }
            }
            // parse complex filter
            if (isset($filters->complex_filter) && is_array($filters->complex_filter)) {
                $parsedFilters += $this->_parseComplexFilterReplacement($filters->complex_filter);
            }

            $filters = $parsedFilters;
        }
        // make sure that method result is always array
        if (!is_array($filters)) {
            $filters = array();
        }
        // apply fields mapping
        if (isset($fieldsMap) && is_array($fieldsMap)) {
            foreach ($filters as $field => $value) {
                if (isset($fieldsMap[$field])) {
                    unset($filters[$field]);
                    $field = $fieldsMap[$field];
                    $filters[$field] = $value;
                }
            }
        }
        return $filters;
    }

    /**
     * Parses complex filter, which may contain several nodes, e.g. when user want to fetch orders which were updated
     * between two dates.
     *
     * @param array $complexFilter
     * @return array
     */
    protected function _parseComplexFilterReplacement($complexFilter)
    {
        $parsedFilters = array();

        foreach ($complexFilter as $filter) {
            if (!isset($filter->key) || !isset($filter->value)) {
                continue;
            }

            list($fieldName, $condition) = array($filter->key, $filter->value);
            $conditionName = $condition->key;
            $conditionValue = $condition->value;
            $this->formatFilterConditionValueReplacement($conditionName, $conditionValue);

            if (array_key_exists($fieldName, $parsedFilters)) {
                $parsedFilters[$fieldName] += array($conditionName => $conditionValue);
            } else {
                $parsedFilters[$fieldName] = array($conditionName => $conditionValue);
            }
        }

        return $parsedFilters;
    }

    /**
     * Convert condition value from the string into the array
     * for the condition operators that require value to be an array.
     * Condition value is changed by reference
     *
     * @param string $conditionOperator
     * @param string $conditionValue
     */
    public function formatFilterConditionValueReplacement($conditionOperator, &$conditionValue)
    {
        if (is_string($conditionOperator) && in_array($conditionOperator, array('in', 'nin', 'finset'))
            && is_string($conditionValue)
        ) {
            $delimiter = ',';
            $conditionValue = explode($delimiter, $conditionValue);
        }
    }

    public function buildCategoryPath($category_id, &$path = array())
    {
        $this->_productCategories[] = $category_id;
        $category = $this->_categories[$category_id];

        if ($category['parent_id'] != '0') {
            $this->buildCategoryPath($category['parent_id'], $path);
        }

        if ($category['is_active'] == '1') {
            $path[] = $category['name'];
        }

        return $path;
    }

    public function loadCategories()
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

        $this->_categories[$node->getId()] = array(
            'category_id' => $node->getId(),
            'parent_id' => $node->getParentId(),
            'name' => $node->getName(),
            'is_active' => $node->getIsActive()
        );
    }

    public function getCategories(){
        return $this->_categories;
    }
    public function getProductCategories(){
        return $this->_productCategories;
    }

    public function getExcludedAttributes(){
        return $this->_excluded_attributes;
    }

    public function getProductAttributes(Mage_Catalog_Model_Product $product)
    {
        $mageObject = new Mage;

        $prices['description'] = $product->getDescription();
        $prices['short_description'] = $product->getShortDescription();

        $baseUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);

        if (method_exists($mageObject, 'getEdition') && Mage::getEdition() == Mage::EDITION_ENTERPRISE && Mage::getVersionInfo() >= $this->_supportedEnterprise) {
            $prices['product_url'] = $product->getProductUrl();
        } else {
            $prices['product_url_rewritten'] = $baseUrl . $this->getRewrittenProductUrl($product,null,$this->storeId);
            $prices['product_url'] = $baseUrl . $product->getUrlPath();
        }

        $prices = $this->addImageToResult($product,$prices);
        $prices = $this->addPricesToResult($product,$prices);

        // Getting Additional information
        $attributes = $product->getAttributes();
        foreach ($attributes as $attribute) {

            //only fetch if this is not excluded field
            if (!array_key_exists($attribute->getAttributeCode(), Mage::helper('connector')->getExcludedAttributes())) {
                $value = $product->getData($attribute->getAttributeCode());

                //only fetch if value is not emtpy
                if (!empty($value)) {
                    $value = trim($attribute->getFrontend()->getValue($product));
                }
                $prices[$attribute->getAttributeCode()] = $value;
            }
        }

        return $prices;
    }

    /**
     * @param $product Mage_Catalog_Model_Product
     * @param $product_result array
     * @param $parent_product Mage_Catalog_Model_Product
     * @return array
     */
    public function addProductDynamicAttributesToResult($product, $product_result, $parent_product = null, $categoriesData){
        //categories
        if ($parent_product != null) {
            // inherit categories from parent
            $product_result = $this->addProductCategoriesToResult($parent_product,$product_result, $categoriesData);
        } else {
            $product_result = $this->addProductCategoriesToResult($product,$product_result, $categoriesData);
        }

        //excluded images
        $product_result = $this->addExcludedImagesToResult($product,$product_result);

        //additional images
        $product_result = $this->addAdditionalImagesToResult($product,$product_result);

        return $product_result;
    }

    public function addProductCategoriesToResult($product,$product_result, $categoriesData){

        $category_id = $product->getCategoryIds();
        if (empty($category_id)) {
            $product_result['category_name'] = '';
            $product_result['category_parent_name'] = '';
            $product_result['category_path'] = '';
        } else {
            rsort($category_id);
            $this->productCategories = array();
            $index = '';
            foreach ($category_id as $key => $cate) {
                if (!in_array($cate, $categoriesData['store_categories'])) {
                    continue;
                }

                $category = $categoriesData['categories'][$cate];
                $product_result['category_name' . $index] = $category['name'];
                $product_result['category_parent_name' . $index] = $categoriesData['categories'][$category['parent_id']]['name'];

                $categoryPath = Mage::helper('connector')->buildCategoryPath($category['category_id']);
                $product_result['category_path' . $index] = implode(' > ', $categoryPath);
                if ($index == '') {
                    $index = 1;
                } else {
                    $index++;
                }
            }
        }

        return $product_result;

    }

    public function addAdditionalImagesToResult($product,$product_result){
        $additional_images = $product->getMediaGalleryImages();
        if (count($additional_images) > 0) {
            $i = 1;
            foreach ($additional_images as $images) {
                if ($images->getUrl() != $product_result['image_url']) {
                    $product_result['additional_image_url' . $i++] = $images->getUrl();
                }
            }
        }
        return $product_result;
    }

    public function addExcludedImagesToResult($product,$product_result)
    {
        $allImages = $product->getMediaGallery('images');

        $i = 1;
        foreach ($allImages as $image) {
            if ($image['disabled']) {
                $excludedUrl = (string)$product->getMediaConfig()->getMediaUrl($image['file']);
                $product_result['image_url_excluded'.$i++] = $excludedUrl;
            }
        }

        return $product_result;
    }

    private function prepareCurrencyRates(){
        if($this->_currencyRates===null) {
            $store_code = Mage::app()->getStore()->getCode();
            // Get Currency Code
            $this->_bas_curncy_code = Mage::app()->getStore()->getBaseCurrencyCode();
            $this->_cur_curncy_code = Mage::app()->getStore($store_code)->getCurrentCurrencyCode();

            $this->_allowedCurrencies = Mage::getModel('directory/currency')
                ->getConfigAllowCurrencies();
            $this->_currencyRates = Mage::getModel('directory/currency')
                ->getCurrencyRates($this->_bas_curncy_code, array_values($this->_allowedCurrencies));
        }
    }

    public function addPricesToResult($product,$prices){

        $_taxHelper = Mage::helper('tax');

        $this->prepareCurrencyRates();

        $prices['price_with_tax'] = $_finalPriceInclTax = $_taxHelper->getPrice($product, $product->getPrice(), 2); //$product['price'];
        $prices['price'] = $_taxHelper->getPrice($product, $product->getPrice(), NULL);
        $prices['special_price'] = 0;
        $prices['special_price_with_tax'] = 0;
        $specialTmpPrice = $product->getSpecialPrice();

        if ($specialTmpPrice && (strtotime(date('Y-m-d H:i:s')) < strtotime($product['special_to_date'])
                || empty($product['special_to_date']))
        ) {
            $prices['special_price'] = $_taxHelper->getPrice($product, $product->getSpecialPrice(), NULL);
            $prices['special_price_with_tax'] = $_taxHelper->getPrice($product, $product->getSpecialPrice(), 2);
            $prices['special_from_date'] = $product['special_from_date'];
            $prices['special_to_date'] = $product['special_to_date'];
        }

        if ($this->_bas_curncy_code != $this->_cur_curncy_code
            && array_key_exists($this->_bas_curncy_code, $this->_currencyRates)
            && array_key_exists($this->_cur_curncy_code, $this->_currencyRates)
        ) {
            if ($prices['special_price'] && (strtotime(date('Y-m-d H:i:s')) < strtotime($product['special_to_date'])
                    || empty($product['special_to_date']))
            ) {
                $prices['special_price_with_tax'] = Mage::helper('directory')->currencyConvert($prices['special_price_with_tax'], $this->_bas_curncy_code, $this->_cur_curncy_code);
                $prices['special_price'] = Mage::helper('directory')->currencyConvert($prices['special_price'], $this->_bas_curncy_code, $this->_cur_curncy_code);
            }

            $prices['price_with_tax'] = Mage::helper('directory')->currencyConvert($_finalPriceInclTax, $this->_bas_curncy_code, $this->_cur_curncy_code);
            $prices['price'] = Mage::helper('directory')->currencyConvert($prices['price'], $this->_bas_curncy_code, $this->_cur_curncy_code);
        }
        return $prices;
    }

    public function addImageToResult($product,$prices){
        $imageUrl = (string)$product->getMediaConfig()->getMediaUrl($product->getData('image'));
        $imageTmpArr = explode('.', $imageUrl);
        $countImgArr = count($imageTmpArr);
        if (empty($imageUrl) || $imageUrl == '' || !isset($imageUrl) || $countImgArr < 2) {
            $imageUrl = (string)Mage::helper('catalog/image')->init($product, 'image');
        }
        $prices['image_url'] = $imageUrl;

        return $prices;
    }

    public function addStockInfoToResult($product,$product_result){
        $inventoryStatus = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
        if (!empty($inventoryStatus)) {
            $product_result['quantity'] = (int)$inventoryStatus->getQty();
            $product_result['is_in_stock'] = $inventoryStatus->getIsInStock() == '1' ? 1 : 0;
        }
        return $product_result;
    }

    public function getRewrittenProductUrl($productObject, $categoryId, $storeId)
    {
        $productId = $productObject->getId();
        $rewrite = Mage::getSingleton('core/url_rewrite');
        $idPath = sprintf('product/%d', $productId);
        if ($categoryId) {
            $idPath = sprintf('%s/%d', $idPath, $categoryId);
        }
        $rewrite->loadByIdPath($idPath);
        return $rewrite->getRequestPath();
    }


}

