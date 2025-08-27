<?php

/**
 * @file
 * Provides views data for the Shortlink Manager module.
 */

/**
 * Implements hook_views_data().
 */
function shortlink_manager_views_data(): array {
  $data = [];

  // Define the base table.
  $data['shortlink']['table']['group'] = t('Shortlink');
  $data['shortlink']['table']['base'] = [
    'field' => 'id',
    'title' => t('Shortlink'),
    'help' => t('Shortlink entities.'),
    'weight' => -10,
  ];
  $data['shortlink']['table']['entity type'] = 'shortlink';

  // Define fields from the base table.
  $data['shortlink']['id'] = [
    'title' => t('Shortlink ID'),
    'help' => t('The shortlink ID.'),
    'field' => [
      'id' => 'standard',
    ],
    'argument' => [
      'id' => 'string',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'sort' => [
      'id' => 'standard',
    ],
  ];

  $data['shortlink']['label'] = [
    'title' => t('Label'),
    'help' => t('The shortlink label.'),
    'field' => [
      'id' => 'standard',
    ],
    'argument' => [
      'id' => 'string',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'sort' => [
      'id' => 'standard',
    ],
  ];

  $data['shortlink']['path'] = [
    'title' => t('Shortlink Path'),
    'help' => t('The path of the shortlink.'),
    'field' => [
      'id' => 'standard',
    ],
    'argument' => [
      'id' => 'string',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'sort' => [
      'id' => 'standard',
    ],
  ];

  $data['shortlink']['status'] = [
    'title' => t('Status'),
    'help' => t('The shortlink publication status.'),
    'field' => [
      'id' => 'boolean',
    ],
    'filter' => [
      'id' => 'boolean',
      'label' => t('Published'),
      'type' => 'yes-no',
      'accept null' => TRUE,
    ],
    'sort' => [
      'id' => 'standard',
    ],
  ];

  // Expose the other base fields that we're not defining with special handlers.
  $data['shortlink']['target_entity_type']['title'] = t('Target Entity Type');
  $data['shortlink']['target_entity_type']['field']['id'] = 'standard';
  $data['shortlink']['target_entity_type']['filter']['id'] = 'string';

  $data['shortlink']['target_entity_id']['title'] = t('Target Entity ID');
  $data['shortlink']['target_entity_id']['field']['id'] = 'standard';
  $data['shortlink']['target_entity_id']['filter']['id'] = 'string';

  $data['shortlink']['destination_override']['title'] = t('Destination Override');
  $data['shortlink']['destination_override']['field']['id'] = 'standard';
  $data['shortlink']['destination_override']['filter']['id'] = 'string';

  $data['shortlink']['utm_set']['title'] = t('UTM Set ID');
  $data['shortlink']['utm_set']['field']['id'] = 'standard';
  $data['shortlink']['utm_set']['filter']['id'] = 'string';

  return $data;
}
