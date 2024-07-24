<?php

use CRM_Square_ExtensionUtil as E;

return [
  [
    'name' => 'Square Terminal',
    'entity' => 'PaymentProcessorType',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Square Terminal',
        'title' => 'Square for Terminal Integration',
        'description' => E::ts('Square payment processor for onsite terminal integration'),
        'is_active'=> true,
        'is_default'=> false,
        'user_name_label'=> 'Access Token',
        'signature_label'=> 'Webhook Signature Key',
        'class_name' => 'Payment_SquarePP',
        'url_site_default' => 'https://unused.org',
        'url_api_default'=> 'https://connect.squareupsandbox.com',
        'url_site_test_default' => 'https://unused.org',
        'url_api_test_default'=> 'https://connect.squareupsandbox.com',
        'billing_mode' => 4,
        'is_recur' => FALSE,
        'payment_instrument_id:name' => 'square terminal',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
];
