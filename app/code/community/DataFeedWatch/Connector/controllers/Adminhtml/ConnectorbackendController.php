<?php
class DataFeedWatch_Connector_Adminhtml_ConnectorbackendController extends Mage_Adminhtml_Controller_Action {
	protected $username = 'datafeedwatch';
	protected $firstname = 'Api Access';
	protected $lastname = 'DataFeedWatch';
	protected $email = 'magento@datafeedwatch.com';
	protected $register_url = 'https://my.datafeedwatch.com/platforms/magento/sessions/finalize';
    protected $redirect_url = 'https://my.datafeedwatch.com/';

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
			'api_key' => '',
			'api_key_confirmation' => '',
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

        //add new hash
        $user = Mage::getModel('api/user')->load($user->getUserId());
        $hash = md5($user->getEmail().$user->getCreated());
        $user->setDfwConnectHash($hash);
        $user->save();
        Mage::log($user->getData());

        $this->getResponse()->setRedirect($this->_registerUrl($api_key,$user->getData('dfw_connect_hash')));
		return;
	}

    public function redirectAction(){
        $this->getResponse()->setRedirect($this->redirect_url);
        return;
    }

	public function updatetokenAction() {
		$api_key = $this->_generateApiKey();
		$model = $this->getUser();

        $hash = $model->getDfwConnectHash();

		$data = array(
			'user_id' => $model->getId(),
			'username' => $this->username,
			'firstname' => $this->firstname,
			'lastname' => $this->lastname,
			'email' => $this->email,
			'api_key' => '',
			'api_key_confirmation' => ''
		);

        $model->setData($data);
		$model->save();

		$this->getResponse()->setRedirect($this->_registerUrl($api_key,$hash));
		return;
	}

	public function getUser() {
		$model = Mage::getModel('api/user');
		return $model->load($this->email, 'email');
	}

	private function _generateApiKey() {
		return sha1(time()+substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 32));
	}

	private function _registerUrl($api_key,$hash) {

		return $this->register_url.'?shop='.Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB).'&token='.$api_key.'&hash='.$hash;
	}
}
