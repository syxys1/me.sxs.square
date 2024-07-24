<?php

use CRM_Square_ExtensionUtil as E;

return [
  [
    'name' => 'Square Terminal',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id:name' => 'payment_instrument',
        'label' => 'Square terminal',
        'name' => 'Square terminal',
        'grouping' => NULL,
        'filter' => 0,
        'is_default' => FALSE,
        'description' => 'Payment made with onsite Square terminal',
        'is_optgroup' => FALSE,
        'is_reserved' => TRUE,
        'is_active' => TRUE 
      ],
      'match' => [
        'option_group_id',
        'name'
      ],
    ],
  ],
];
