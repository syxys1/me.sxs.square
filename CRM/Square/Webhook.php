<?php

use CRM_Square_ExtensionUtil as E;

class CRM_Square_Webhook {

  /**
   * Get the path of the webhook
   *
   * @param string $paymentProcessorId
   *
   * @return string
   */
  public static function getWebhookPath($paymentProcessorId): string {
    return CRM_Utils_System::url('civicrm/payment/ipn/' . $paymentProcessorId, NULL, TRUE, NULL, FALSE, TRUE);
  }

  /**
   * Verify if the webhook was installed
   */
  public static function check(&$messages): void {
    $paymentProcessors = \Civi\Api4\PaymentProcessor::get(FALSE)
      ->addWhere('class_name', '=', 'Payment_SquarePP')
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('domain_id', '=', 'current_domain')
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute(); 

    foreach ($paymentProcessors as $paymentProcessor) {
      try {
        $match_found = FALSE;
        $all_webhooks = [];
        $expect_url = self::getWebhookPath($paymentProcessor['id']);
        $client = CRM_Square_Utils::connectToSquare($paymentProcessor['user_name']);
        $webhooks = self::myListWebhookSubscriptions($client);

        foreach ($webhooks as $wh) {
          $url = $wh->getNotificationUrl();
          if ($url == $expect_url) {
            $match_found = TRUE;
          }
          $all_webhooks[] = $url;
        }
      }
      catch (Exception $e) {
        $messages[] = new CRM_Utils_Check_Message(
          __FUNCTION__ . $paymentProcessor['id'] . 'square_webhook',
          $e->getMessage(),
          self::getTitle($paymentProcessor),
          \Psr\Log\LogLevel::ERROR,
          'fa-money'
        );
      }

      if ($match_found) {
        $messages[] = new CRM_Utils_Check_Message(
          __FUNCTION__ . $paymentProcessor['id'] . 'square_webhook',
          E::ts('Found a Square webhook %1', [1 => $expect_url]) . (count($all_webhooks) > 1 ? ' ' . E::ts('Found: %1', [1 => implode(', ', $all_webhooks)]) : ''),
          self::getTitle($paymentProcessor),
          \Psr\Log\LogLevel::INFO,
          'fa-money'
        );
      }
      else {
        $message = new CRM_Utils_Check_Message(
          __FUNCTION__ . $paymentProcessor['id'] . 'square_webhook',
          E::ts('The Square webhook does not exist. This means that we will not be notified when a payment is processed. You can it in the Square console under Webhooks > Subscriptions. The webhook URL is: %1', [1 => $expect_url]) . (count($all_webhooks) > 1 ? ' ' . E::ts('Found: %1', [1 => implode(', ', $all_webhooks)]) : ''),
          self::getTitle($paymentProcessor),
          \Psr\Log\LogLevel::ERROR,
          'fa-money'
        );
/* @todo Copied from Stripe
        $message->addAction(
          E::ts('View and fix problems'),
          NULL,
          'href',
          ['path' => 'civicrm/square/fix-webhook', 'query' => ['reset' => 1]]
        );
*/
        $messages[] = $message;
      }
    }
  }

  /**     
   * Get the error message title for the system check
   * (based on the getTitle function from Stripe)
   *
   * @param array $paymentProcessor
   *
   * @return string
   */
  private static function getTitle(array $paymentProcessor): string {
    if (!empty($paymentProcessor['is_test'])) {
      $paymentProcessor['name'] .= ' (test)';
    }
    return E::ts('Stripe Payment Processor: %1 (%2)', [
      1 => $paymentProcessor['name'],
      2 => $paymentProcessor['id'],
    ]);
  }

  /**
   * Update Square Webhook Subscription 
   * @todo Not currently used
   */
  function myUpdateWebhookSubscription($paymentProcessor): void {
    Civi::log()->debug('squareUtils.php::myUpdateWebhookSubscription');
    $client = CRM_Square_Utils::connectToSquare($paymentProcessor['user_name']);
    $subscriptions = [];
    $subscriptions = self::myListWebhookSubscriptions($client);
    $found = 0;
    foreach ($subscriptions as $var) {
      Civi::log()->debug('squareUtils.php::myUpdateWebhookSubscription subscription id : ' . print_r($var->getId(), true));
      Civi::log()->debug('squareUtils.php::myUpdateWebhookSubscription subscription name: ' . print_r($var->getName(), true));
      Civi::log()->debug('squareUtils.php::myUpdateWebhookSubscription subscription notification_url : ' . print_r($var->getNotificationUrl(), true));
      $found += $var->getNotificationUrl() == $webhookUrl ? 1 : 0;
    }
    if (!$found) {
      self::createWebhookSubscription($client, $webhookUrl);
      Civi::log()->debug('squareUtils.php::myUpdateWebhookSubscription create new webhook');
    }
  }

  /**
   * List Square Webhook Subscriptions 
   */
  public static function myListWebhookSubscriptions($client)
  {
    Civi::log()->debug('squareUtils.php::myListWebhookSubscriptions');

    try {
      $apiResponse = $client->getWebhookSubscriptionsApi()->listWebhookSubscriptions();
      if ($apiResponse->isSuccess()) {
        $result = $apiResponse->getResult();
        Civi::log()->debug('squareUtils.php::myListWebhookSubscriptions result ' . print_r($result,true));

        $subscriptions = [];
        $subscriptions = $result->getSubscriptions();
        Civi::log()->debug('squareUtils.php::myListWebhookSubscriptions subscriptions ' . print_r($subscriptions,true));
 
        foreach ($subscriptions as $var) {
          Civi::log()->debug('squareUtils.php::myListWebhookSubscriptions subscriptions id : ' . print_r($var->getId(),true));
          Civi::log()->debug('squareUtils.php::myListWebhookSubscriptions subscriptions name : ' . print_r($var->getName(),true));
          Civi::log()->debug('squareUtils.php::myListWebhookSubscriptions subscriptions notification Url : ' . print_r($var->getNotificationUrl(),true));
        }
      } else {
        $errors = $apiResponse->getErrors();
        foreach ($errors as $error) {
          Civi::log()->debug('squareUtils.php::myListWebhookSubscriptions errors ' . 
          print_r($error->getCategory(), true) . ' ' . 
          print_r($error->getCode(), true) . ' ' .
          print_r($error->getDetail(), true));
        }
      }
    } catch (ApiException $e) {
      Civi::log()->debug('squareUtils.php::myListWebhookSubscriptions errors ApiException occurred: ' . 
          print_r($e->getMessage(), true));
    }
    return ($subscriptions);
  }

  /**
   * Create a Webhook subscription.
   */
  public static function createWebhookSubscription($client, $webhookUrl)
  {
    Civi::log()->debug('squareUtils.php::createWebhookSubscription');
    # define event to listen to
    $event_types = ['payment.created', 'payment.updated'];
    $subscription = new \Square\Models\WebhookSubscription();
    # define webhook name
    # TODO Function to generate the webhook name
    $subscription->setName('CiviCRM_Webhook');
    $subscription->setEventTypes($event_types);
    $subscription->setNotificationUrl($webhookUrl);

    $body = new \Square\Models\CreateWebhookSubscriptionRequest($subscription);
    $body->setIdempotencyKey(generateIdempotencyKey());
    print_r('<br/>...<br/>');
    print_r('Create Webhook Subscription.');
    try {
      $apiResponse = $client->getWebhookSubscriptionsApi()->createWebhookSubscription($body);
      if ($apiResponse->isSuccess()) {
        $result = $apiResponse->getResult();
        print_r('result : ');
        print_r($result);
        print_r('<br/>...<br/>');
      }
      else {
        $errors = $apiResponse->getErrors();
        foreach ($errors as $error) {
          Civi::log()->debug('squareUtils.php::createWebhookSubscription errors ' .
          print_r($error->getCategory(), true) . ' ' .
          print_r($error->getCode(), true) . ' ' .
          print_r($error->getDetail(), true));
        }
      }
    }
    catch (ApiException $e) {
      Civi::log()->debug('squareUtils.php::createWebhookSubscription errors ApiException occurred: ' . print_r($e->getMessage(), true));
    }
    return ($result);
  }


}
