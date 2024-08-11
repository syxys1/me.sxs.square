<?php

require_once __DIR__.'/../../vendor/autoload.php';

use Square\SquareClientBuilder;
use Square\Authentication\BearerAuthCredentialsBuilder;
use Square\Environment;
use Square\Exceptions\ApiException;

# set SQUARE_ACCESS_TOKEN in /etc/nginx/fastcgi.config
# Currently, the SQUARE_ACCESS_ TOKEN is set 
# in /etc/nginx/fastcgi.config
# TODO MUST change to get it from Payment Processor config from code

# Connect to Square account
#
function connectToSquare()
{
    Civi::log()->debug('squareUtils.php::connectToSquare');
    try {
        $client = SquareClientBuilder::init()
        ->bearerAuthCredentials(
            BearerAuthCredentialsBuilder::init(
                getenv('SQUARE_ACCESS_TOKEN')
              )
        )
        ->environment(Environment::SANDBOX)
        ->build();
    } catch (ApiException $e) {
        civi::log()->debug('squareUtils.php::connectToSquare ApiException occurred: ' . 
        print_r($e->getMessage(), true));
    }
    //Civi::log()->debug('squareUtils.php::connectToSquare client ' .  print_r($client, true));
    return $client;
}

# Generate an IdempotencyKey
#
function generateIdempotencyKey()
{
    $uniqueId1 = uniqid('',true);
    $uniqueId2 = uniqid(substr($uniqueId1, 0, 14) . '-',true);
    return $uniqueId2;
}

# List Square Location
#
function myListLocations($client)
{
    Civi::log()->debug('squareUtils.php::myListLocations');
    try {
        $apiResponse = $client->getLocationsApi()->listLocations();
        if ($apiResponse->isSuccess()) {
            $result = $apiResponse->getResult();
            $locations = array();
            $locations = $result->getLocations();
            foreach ($locations as $var) {
                Civi::log()->debug('squareUtils.php::myListLocations location id: ' . print_r($var->getId(),true));
                Civi::log()->debug('squareUtils.php::myListLocations location name: ' . print_r($var->getName(),true));
                $address = array();
                $address = $var->getAddress();
                Civi::log()->debug('squareUtils.php::myListLocations location address: ' . print_r($address,true));
                foreach ($address as $var2) {
                    Civi::log()->debug('squareUtils.php::myListLocations location address line 1: ' . print_r($var2->getAddressLine1(),true));
                    Civi::log()->debug('squareUtils.php::myListLocations location address line 2: ' . print_r($var2->getAddressLine2(),true));
                    Civi::log()->debug('squareUtils.php::myListLocations location address locality: ' . print_r($var2->getLocality(),true));
                }                
            }
    
        } else {
            $errors = $apiResponse->getErrors();
            foreach ($errors as $error) {
              Civi::log()->debug('squareUtils.php::myListLocations error ' . 
                print_r($error->getCategory(), true) . ' ' . 
                print_r($error->getCode(), true) . ' ' .
                print_r($error->getDetail(), true));
            }
        }
    } catch (ApiException $e) {
        civi::log()->debug('squareUtils.php::myListLocations ApiException occurred: ' . 
          print_r($e->getMessage(), true));
    }
    return ($locations);
}   

# List Square Location id
#
function myListLocationsIds($locations)
{
    Civi::log()->debug('squareUtils.php::myListLocationsIds');
    $locationsIds = array();
    foreach ($locations as $var) {
        $locationsIds[] = $var->getId();
    }
    return ($locationsIds);
}   

# Update Square Webhook Subscription 
#
function myUpdateWebhookSubscription($webhookUrl)
{
    Civi::log()->debug('squareUtils.php::myUpdateWebhookSubscription');
    $client = connectToSquare();
    $subscriptions = array();
    $subscriptions = myListWebhookSubscriptions($client);
    $found = 0;
    foreach ($subscriptions as $var) {
      Civi::log()->debug('squareUtils.php::myUpdateWebhookSubscription subscription id : ' . print_r($var->getId(), true));
      Civi::log()->debug('squareUtils.php::myUpdateWebhookSubscription subscription name: ' . print_r($var->getName(), true));
      Civi::log()->debug('squareUtils.php::myUpdateWebhookSubscription subscription notification_url : ' . print_r($var->getNotificationUrl(), true));
      $found += $var->getNotificationUrl() == $webhookUrl ? 1 : 0;
    }
    if (!$found) {
      createWebhookSubscription($client, $webhookUrl);
      Civi::log()->debug('squareUtils.php::myUpdateWebhookSubscription create new webhook');
    }
}
# List Square Webhook Subscriptions 
#
function myListWebhookSubscriptions($client)
{
  Civi::log()->debug('squareUtils.php::myListWebhookSubscriptions');

  try {
    $apiResponse = $client->getWebhookSubscriptionsApi()->listWebhookSubscriptions();
    if ($apiResponse->isSuccess()) {
      $result = $apiResponse->getResult();
      Civi::log()->debug('squareUtils.php::myListWebhookSubscriptions result ' . print_r($result,true));

      $subscriptions = array();
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

# Create a Webhook subscription.
#
function createWebhookSubscription($client, $webhookUrl) 
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
      } else {
        $errors = $apiResponse->getErrors();
        foreach ($errors as $error) {
            Civi::log()->debug('squareUtils.php::createWebhookSubscription errors ' . 
            print_r($error->getCategory(), true) . ' ' . 
            print_r($error->getCode(), true) . ' ' .
            print_r($error->getDetail(), true));
        }
      }
    } catch (ApiException $e) {
        Civi::log()->debug('squareUtils.php::createWebhookSubscription errors ApiException occurred: ' . 
        print_r($e->getMessage(), true));
    }
    return ($result);
  }

# Create new Square order 
#
function myPrepareOrderBody($requestFields)
{
    Civi::log()->debug('squareUtils.php::myPrepareOrderBody');
    $client = connectToSquare();

    $order_line_item_applied_tax = new \Square\Models\OrderLineItemAppliedTax($requestFields['line_item_tax']);
    $order_line_item_applied_tax1 = new \Square\Models\OrderLineItemAppliedTax($requestFields['line_item_tax1']);
    $applied_taxes = [$order_line_item_applied_tax, $order_line_item_applied_tax1];

    $base_price_money = new \Square\Models\Money();

    $base_price_money->setAmount($requestFields['base_price_amount']);
    //$base_price_money->setCurrency($requestFields['base_price_currency']);
    $base_price_money->setCurrency('CAD');
    
    $order_line_item = new \Square\Models\OrderLineItem($requestFields['line_item_qty']);
    $order_line_item->setUid($requestFields['line_item_uid']);
    if ($requestFields['line_item_type'] != 'CUSTOM_AMOUNT') {
        $order_line_item->setName($requestFields['line_item_name']);
    }
    
    Civi::log()->debug('squareUtils.php::myPrepareOrderBody ' . print_r(strlen($requestFields['line_item_note'])));
    $order_line_item->setNote($requestFields['line_item_note']);
    $order_line_item->setItemType($requestFields['line_item_type']);
    
    //$locationId = myListLocations($client)->getId();
    $order = new \Square\Models\Order(myListLocationsIds(myListLocations($client))[0]);

    if ($requestFields['applied_tax_amount'] > 0) {
        $order_line_item->setAppliedTaxes($applied_taxes);
        $applied_money = new \Square\Models\Money();
        $applied_money->setAmount($requestFields['applied_tax_amount']);
        //$applied_money->setCurrency($requestFields['base_price_currency']);
        $applied_money->setCurrency('CAD');

        $order_line_item_tax = new \Square\Models\OrderLineItemTax();
        $order_line_item_tax->setAppliedMoney($applied_money);

        $taxes = [$order_line_item_tax];
        $order->setTaxes($taxes);            
    }

    $order_line_item->setBasePriceMoney($base_price_money);
    $line_items = [$order_line_item];
    
    $order->setReferenceId($requestFields['reference_id']);
    $order->setCustomerId($requestFields['customer_id']);
    $order->setLineItems($line_items);
    
    $order->setState('OPEN');
    $order->setTicketName($requestFields['ticket_name']);
    
    $body = new \Square\Models\CreateOrderRequest();
    $body->setOrder($order);
    $body->setIdempotencyKey(generateIdempotencyKey());
    
    return ($body);
}

# Create new Square order 
#
function myCreateOrder($body)
{
    Civi::log()->debug('squareUtils.php::myCreateOrder');
    $client = connectToSquare();
    try {
        $apiResponse = $client->getOrdersApi()->createOrder($body);
        if ($apiResponse->isSuccess()) {
            $result = $apiResponse->getResult();
            //Civi::log()->debug('squareUtils.php::myCreateOrder result ' . print_r($result,true));
            
            $orders = array();
            $orders = $result->getOrder();
            Civi::log()->debug('squareUtils.php::myCreateOrder orders ' . print_r($orders,true));
             
            foreach ($orders as $var) {
                Civi::log()->debug('squareUtils.php::myCreateOrder order id : ' . print_r($var->getId(),true));
                Civi::log()->debug('squareUtils.php::myCreateOrder order location id : ' . print_r($var->getLocationId(),true));
                Civi::log()->debug('squareUtils.php::myCreateOrder order customer id : ' . print_r($var->getCustomerId(),true));
                $registeredLineItem = $var->getLineItems();
                Civi::log()->debug('squareUtils.php::myCreateOrder line items : ' . print_r($registeredLineItem,true));
                foreach ($registeredLineItem as $var2) {
                    Civi::log()->debug('squareUtils.php::myCreateOrder line item uid : ' . print_r($var2->getUid(),true));
                    Civi::log()->debug('squareUtils.php::myCreateOrder line item name : ' . print_r($var2->getName(),true));
                    Civi::log()->debug('squareUtils.php::myCreateOrder line item quantity : ' . print_r($var2->getQuantity(),true));
                    Civi::log()->debug('squareUtils.php::myCreateOrder line item base price amount : ' . print_r($var2->getBasePriceMoney()->getAmount(),true));
                    Civi::log()->debug('squareUtils.php::myCreateOrder line item base price currency : ' . print_r($var2->getBasePriceMoney()->getCurrency(),true));
                    Civi::log()->debug('squareUtils.php::myCreateOrder line item type : ' . print_r($var2->getItemType(),true));
                }                
                Civi::log()->debug('squareUtils.php::myCreateOrder order total money : ' . print_r($var->getTotalMoney(),true));
                Civi::log()->debug('squareUtils.php::myCreateOrder order total money amount : ' . print_r($var->getTotalMoney()->getAmount()));
                Civi::log()->debug('squareUtils.php::myCreateOrder order total money currency : ' . print_r($var->getTotalMoney()->getCurrency()));
            }
        } else {
            $errors = $apiResponse->getErrors();
            foreach ($errors as $error) {
                Civi::log()->debug('squareUtils.php::myCreateOrder errors ' . 
                    print_r($error->getCategory(), true) . ' ' . 
                    print_r($error->getCode(), true) . ' ' .
                    print_r($error->getDetail(), true));
                }
        }
    } catch (ApiException $e) {
        Civi::log()->debug('squareUtils.php::myCreateOrder errors ApiException occurred: ' . 
            print_r($e->getMessage(), true));
    }
    
    return ($orders);
}

# Create a new Square order 
# Convert it to invoice
#
function myPushInvoiceToSquare($requestFields)
{
    Civi::log()->debug('squareUtils.php::myPushInvoiceToSquare');
    Civi::log()->debug('squareUtils.php::myPushInvoiceToSquare $requestFields ' . print_r($requestFields, true));
    $body = myPrepareOrderBody($requestFields);
    Civi::log()->debug('squareUtils.php::myPushInvoiceToSquare $body ' . print_r($body, true));
    $order = myCreateOrder($body);
    Civi::log()->debug('squareUtils.php::myPushInvoiceToSquare $order ' . print_r($order, true));
    myCreateInvoice($order);
    return true;
}

# List Square Orders 
#
function myListOrders($client)
{
    Civi::log()->debug('squareUtils.php::myListOrders');
    $location_ids = array();
    $location_ids = myListLocationsIds(myListLocations($client));
    
    $states = ['OPEN'];
    $state_filter = new \Square\Models\SearchOrdersStateFilter($states);

    $filter = new \Square\Models\SearchOrdersFilter();
    $filter->setStateFilter($state_filter);

    $query = new \Square\Models\SearchOrdersQuery();
    $query->setFilter($filter);

    $body = new \Square\Models\SearchOrdersRequest();
    $body->setLocationIds($location_ids);
    $body->setQuery($query);
    $body->setReturnEntries(true);

    print_r('<br/>...<br/>');
    print_r('List Open Orders.');
    try {
        $apiResponse = $client->getOrdersApi()->searchOrders($body);

        if ($apiResponse->isSuccess()) {
            $result = $apiResponse->getResult();
            //print_r('<br/>...<br/>');
            //print_r('result : ');
            //print_r($result);
            //print_r('<br/>...<br/>');
            $orderEntries = array();

            $orderEntries = $result->getOrderEntries();
            //print_r('<br/>...<br/>');
            //print_r('orderEntries : ');
            //print_r($orderEntries);
            //print_r('<br/>...<br/>');
            foreach ($orderEntries as $var) {
                print_r('<br/>...<br/>');
                print_r('orderId: ');
                print_r($var->getOrderId());
                print_r('<br/>');
                print_r('locationId: ');
                print_r($var->getLocationId());
            }
        } else {
            $errors = $apiResponse->getErrors();
            foreach ($errors as $error) {
                Civi::log()->debug('squareUtils.php::myListOrders errors ' . 
                    print_r($error->getCategory(), true) . ' ' . 
                    print_r($error->getCode(), true) . ' ' .
                    print_r($error->getDetail(), true));
                }
        }
    } catch (ApiException $e) {
        Civi::log()->debug('squareUtils.php::myListOrders errors ApiException occurred: ' . 
            print_r($e->getMessage(), true));
    }
    return ($orderEntries);
}

# Create invoice from Open Order 
#
function myCreateInvoice($orders)
{
    Civi::log()->debug('squareUtils.php::myCreateInvoice');
    Civi::log()->debug('squareUtils.php::myCreateInvoice order date : ' . print_r(date('Y-m-d'), true));
    $invoice_payment_request = new \Square\Models\InvoicePaymentRequest();
    $invoice_payment_request->setRequestType('BALANCE');
    $invoice_payment_request->setDueDate(date('Y-m-d'));
    $invoice_payment_request->setAutomaticPaymentSource('NONE');
    
    $payment_requests = [$invoice_payment_request];
    $accepted_payment_methods = new \Square\Models\InvoiceAcceptedPaymentMethods();
    $accepted_payment_methods->setCard(true);
    $accepted_payment_methods->setBuyNowPayLater(false);
    $accepted_payment_methods->setCashAppPay(false);
    
    $invoice = new \Square\Models\Invoice();
    $invoice->setLocationId($orders->getLocationId());
    $invoice->setOrderId($orders->getId());
    $invoice->setPaymentRequests($payment_requests);
    $invoice->setDeliveryMethod('SHARE_MANUALLY');
    $invoice->setScheduledAt($orders->getCreatedAt());
    $invoice->setAcceptedPaymentMethods($accepted_payment_methods);
    
    $body = new \Square\Models\CreateInvoiceRequest($invoice);
    $body->setIdempotencyKey(generateIdempotencyKey());

    $client = connectToSquare(); 
    
    try {
        $apiResponse = $client->getInvoicesApi()->createInvoice($body);
    
        if ($apiResponse->isSuccess()) {
            $result = $apiResponse->getResult();
            Civi::log()->debug('squareUtils.php::myCreateInvoice $result : ' . print_r($result, true));
        
        } else {
            $errors = $apiResponse->getErrors();
            foreach ($errors as $error) {
                Civi::log()->debug('squareUtils.php::myCreateInvoice errors ' . 
                    print_r($error->getCategory(), true) . ' ' . 
                    print_r($error->getCode(), true) . ' ' .
                    print_r($error->getDetail(), true));
            }
        }
    } catch (ApiException $e) {
        Civi::log()->debug('squareUtils.php::myCreateInvoice errors ApiException occurred: ' . 
            print_r($e->getMessage(), true));
    }
}

# List invoice 
#
function myListInvoice($client)
{
    Civi::log()->debug('squareUtils.php::myListInvoice');
    try {
        $apiResponse = $client->getInvoicesApi()->listInvoices('L5PCE8REVXZN6');

        if ($apiResponse->isSuccess()) {
            $result = $apiResponse->getResult();
            //print_r('result : ');
            //print_r($result);
            //print_r('<br/>...<br/>');
            $invoices = array();

            $invoices = $result->getInvoices();
            //print_r('Invoices : ');
            //print_r($invoices);
            //print_r('<br/>...<br/>');
            foreach ($invoices as $var) {
                print_r('<br/>...<br/>');
                print_r('InvoiceId: ');
                print_r($var->getId());
                print_r('<br/>');
                print_r('Version: ');
                print_r($var->getVersion());
                print_r('<br/>');
                print_r('locationId: ');
                print_r($var->getLocationId());
                print_r('<br/>');
                print_r('orderId: ');
                print_r($var->getOrderId());
                print_r('<br/>');
                print_r('primary recipient: ');
                print_r($var->getPrimaryRecipient());
                print_r('<br/>');
                print_r('Payment Resquest: ');
                //print_r($var->getPaymentRequests());
                $paymentRequest = array();
                $paymentRequest = $var->getPaymentRequests();
                //print_r('<br/>...<br/>');
                //print_r($paymentRequest);
                foreach ($paymentRequest as $var2) {
                    print_r('<br/>...<br/>');
                    print_r('Payment Resquest - uid: ');
                    print_r($var2->getUid());
                    print_r('<br/>');
                    print_r('Payment Resquest - request method: ');
                    print_r($var2->getRequestMethod());
                    print_r('<br/>');
                    print_r('Payment Resquest - request type: ');
                    print_r($var2->getRequestType());
                    print_r('<br/>');
                    print_r('Payment Resquest - due Date: ');
                    print_r($var2->getDueDate());
                    print_r('<br/>');
                    print_r('computed amount money - Amount: ');
                    print_r($var2->getComputedAmountMoney()->getAmount());
                    print_r('<br/>');
                    print_r('computed amount money - Currency: ');
                    print_r($var2->getComputedAmountMoney()->getCurrency());
                }
       
                print_r('<br/>');
                print_r('delivery method: ');
                print_r($var->getDeliveryMethod());
                print_r('<br/>');
                print_r('invoice number: ');
                print_r($var->getInvoiceNumber());
                print_r('<br/>');
                print_r('title: ');
                print_r($var->getTitle());
                print_r('<br/>');
                print_r('description: ');
                print_r($var->getDescription());
                print_r('<br/>');
                print_r('status : ');
                print_r($var->getStatus());
                print_r('<br/>');
                print_r('createdAt : ');
                print_r($var->getCreatedAt());
                print_r('<br/>');
                print_r('updatedAt : ');
                print_r($var->getUpdatedAt());
            }

        } else {
            $errors = $apiResponse->getErrors();
            foreach ($errors as $error) {
                Civi::log()->debug('squareUtils.php::myListInvoice errors ' . 
                    print_r($error->getCategory(), true) . ' ' . 
                    print_r($error->getCode(), true) . ' ' .
                    print_r($error->getDetail(), true));
            }
        }
    } catch (ApiException $e) {
        Civi::log()->debug('squareUtils.php::myListInvoice errors ApiException occurred: ' . 
            print_r($e->getMessage(), true));
    }
}
