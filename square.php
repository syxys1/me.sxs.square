<?php

require_once 'square.civix.php';

$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
  require_once $autoload;
}

// phpcs:disable
use CRM_Square_ExtensionUtil as E;
// phpcs:enable

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function square_civicrm_config(&$config): void {
  _square_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function square_civicrm_install(): void {
  _square_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function square_civicrm_enable(): void {
  _square_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_check().
 * 
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_check
 */
function square_civicrm_check(&$messages) {
  CRM_Core_Payment_SquarePP::checkConfig2($messages);
  //CRM_Square_Webhook::check($messages);
}

/**
 * Implements hook_civicrm_managed().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function square_civicrm_managed(&$entities): void {
  Civi::log()->debug('square.php::civicrm_managed hook');
  foreach ($entities as $entity) {
    if($entity["module"] == 'me.sxs.square') {
      Civi::log()->debug('square.php::civicrm_managed entity' . '  ' . print_r($entity, true));
    }
  }
}

/**
 * Implements hook_civicrm_postinstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postinstall
 */
function square_civicrm_postinstall(): void {
  Civi::log()->debug('square.php::civicrm_postinstall hook');
  
  // Check if a financial account "Square Account" exist.
  // If not, create it.
  $financial_accounts = \Civi\Api4\FinancialAccount::save(FALSE)
    ->addRecord([
      'name' => 'Square Account',
      'contact_id' => 1,
      'financial_account_type_id' => 1,
      'account_type_code' => 'BANK',
      'description' => 'Square payment processor merchant account',
      'is_reserved'=> false,
      'is_active'=> true,
      'is_default'=> false ])
    ->setMatch(['name'])
    ->execute();
  
  foreach ($financial_accounts as $financial_account) {
    Civi::log()->debug('square.php::post_install hook financial account' . '  ' . print_r($financial_account, true));
    // Check if a payment instrument name "square terminal" exist.
    // If not, create it.
    $optionGroups = \Civi\Api4\OptionGroup::get(TRUE)
      ->addSelect('id')
      ->addWhere('name', '=', 'payment_instrument')
      ->execute();
    foreach ($optionGroups as $optionGroup) {
      Civi::log()->debug('square.php::post_install hook optionGroup' . '  ' . print_r($optionGroup, true));
      Civi::log()->debug('square.php::post_install hook optionGroup[id]' . '  ' . print_r($optionGroup["id"], true));
      $payment_instruments = \Civi\Api4\OptionValue::save(TRUE)
        ->addRecord([
          'option_group_id' => $optionGroup["id"],
          'label' => 'Square terminal',
          'name' => 'Square terminal',
          'grouping' => NULL,
          'filter' => 0,
          'is_default' => FALSE,
          'description' => 'Payment made with onsite Square terminal',
          'is_optgroup' => FALSE,
          'is_reserved' => TRUE,
          'is_active' => TRUE ])
        ->setMatch(['option_group_id', 'name'])
        ->execute();
      foreach ($payment_instruments as $payment_instrument) {
        Civi::log()->debug('square.php::post_install hook payment instrument' . '  ' . print_r($payment_instrument, true));
        // do something
      }
    }
  }

  // Check if the payment processor type "Square Terminal" exist.
  // If not, create it.
  $paymentProcessorTypes = \Civi\Api4\PaymentProcessorType::save(TRUE)
    ->addRecord([
      'name'=> 'Square Terminal',
      'title'=> 'Square for Terminal Integration',
      'description' => E::ts('Square payment processor for onsite terminal integration'),
      'is_active'=> true,
      'is_default'=> false,
      'user_name_label'=> 'Access Token',
      'signature_label'=> 'Webhook Signature Key',
      'class_name'=> 'Payment_SquarePP',
      'url_site_default' => 'https://unused.org',
      'url_api_default'=> 'https://connect.squareup.com',
      'url_site_test_default' => 'https://unused.org',
      'url_api_test_default'=> 'https://connect.squareupsandbox.com',
      'billing_mode'=> 4,
      'is_recur'=> 0,
      'payment_type' => 1,
      'payment_instrument_id'=> $payment_instrument["id"] ])
    ->setMatch(['name'])
    ->execute();
  foreach ($paymentProcessorTypes as $paymentProcessorType) {
    Civi::log()->debug('square.php::post_install hook payment processor type' . '  ' . print_r($paymentProcessorType, true));
    // do something
  }
}

/**
 * Implements hook_civicrm_postCommit().
 * 
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postCommit
 * 
 * hook_civicrm_postCommit($op, $objectName, $objectId, &$objectRef)
 */
function square_civicrm_postCommit(string $op, string $objectName, int $objectId, &$objectRef) {
  Civi::log()->debug('square.php::postCommit hook op ' . print_r($op, true));
  Civi::log()->debug('square.php::postCommit hook objectName ' . print_r($objectName, true));
  Civi::log()->debug('square.php::postCommit hook objectId ' . print_r($objectId, true));

  if ($objectName == 'PaymentProcessor' && $op == 'create') {
    if ($objectRef->class_name == 'Payment_SquarePP') {
      if(!$objectRef->is_test) {
        Civi::log()->debug('square.php::postCommit hook objectRef ' . print_r($objectRef, true));
        Civi::log()->debug('square.php::postCommit hook objectRef class_name ' . print_r($objectRef->class_name, true));
        // Test for webhook presence
        $paymentProcessors = get_object_vars($objectRef);
        CRM_Square_Webhook::myUpdateWebhookSubscription($paymentProcessors);
        // Test for syncing Square Id with contact
      }
    }
  }
}

/**
 * Implements hook_civicrm_postIPNProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postIPNProcess
 */
function square_civicrm_postIPNProcess(&$IPNData) {
  Civi::log()->debug('square.php::postIPNProcess hook' . '  ' . print_r($IPNData, true));
  if (!empty($IPNData['custom'])) {
    $customParams = json_decode($IPNData['custom'], TRUE);
    if (!empty($customParams['gaid'])) {
      Civi::log()->debug('square.php::postIPNProcess hook json decode' . '  ' . print_r($customParams["gaid"], true));
      // do something
    }
  }
}

/**
 * Implements hook_civicrm_postProcess().
 * 
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postIPNProcess
 * 
 * @param string $formName
 * @param CRM_Core_Form $form
 */
//function square_civicrm_postProcess($formName, $form) {
  // Civi::log()->debug('square.php::postProcess hook formName' . '  ' . print_r($formName, true));
  // Civi::log()->debug('square.php::postProcess hook form' . '  ' . print_r($form, true));  
//}

/**
 * Implements hook_civicrm_alterContent().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterContent
 */
function square_civicrm_alterContent(&$content, $context, &$tplName, &$object) {
  // Civi::log()->debug('square.php::civicrm_alterContent Hook templateName' . '  ' . print_r($tplName, true));
  // Civi::log()->debug('squere.php::civicrm_alterContent Hook context' . '  ' . print_r($context, true));
  
  if ($context == "form") {
    if ($tplName == "CRM/Event/Form/Registration/Confirm.tpl") {
      $closuremethod = Closure::bind(function($object) {
        return $object->_params;
      }, null, get_class($object));
      $lParams = $closuremethod($object);
      Civi::log()->debug('square.php::civicrm_alterContent Hook lParams.0.InvoiceID' . '  ' . print_r( $lParams[0]["invoiceID"], true));
  
      //Civi::log()->debug('square.php::civicrm_alterContent Hook lParams.0.InvoiceID' . '  ' . print_r( $accessProtected ($object, "invoiceID"), true));
  
      $payInvoiceID = ts("Invoice ID") . ": " . $lParams[0]["invoiceID"];
      $payContactID = ts("Client ID") . ": " . $lParams[0]["contact_id"];
      $payAmount = ts("Total Amount") . ": " . $lParams[0]["amount"];
      $payInstruction = ts("Please proceed to Square terminal and use these values to complete the transaction.");  
      // Find the div element with id "continue_message-section"
      $pattern = '/<\s*div\s*class\s*=\s*".*\Qcontinue_message-section\E.*"\s*>\X*?<\s*\/div\s*>/';
      $replacement = '$0<br/><div>' . $payInstruction . '<br/>' . $payContactID . '<br/>' . $payInvoiceID . '</div>';
      // Replace div element with pay_instruction 
      $content = preg_replace ($pattern, $replacement, $content, 1); 
      }
    else {
      if($tplName == "CRM/Financial/Form/Payment.tpl") {
        Civi::log()->debug('squere.php::civicrm_alterContent Hook $content' . '  ' . print_r($content, true));
        // todo support other forms  
      }
    }
  }   
}

/**
 * Implements Closure::bind to access protected properties.
 * 
 */
{
  $accessProtected = function & ($obj, $prop) {
    $value = & Closure::bind(function & () use ($prop) {
      return $this->$prop;
    }, $obj, $obj)->__invoke();

    return $value;
  };
}
