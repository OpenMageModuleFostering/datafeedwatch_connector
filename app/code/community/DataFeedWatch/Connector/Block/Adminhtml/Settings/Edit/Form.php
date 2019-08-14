<?php

class DataFeedWatch_Connector_Block_Adminhtml_Settings_Edit_Form extends Mage_Adminhtml_Block_Widget_Form{

    protected function _prepareForm()
    {

        $form = new Varien_Data_Form(array(
            'id' => 'edit_form',
            'method' => 'post'
        ));

        $form->setUseContainer(true);

        $this->setForm($form);

        $fieldset = $form->addFieldset('attributes', array(
            'legend' =>Mage::helper('connector')->__('Select Attributes')
        ));

        $data = array();

        $required = Mage::helper('connector')->getRequiredAttributes();
        $additional = array();
        if(Mage::getStoreConfig('datafeedwatch/settings/attributes')){
            $additional = Zend_Serializer::unserialize(Mage::getStoreConfig('datafeedwatch/settings/attributes'));
        }
        $data["required_attributes"] = array_keys($required);
        $data["additional_attributes"] = $additional;


        $fieldset->addField('required_attributes', 'checkboxes',array(
            "label" => Mage::helper('connector')->__("Required Attributes"),
            "required" => false,
            "options" => $required,
            "name" => "required_attributes[]",
            "disabled" => array_keys($required),
            "ignore" => true
        ));

        $attributesList = array('0_all'=>'Select/Unselect all');

        $attributesList = array_merge($attributesList,Mage::helper('connector')->getAttributesList());

        $fieldset->addField('additional_attributes', 'checkboxes',array(
            "label" => Mage::helper('connector')->__("Optional Attributes"),
            "required" => false,
            "options" => $attributesList,
            "name" => "additional_attributes[]"
        ));



        $form->setValues($data);

        return parent::_prepareForm();
    }
}