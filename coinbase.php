<?php
/**
 * @package		 CrowdFunding
 * @subpackage	 Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2010 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * CrowdFunding is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */

// no direct access
defined('_JEXEC') or die;

jimport('joomla.plugin.plugin');

/**
 * CrowdFunding Coinbase Payment Plugin
 *
 * @package		CrowdFunding
 * @subpackage	Plugins
 */
class plgCrowdFundingPaymentCoinbase extends JPlugin {
    
    public function onProjectPayment($context, $item) {
        
        $app = JFactory::getApplication();
        /** @var $app JSite **/

        if($app->isAdmin()) {
            return;
        }

        $doc     = JFactory::getDocument();
        /**  @var $doc JDocumentHtml **/
        
        // Check document type
        $docType = $doc->getType();
        if(strcmp("html", $docType) != 0){
            return;
        }
       
        if(strcmp("com_crowdfunding.payment", $context) != 0){
            return;
        }
        
        // Load language
        $this->loadLanguage();
        
        $html  =  "";
        $html .= '<h4>'.JText::_("PLG_CROWDFUNDINGPAYMENT_COINBASE_TITLE").'</h4>';
        $html .= '<p>'.JText::_("PLG_CROWDFUNDINGPAYMENT_COINBASE_INFO").'</p>';
        
        $userId = JFactory::getUser()->id;
        
        // Custom data
        $custom = array(
            "project_id" =>  $item->id,
            "reward_id"  =>  $item->rewardId,
            "user_id"    =>  $userId,
            "gateway"	 =>  "Coinbase"
        );
        
        $custom = base64_encode( json_encode($custom) );
        
        $title  = htmlentities($item->title, ENT_QUOTES, "UTF-8");
        
        // Button options
        $options  = array();
        
        // Button type
        if($this->params->get("coinbase_button_type")) {
            $options["type"] = $this->params->get("coinbase_button_type");
        }
        
        // Button style
        $customStyle = $this->params->get("coinbase_button_style");
        if(!empty($customStyle)) {
            if( false !== strcmp("custom", $customStyle) ) {
                if($this->params->get("coinbase_button_text")) {
                    $options["style"] = $this->params->get("coinbase_button_style");
                    $options["text"]  = $this->params->get("coinbase_button_text");
                }
            } else {
                $options["style"] = $this->params->get("coinbase_button_style");
            }
        }
        
        // Send request for button
        jimport("itprism.bitcoin.coinbase.Coinbase");
        $coinbase = new Coinbase($this->params->get("coinbase_api_key"));
        if(!empty($options)) {
            $response = $coinbase->createButton($title, $item->amount, $item->currencyCode, $custom, $options);
        } else { // Get default
            $response = $coinbase->createButton($title, $item->amount, $item->currencyCode, $custom);
        }
        
        $html .= $response->embedHtml;
        
        if($this->params->get('coinbase_test_mode', 1)) {
            $html .= '<p class="sticky">'.JText::_("PLG_CROWDFUNDINGPAYMENT_COINBASE_WORKS_TEST_MODE").'</p>';
            $html .= '<label>'.JText::_("PLG_CROWDFUNDINGPAYMENT_COINBASE_TEST_CUSTOM_STRING").'</label>';
            $html .= '<input type="test" name="test_custom_string" value="'.$custom.'" class="span12"/>';
        }
        
        return $html;
    }
    
    /**
     * 
     * Enter description here ...
     * @param array 	$post	This is _POST variable
     * @param JRegistry $params	The parameters of the component
     */
    public function onPaymenNotify($context, $post, $params) {
        
        $app = JFactory::getApplication();
        /** @var $app JSite **/
        
        if($app->isAdmin()) {
            return;
        }

        $doc     = JFactory::getDocument();
        /**  @var $doc JDocumentHtml **/
        
        // Check document type
        $docType = $doc->getType();
        if(strcmp("raw", $docType) != 0){
            return;
        }
       
        if(strcmp("com_crowdfunding.notify", $context) != 0){
            return;
        }
        
        // Get data from PHP input
        $jsonData = file_get_contents('php://input');
        $post     = json_decode($jsonData, true);
        if(!empty($post)) {
            $post = JArrayHelper::getValue($post, "order");
        }
        
        // Set the data that will be used for testing Instant Payment Notifications
        // This works when the extension is in test mode.
        if($this->params->get("coinbase_test_mode", 1)) {
            $post["custom"]             = $this->params->get("coinbase_test_custom_string");
            $post["total_btc"]["cents"] = $this->params->get("coinbase_test_amount", 1);
        }
        
        // Verify gateway. Is it Coinbase? 
        if(!$this->isCoinbaseGateway($post)) {
            return null;
        }
        
        // Load language
        $this->loadLanguage();
            
        $result = array(
            "project"     => null,
            "reward"      => null,
            "transaction" => null
        );
        
        // Get extension parameters
        jimport("crowdfunding.currency");
        $currencyId      = $params->get("project_currency");
        $currency        = CrowdFundingCurrency::getInstance($currencyId);
        
        // Validate transaction data
        $validData = $this->validateData($post, $currency->abbr);
        if(is_null($validData)) {
            return $result;
        }
        
        // Check for valid project
        jimport("crowdfunding.project");
        $projectId = JArrayHelper::getValue($validData, "project_id");
        
        $project   = CrowdFundingProject::getInstance($projectId);
        if(!$project->id) {
            $error  = JText::_("PLG_CROWDFUNDINGPAYMENT_COINBASE_ERROR_INVALID_PROJECT");
            $error .= "\n". JText::sprintf("PLG_CROWDFUNDINGPAYMENT_COINBASE_TRANSACTION_DATA", var_export($validData, true));
			JLog::add($error);
			return $result;
        }
        
        // Set the receiver of funds
        $validData["receiver_id"] = $project->user_id;
        
        // Validate and Update distributed value of the reward
        $rewardId  = JArrayHelper::getValue($validData, "reward_id");
        $reward    = null;
        if(!empty($rewardId)) {
            $reward = $this->updateReward($validData);
        }
    
        // Save transaction data
        $this->save($validData, $project);
        
        //  Prepare the data that will be returned
        
        $result["transaction"] = JArrayHelper::toObject($validData);
        
        // Generate object of data based on the project properties
        $properties            = $project->getProperties();
        $result["project"]     = JArrayHelper::toObject($properties);
        
        // Generate object of data based on the reward properties
        if(!empty($reward)) {
            $properties        = $reward->getProperties();
            $result["reward"]  = JArrayHelper::toObject($properties);
        }
        
        return $result;
                
    }
    
	/**
     * Validate transaction
     * @param array $data
     */
    protected function validateData($data, $currency) {
        
        // Prepare transaction data
        $custom    = JArrayHelper::getValue($data, "custom");
        $custom    = json_decode( base64_decode($custom), true );
        
        $cbTransaction = JArrayHelper::getValue($data, "transaction");
        $cbTotalBtc    = JArrayHelper::getValue($data, "total_btc");
        
        $created       = JArrayHelper::getValue($data, "created_at");
        $date = new JDate($created);
        
        // Prepare transaction data
        $transaction = array(
            "investor_id"		     => JArrayHelper::getValue($custom, "user_id", 0, "int"),
            "project_id"		     => JArrayHelper::getValue($custom, "project_id", 0, "int"),
            "reward_id"			     => JArrayHelper::getValue($custom, "reward_id", 0, "int"),
        	"service_provider"       => "Coinbase",
        	"txn_id"                 => JArrayHelper::getValue($cbTransaction, "id"),
        	"txn_amount"		     => JArrayHelper::getValue($cbTotalBtc, "cents"),
            "txn_currency"           => JArrayHelper::getValue($cbTotalBtc, "currency_iso"),
            "txn_status"             => strtolower( JArrayHelper::getValue($data, "status") ),
            "txn_date"               => $date->toSql()
        ); 
        
        // Check User Id, Project ID and Transaction ID
        if(!$transaction["investor_id"] OR !$transaction["project_id"] OR !$transaction["txn_id"]) {
            $error  = JText::_("PLG_CROWDFUNDINGPAYMENT_ERROR_INVALID_TRANSACTION_DATA");
            $error .= "\n". JText::sprintf("PLG_CROWDFUNDINGPAYMENT_TRANSACTION_DATA", var_export($transaction, true));
            JLog::add($error);
            return null;
        }
        
        // Check currency
        if(strcmp($transaction["txn_currency"], $currency) != 0) {
            $error  = JText::_("PLG_CROWDFUNDINGPAYMENT_ERROR_INVALID_TRANSACTION_CURRENCY");
            $error .= "\n". JText::sprintf("PLG_CROWDFUNDINGPAYMENT_TRANSACTION_DATA", var_export($transaction, true));
            JLog::add($error);
            return null;
        }
        
        return $transaction;
    }
    
    protected function updateReward(&$data) {
        
        $db     = JFactory::getDbo();
        
        jimport("crowdfunding.reward");
        $reward = new CrowdFundingReward($db);
        $keys   = array(
        	"id"         => $data["reward_id"], 
        	"project_id" => $data["project_id"]
        );
        $reward->load($keys);
        
        // Check for valid reward
        if(!$reward->id) {
            $error  = JText::_("PLG_CROWDFUNDINGPAYMENT_ERROR_INVALID_REWARD");
            $error .= "\n". JText::sprintf("PLG_CROWDFUNDINGPAYMENT_TRANSACTION_DATA", var_export($data, true));
			JLog::add($error);
			
			$data["reward_id"] = 0;
			return null;
        }
        
        // Check for valida amount between reward value and payed by user
        $txnAmount = JArrayHelper::getValue($data, "txn_amount");
        
        if($txnAmount < $reward->amount) {
            $error  = JText::_("PLG_CROWDFUNDINGPAYMENT_ERROR_INVALID_REWARD_AMOUNT");
            $error .= "\n". JText::sprintf("PLG_CROWDFUNDINGPAYMENT_TRANSACTION_DATA", var_export($data, true));
			JLog::add($error);
			
			$data["reward_id"] = 0;
			return null;
        }
        
        if($reward->isLimited() AND !$reward->getAvailable()) {
            $error  = JText::_("PLG_CROWDFUNDINGPAYMENT_ERROR_REWARD_NOT_AVAILABLE");
            $error .= "\n". JText::sprintf("PLG_CROWDFUNDINGPAYMENT_TRANSACTION_DATA", var_export($data, true));
			JLog::add($error);
			
			$data["reward_id"] = 0;
			return null;
        }
        
        // Increase the number of distributed rewards 
        // if there is a limit.
        if($reward->isLimited()) {
            $reward->increaseDistributed();
            $reward->store();
        }
        
        return $reward;
    }
    
    /**
     * 
     * Save transaction
     * @param array $data
     */
    public function save($data, $project) {
        
        // Save data about donation
        $db     = JFactory::getDbo();
        
        jimport("crowdfunding.transaction");
        $transaction = new CrowdFundingTransaction($db);
        $transaction->bind($data);
        $transaction->store();
        
        // Update project funded amount
        $amount = JArrayHelper::getValue($data, "txn_amount");
        $project->addFunds($amount);
        $project->store();
    }
    
    private function isCoinbaseGateway($post) {
        
        $custom         = JArrayHelper::getValue($post, "custom");
        $custom         = json_decode( base64_decode($custom), true );
        $paymentGateway = JArrayHelper::getValue($custom, "gateway");

        if(strcmp("Coinbase", $paymentGateway) != 0 ) {
            return false;
        }
        
        return true;
    }
    
}