<?php
/**
 * @package		 CrowdFunding
 * @subpackage	 Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2014 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// no direct access
defined('_JEXEC') or die;

jimport('crowdfunding.payment.plugin');

/**
 * CrowdFunding Coinbase payment plugin
 *
 * @package		CrowdFunding
 * @subpackage	Plugins
 */
class plgCrowdFundingPaymentCoinbase extends CrowdFundingPaymentPlugin {
    
    protected   $paymentService = "coinbase";
    
    protected   $textPrefix   = "PLG_CROWDFUNDINGPAYMENT_COINBASE";
    protected   $debugType    = "COINBASE_PAYMENT_PLUGIN_DEBUG";
    
    public function onProjectPayment($context, $item) {
        
        if(strcmp("com_crowdfunding.payment", $context) != 0){
            return;
        }
        
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
       
        // Load language
        $this->loadLanguage();
        
        // This is a URI path to the plugin folder
        $pluginURI = "plugins/crowdfundingpayment/coinbase";
        
        $html  =  array();
        $html[] = '<h4><img src="'.$pluginURI.'/images/coinbase_icon.png" width="38" height="32" /> '.JText::_($this->textPrefix."_TITLE").'</h4>';
        $html[] = '<p>'.JText::_($this->textPrefix."_INFO").'</p>';
        
        // Check for valid API key
        $apiKey     = JString::trim($this->params->get("coinbase_api_key"));
        $secretKey  = JString::trim($this->params->get("coinbase_secret_key"));
        if(!$apiKey OR !$secretKey) {
            $html[] = '<div class="alert">'.JText::_($this->textPrefix."_ERROR_PLUGIN_NOT_CONFIGURED").'</div>';
            
            return implode("\n", $html);
        }
        
        // Get intention
        $userId  = JFactory::getUser()->id;
        $aUserId = $app->getUserState("auser_id");

        $intention = $this->getIntention(array(
            "user_id"       => $userId,
            "auser_id"      => $aUserId,
            "project_id"    => $item->id
        ));
        
        // Custom data
        $custom = array(
            "intention_id" =>  $intention->getId(),
            "gateway"	   =>  "Coinbase"
        );
        
        $custom = base64_encode(json_encode($custom));
        
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
                    $options["text"]  = addslashes(htmlspecialchars($this->params->get("coinbase_button_text"), ENT_COMPAT, 'UTF-8'));
                }
            } else {
                $options["style"] = $this->params->get("coinbase_button_style");
            }
        }
        
        // Return URL
        $returnUrl = JString::trim($this->params->get("coinbase_return_url"));
        if(!empty($returnUrl)) {
            $options["success_url"] = $returnUrl;
        }
        
        // Cancel URL
        $cancelUrl = JString::trim($this->params->get("coinbase_cancel_url"));
        if(!empty($cancelUrl)) {
            $options["cancel_url"] = $cancelUrl;
        }
        
        // Set auto-redirect option.
        $options["auto_redirect"] = (bool)$this->params->get("coinbase_auto_redirect", 1);
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_CREATE_BUTTON_OPTIONS"), $this->debugType, $options) : null;
        
        // Send request for button
        jimport("itprism.payment.coinbase.Coinbase");
        $coinbase = Coinbase::withApiKey($apiKey, $secretKey);
        
        // DEBUG DATA
        $coinbase_ = @var_export($coinbase, true);
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_CREATE_BUTTON_OBJECT"), $this->debugType, $coinbase_) : null;
        
        if(!empty($options)) {
            $response = $coinbase->createButton($title, $item->amount, $item->currencyCode, $custom, $options);
        } else { // Get default
            $response = $coinbase->createButton($title, $item->amount, $item->currencyCode, $custom);
        }
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_CREATE_BUTTON_OBJECT_RESPONSE"), $this->debugType, $response) : null;
        
        $html[] = $response->embedHtml;
        
        if($this->params->get('coinbase_test_mode', 1)) {
            $html[] = '<p class="sticky">'.JText::_($this->textPrefix."_WORKS_TEST_MODE").'</p>';
            $html[] = '<label>'.JText::_($this->textPrefix."_TEST_CUSTOM_STRING").'</label>';
            $html[] = '<input type="test" name="test_custom_string" value="'.$custom.'" class="span12"/>';
        }
        
        return implode("\n", $html);
    }
    
    /**
     * 
     * This method processes transaction.
     * 
     * @param string 	$context	This string gives information about that where it has been executed the trigger.
     * @param JRegistry $params	    The parameters of the component
     */
    public function onPaymenNotify($context, $params) {
        
        if(strcmp("com_crowdfunding.notify.coinbase", $context) != 0){
            return;
        }
        
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
       
        // Get data from PHP input
        $jsonData = file_get_contents('php://input');
        $post     = json_decode($jsonData, true);
        
        // Load language
        $this->loadLanguage();
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_RESPONSE"), $this->debugType, $_POST) : null;
        
        if(!empty($post)) {
            $post = JArrayHelper::getValue($post, "order");
        }
        
        // Set the data that will be used for testing Instant Payment Notifications
        // This works when the extension is in test mode.
        if($this->params->get("coinbase_test_mode", 1)) {
            $post["custom"]             = JString::trim($this->params->get("coinbase_test_custom_string"));
            $post["total_btc"]["cents"] = JString::trim($this->params->get("coinbase_test_amount", 1));
        }
        
        $custom         = JArrayHelper::getValue($post, "custom");
        $custom         = json_decode( base64_decode($custom), true );
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_CUSTOM"), $this->debugType, $custom) : null;
        
        // Verify gateway. Is it Coinbase? 
        if(!$this->isCoinbaseGateway($custom)) {
            
            $this->log->add(
                JText::_($this->textPrefix."_ERROR_INVALID_PAYMENT_GATEWAY"),
                $this->debugType,
                array("custom" => $custom, "_POST" => $_POST)
            );
            
            return null;
        }
        
        $result = array(
        	"project"          => null, 
        	"reward"           => null, 
        	"transaction"      => null,
            "payment_service"  => "Coinbase"
        );
        
        // Get extension parameters
        jimport("crowdfunding.currency");
        $currencyId      = $params->get("project_currency");
        $currency        = CrowdFundingCurrency::getInstance(JFactory::getDbo(), $currencyId);
        
        // Get intention data
        $intentionId     = JArrayHelper::getValue($custom, "intention_id", 0, "int");
        
        jimport("crowdfunding.intention");
        $intention = new CrowdFundingIntention($intentionId);
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_INTENTION"), $this->debugType, $intention->getProperties()) : null;
        
        // Validate transaction data
        $validData = $this->validateData($post, $currency->getAbbr(), $intention);
        if(is_null($validData)) {
            return $result;
        }
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_VALID_DATA"), $this->debugType, $validData) : null;
        
        // Get project
        jimport("crowdfunding.project");
        $projectId = JArrayHelper::getValue($validData, "project_id");
        $project   = CrowdFundingProject::getInstance($projectId);
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_PROJECT_OBJECT"), $this->debugType, $project->getProperties()) : null;
        
        // Check for valid project
        if(!$project->getId()) {
            
            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix."_ERROR_INVALID_PROJECT"),
                $this->debugType,
                $validData
            );
            
			return $result;
        }
        
        // Set the receiver of funds
        $validData["receiver_id"] = $project->getUserId();
        
        // Save transaction data.
        // If it is not completed, return empty results.
        // If it is complete, continue with process transaction data
        if(!$this->storeTransaction($validData, $project)) {
            return $result;
        }
        
        // Validate and Update distributed value of the reward
        $rewardId  = JArrayHelper::getValue($validData, "reward_id");
        $reward    = null;
        if(!empty($rewardId)) {
            $reward = $this->updateReward($validData);
        }
        
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
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_RESULT_DATA"), $this->debugType, $result) : null;
        
        // Remove intention
        $intention->delete();
        unset($intention);
        
        return $result;
                
    }
    
    /**
     * This metod is executed after complete payment.
     * It is used to be sent mails to user and administrator
     *
     * @param stdObject  Transaction data
     * @param JRegistry  Component parameters
     * @param stdObject  Project data
     * @param stdObject  Reward data
     */
    public function onAfterPayment($context, &$transaction, $params, $project, $reward) {
    
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
         
        if(strcmp("com_crowdfunding.notify.coinbase", $context) != 0){
            return;
        }
    
        // Send mails
        $this->sendMails($project, $transaction);
    
    }
    
	/**
     * Validate transaction data.
     * 
     * @param array                  $data
     * @param string                 $currency
     * @param CrowdFundingIntention  $intention
     */
    protected function validateData($data, $currency, $intention) {
        
        // Prepare transaction data
        $cbTransaction = JArrayHelper::getValue($data, "transaction");
        $cbTotalBtc    = JArrayHelper::getValue($data, "total_btc");
        
        $created       = JArrayHelper::getValue($data, "created_at");
        $date          = new JDate($created);
        
        // Get transaction status
        $status = strtolower( JArrayHelper::getValue($data, "status") );
        if(strcmp("completed", $status) != 0) {
            $status = "canceled";
        }
        
        // Prepare transaction data
        $transaction = array(
            "investor_id"		     => (int)$intention->getUserId(),
            "project_id"		     => (int)$intention->getProjectId(),
            "reward_id"			     => ($intention->isAnonymous()) ? 0 : (int)$intention->getRewardId(), // If the transaction has been made by anonymous user, reset reward. Anonymous users cannot select rewards.
        	"service_provider"       => "Coinbase",
        	"txn_id"                 => JArrayHelper::getValue($cbTransaction, "id"),
        	"txn_amount"		     => JArrayHelper::getValue($cbTotalBtc, "cents"),
            "txn_currency"           => JArrayHelper::getValue($cbTotalBtc, "currency_iso"),
            "txn_status"             => $status,
            "txn_date"               => $date->toSql()
        ); 
        
        // Check User Id, Project ID and Transaction ID
        if(!$transaction["project_id"] OR !$transaction["txn_id"]) {
            
            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix."_ERROR_INVALID_TRANSACTION_DATA"),
                $this->debugType,
                $transaction
            );
            
            return null;
        }
        
        // Check currency
        if(strcmp($transaction["txn_currency"], $currency) != 0) {
            
            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix."_ERROR_INVALID_TRANSACTION_CURRENCY"),
                $this->debugType,
                array("TRANSACTION DATA" => $transaction, "CURRENCY" => $currency)
            );
            
            return null;
        }
        
        return $transaction;
    }
    
    /**
     * Save transaction
     *
     * @param array $data           The data about transaction from the payment gateway. 
     * @param CrowdFundingProject   $project  
     * 
     * @return boolean
     */
    public function storeTransaction($data, $project) {
        
        // Get transaction by txn ID
        jimport("crowdfunding.transaction");
        $keys = array(
            "txn_id" => JArrayHelper::getValue($data, "txn_id")
        );
        $transaction = new CrowdFundingTransaction($keys);
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_TRANSACTION_OBJECT"), $this->debugType, $transaction->getProperties()) : null;
        
        // Check for existed transaction
        if($transaction->getId()) {
        
            // If the current status if completed,
            // stop the process.
            if($transaction->isCompleted()) {
                return false;
            }
        
        }
        
        // Store the transaction data
        $transaction->bind($data);
        $transaction->store();
        
        // If it is not completed (it might be pending or other status),
        // stop the process. Only completed transaction will continue 
        // and will process the project, rewards,...
        $txnStatus = JArrayHelper::getValue($data, "txn_status");
        if(strcmp("completed", $txnStatus) != 0) {
            return false;
        }
        
        // Update project funded amount
        $amount = JArrayHelper::getValue($data, "txn_amount");
        $project->addFunds($amount);
        $project->store();
        
        return true;
    }
    
    protected function isCoinbaseGateway($custom) {
        
        $paymentGateway = JArrayHelper::getValue($custom, "gateway");

        if(strcmp("Coinbase", $paymentGateway) != 0 ) {
            return false;
        }
        
        return true;
    }
    
}