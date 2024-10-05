<?php
/*
 +----------------------------------------------------------------------------+
 | Square In Person Merchant Extension Payment Module for CiviCRM version 5   |
 +----------------------------------------------------------------------------+
 | Licensed to CiviCRM under the Academic Free License version 3.0            |
 |                                                                            |
 | Based on work from Eileen McNaughton - Nov March 2008                      |
 | Written by Sylvain Plante - July 2024                                      |
 +----------------------------------------------------------------------------+
 */

//require_once __DIR__.'/CRM/square/squareUtils.php';
use Civi\Payment\Exception\PaymentProcessorException;

/**
 * -----------------------------------------------------------------------------------------------
 * TO BE EDITED 
 * The basic functionality of this processor is that variables from the $params object are transformed
 * into xml. The xml is submitted to the processor's https site
 * using curl and the response is translated back into an array using the processor's function.
 *
 * If an array ($params) is returned to the calling function the values from
 * the array are merged into the calling functions array.
 *
 * If an result of class error is returned it is treated as a failure. No error denotes a success. Be
 * WARY of this when coding
 *
 * -----------------------------------------------------------------------------------------------
 */

class CRM_Core_Payment_SquarePP extends CRM_Core_Payment {
    
  /**
   * Payment Processor Mode
   *   either test or live
   * @var string
   */
  protected $_mode;

   /**
   * Constructor.
   *
   * @param string $mode
   *   The mode of operation: live or test.
   *
   * @param array $paymentProcessor
   */
  public function __construct($mode, &$paymentProcessor) {
    // live or test
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
  }

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
    'BackOffice' => TRUE,
    'NoEmailProvided' => FALSE,
    'CancelRecurring' => FALSE,
    'FutureRecurStartDate' => FALSE,
    'Refund' => FALSE,
  ];
  
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
   * Map fields to parameters. To be edited
   *
   * This function is set up and put here to make the mapping of fields
   * from the params object  as visually clear as possible for easy editing
   *
   * @param array $params
   *
   * @return array
   */
  public function mapProcessorFieldstoParams($params) {

    //$requestFields['a'] = $params['billing_first_name'];
    //$requestFields['b'] = $params['billing_last_name'];
    $requestFields['c'] = $params['first_name'];
    $requestFields['d'] = $params['last_name'];
    // 32 character string
    $requestFields['base_price_amount'] = (int)trim($params['amount']) * 100;
    $requestFields['base_price_currency'] = $params['currencyID'];
    $requestFields['applied_tax_amount'] = (int)trim($params['tax_amount']) * 100;
    $requestFields['line_item_uid'] = $params['invoiceID'];
    $requestFields['line_item_note'] = $params['description'];
    $requestFields['line_item_name'] = $params['amount_level'];
    $requestFields['line_item_type'] = 'CUSTOM_AMOUNT';
    $requestFields['line_item_qty'] = 1;
    $requestFields['line_item_tax'] = 'TPS';
    $requestFields['line_item_tax1'] = 'TVQ';
    
    $requestFields['reference_id'] = $params['contributionID'];
    $requestFields['customer_id'] = $params['contact_id'];
    $requestFields['ticket_name'] = $params['email-Primary'];
    
    //$requestFields['e'] = $params['street_address'];
    //$requestFields['f'] = $params['city'];
    //$requestFields['g'] = $params['state_province'];
    //$requestFields['h'] = $params['postal_code'];
    //$requestFields['i'] = $params['country'];
    
    return $requestFields;
  }

   /**
   * Set result from do Direct Payment for test purposes.
   *
   * @param array $doDirectPaymentResult
   *  Result to be returned from test.
   */
  public function setDoDirectPaymentResult($doDirectPaymentResult) {
    Civi::log()->debug('SuarePP.php::setDoDirectPaymentResult' . '  ' . $doDirectPaymentResult);
    $this->_doDirectPaymentResult = $doDirectPaymentResult;
    if (empty($this->_doDirectPaymentResult['trxn_id'])) {
      $this->_doDirectPaymentResult['trxn_id'] = [];
    }
    else {
      $this->_doDirectPaymentResult['trxn_id'] = (array) $doDirectPaymentResult['trxn_id'];
    }
  }


  /**
   * 
   * This function sends request to the processor or display info for manual process.
   *
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
    
    $propertyBag = \Civi\Payment\PropertyBag::cast($params);
    $this->_component = $component;
    $result = $this->setStatusPaymentPending([]);

    // If we have a $0 amount, skip call to processor and set payment_status to Completed.
    // Conceivably a processor might override this - perhaps for setting up a token - but we don't
    // have an example of that at the moment.
    //  $result['payment_status_id'] = array_search('Completed', $statuses);
    //  $result['payment_status'] = 'Completed';
    if ($propertyBag->getAmount() == 0) {
      $result = $this->setStatusPaymentCompleted($result);
      return $result;
    }

    if (isset($params['is_recur']) && $params['is_recur'] == TRUE) {
      throw new CRM_Core_Exception(ts('Square - recurring payments not implemented'));
    }

    //Create the array of variables to be sent to the processor from the $params array
    // passed into this function
    $requestFields = $this->mapProcessorFieldstoParams($params);
 
    // Invoke hook_civicrm_paymentProcessor
    // It needs to invoke this hook after it has done translation,
    // but before it actually starts talking to its proprietary back-end.
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $requestFields);

    // Check to see if we have a duplicate before we send
    if ($this->checkDupe($params['invoiceID'], CRM_Utils_Array::value('contributionID', $params))) {
      throw new PaymentProcessorException(ts('It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem.  Check your email for a receipt.  If you do not receive a receipt within 2 hours you can try your transaction again.  If you continue to have problems please contact the site administrator.'), 9003);
    }

    CRM_Square_Utils::myPushInvoiceToSquare($this->_paymentProcessor, $requestFields);

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
    Civi::log()->debug('SquarePP.php::handlePaymentNotification');
        
    $rawData = file_get_contents("php://input");
    //Civi::log()->debug('SquarePP.php::handlePaymentNotification rawData' . '  ' . print_r($rawData, true));
    $rawData = json_decode($rawData, true);
    //Civi::log()->debug('SquarePP.php::handlePaymentNotification rawData after json_decode' . '  ' . print_r($rawData, true));
    $ipnClass = new CRM_Core_Payment_SquareIPN($rawData);
    Civi::log()->debug('SquarePP.php::handlePaymentNotification ipnClass' . '  ' . print_r($ipnClass, true));

    
    Civi::log()->debug('SquarePP.php::handlePaymentNotification $rawData merchant_id' . '  ' . print_r($rawData["merchant_id"], true));
    Civi::log()->debug('SquarePP.php::handlePaymentNotification $rawData event_id' . '  ' . print_r($rawData["event_id"], true));
    Civi::log()->debug('SquarePP.php::handlePaymentNotification $rawData created_at' . '  ' . print_r($rawData["created_at"], true));
    Civi::log()->debug('SquarePP.php::handlePaymentNotification switch $rawData type' . '  ' . print_r($rawData["type"], true));
    $subData = $rawData["data"];

    switch ($rawData["type"]) {
      case "payment.created":
      case "payment.updated":
        Civi::log()->debug('SquarePP.php::handlePaymentNotification payment.created');
        Civi::log()->debug('SquarePP.php::handlePaymentNotification payment.updated');
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $rawData data' . '  ' . print_r($rawData["data"], true));
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $subData type' . '  ' . print_r($subData["type"], true));
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $subData id' . '  ' . print_r($subData["id"], true));
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $subData object' . '  ' . print_r($subData["object"], true));
        $payData = $subData["object"]["payment"];
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData amount_money' . '  ' . print_r($payData["amount_money"], true));
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData application_details' . '  ' . print_r($payData["application_details"], true));
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData approved_money' . '  ' . print_r($payData["approved_money"], true));
        //Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData capabilities' . '  ' . print_r($payData["capabilities"], true));
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData card_details' . '  ' . print_r($payData["card_details"], true));
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData created_at' . '  ' . print_r($payData["created_at"], true));
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData customer_id' . '  ' . print_r($payData["customer_id"], true));
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData id' . '  ' . print_r($payData["id"], true));
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData location_id' . '  ' . print_r($payData["location_id"], true));
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData order_id' . '  ' . print_r($payData["order_id"], true));
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData receipt_number' . '  ' . print_r($payData["receipt_number"], true));
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData reference_id' . '  ' . print_r($payData["reference_id"], true));
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData source_type' . '  ' . print_r($payData["source_type"], true));
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData status' . '  ' . print_r($payData["status"], true));
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData total_money' . '  ' . print_r($payData["total_money"], true));
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData updated_at' . '  ' . print_r($payData["updated_at"], true));
        Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData version' . '  ' . print_r($payData["version"], true));
        
        switch ($payData["status"]) {
          case "APPROVED":
            Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData status APPROVED - wait for completed');
            Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData status' . '  ' . print_r($payData["status"], true));
            Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData version' . '  ' . print_r($payData["version"], true));
            break;
          case "COMPLETED":
            Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData status COMPLETED');
            Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData status' . '  ' . print_r($payData["status"], true));
            Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData version' . '  ' . print_r($payData["version"], true));
            
            $cardData = $payData["card_details"];
            Civi::log()->debug('SquarePP.php::handlePaymentNotification $cardData' . '  ' . print_r($cardData, true));

            if ($this->_mode === 'test') {
              $query = "SELECT MAX(trxn_id) FROM civicrm_contribution WHERE trxn_id LIKE 'test%'";
              $trxn_id = (string) CRM_Core_DAO::singleValueQuery($query);
              $trxn_id = (int) str_replace('test', '', $trxn_id);
              ++$trxn_id;
              $result['trxn_id'] = sprintf('test%08d', $trxn_id);
              return $result;
            }
              //throw new PaymentProcessorException('Error: [approval code related to test transaction but mode was ' . $this->_mode, 9099);
            else {
              $result['trxn_id'] = $payData['id'];
              Civi::log()->debug('SquarePP.php::handlePaymentNotification $payData id' . '  ' . print_r($payData['id'], true));
              $params['trxn_result_code'] = $cardData['card']['bin'] . "-Cvv2:" . $cardData['cvv_status'] . "-avs:" . $cardData['avs_status'];
              Civi::log()->debug('SquarePP.php::handlePaymentNotification $params' . '  ' . print_r($params, true));
              
              $contributions = \Civi\Api4\Contribution::get(FALSE)
                ->addSelect('RIGHT(invoice_id, 4) AS invoice_id_last4', 'id', 'contact_id', 'payment_instrument_id', 'total_amount', 'invoice_id', 'trxn_id', 'financial_type_id', 'receive_date', 'currency', 'contribution_status_id', 'paid_amount', 'balance_amount')
                ->addWhere('contribution_status_id', '=', 2)
                //->addWhere('contact_id', '=', $payData["customer_id"])
                ->addWhere('balance_amount', '>', 0)
                ->setHaving([['invoice_id_last4', 'LIKE', $payData["reference_id"]]])
                ->execute();
              Civi::log()->debug('SquarePP.php::handlePaymentNotification $contributions' . '  ' . print_r($contributions, true));
              
              foreach ($contributions as $contribution) {
                Civi::log()->debug('SquarePP.php::handlePaymentNotification $contribution id' . '  ' . print_r($contribution["id"], true));
                if (strcasecmp($contribution["contact_id"], $payData["customer_id"]) <> 0) {
                  Civi::log()->debug('SquarePP.php::handlePaymentNotification $contribution contact_id' . ' ' .
                    print_r($contribution["contact_id"], true) . ' is different from payee id ' . print_r($payData["customer_id"], true));
                }
                if (strcasecmp($contribution["currency"], $payData["amount_money"]["currency"]) <> 0) {
                  Civi::log()->debug('SquarePP.php::handlePaymentNotification $contribution currency' . '  ' . 
                    print_r($contribution["currency"], true) . '  is different from payee currency ( not supported ) : ' . 
                    print_r($payData["amount_money"]["currency"], true));
                  return $result = null;
                }

                $x = ((int)$payData["amount_money"]["amount"] / 100) - (int)$contribution["balance_amount"];
                Civi::log()->debug('SquarePP.php::handlePaymentNotification x' . '  ' . print_r($x, true));
                Civi::log()->debug('SquarePP.php::handlePaymentNotification payData' . '  ' . print_r((int)$payData["amount_money"]["amount"] / 100, true));
                Civi::log()->debug('SquarePP.php::handlePaymentNotification contribution' . '  ' . print_r((int)$contribution["balance_amount"], true));
                   
                switch (true) {
                  case $x == 0:
                    $contribution["contribution_status_id"] = 1;
                    $contribution["trxn_id"] = $payData['id'];
                    Civi::log()->debug('SquarePP.php::handlePaymentNotification $contribution trxn_id' . '  ' . print_r($contribution["trxn_id"], true));
                    break;
                  case $x < 0:
                    Civi::log()->debug('SquarePP.php::handlePaymentNotification $contribution balance amount' . '  ' . 
                      print_r($contribution["balance_amount"], true) . '  is greater then payee amount ( still a balance ) : ' . 
                      print_r(((int)$payData["amount_money"]["amount"] / 100), true));
                    break;
                  case $x > 0;
                    Civi::log()->debug('SquarePP.php::handlePaymentNotification $contribution balance amount' . '  ' . 
                      print_r($contribution["balance_amount"], true) . '  is less then payee amount ( not supported ) : ' . 
                      print_r(((int)$payData["amount_money"]["amount"] / 100), true));
                    return $result = null;
                    break;
                }
            
                $results = \Civi\Api4\Contribution::update(FALSE)
                  //->addValue('payment_instrument_id', '')
                  ->addValue('paid_amount', ((int)$payData["amount_money"]["amount"] / 100))
                  ->addValue('trxn_id', $contribution["trxn_id"])
                  ->addValue('contribution_status_id', $contribution["contribution_status_id"])
                  ->addWhere('id', '=', $contribution["id"])
                  ->execute();
                foreach ($results as $result) {
                  Civi::log()->debug('SquarePP.php::handlePaymentNotification $contribution update result' . '  ' . print_r($result, true));
                    // do something
                }
                // do something
              }
                            
              $result = $this->setStatusPaymentCompleted($result);
              Civi::log()->debug('SquarePP.php::handlePaymentNotification $result' . '  ' . print_r($result, true));
              return $result;
            }
            break;
          default:
            Civi::log()->debug('SquarePP.php::handlePaymentNotification other status' . ' ' . print_r($payData["status"], true));
        }
        break;
      case "order.created":
        Civi::log()->debug('SquarePP.php::handlePaymentNotification order.created');
        break;
      case "order.updated":
        Civi::log()->debug('SquarePP.php::handlePaymentNotification order.updated');
        break;
      default:
      Civi::log()->debug('SquarePP.php::handlePaymentNotification undefined response');
    }
    
    
    
    
    
    if ($ipnClass->onReceiveWebhook()) {
      http_response_code(200);
      Civi::log()->debug('SquarePP.php::handlePaymentNotification ipnClass ReceiveWebhook' . '  ' . print_r($ipnClass->onReceiveWebhook(), true));
    }
  }

  /**
   * This public function checks to see if we have the right processor config values set.
   *
   * NOTE: Called by Events and Contribute to check config params are set prior to trying
   *  register any credit card details
   *
   * @return string|null
   *   $errorMsg if any errors found - null if OK
   *
   */
  public function checkConfig() {
    $errorMsg = [];

    if (empty($this->_paymentProcessor['user_name'])) {
      $errorMsg[] = ' ' . ts('ssl_merchant_id is not set for this payment processor');
    }

    if (empty($this->_paymentProcessor['url_site'])) {
      $errorMsg[] = ' ' . ts('URL is not set for this payment processor');
    }

    if (!empty($errorMsg)) {
      return implode('<p>', $errorMsg);
    }
    return NULL;
  }
  


}
