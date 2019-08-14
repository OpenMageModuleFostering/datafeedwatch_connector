<?php
class DataFeedWatch_Connector_Adminhtml_ConnectorbackendController extends Mage_Adminhtml_Controller_Action {
	protected $username = 'datafeedwatch';
	protected $firstname = 'Api Access';
	protected $lastname = 'DataFeedWatch';
	protected $email = 'magento@datafeedwatch.com';
	protected $register_url = 'https://my.datafeedwatch.com/platforms/magento/sessions/finalize';

	public function indexAction() {
		$this->loadLayout();
		$this->_title($this->__("DataFeedWatch"));
		$this->renderLayout();
	}

	public function createuserAction() {
		$api_key = $this->_generateApiKey();

		$data = array(
			'username' => $this->username,
			'firstname' => $this->firstname,
			'lastname' => $this->lastname,
			'email' => $this->email,
			'api_key' => $api_key,
			'api_key_confirmation' => $api_key,
			'is_active' => 1
		);

		$role = Mage::getModel('api/roles')->load($this->lastname, 'role_name');

		if ($role->isObjectNew()) {
			$role = $role
				->setName($this->lastname)
				->setPid(false)
				->setRoleType('G')
				->save();

			$resource = array("all");

			Mage::getModel("api/rules")
				->setRoleId($role->getId())
				->setResources($resource)
				->saveRel();
		}


		$user = Mage::getModel('api/user');
		$user->setData($data);
		$user->save();

		$user->setRoleId($role->getId())->setUserId($user->getId());
		$user->add();

		$this->getResponse()->setRedirect($this->_registerUrl($api_key));
		return;
	}

	public function updatetokenAction() {
		$api_key = $this->_generateApiKey();
		$model = $this->getUser();

		$data = array(
			'user_id' => $model->getId(),
			'username' => $this->username,
			'firstname' => $this->firstname,
			'lastname' => $this->lastname,
			'email' => $this->email,
			'api_key' => $api_key,
			'api_key_confirmation' => $api_key
		);

		$model->setData($data);
		$model->save();

		$this->getResponse()->setRedirect($this->_registerUrl($api_key));
		return;
	}

	public function getUser() {
		$model = Mage::getModel('api/user');
		return $model->load($this->email, 'email');
	}

	private function _generateApiKey() {
		return sha1(time()+substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 32));
	}

	private function _registerUrl($api_key) {
		return $this->register_url.'?shop='.Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB).'&token='.$api_key;
	}
}
