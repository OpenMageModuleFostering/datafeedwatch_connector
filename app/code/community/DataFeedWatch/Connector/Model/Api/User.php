<?php
/**
 * Created by PhpStorm.
 * User: hexacode
 * Date: 5/19/14
 * Time: 9:24 AM
 */

class DataFeedWatch_Connector_Model_Api_User extends Mage_Api_Model_User {

    /**
     * @deprecated since 0.2.9
     * @return Mage_Api_Model_User|Mage_Core_Model_Abstract
     */
    public function save()
    {
        return parent::save();
        /*$this->_beforeSave();
        $data = array(
            'firstname' => $this->getFirstname(),
            'lastname'  => $this->getLastname(),
            'email'     => $this->getEmail(),
            'modified'  => Mage::getSingleton('core/date')->gmtDate(),
        );

        if ($this->getId() > 0) {
            $data['user_id']   = $this->getId();
        }

        if ( $this->getUsername() ) {
            $data['username']   = $this->getUsername();
        }

        if ($this->getApiKey()) {
            $data['api_key']   = $this->_getEncodedApiKey($this->getApiKey());
        }

        if ($this->getNewApiKey()) {
            $data['api_key']   = $this->_getEncodedApiKey($this->getNewApiKey());
        }

        if ( !is_null($this->getIsActive()) ) {
            $data['is_active']  = intval($this->getIsActive());
        }

        //if ( $this->getDfwConnectHash() ) {
//            $data['dfw_connect_hash']   = $this->getDfwConnectHash();
//        }


        $this->setData($data);
        $this->_getResource()->save($this);
        $this->_afterSave();
        return $this;
        */
    }
}