<?php

use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Payment\PropertyBag;

class CRM_Core_Payment_SquarePP extends CRM_Core_Payment {
    
  protected $_mode;
  protected $_params = [];
  protected $_doDirectPaymentResult = [];

  /**
   * @var GuzzleHttp\Client
   */
  protected $guzzleClient;

  /**
   * @return \GuzzleHttp\Client
   */
  public function getGuzzleClient(): \GuzzleHttp\Client {
    return $this->guzzleClient ?? new \GuzzleHttp\Client();
  }

  /**
   * @param \GuzzleHttp\Client $guzzleClient
   */
  public function setGuzzleClient(\GuzzleHttp\Client $guzzleClient) {
    $this->guzzleClient = $guzzleClient;
  }
  
  /**
   * This support variable is used to allow the capabilities supported by the Dummy processor to be set from unit tests
   *   so that we don't need to create a lot of new processors to test combinations of features.
   * Initially these capabilities are set to TRUE, however they can be altered by calling the setSupports function directly from outside the class.
   * @var bool[]
   */
  protected $supports = [
    'MultipleConcurrentPayments' => FALSE,
    'EditRecurringContribution' => FALSE,
    'CancelRecurringNotifyOptional' => FALSE,
    'BackOffice' => FALSE,
    'NoEmailProvided' => FALSE,
    'CancelRecurring' => FALSE,
    'FutureRecurStartDate' => FALSE,
    'Refund' => FALSE,
  ];
  
   /**
   * Set result from do Direct Payment for test purposes.
   *
   * @param array $doDirectPaymentResult
   *  Result to be returned from test.
   */
  public function setDoDirectPaymentResult($doDirectPaymentResult) {
    Civi::log()->debug('Dummy.php::setDoDirectPaymentResult' . '  ' . $doDirectPaymentResult);
    $this->_doDirectPaymentResult = $doDirectPaymentResult;
    if (empty($this->_doDirectPaymentResult['trxn_id'])) {
      $this->_doDirectPaymentResult['trxn_id'] = [];
    }
    else {
      $this->_doDirectPaymentResult['trxn_id'] = (array) $doDirectPaymentResult['trxn_id'];
    }
  }

  /**
   * Constructor.
   *
   * @param string $mode
   *   The mode of operation: live or test.
   *
   * @param array $paymentProcessor
   */
  public function __construct($mode, &$paymentProcessor) {
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
  }

  /**
   * Does this payment processor support refund?
   *
   * @return bool
   */
  public function supportsRefund() {
    return $this->supports['Refund'];
  }

  /**
   * Should the first payment date be configurable when setting up back office recurring payments.
   *
   * We set this to false for historical consistency but in fact most new processors use tokens for recurring and can support this
   *
   * @return bool
   */
  public function supportsFutureRecurStartDate() {
    return $this->supports['FutureRecurStartDate'];
  }

  /**
   * Can more than one transaction be processed at once?
   *
   * In general processors that process payment by server to server communication support this while others do not.
   *
   * In future we are likely to hit an issue where this depends on whether a token already exists.
   *
   * @return bool
   */
  protected function supportsMultipleConcurrentPayments() {
    return $this->supports['MultipleConcurrentPayments'];
  }

  /**
   * Checks if back-office recurring edit is allowed
   *
   * @return bool
   */
  public function supportsEditRecurringContribution() {
    return $this->supports['EditRecurringContribution'];
  }

  /**
   * Are back office payments supported.
   *
   * e.g paypal standard won't permit you to enter a credit card associated
   * with someone else's login.
   * The intention is to support off-site (other than paypal) & direct debit but that is not all working yet so to
   * reach a 'stable' point we disable.
   *
   * @return bool
   */
  protected function supportsBackOffice() {
    return $this->supports['BackOffice'];
  }

  /**
   * Does the processor work without an email address?
   *
   * The historic assumption is that all processors require an email address. This capability
   * allows a processor to state it does not need to be provided with an email address.
   * NB: when this was added (Feb 2020), the Manual processor class overrides this but
   * the only use of the capability is in the webform_civicrm module.  It is not currently
   * used in core but may be in future.
   *
   * @return bool
   */
  protected function supportsNoEmailProvided() {
    return $this->supports['NoEmailProvided'];
  }

  /**
   * Does this processor support cancelling recurring contributions through code.
   *
   * If the processor returns true it must be possible to take action from within CiviCRM
   * that will result in no further payments being processed. In the case of token processors (e.g
   * IATS, eWay) updating the contribution_recur table is probably sufficient.
   *
   * @return bool
   */
  protected function supportsCancelRecurring() {
    return $this->supports['CancelRecurring'];
  }

  /**
   * Does the processor support the user having a choice as to whether to cancel the recurring with the processor?
   *
   * If this returns TRUE then there will be an option to send a cancellation request in the cancellation form.
   *
   * This would normally be false for processors where CiviCRM maintains the schedule.
   *
   * @return bool
   */
  protected function supportsCancelRecurringNotifyOptional() {
    return $this->supports['CancelRecurringNotifyOptional'];
  }

  /**
   * Does this processor support pre-approval.
   *
   * This would generally look like a redirect to enter credentials which can then be used in a later payment call.
   *
   * Currently Paypal express supports this, with a redirect to paypal after the 'Main' form is submitted in the
   * contribution page. This token can then be processed at the confirm phase. Although this flow 'looks' like the
   * 'notify' flow a key difference is that in the notify flow they don't have to return but in this flow they do.
   *
   * @return bool
   */
  protected function supportsPreApproval(): bool {
    return $this->supports['PreApproval'] ?? FALSE;
  }

  /**
   * Set the return value of support functions. By default it is TRUE
   *
   */
  public function setSupports(array $support) {
    $this->supports = array_merge($this->supports, $support);
  }

    /**
   * @param array|PropertyBag $params
   *
   * @param string $component
   *
   * @return array
   *   Result array (containing at least the key payment_status_id)
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doPayment(&$params, $component = 'contribute') {
    Civi::log()->debug('squarePP.php::doPayment component' . '  ' . print_r($component, true));
    Civi::log()->debug('squarePP.php::doPayment params' . '  ' . print_r($params, true));
    $this->_component = $component;
    //$statuses = CRM_Contribute_BAO_Contribution::buildOptions('contribution_status_id', 'validate');

    // $propertyBag = PropertyBag::cast($params);
    // if ((float) $propertyBag->getAmount() !== (float) $params['amount']) {
    //   CRM_Core_Error::deprecatedWarning('amount should be passed through in machine-ready format');
    // }
    // // If we have a $0 amount, skip call to processor and set payment_status to Completed.
    // // Conceivably a processor might override this - perhaps for setting up a token - but we don't
    // // have an example of that at the mome.
    // if ($propertyBag->getAmount() == 0) {
    //   $result['payment_status_id'] = array_search('Completed', $statuses);
    //   $result['payment_status'] = 'Completed';
    //   return $result;
    // }

    // Invoke hook_civicrm_paymentProcessor
    // In Dummy's case, there is no translation of parameters into
    // the back-end's canonical set of parameters.  But if a processor
    // does this, it needs to invoke this hook after it has done translation,
    // but before it actually starts talking to its proprietary back-end.
    // if ($propertyBag->getIsRecur()) {
    //   $throwAnENoticeIfNotSetAsTheseAreRequired = $propertyBag->getRecurFrequencyInterval() . $propertyBag->getRecurFrequencyUnit();
    // }
    // no translation in Dummy processor
    // CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $propertyBag);
    // // This means we can test failing transactions by setting a past year in expiry. A full expiry check would
    // // be more complete.
    // if (!empty($params['credit_card_exp_date']['Y']) && CRM_Utils_Time::date('Y') >
    //   CRM_Core_Payment_Form::getCreditCardExpirationYear($params)) {
    //   throw new PaymentProcessorException(ts('Invalid expiry date'));
    // }

    // if (!empty($this->_doDirectPaymentResult)) {
    //   $result = $this->_doDirectPaymentResult;
    //   if (empty($result['payment_status_id'])) {
    //     $result['payment_status_id'] = array_search('Pending', $statuses);
    //     $result['payment_status'] = 'Pending';
    //   }
    //   if ($result['payment_status_id'] === 'failed') {
    //     throw new PaymentProcessorException($result['message'] ?? 'failed');
    //   }
    //   $result['trxn_id'] = array_shift($this->_doDirectPaymentResult['trxn_id']);
    //   return $result;
    // }

    // $result['trxn_id'] = $this->getTrxnID();

    // // Add a fee_amount so we can make sure fees are handled properly in underlying classes.
    // $result['fee_amount'] = 1.50;
    // $result['description'] = $this->getPaymentDescription($params);

    // if (!isset($result['payment_status_id'])) {
    //   if (!empty($propertyBag->getIsRecur())) {
    //     // See comment block.
    //     $result['payment_status_id'] = array_search('Pending', $statuses);
    //     $result['payment_status'] = 'Pending';
    //   }
    //   else {
    //     $result['payment_status_id'] = array_search('Completed', $statuses);
    //     $result['payment_status'] = 'Completed';
    //   }
    // }

    return $result;
  }


  public function handlePaymentNotification() {
    Civi::log()->debug('SquarePP.php::handlePaymentNotification' . '  ');
        
    $rawData = file_get_contents("php://input");
    Civi::log()->debug('SquarePP.php::handlePaymentNotification rawDate' . '  ' . print_r($rawData, true));
    $rawData = json_decode($rawData, true);
    $ipnClass = new CRM_Core_Payment_SquareIPN($rawData);
    Civi::log()->debug('SquarePP.php::handlePaymentNotification ipnClass' . '  ' . print_r($ipnClass, true));
    if ($ipnClass->onReceiveWebhook()) {
      http_response_code(200);
      Civi::log()->debug('SquarePP.php::handlePaymentNotification ipnClass ReceiveWebhook' . '  ' . print_r($ipnClass->onReceiveWebhook(), true));
    }
  }

   /**
   * This function checks to see if we have the right config values.
   *
   * @return string
   *   the error message if any
   */
  public function checkConfig() {
    return NULL;
  }



}