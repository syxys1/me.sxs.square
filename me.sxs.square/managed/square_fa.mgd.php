<?php

use CRM_Square_ExtensionUtil as E;

return [
  [
    'name' => 'Square Account',
    'entity' => 'FinancialAccount',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Square Account',
        'contact_id' => 1,
        'financial_account_type_id' => 1,
        'account_type_code' => 'BANK',
        'description' => 'Square payment processor merchant account', 
        'is_reserved'=> false,
        'is_active'=> true,
        'is_default'=> false,
      ],
      'match' => [
        'name',
      ],
    ],
  ],
];
