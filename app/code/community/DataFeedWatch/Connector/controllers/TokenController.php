<?php
class DataFeedWatch_Connector_TokenController extends Mage_Core_Controller_Front_Action {

    /**
     *reachable by
     * http://magentostore.com/datafeedwatch/token/confirm/hash/b271ecf10045d888245202b3268a72fb/new_api_key/yournewkey
     */
    public function confirmAction()
    {
        $request = $this->getRequest();
        if(!$request->isPost()){
            Mage::log(__METHOD__.' - not sent through POST');
        } else {
            $params = $request->getParams();
            if(array_key_exists('hash',$params) && array_key_exists('new_api_key',$params)){
                $matchingUser = Mage::getModel('api/user')->load($params['hash'],'dfw_connect_hash');
                if(is_object($matchingUser) && $matchingUser->getId()>0){
                    $matchingUser->setApiKey($params['new_api_key']);
                    $matchingUser->save();
                } else {
                    Mage::log(__METHOD__.' - no matching user found');
                }
            } else {
                Mage::log(__METHOD__.' - params are missing');
            }
        }
    }
}