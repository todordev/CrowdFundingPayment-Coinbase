<?php
/**
 * @package         Crowdfunding
 * @subpackage      Plugins
 * @author          Todor Iliev
 * @copyright       Copyright (C) 2017 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license         http://www.gnu.org/licenses/gpl-3.0.en.html GNU/GPL
 */

use Crowdfunding\Transaction\Transaction;
use Crowdfunding\Transaction\TransactionManager;
use Crowdfunding\Observer\Transaction\TransactionObserver;
use Joomla\Utilities\ArrayHelper;
use Joomla\Registry\Registry;
use Prism\Payment\Result as PaymentResult;

// no direct access
defined('_JEXEC') or die;

jimport('Prism.init');
jimport('Crowdfunding.init');
jimport('Emailtemplates.init');
jimport('Prism.libs.GuzzleHttp.init');

JObserverMapper::addObserverClassToClass(TransactionObserver::class, TransactionManager::class, array('typeAlias' => 'com_crowdfunding.payment'));

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
        $this->serviceProvider = 'Coinbase';
        $this->serviceAlias    = 'coinbase';

        $this->extraDataKeys = array(
            'id', 'code', 'type', 'amount', 'status', 'bitcoin_amount',
            'receipt_url', 'resource', 'resource_path', 'bitcoin_address', 'refund_address',
            'bitcoin_uri', 'paid_at', 'mispaid_at', 'expires_at', 'metadata', 'created_at',
            'updated_at', 'transaction'
        );

        parent::__construct($subject, $config);
    }

    /**
     * This method prepare and return Coinbase button.
     *
     * @param string $context
     * @param stdClass $item
     *
     * @return null|string
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public function onProjectPayment($context, $item)
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

        if (!$apiSettings['api_key'] or !$apiSettings['api_secret']) {
            $html[] = $this->generateSystemMessage(JText::_($this->textPrefix . '_ERROR_PLUGIN_NOT_CONFIGURED'));
            return implode("\n", $html);
        }

        // Get payment session
        $paymentSessionContext    = Crowdfunding\Constants::PAYMENT_SESSION_CONTEXT . $item->id;
        $paymentSessionLocal      = $this->app->getUserState($paymentSessionContext);

        $paymentSession = $this->getPaymentSession(array(
            'session_id'    => $paymentSessionLocal->session_id
        ));

        $configuration = Coinbase\Wallet\Configuration::apiKey($apiSettings['api_key'], $apiSettings['api_secret']);
        $client = Coinbase\Wallet\Client::create($configuration);

        $options = $this->prepareCheckoutOptions($item, $paymentSession->getId());

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_CREATE_BUTTON_OPTIONS'), $this->debugType, $options) : null;

        $checkout = new Coinbase\Wallet\Resource\Checkout($options);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_CREATE_CHECKOUT_OBJECT'), $this->debugType, $checkout) : null;

        try {
            $client->createCheckout($checkout);
            $code = $checkout->getEmbedCode();

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_CREATE_CHECKOUT_OBJECT'), $this->debugType, $client) : null;

            if (strcmp('button', $this->params->get('payment_process', 'button')) === 0) {
                if (!$this->params->get('button_text')) {
                    $buttonText = JText::_($this->textPrefix.'_'.strtoupper($this->params->get('button_type')));
                } else {
                    $buttonText = htmlspecialchars($this->params->get('button_text'), ENT_COMPAT, 'UTF-8');
                }

                $html[] = '<p class="alert alert-info p-10-5"><span class="fa fa-info-circle"></span> ' . JText::_($this->textPrefix . '_INFO') . '</p>';
                $html[] = '<a href="https://www.coinbase.com/checkouts/'.$code.'" class="btn btn-primary"><span class="fa fa-btc"></span> '.$buttonText.'</a>';
            } else {
                $html[] = '<iframe id="coinbase_inline_iframe_'.$code.'" src="https://www.coinbase.com/checkouts/'.$code.'/inline" style="width: 460px; height: 350px; border: none; box-shadow: 0 1px 3px rgba(0,0,0,0.25);" allowtransparency="true" frameborder="0"></iframe>';
            }
        } catch (Exception $e) {
            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_CREATE_CHECKOUT_OBJECT'), $this->debugType, $client) : null;

            $this->log->add($e->getMessage(), $this->errorType);
            $this->notifyAdministrator($e->getMessage());
        }

        $html[] = '</div>';

        return implode("\n", $html);
    }

    /**
     * This method processes transaction.
     *
     * @param string    $context This string gives information about that where it has been executed the trigger.
     * @param Registry  $params  The parameters of the component
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     * @throws \OutOfBoundsException
     *
     * @return null|PaymentResult
     */
    public function onPaymentNotify($context, $params)
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
        if (!$apiSettings['api_key'] or !$apiSettings['api_secret']) {
            return null;
        }

        // Get data from PHP input
        $rawBody   = file_get_contents('php://input');
        $signature = $this->app->input->server->get('HTTP_CB_SIGNATURE');

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESPONSE'), $this->debugType, $rawBody) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_SIGNATURE'), $this->debugType, $signature) : null;

        $configuration = Coinbase\Wallet\Configuration::apiKey($apiSettings['api_key'], $apiSettings['api_secret']);
        $client = Coinbase\Wallet\Client::create($configuration);

        $authenticity = $client->verifyCallback($rawBody, $signature); // boolean
        $response     = json_decode($rawBody, true);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_AUTHENTICITY'), $this->debugType, var_export($authenticity, true)) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESPONSE'), $this->debugType, $response) : null;

        if (!$response or !$authenticity) {
            return null;
        }

        $postData = ArrayHelper::getValue($response, 'data');
        $metadata = ArrayHelper::getValue($postData, 'metadata');

        // Verify gateway. Is it Coinbase
        if (!$this->isValidPaymentGateway($metadata['gateway'])) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_PAYMENT_GATEWAY'), $this->debugType, array('metadata' => $metadata, '_POST' => $postData));
            return null;
        }

        // Prepare the array that have to be returned by this method.
        $paymentResult = new PaymentResult();

        $containerHelper  = new Crowdfunding\Container\Helper();
        $currency         = $containerHelper->fetchCurrency($this->container, $params);

        // Get payment session data
        $paymentSessionId       = ArrayHelper::getValue($metadata, 'payment_session_id', 0, 'int');
        $paymentSessionRemote   = $this->getPaymentSession(array('id' => $paymentSessionId));

        // Check for valid payment session.
        if (!$paymentSessionRemote->getId()) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_PAYMENT_SESSION'), $this->errorType, $paymentSessionRemote->getProperties());
            return null;
        }

        // Validate transaction data
        $validData = $this->validateData($postData, $currency->getCode(), $paymentSessionRemote);
        if ($validData === null) {
            return null;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_VALID_DATA'), $this->debugType, $validData) : null;

        // Set the receiver ID.
        $project = $containerHelper->fetchProject($this->container, $validData['project_id']);
        $validData['receiver_id'] = $project->getUserId();

        // Get reward object.
        $reward = null;
        if ($validData['reward_id']) {
            $reward = $containerHelper->fetchReward($this->container, $validData['reward_id'], $project->getId());
        }

        // Save transaction data.
        // If it is not completed, return empty results.
        // If it is complete, continue with process transaction data
        $transaction = $this->storeTransaction($validData);
        if ($transaction === null) {
            return null;
        }

        // Generate object of data, based on the transaction properties.
        $paymentResult->transaction = $transaction;

        // Generate object of data based on the project properties.
        $paymentResult->project = $project;

        // Generate object of data based on the reward properties.
        if ($reward !== null and ($reward instanceof Crowdfunding\Reward)) {
            $paymentResult->reward = $reward;
        }

        // Generate data object, based on the payment session properties.
        $paymentResult->paymentSession = $paymentSessionRemote;

        // Removing intention.
        $this->removeIntention($paymentSessionRemote, $transaction);

        return $paymentResult;
    }

    /**
     * Validate transaction data.
     *
     * @param array                 $data
     * @param string                $currencyCode
     * @param Crowdfunding\Payment\Session $paymentSessionRemote
     *
     * @throws \InvalidArgumentException
     *
     * @return null|array
     */
    protected function validateData($data, $currencyCode, $paymentSessionRemote)
    {
        $createdAt = ArrayHelper::getValue($data, 'created_at');
        $dateValidator = new Prism\Validator\Date($createdAt);

        if ($dateValidator->isValid()) {
            $date = new \JDate($createdAt);
        } else {
            $date = new \JDate();
        }

        // Get transaction status
        $status = strtolower(ArrayHelper::getValue($data, 'status'));
        switch ($status) {
            case 'completed':
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

        if (array_key_exists('bitcoin_amount', $data)) {
            $amount = ArrayHelper::getValue($data, 'bitcoin_amount', array(), 'array');
        } else {
            $amount = ArrayHelper::getValue($data, 'amount', array(), 'array');
        }

        // Prepare transaction data
        $transaction = array(
            'investor_id'      => (int)$paymentSessionRemote->getUserId(),
            'project_id'       => (int)$paymentSessionRemote->getProjectId(),
            'reward_id'        => (int)$paymentSessionRemote->getRewardId(),
            'service_provider' => $this->serviceProvider,
            'service_alias'    => $this->serviceAlias,
            'txn_id'           => ArrayHelper::getValue($data, 'code'),
            'txn_amount'       => ArrayHelper::getValue($amount, 'amount'),
            'txn_currency'     => ArrayHelper::getValue($amount, 'currency'),
            'txn_status'       => $status,
            'txn_date'         => $date->toSql(),
            'extra_data'       => $this->prepareExtraData($data)
        );

        // Check User Id, Project ID and Transaction ID
        if (!$transaction['project_id'] or !$transaction['txn_id']) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'), $this->debugType, $transaction);
            return null;
        }

        // Check currency
        if (strcmp($transaction['txn_currency'], $currencyCode) !== 0) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_CURRENCY'), $this->debugType, array('TRANSACTION DATA' => $transaction, 'CURRENCY' => $currencyCode));
            return null;
        }

        return $transaction;
    }

    /**
     * Save transaction
     *
     * @param array  $transactionData The data about transaction from the payment gateway.
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     *
     * @return null|Transaction
     */
    public function storeTransaction($transactionData)
    {
        // Get transaction by txn ID
        $keys        = array(
            'txn_id' => ArrayHelper::getValue($transactionData, 'txn_id')
        );
        $transaction = new Transaction(JFactory::getDbo());
        $transaction->load($keys);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_OBJECT'), $this->debugType, $transaction->getProperties()) : null;

        // Check for existed transaction
        // If the current status if completed, stop the process.
        if ($transaction->getId() and $transaction->isCompleted()) {
            return null;
        }

        // Add extra data.
        if (array_key_exists('extra_data', $transactionData)) {
            if (!empty($transactionData['extra_data'])) {
                $transaction->addExtraData($transactionData['extra_data']);
            }

            unset($transactionData['extra_data']);
        }

        // IMPORTANT: It must be placed before ->bind();
        $options = array(
            'old_status' => $transaction->getStatus(),
            'new_status' => $transactionData['txn_status']
        );

        // Create the new transaction record if there is not record.
        // If there is new record, store new data with new status.
        // Example: It has been 'pending' and now is 'completed'.
        // Example2: It has been 'pending' and now is 'failed'.
        $transaction->bind($transactionData);

        // Start database transaction.
        $db = JFactory::getDbo();

        try {
            $db->transactionStart();

            $transactionManager = new TransactionManager($db);
            $transactionManager->setTransaction($transaction);
            $transactionManager->process('com_crowdfunding.payment', $options);

            $db->transactionCommit();
        } catch (Exception $e) {
            $db->transactionRollback();

            $this->log->add(JText::_($this->textPrefix . '_ERROR_TRANSACTION_PROCESS'), $this->errorType, $e->getMessage());
            return null;
        }

        return $transaction;
    }

    /**
     * Prepare API settings.
     *
     * @return array
     */
    protected function getApiSettings()
    {
        $settings = array();

        $settings['api_key']    = $this->params->get('api_key');
        $settings['api_secret'] = $this->params->get('secret_key');

        return $settings;
    }

    protected function prepareCheckoutOptions($item, $paymentSessionId)
    {
        // Button options
        $options = array();

        // Button type
        $options['type'] = $this->params->get('checkout_type', 'donation');

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

        // Notification URL
        $callbackUrl = $this->getCallbackUrl();
        if ($callbackUrl !== '') {
            $options['notifications_url'] = $callbackUrl;
        }

        // Set auto-redirect option.
        $options['auto_redirect'] = (bool)$this->params->get('auto_redirect', Prism\Constants::YES);

        // Custom data
        $custom = array(
            'payment_session_id' => $paymentSessionId,
            'gateway'            => $this->serviceAlias
        );

        $title  = htmlentities($item->title, ENT_QUOTES, 'UTF-8');

        $options['name']     = $title;
        $options['amount']   = new Coinbase\Wallet\Value\Money($item->amount, $item->currencyCode);
        $options['metadata'] = $custom;

        return $options;
    }
}
