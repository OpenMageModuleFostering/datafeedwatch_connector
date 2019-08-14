<?php
    
    class DataFeedWatch_Connector_Model_Datafeedwatch_Api extends Mage_Catalog_Model_Product_Api {
        
        const STOCK_ITEM_MODEL = 'cataloginventory/stock_item';
        const CATALOG_PRODUCT_MODEL = 'catalog/product';
        
        // category
        const CATALOG_CATEGORY_MODEL = 'catalog/category';
        const CATEGORY_NAME_FIELD = 'name';
        const CATEGORY_SEPARATOR = ' > ';
        
        public function __construct() {
            $this->categories = array();
            ini_set('memory_limit','1024M');
        }
        
        public function version() {
            return "0.2.4";  // this needs to be updated in etc/config.xml as well
        }
        
        public function product_count($options = array()) {
            $collection = Mage::getModel(self::CATALOG_PRODUCT_MODEL)
            ->getCollection();
            
            if (array_key_exists('store', $options)) {
                $collection->addStoreFilter($this->_getStoreId($options['store']));
            }
            
            $apiHelper = Mage::helper('api');
            $filters = $apiHelper->parseFilters($options, $this->_filtersMap);
            try {
                foreach ($filters as $field => $value) {
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
        
        public function products($options = array()) {
            
            if (!array_key_exists('page', $options)) {
                $options['page'] = 1;
            }
            
            if (!array_key_exists('per_page', $options)) {
                $options['per_page'] = 100;
            }
            
            $collection = Mage::getModel(self::CATALOG_PRODUCT_MODEL)
            ->getCollection()
            ->addAttributeToSelect('*')
            ->setPage($options['page'], $options['per_page']);
            
            if (array_key_exists('store', $options)) {
                $collection->addStoreFilter($this->_getStoreId($options['store']));
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
            $filters = $apiHelper->parseFilters($options, $this->_filtersMap);
            try {
                foreach ($filters as $field => $value) {
                    $collection->addFieldToFilter($field, $value);
                }
            } catch (Mage_Core_Exception $e) {
                $this->_fault('filters_invalid', $e->getMessage());
            }
            
            $result = array();
            $product_cache = array();
            $price_keys = array('price', 'special_price');
            
            foreach ($collection as $product) {
                $product_result = array(// Basic product data
                                        'product_id' => $product->getId(),
                                        'sku' => $product->getSku()
                                        );
                
                $parent_id = '0';
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
                        $parent_id = Mage::getModel('catalog/product')->load($parentIds[0])->getId();
                    }
                }
                
                $product_result['parent_id'] = $parent_id;
                
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
                
                $product = Mage::getModel('catalog/product')->load($product->getId());
                //            $imageUrl = $product->getImage();
                //            if(empty($imageUrl)) {
                //                $product = Mage::getModel('catalog/product')->load($product->getId());
                //                $imageUrl = $product->getImage();
                //            }
                //
                //            if(empty($imageUrl) || $imageUrl == '') {
                //                $imageUrl = $product->getMediaConfig()->getMediaUrl($product->getData('image'));
                //                if(empty($imageUrl) || $imageUrl == '') {
                //                    $imageUrl = Mage::helper('catalog/image')->init($product, 'image');
                //                }
                //            } else {
                //                $imageUrl = $imageBaseURL . $imageUrl;
                //            }
                $imageUrl = (string)$product->getMediaConfig()->getMediaUrl($product->getData('image'));
                $imageTmpArr = explode('.', $imageUrl);
                $countImgArr = count($imageTmpArr);
                if(empty($imageUrl) || $imageUrl == '' || !isset($imageUrl) || $countImgArr < 2) {
                    $imageUrl = (string) Mage::helper('catalog/image')->init($product, 'image');
                }
                
                $product_result['product_url'] = $baseUrl . $product->getUrlPath();
                $product_result['image_url'] = $imageUrl;
                
                $tmpPrices = array();
                if($parent_id && $configrable && $product->getVisibility() == 1) {
                    $tmpPrices = $this->getDisplayPrice($parent_id);
                    
                } else {
                    $tmpPrices = $this->getDisplayPrice($product->getId());
                }
                if(count($tmpPrices)) {
                   foreach($tmpPrices as $key=>$value) {
                        $product_result[$key] = $value;
                    }
                }
                
                
                $inventoryStatus = Mage::getModel(self::STOCK_ITEM_MODEL)->loadByProduct($product);
                if (!empty($inventoryStatus)) {
                    $product_result['quantity'] = (int) $inventoryStatus->getQty();
                    $product_result['is_in_stock'] = $inventoryStatus->getIsInStock() == '1' ? 1 : 0;
                }
                
                
                
                $result[] = $product_result;
            }
            return $result;
        }
        
        private function _loadCategories() {
            $parentId = 1;
            
            /* @var $tree Mage_Catalog_Model_Resource_Eav_Mysql4_Category_Tree */
            $tree = Mage::getResourceSingleton('catalog/category_tree')->load();
            $root = $tree->getNodeById($parentId);
            
            if ($root && $root->getId() == 1) {
                $root->setName(Mage::helper('catalog')->__('Root'));
            }
            
            $collection = Mage::getModel('catalog/category')->getCollection()
            ->setStoreId($this->_getStoreId(null))
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
        private function _nodeToArray(Varien_Data_Tree_Node $node) {
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
        
        private function _buildCategoryPath($category_id, &$path = array()) {
            $category = $this->categories[$category_id];
            
            if ($category['parent_id'] != '0') {
                $this->_buildCategoryPath($category['parent_id'], $path);
            }
            
            if ($category['is_active'] == '1') {
                $path[] = $category['name'];
            }
            
            return $path;
        }
        
        private function _notNeededFields() {
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
                         'conopy_diameter' => 0,
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
        
        private function getDisplayPrice($product_id = 0) {
            
            if ($product_id < 1) {
                return 0;
            }
            $prices = array();
            
            $product = Mage::getModel('catalog/product')->load($product_id);
            $store_code = Mage::app()->getStore()->getCode();
            $_taxHelper = Mage::helper('tax');
            // Get Currency Code
            $bas_curncy_code = Mage::app()->getStore()->getBaseCurrencyCode();
            $cur_curncy_code = Mage::app()->getStore($store_code)->getCurrentCurrencyCode();
            
            $allowedCurrencies = Mage::getModel('directory/currency')
            ->getConfigAllowCurrencies();
            $currencyRates = Mage::getModel('directory/currency')
            ->getCurrencyRates($bas_curncy_code, array_values($allowedCurrencies));
            
            $prices['price_with_tax']  = $_finalPriceInclTax = $_taxHelper->getPrice($product, $product->getPrice(), 2);//$product['price'];
            $prices['price'] = $_taxHelper->getPrice($product, $product->getPrice(), NULL);
            $prices['special_price'] = 0;
            $prices['special_price_with_tax'] = 0;
            $prices['special_from_date'] = '';
            $prices['special_to_date'] = '';
            
            $prices['description'] = $product->getDescription();
            $prices['short_description'] = $product->getShortDescription();
            
            $baseUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
            $prices['product_url'] = $baseUrl . $product->getUrlPath();
            //Setting image
            $imageUrl = (string)$product->getMediaConfig()->getMediaUrl($product->getData('image'));
            $imageTmpArr = explode('.', $imageUrl);
            $countImgArr = count($imageTmpArr);
            if(empty($imageUrl) || $imageUrl == '' || !isset($imageUrl) || $countImgArr < 2) {
                $imageUrl = (string) Mage::helper('catalog/image')->init($product, 'image');
            }
                
            $prices['image_url'] = $imageUrl;
            
            $specialTmpPrice = $product->getSpecialPrice();
            
            if ($specialTmpPrice && (strtotime(date('Y-m-d H:i:s')) < strtotime($product['special_to_date'])
                                     || empty($product['special_to_date']))) {
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
                                                 || empty($product['special_to_date']))) {
                    $prices['special_price_with_tax'] = Mage::helper('directory')->currencyConvert($prices['special_price_with_tax'], $bas_curncy_code, $cur_curncy_code);
                    $prices['special_price'] = Mage::helper('directory')->currencyConvert($prices['special_price'], $bas_curncy_code, $cur_curncy_code);
                }
                
                $prices['price_with_tax'] = Mage::helper('directory')->currencyConvert($_finalPriceInclTax, $bas_curncy_code, $cur_curncy_code);
                $prices['price'] = Mage::helper('directory')->currencyConvert($prices['price'], $bas_curncy_code, $cur_curncy_code);
            }
            
            // categories
                $category_id = $product->getCategoryIds();
                if (empty($category_id)) {
                    $prices['category_name'] = '';
                    $prices['category_parent_name'] = '';
                    $prices['category_path'] = '';
                } else {
                    rsort($category_id);
                    $category = $this->categories[$category_id[0]];
                    $prices['category_name'] = $category['name'];
                    $prices['category_parent_name'] = $this->categories[$category['parent_id']]['name'];
                    $prices['category_path'] = implode(' > ', $this->_buildCategoryPath($category['category_id']));
                }
                
            return $prices;
        }
        
        
        
        
        
    }
