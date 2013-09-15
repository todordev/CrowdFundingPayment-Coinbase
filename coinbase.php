<?php
/**
 * @package		 CrowdFunding
 * @subpackage	 Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2013 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      http://www.gnu.org/copyleft/gpl.html GNU/GPL
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
        
        // This is a URI path to the plugin folder
        $pluginURI = "plugins/crowdfundingpayment/coinbase";
        
        $html  =  "";
        $html .= '<h4><img src="'.$pluginURI.'/images/coinbase_icon.png" width="38" height="32" /> '.JText::_("PLG_CROWDFUNDINGPAYMENT_COINBASE_TITLE").'</h4>';
        $html .= '<p>'.JText::_("PLG_CROWDFUNDINGPAYMENT_COINBASE_INFO").'</p>';
        
        // Get intention
        $userId  = JFactory::getUser()->id;
        $aUserId = $app->getUserState("auser_id");

        $intention = CrowdFundingHelper::getIntention($userId, $aUserId, $item->id);
        
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
        
        // Send request for button
        jimport("itprism.bitcoin.coinbase.Coinbase");
        
        $apiKey = JString::trim($this->params->get("coinbase_api_key"));
        $coinbase = new Coinbase($apiKey);
        
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
     * This method processes transaction.
     * 
     * @param string 	$context	This string gives information about that where it has been executed the trigger.
     * @param JRegistry $params	    The parameters of the component
     */
    public function onPaymenNotify($context, $params) {
        
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
            $post["custom"]             = JString::trim($this->params->get("coinbase_test_custom_string"));
            $post["total_btc"]["cents"] = JString::trim($this->params->get("coinbase_test_amount", 1));
        }
        
        $custom         = JArrayHelper::getValue($post, "custom");
        $custom         = json_decode( base64_decode($custom), true );
        
        // Verify gateway. Is it Coinbase? 
        if(!$this->isCoinbaseGateway($custom)) {
            return null;
        }
        
        // Load language
        $this->loadLanguage();
            
        $result = array(
        	"project"          => null, 
        	"reward"           => null, 
        	"transaction"      => null,
            "payment_service"  => "Coinbase"
        );
        
        // Get extension parameters
        jimport("crowdfunding.currency");
        $currencyId      = $params->get("project_currency");
        $currency        = CrowdFundingCurrency::getInstance($currencyId);
        
        // Get intention data
        $intentionId     = JArrayHelper::getValue($custom, "intention_id", 0, "int");
        
        jimport("crowdfunding.intention");
        $intention = new CrowdFundingIntention($intentionId);
        
        // Validate transaction data
        $validData = $this->validateData($post, $currency->getAbbr(), $intention);
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
        
        // Remove intention
        $intention->delete();
        unset($intention);
        
        return $result;
                
    }
    
    /**
     * This metod is executed after complete payment.
     * It is used to be sent mails to user and administrator
     *
     * @param object     $transaction   Transaction data
     * @param JRegistry  $params        Component parameters
     * @param object     $project       Project data
     * @param object     $reward        Reward data
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
    
        // Send email to the administrator
        if($this->params->get("coinbase_send_admin_mail", 0)) {
    
            $subject = JText::_("PLG_CROWDFUNDINGPAYMENT_COINBASE_NEW_INVESTMENT_ADMIN_SUBJECT");
            $body    = JText::sprintf("PLG_CROWDFUNDINGPAYMENT_COINBASE_NEW_INVESTMENT_ADMIN_BODY", $project->title);
            $return  = JFactory::getMailer()->sendMail($app->getCfg("mailfrom"), $app->getCfg("fromname"), $app->getCfg("mailfrom"), $subject, $body);
    
            // Check for an error.
            if ($return !== true) {
                $error = JText::sprintf("PLG_CROWDFUNDINGPAYMENT_COINBASE_ERROR_MAIL_SENDING_ADMIN");
                JLog::add($error);
            }
        }
    
        // Send email to the user
        if($this->params->get("coinbase_send_user_mail", 0)) {
    
            $amount   = $transaction->txn_amount.$transaction->txn_currency;
    
            $user     = JUser::getInstance($project->user_id);
    
            // Send email to the administrator
            $subject = JText::sprintf("PLG_CROWDFUNDINGPAYMENT_COINBASE_NEW_INVESTMENT_USER_SUBJECT", $project->title);
            $body    = JText::sprintf("PLG_CROWDFUNDINGPAYMENT_COINBASE_NEW_INVESTMENT_USER_BODY", $amount, $project->title );
            $return  = JFactory::getMailer()->sendMail($app->getCfg("mailfrom"), $app->getCfg("fromname"), $user->email, $subject, $body);
    
            // Check for an error.
            if ($return !== true) {
                $error = JText::_("PLG_CROWDFUNDINGPAYMENT_COINBASE_ERROR_MAIL_SENDING_USER");
                JLog::add($error);
            }
    
        }
    
    }
    
	/**
     * Validate transaction data,
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
            $error  = JText::_("PLG_CROWDFUNDINGPAYMENT_COINBASE_ERROR_INVALID_TRANSACTION_DATA");
            $error .= "\n". JText::sprintf("PLG_CROWDFUNDINGPAYMENT_COINBASE_TRANSACTION_DATA", var_export($transaction, true));
            JLog::add($error);
            return null;
        }
        
        // Check currency
        if(strcmp($transaction["txn_currency"], $currency) != 0) {
            $error  = JText::_("PLG_CROWDFUNDINGPAYMENT_COINBASE_ERROR_INVALID_TRANSACTION_CURRENCY");
            $error .= "\n". JText::sprintf("PLG_CROWDFUNDINGPAYMENT_COINBASE_TRANSACTION_DATA", var_export($transaction, true));
            JLog::add($error);
            return null;
        }
        
        return $transaction;
    }
    
    protected function updateReward(&$data) {
        
        $keys   = array(
            "id"         => $data["reward_id"],
            "project_id" => $data["project_id"]
        );
        
        jimport("crowdfunding.reward");
        $reward = new CrowdFundingReward($keys);
        
        // Check for valid reward
        if(!$reward->getId()) {
            $error  = JText::_("PLG_CROWDFUNDINGPAYMENT_COINBASE_ERROR_INVALID_REWARD");
            $error .= "\n". JText::sprintf("PLG_CROWDFUNDINGPAYMENT_COINBASE_TRANSACTION_DATA", var_export($data, true));
			JLog::add($error);
			
			$data["reward_id"] = 0;
			return null;
        }
        
        // Check for valida amount between reward value and payed by user
        $txnAmount = JArrayHelper::getValue($data, "txn_amount");
        if($txnAmount < $reward->getAmount()) {
            $error  = JText::_("PLG_CROWDFUNDINGPAYMENT_COINBASE_ERROR_INVALID_REWARD_AMOUNT");
            $error .= "\n". JText::sprintf("PLG_CROWDFUNDINGPAYMENT_COINBASE_TRANSACTION_DATA", var_export($data, true));
			JLog::add($error);
			
			$data["reward_id"] = 0;
			return null;
        }
        
        if($reward->isLimited() AND !$reward->getAvailable()) {
            $error  = JText::_("PLG_CROWDFUNDINGPAYMENT_COINBASE_ERROR_REWARD_NOT_AVAILABLE");
            $error .= "\n". JText::sprintf("PLG_CROWDFUNDINGPAYMENT_COINBASE_TRANSACTION_DATA", var_export($data, true));
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
     * Save transaction
     *
     * @param array $data           The data about transaction from the payment gateway. 
     * @param CrowdFundingProject   $project  
     * 
     * @return boolean
     */
    public function storeTransaction($data, $project) {
        
        jimport("crowdfunding.transaction");
        
        $keys = array(
            "txn_id" => JArrayHelper::getValue($data, "txn_id")
        );
        
        $transaction = new CrowdFundingTransaction($keys);
        
        // Check for existed transaction
        if(!empty($transaction->id)) {
        
            // If the current status if completed,
            // stop the process.
            if(strcmp("completed", $transaction->txn_status) == 0) {
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
    
    private function isCoinbaseGateway($custom) {
        
        $paymentGateway = JArrayHelper::getValue($custom, "gateway");

        if(strcmp("Coinbase", $paymentGateway) != 0 ) {
            return false;
        }
        
        return true;
    }
    
}