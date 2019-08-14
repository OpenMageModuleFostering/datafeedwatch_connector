<?php

class DataFeedWatch_Connector_TestController extends Mage_Core_Controller_Front_Action {

    public function indexAction() {

        $this->loadLayout();
        $this->_title($this->__("DataFeedWatch"));
        $this->renderLayout();

        $collection = Mage::getModel('catalog/product')
                ->getCollection()
                ->addAttributeToSelect('*')
                ->setPage(1, 20);
        $productsAtts = array();
        foreach ($collection as $product) {
            $attributes = $product->getAttributes();
            //$attrs = $product->getTypeInstance(true)->getConfigurableAttributesAsArray($product);
            foreach ($attributes as $attribute) {
                if ($attribute->getIsUserDefined()) {  //&& $attribute->getIsUserDefined()
                    $value = $product->getData($attribute->getAttributeCode());
                    if (!empty($value)) {
                        $value = trim($attribute->getFrontend()->getValue($product));
                    }
                    $productsAtts[$product->getId()][$attribute->getAttributeCode()] = $value;
                } 
            }
        }
        echo '<pre>';
        print_r($productsAtts);
        die('nothing found');
    }

}
