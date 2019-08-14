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
                foreach($collection as $product)
		{
                    echo '<pre>'; print_r($product); 
                    echo 'And images are <br>'; 
                    $imageUrl = $product->getImage();
                    print_r($imageUrl);
                    echo '<br> second';
                    $imageUrl = $product->getMediaConfig()->getMediaUrl($product->getData('image'));
                    print_r($imageUrl);
                }
                die('nothing found');
	}

	
}
