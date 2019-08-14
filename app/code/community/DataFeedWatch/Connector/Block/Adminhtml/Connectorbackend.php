<?php  

class DataFeedWatch_Connector_Block_Adminhtml_Connectorbackend extends Mage_Adminhtml_Block_Template {
	protected $email = 'magento@datafeedwatch.com';

	public function __construct() {
		parent::__construct();
		$this->assign('user', $this->getUser());
	}

	public function getCreateUserUrl() {
		return $this->getUrl('*/*/createuser');
	}

    public function getUser() {
        $model = Mage::getModel('api/user');
        return $model->load($this->email, 'email');
    }

    public function getRedirectUrl() {
        return $this->getUrl('*/*/redirect');
    }
}
