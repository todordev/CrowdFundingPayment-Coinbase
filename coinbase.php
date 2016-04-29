<?php
/**
 * @package         Crowdfunding
 * @subpackage      Plugins
 * @author          Todor Iliev
 * @copyright       Copyright (C) 2016 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license         http://www.gnu.org/licenses/gpl-3.0.en.html GNU/GPL
 */

use Joomla\Utilities\ArrayHelper;

// no direct access
defined('_JEXEC') or die;

jimport('Prism.init');
jimport('Crowdfunding.init');
jimport('Emailtemplates.init');

/**
 * Crowdfunding Coinbase payment plugin
 *
 * @package        Crowdfunding
 * @subpackage     Plugins
 */
class plgCrowdfundingPaymentCoinbase extends Crowdfunding\Payment\Plugin
{
    public function __construct(&$subject, $config = array())
    {
        parent::__construct($subject, $config);

        $this->serviceProvider = 'Coinbase';
        $this->serviceAlias    = 'coinbase';
        $this->textPrefix     .= '_' . strtoupper($this->serviceAlias);
        $this->debugType      .= '_' . strtoupper($this->serviceAlias);

        $this->extraDataKeys = array(
            'id', 'code', 'type', 'amount', 'status', 'bitcoin_amount',
            'receipt_url', 'resource', 'resource_path', 'bitcoin_address', 'refund_address',
            'bitcoin_uri', 'paid_at', 'mispaid_at', 'expires_at', 'metadata', 'created_at',
            'updated_at', 'transaction'
        );
    }

    /**
     * This method prepare and return Coinbase button.
     *
     * @param string $context
     * @param stdClass $item
     *
     * @return null|string
     */
    public function onProjectPayment($context, &$item)
    {
        if (strcmp('com_crowdfunding.payment', $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        // This is a URI path to the plugin folder
        $html   = array();
        $html[] = '<div class="well">';

        $html[] = '<h4><img src="plugins/crowdfundingpayment/coinbase/images/coinbase_icon.png" width="38" height="32" /> ' . JText::_($this->textPrefix . '_TITLE') . '</h4>';

        $apiSettings = $this->getApiSettings();

        if (!$apiSettings->api_key or !$apiSettings->api_secret or !$apiSettings->url) {
            $html[] = $this->generateSystemMessage(JText::_($this->textPrefix . '_ERROR_PLUGIN_NOT_CONFIGURED'));
            return implode("\n", $html);
        }

        // Get payment session
        $paymentSessionContext    = Crowdfunding\Constants::PAYMENT_SESSION_CONTEXT . $item->id;
        $paymentSessionLocal      = $this->app->getUserState($paymentSessionContext);

        $paymentSession = $this->getPaymentSession(array(
            'session_id'    => $paymentSessionLocal->session_id
        ));

        // Custom data
        $custom = array(
            'payment_session_id' => $paymentSession->getId(),
            'gateway'            => $this->serviceAlias
        );

//        $custom = base64_encode(json_encode($custom));
        $title  = htmlentities($item->title, ENT_QUOTES, 'UTF-8');

        // Button options
        $options = array();

        // Button type
        $options['type'] = $this->params->get('checkout_type', 'donation');

        if (!$this->params->get('button_text')) {
            $buttonText = JText::_($this->textPrefix.'_'.JString::strtoupper($this->params->get('button_type')));
        } else {
            $buttonText = htmlspecialchars($this->params->get('button_text'), ENT_COMPAT, 'UTF-8');
        }

        // Return URL
        $returnUrl = $this->getReturnUrl($item->slug, $item->catslug);
        if ($returnUrl !== '') {
            $options['success_url'] = $returnUrl;
        }

        // Cancel URL
        $cancelUrl = $this->getCancelUrl($item->slug, $item->catslug);
        if ($returnUrl !== '') {
            $options['cancel_url'] = $cancelUrl;
        }

        // Set auto-redirect option.
        $options['auto_redirect'] = (bool)$this->params->get('auto_redirect', 1);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_CREATE_BUTTON_OPTIONS'), $this->debugType, $options) : null;

        jimport('Prism.libs.init');
        $configuration = Coinbase\Wallet\Configuration::apiKey($apiSettings->api_key, $apiSettings->api_secret);
        $configuration->setApiUrl($apiSettings->api_url);
        $client = Coinbase\Wallet\Client::create($configuration);

        $options['name'] = $title;
        $options['amount'] = new Coinbase\Wallet\Value\Money($item->amount, $item->currencyCode);
        $options['metadata'] = $custom;

        $checkout = new Coinbase\Wallet\Resource\Checkout($options);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_CREATE_CHECKOUT_OBJECT'), $this->debugType, $checkout) : null;

        try {
            $client->createCheckout($checkout);
            $code = $checkout->getEmbedCode();

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_CREATE_CHECKOUT_OBJECT'), $this->debugType, $client) : null;

            if ($this->params->get('payment_process') === 'button') {
                $html[] = '<p class="alert alert-info p-10-5"><span class="fa fa-info-circle"></span> ' . JText::_($this->textPrefix . '_INFO') . '</p>';
                $html[] = '<a href="'.$apiSettings->url.$code.'" class="btn btn-primary"><span class="fa fa-btc"></span> '.$buttonText.'</a>';
            } else {
                $html[] = '<iframe id="coinbase_inline_iframe_'.$code.'" src="'.$apiSettings->url.$code.'/inline" style="width: 460px; height: 350px; border: none; box-shadow: 0 1px 3px rgba(0,0,0,0.25);" allowtransparency="true" frameborder="0"></iframe>';
            }
        } catch (Exception $e) {
            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_CREATE_CHECKOUT_OBJECT'), $this->debugType, $client) : null;

            $this->log->add($e->getMessage(), $this->errorType);
            $this->notifyAdministrator($e->getMessage());
        }

        if ($this->params->get('sandbox', 1)) {
            $html[] = '<div class="alert alert-warning mt-5 p-10-5"><span class="fa fa-exclamation-circle"></span> ' . JText::_($this->textPrefix . '_SANDBOX_MESSAGE') . '</div>';
        }

        $html[] = '</div>';

        return implode("\n", $html);
    }

    /**
     * This method processes transaction.
     *
     * @param string    $context This string gives information about that where it has been executed the trigger.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @return null|array
     */
    public function onPaymentNotify($context, &$params)
    {
        if (strcmp('com_crowdfunding.notify.coinbase', $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('raw', $docType) !== 0) {
            return null;
        }

        $apiSettings = $this->getApiSettings();

        if (!$apiSettings->api_key or !$apiSettings->api_secret or !$apiSettings->url) {
            return null;
        }

        // Get data from PHP input
        $rawBody = file_get_contents('php://input');
        $signature = $this->app->input->server->get('HTTP_CB_SIGNATURE');

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESPONSE'), $this->debugType, $rawBody) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_SIGNATURE'), $this->debugType, $signature) : null;

        jimport('Prism.libs.init');
        $configuration = Coinbase\Wallet\Configuration::apiKey($apiSettings->api_key, $apiSettings->api_secret);
        $configuration->setApiUrl($apiSettings->api_url);
        $client = Coinbase\Wallet\Client::create($configuration);

        $authenticity = $client->verifyCallback($rawBody, $signature); // boolean
        $post         = json_decode($rawBody, true);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_AUTHENTICITY'), $this->debugType, var_export($authenticity, true)) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESPONSE'), $this->debugType, $post) : null;

        if (!$post or (!$this->params->get('sandbox', 1) and !$authenticity)) {
            return null;
        }

        $postData = ArrayHelper::getValue($post, 'data');
        $metadata = ArrayHelper::getValue($postData, 'metadata');

        // Verify gateway. Is it Coinbase
        if (!$this->isValidPaymentGateway($metadata['gateway'])) {
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_PAYMENT_GATEWAY'),
                $this->debugType,
                array('metadata' => $metadata, '_POST' => $post)
            );

            return null;
        }

        $result = array(
            'project'          => null,
            'reward'           => null,
            'transaction'      => null,
            'payment_session'  => null,
            'service_provider' => $this->serviceProvider,
            'service_alias'    => $this->serviceAlias
        );

        // Get extension parameters
        $currencyId = $params->get('project_currency');
        $currency   = new Crowdfunding\Currency(JFactory::getDbo());
        $currency->load($currencyId);

        // Get payment session data
        $paymentSessionId = ArrayHelper::getValue($metadata, 'payment_session_id', 0, 'int');
        $paymentSession   = $this->getPaymentSession(array('id' => $paymentSessionId));

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYMENT_SESSION'), $this->debugType, $paymentSession->getProperties()) : null;

        // Validate transaction data
        $validData = $this->validateData($postData, $currency->getCode(), $paymentSession);
        if ($validData === null) {
            return $result;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_VALID_DATA'), $this->debugType, $validData) : null;

        // Get project
        $projectId = ArrayHelper::getValue($validData, 'project_id');
        $project   = new Crowdfunding\Project(JFactory::getDbo());
        $project->load($projectId);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PROJECT_OBJECT'), $this->debugType, $project->getProperties()) : null;

        // Check for valid project
        if (!$project->getId()) {
            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_PROJECT'),
                $this->debugType,
                $validData
            );

            return $result;
        }

        // Set the receiver of funds.
        $validData['receiver_id'] = $project->getUserId();

        $transactionData   = null;
        $reward            = null;

        // Start database transaction.
        $db = JFactory::getDbo();
        $db->transactionStart();

        try {
            // Save transaction data.
            // If it is not completed, return empty results.
            // If it is complete, continue with process transaction data
            $transactionData = $this->storeTransaction($validData, $project);
            if ($transactionData === null) {
                return $result;
            }

            // Update the number of distributed reward.
            $rewardId = ArrayHelper::getValue($transactionData, 'reward_id', 0, 'int');
            if ($rewardId > 0) {
                $reward = $this->updateReward($transactionData);

                // Validate the reward.
                if (!$reward) {
                    $transactionData['reward_id'] = 0;
                }
            }

            $db->transactionCommit();

        } catch (Exception $e) {
            $db->transactionRollback();
            return $result;
        }

        //  Prepare the data that will be returned

        $result['transaction'] = ArrayHelper::toObject($transactionData);

        // Generate object of data based on the project properties
        $properties        = $project->getProperties();
        $result['project'] = ArrayHelper::toObject($properties);

        // Generate object of data based on the reward properties
        if ($reward !== null and ($reward instanceof Crowdfunding\Reward)) {
            $properties       = $reward->getProperties();
            $result['reward'] = ArrayHelper::toObject($properties);
        }

        // Generate data object, based on the payment session properties.
        $properties       = $paymentSession->getProperties();
        $result['payment_session'] = ArrayHelper::toObject($properties);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESULT_DATA'), $this->debugType, $result) : null;

        // Remove payment session
        // Remove payment session.
        $txnStatus = (isset($result['transaction']->txn_status)) ? $result['transaction']->txn_status : null;
        $removeIntention  = (strcmp('completed', $txnStatus) === 0);
        
        $this->closePaymentSession($paymentSession, $removeIntention);

        return $result;
    }

    /**
     * Validate transaction data.
     *
     * @param array                 $data
     * @param string                $currency
     * @param Crowdfunding\Payment\Session $paymentSessionId
     *
     * @return null|array
     */
    protected function validateData($data, $currency, $paymentSessionId)
    {
        $created = ArrayHelper::getValue($data, 'created_at');
        $date    = new JDate($created);

        // Get transaction status
        $status = strtolower(ArrayHelper::getValue($data, 'status'));
        switch ($status) {
            case 'paid':
                $status = 'completed';
                break;

            case 'mispaid':
                $status = 'failed';
                break;

            default:
                $status = 'pending';
                break;
        }

        // If the transaction has been made by anonymous user, reset reward. Anonymous users cannot select rewards.
        $rewardId = ($paymentSessionId->isAnonymous()) ? 0 : (int)$paymentSessionId->getRewardId();

        // Prepare transaction data
        $transaction = array(
            'investor_id'      => (int)$paymentSessionId->getUserId(),
            'project_id'       => (int)$paymentSessionId->getProjectId(),
            'reward_id'        => (int)$rewardId,
            'service_provider' => $this->serviceProvider,
            'service_alias'    => $this->serviceAlias,
            'txn_id'           => ArrayHelper::getValue($data, 'code'),
            'txn_amount'       => ArrayHelper::getValue($data['bitcoin_amount'], 'amount'),
            'txn_currency'     => ArrayHelper::getValue($data['bitcoin_amount'], 'currency'),
            'txn_status'       => $status,
            'txn_date'         => $date->toSql(),
            'extra_data'       => $this->prepareExtraData($data)
        );

        // Check User Id, Project ID and Transaction ID
        if (!$transaction['project_id'] or !$transaction['txn_id']) {
            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'),
                $this->debugType,
                $transaction
            );

            return null;
        }

        // Check currency
        if (strcmp($transaction['txn_currency'], $currency) !== 0) {
            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_CURRENCY'),
                $this->debugType,
                array('TRANSACTION DATA' => $transaction, 'CURRENCY' => $currency)
            );

            return null;
        }

        return $transaction;
    }

    /**
     * Save transaction
     *
     * @param array               $transactionData The data about transaction from the payment gateway.
     * @param Crowdfunding\Project $project
     *
     * @return null|array
     */
    public function storeTransaction($transactionData, $project)
    {
        // Get transaction by txn ID
        $keys        = array(
            'txn_id' => ArrayHelper::getValue($transactionData, 'txn_id')
        );
        $transaction = new Crowdfunding\Transaction(JFactory::getDbo());
        $transaction->load($keys);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_OBJECT'), $this->debugType, $transaction->getProperties()) : null;

        // Check for existed transaction
        // If the current status if completed, stop the process.
        if ($transaction->getId() and $transaction->isCompleted()) {
            return null;
        }

        // Add extra data.
        if (array_key_exists('extra_data', $transactionData) and !empty($transactionData['extra_data'])) {
            $transaction->addExtraData($transactionData['extra_data']);
            unset($transactionData['extra_data']);
        }

        // Store the transaction data
        $transaction->bind($transactionData);
        $transaction->store();

        // If it is not completed (it might be pending or other status),
        // stop the process. Only completed transaction will continue
        // and will process the project, rewards,...
        if (!$transaction->isCompleted()) {
            return null;
        }

        // Set transaction ID.
        $transactionData['id'] = $transaction->getId();

        // Update project funded amount
        $amount = ArrayHelper::getValue($transactionData, 'txn_amount');
        $project->addFunds($amount);
        $project->storeFunds();

        return $transactionData;
    }

    protected function getApiSettings()
    {
        $settings = new stdClass;

        if ($this->params->get('sandbox', 1)) {
            $settings->api_key    = $this->params->get('sandbox_api_key');
            $settings->api_secret = $this->params->get('sandbox_secret_key');
            $settings->url        = $this->params->get('sandbox_url');
            $settings->api_url    = Coinbase\Wallet\Configuration::SANDBOX_API_URL;
        } else {
            $settings->api_key    = $this->params->get('api_key');
            $settings->api_secret = $this->params->get('secret_key');
            $settings->url        = $this->params->get('live_url');
            $settings->api_url    = Coinbase\Wallet\Configuration::DEFAULT_API_URL;
        }

        return $settings;
    }
}
