<?php

use CRM_Square_ExtensionUtil as E;

use Square\SquareClientBuilder;
use Square\Authentication\BearerAuthCredentialsBuilder;
use Square\Environment;
use Square\Exceptions\ApiException;

class CRM_Square_Utils {

  /**
   * Connect to Square account
   */
  public static function connectToSquare(array $paymentProcessor)
  {
    Civi::log()->debug('CRM_Square_Utils::connectToSquare');
    Civi::log()->debug('CRM_Square_Utils::connectToSquare paymentProcessor ' . print_r($paymentProcessor, true));
    
    $env = $paymentProcessor['is_test'] ? Environment::SANDBOX : Environment::PRODUCTION;
    $client = SquareClientBuilder::init()
      ->bearerAuthCredentials(BearerAuthCredentialsBuilder::init($paymentProcessor['user_name']))
      ->environment($env)
      ->build();
    return $client;
  }

  /**
   * Generate an IdempotencyKey
   */
  public static function generateIdempotencyKey()
  {
    $uniqueId1 = uniqid('',true);
    $uniqueId2 = uniqid(substr($uniqueId1, 0, 14) . '-',true);
    return $uniqueId2;
  }

  /**
   * List Square Location
   */
  public static function myListLocations($client)
  {
    Civi::log()->debug('CRM_Square_Utils::myListLocations');
    $location = [];
    $apiResponse = $client->getLocationsApi()->listLocations();
    if ($apiResponse->isSuccess()) {
      $result = $apiResponse->getResult();
      $locations = [];
      $locations = $result->getLocations();
      foreach ($locations as $var) {
        Civi::log()->debug('squareUtils.php::myListLocations location id: ' . print_r($var->getId(),true));
        Civi::log()->debug('squareUtils.php::myListLocations location name: ' . print_r($var->getName(),true));
        $address = [];
        $address = $var->getAddress();
        Civi::log()->debug('squareUtils.php::myListLocations location address: ' . print_r($address,true));
        foreach ($address as $var2) {
          Civi::log()->debug('squareUtils.php::myListLocations location address line 1: ' . print_r($var2->getAddressLine1(),true));
          Civi::log()->debug('squareUtils.php::myListLocations location address line 2: ' . print_r($var2->getAddressLine2(),true));
          Civi::log()->debug('squareUtils.php::myListLocations location address locality: ' . print_r($var2->getLocality(),true));
        }
      }
    }
    else {
      $errors = $apiResponse->getErrors();
      throw new Exception('Square getLocationsApi failed: category=' . print_r($errors, 1));
    }
    return $locations;
  }

  /**
   * List Square Location id
   */
  public static function myListLocationsIds($locations)
  {
    Civi::log()->debug('squareUtils.php::myListLocationsIds');
    $locationsIds = [];
    foreach ($locations as $var) {
      $locationsIds[] = $var->getId();
    }
    return $locationsIds;
  } 

  /**
   * Update Square Customer
   */
  public static function myUpdateCustomer($paymentProcessor, $requestFields)
  {
    Civi::log()->debug('CRM_Square_Utils::myUpdateCustomer');
    civi::log()->debug('CRM_Square_Utils::myUpdateCustomer requestFields' . print_r($requestFields, true));
    $customer = self::myCreateCustomer($paymentProcessor, $requestFields);
    return $customer;
  }

  /**
   * Create Square Customer
   */
  public static function myCreateCustomer($paymentProcessor, $requestFields)
  {
    Civi::log()->debug('CRM_Square_Utils::myCreateCustomer');
    $client = self::connectToSquare($paymentProcessor);

    $customerRequest = new \Square\Models\CreateCustomerRequest();
    $customerRequest->setIdempotencyKey(self::generateIdempotencyKey());
    $customerRequest->setGivenName($requestFields['first_name']);
    $customerRequest->setFamilyName($requestFields['last_name']);
    $customerRequest->setEmailAddress($requestFields['email']);
    $customerRequest->setPhoneNumber($requestFields['phone']);
    try {
      $apiResponse = $client->getCustomersApi()->createCustomer($customerRequest);

      if ($apiResponse->isSuccess()) {
        $customer = $apiResponse->getResult();
        Civi::log()->debug('CRM_Square_Utils::myCreateCustomer result ' . print_r($customer, true));
        Civi::log()->debug('CRM_Square_Utils::myCreateCustomer customer id ' . print_r($customer->getCustomer()->getId(), true));
      } else {
        $errors = $apiResponse->getErrors();
        foreach ($errors as $error) {
            Civi::log()->debug('CRM_Square_Utils::myCreateCustomer errors ' . 
                print_r($error->getCategory(), true) . ' ' . 
                print_r($error->getCode(), true) . ' ' .
                print_r($error->getDetail(), true));
        }
        throw new Exception('CRM_Square_Utils::myCreateCustomer: ' . print_r($errors, true));
      }

    } catch (ApiException $e) {
          Civi::log()->debug('CRM_Square_Utils::myCreateCustomer ApiException occurred: ' . 
              print_r($e->getMessage(), true));
    }
    return $customer;
}



  /**
   * Create new Square order 
   */
  public static function myPrepareOrderBody($paymentProcessor, $requestFields, $customer)
  {
    Civi::log()->debug('squareUtils.php::myPrepareOrderBody');
    $client = self::connectToSquare($paymentProcessor);

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

    // Associated the order with the default location
    $locations = self::myListLocations($client);
    $order = new \Square\Models\Order($locations[0]->getId());

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
    $order->setCustomerId($customer->getCustomer()->getId());
    $order->setLineItems($line_items);

    $order->setState('OPEN');
    //$order->setTicketName($requestFields['ticket_name']);

    $body = new \Square\Models\CreateOrderRequest();
    $body->setOrder($order);
    $body->setIdempotencyKey(self::generateIdempotencyKey());

    return ($body);
  }

  /**
   * Create new Square order 
   */
  public static function myCreateOrder($paymentProcessor, $body)
  {
      Civi::log()->debug('squareUtils.php::myCreateOrder');
      Civi::log()->debug('squareUtils.php::myCreateOrder body ' . print_r($body, true));
      
      $client = self::connectToSquare($paymentProcessor);
      try {
          $apiResponse = $client->getOrdersApi()->createOrder($body);
          if ($apiResponse->isSuccess()) {
              $result = $apiResponse->getResult();
              //Civi::log()->debug('squareUtils.php::myCreateOrder result ' . print_r($result,true));
  
              $orders = [];
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

  
  /**
   * List Square Orders 
   */
  public static function myListOrders($client)
  {
      Civi::log()->debug('squareUtils.php::myListOrders');
      $location_ids = [];
      $location_ids = self::myListLocationsIds(myListLocations($client));

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
              $orderEntries = [];

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

  /**
   * Create invoice from Open Order 
   */
  public static function myCreateInvoice($paymentProcessor, $orders)
  {
      Civi::log()->debug('squareUtils.php::myCreateInvoice');
      Civi::log()->debug('squareUtils.php::myCreateInvoice order date : ' . print_r(date('Y-m-d'), true));

      $primary_recipient = new \Square\Models\InvoiceRecipient();
      $primary_recipient->setCustomerId($orders->getCustomerId());
      
      $invoice_payment_request = new \Square\Models\InvoicePaymentRequest();
      $invoice_payment_request->setRequestType('BALANCE');
      $invoice_payment_request->setDueDate(date('Y-m-d'));
      $invoice_payment_request->setAutomaticPaymentSource('NONE');
      $payment_requests = [$invoice_payment_request];

      $accepted_payment_methods = new \Square\Models\InvoiceAcceptedPaymentMethods();
      $accepted_payment_methods->setCard(true);
      
      $invoice = new \Square\Models\Invoice();
      $invoice->setLocationId($orders->getLocationId());
      $invoice->setOrderId($orders->getId());
      $invoice->setPrimaryRecipient($primary_recipient);
      $invoice->setPaymentRequests($payment_requests);
      $invoice->setDeliveryMethod('SHARE_MANUALLY');
      //$invoice->setScheduledAt($orders->getCreatedAt());
      $invoice->setAcceptedPaymentMethods($accepted_payment_methods);
      $invoice->setStorePaymentMethodEnabled(true);
      
      $body = new \Square\Models\CreateInvoiceRequest($invoice);
      $body->setIdempotencyKey(self::generateIdempotencyKey());

      $client = self::connectToSquare($paymentProcessor);

      try {
          $apiResponse = $client->getInvoicesApi()->createInvoice($body);

          if ($apiResponse->isSuccess()) {
              $invoice = $apiResponse->getResult();
              Civi::log()->debug('squareUtils.php::myCreateInvoice $invoice : ' . print_r($invoice, true));

          } else {
              $invoice = null;
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
      return $invoice;
  }

  /**
   * Publish invoice to be visible at terminal 
   */
  public static function myPublishInvoice($paymentProcessor, $invoice)
  {
    Civi::log()->debug('squareUtils.php::myPublishInvoice');
    $client = self::connectToSquare($paymentProcessor);

    $publishRequest = new \Square\Models\PublishInvoiceRequest($invoice->getInvoice()->getVersion());
    try {
      $apiResponse = $client->getInvoicesApi()->publishInvoice($invoice->getInvoice()->getId(), $publishRequest);

      if ($apiResponse->isSuccess()) {
        $result = $apiResponse->getResult();
        Civi::log()->debug('CRM_Square_Utils::myPublishInvoice result ' . print_r($result, true));

      } else {
        $errors = $apiResponse->getErrors();
        foreach ($errors as $error) {
            Civi::log()->debug('CRM_Square_Utils::myPublishInvoice errors ' . 
                print_r($error->getCategory(), true) . ' ' . 
                print_r($error->getCode(), true) . ' ' .
                print_r($error->getDetail(), true));
        }
        throw new Exception('CRM_Square_Utils::myPublishInvoice: ' . print_r($errors, true));
      }

    } catch (ApiException $e) {
          Civi::log()->debug('CRM_Square_Utils::myPublishInvoice ApiException occurred: ' . 
              print_r($e->getMessage(), true));
    }
  }

  /**
   * List invoice 
   */
  public static function myListInvoice($client)
  {
      Civi::log()->debug('squareUtils.php::myListInvoice');
      try {
          $apiResponse = $client->getInvoicesApi()->listInvoices('L5PCE8REVXZN6');

          if ($apiResponse->isSuccess()) {
              $result = $apiResponse->getResult();
              //print_r('result : ');
              //print_r($result);
              $invoices = [];

              $invoices = $result->getInvoices();
              //print_r('Invoices : ');
              //print_r($invoices);
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
                  $paymentRequest = [];
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

/**
   * Create a new Square order 
   * Convert it to invoice
   * Check for customer presence, if absent create it
   * Publish invoice
   */
  public static function myPushInvoiceToSquare($paymentProcessor, $requestFields)
  {
    Civi::log()->debug('squareUtils.php::myPushInvoiceToSquare');
    $customer = self::myUpdateCustomer($paymentProcessor, $requestFields);
    Civi::log()->debug('squareUtils.php::myPushInvoiceToSquare $requestFields ' . print_r($requestFields, true));
    $body = self::myPrepareOrderBody($paymentProcessor, $requestFields, $customer);
    Civi::log()->debug('squareUtils.php::myPushInvoiceToSquare $body ' . print_r($body, true));
    $order = self::myCreateOrder($paymentProcessor, $body);
    Civi::log()->debug('squareUtils.php::myPushInvoiceToSquare $order ' . print_r($order, true));
    $invoice = self::myCreateInvoice($paymentProcessor, $order);
    Civi::log()->debug('squareUtils.php::myPushInvoiceToSquare $invoice ' . print_r($invoice, true));
    self::myPublishInvoice($paymentProcessor, $invoice);
    return true;
  }

}
