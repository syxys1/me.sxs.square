<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\PaymentProcessor;

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */
class CRM_Core_Payment_SquareIPN extends CRM_Core_Payment_BaseIPN {
  
  /**
   * Input parameters from payment processor. Store these so that
   * the code does not need to keep retrieving from the http request
   * @var array
   */
  protected $_inputParameters = [];

  /**
   * Constructor function.
   *
   * @param array $inputData
   *   contents of HTTP REQUEST.
   *
   * @throws CRM_Core_Exception
   */
  public function __construct($inputData) {
    //Civi::log()->debug('SquareIPN.php::__Construct inputData' . '  ' . print_r($inputData, true));
    
    $this->setInputParameters($inputData);
    parent::__construct();
  }

  /**
   * @var string
   */
  protected $transactionID;

  /**
   * @var string
   */
  protected $contributionStatus;

   /**
   * @param string $name
   * @param string $type
   * @param bool $abort
   *
   * @return mixed
   * @throws \CRM_Core_Exception
   */
  public function retrieve($name, $type, $abort = TRUE) {
    $value = CRM_Utils_Type::validate(CRM_Utils_Array::value($name, $this->_inputParameters), $type, FALSE);
    if ($abort && $value === NULL) {
      throw new CRM_Core_Exception("SquareIPN: Could not find an entry for $name");
    }
    return $value;
  }

  /**
   * @return mixed
   * @throws \CRM_Core_Exception
   */
  protected function getTrxnType() {
    return $this->retrieve('txn_type', 'String');
  }

   /**
   * Get Contribution ID.
   *
   * @return int
   *
   * @throws \CRM_Core_Exception
   */
  protected function getContributionID(): int {
    return (int) $this->retrieve('contributionID', 'Integer', TRUE);
  }

    /**
   * Get contact id from parameters.
   *
   * @return int
   *
   * @throws \CRM_Core_Exception
   */
  protected function getContactID(): int {
    return (int) $this->retrieve('contactID', 'Integer', TRUE);
  }


   /**
   * Main IPN processing function.
   */
  public function main() {
    try {
      //we only get invoice num as a key player from payment gateway response.
      //for ARB we get x_subscription_id and x_subscription_paynum
      // @todo - no idea what the above comment means. The do-nothing line below
      // this is only still here as it might relate???
      Civi::log()->debug('SquareIPN.php::main this' . '  ' . print_r($this, true));
    
      $x_subscription_id = $this->getRecurProcessorID();

      if (!$this->isSuccess()) {
        $errorMessage = ts('Subscription payment failed - %1', [1 => htmlspecialchars($this->getInput()['response_reason_text'])]);
        ContributionRecur::update(FALSE)
          ->addWhere('id', '=', $this->getContributionRecurID())
          ->setValues([
            'contribution_status_id:name' => 'Failed',
            'cancel_date' => 'now',
            'cancel_reason' => $errorMessage,
          ])->execute();
        \Civi::log('authorize_net')->info($errorMessage);
        return;
      }
      if ($this->getContributionStatus() !== 'Completed') {
        ContributionRecur::update(FALSE)->addWhere('id', '=', $this->getContributionRecurID())
          ->setValues(['trxn_id' => $this->getRecurProcessorID()])->execute();
        $contributionID = $this->getContributionID();
      }
      else {
        $contribution = civicrm_api3('Contribution', 'repeattransaction', [
          'contribution_recur_id' => $this->getContributionRecurID(),
          'receive_date' => $this->getInput()['receive_date'],
          'payment_processor_id' => $this->getPaymentProcessorID(),
          'trxn_id' => $this->getInput()['trxn_id'],
          'amount' => $this->getAmount(),
        ]);
        $contributionID = $contribution['id'];
      }
      civicrm_api3('Payment', 'create', [
        'trxn_id' => $this->getInput()['trxn_id'],
        'trxn_date' => $this->getInput()['receive_date'],
        'payment_processor_id' => $this->getPaymentProcessorID(),
        'contribution_id' => $contributionID,
        'total_amount' => $this->getAmount(),
        'is_send_contribution_notification' => $this->getContributionRecur()->is_email_receipt,
      ]);
      $this->notify();
    }
    catch (CRM_Core_Exception $e) {
      Civi::log('authorize_net')->debug($e->getMessage());
      echo 'Invalid or missing data';
    }
  }


  /**
   * onReceiveWebhook()
   *
   * @param string 
   *
   * @param array 
   *  
   * @return bool
   */
  public function onReceiveWebhook() {
    return TRUE;
  }

}