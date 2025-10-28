<?php

/**
 * @file
 * Hooks related to the Shortlink Manager module.
 */

/**
 * Alter the array of UTM parameters for a UtmSet configuration entity.
 *
 * This hook allows other modules to modify, add, or remove parameters before
 * they are used to generate a shortlink URL.
 *
 * @param array $parameters
 *   The array of UTM parameters (key => value), passed by reference.
 *   Modules can modify this array to change the resulting shortlink URL.
 * @param \Drupal\shortlink_manager\UtmSetInterface $utm_set
 *   The UtmSet entity currently being processed. This can be inspected to
 *   conditionally alter the parameters.
 */
function hook_shortlink_manager_utm_parameters_alter(array &$parameters, \Drupal\shortlink_manager\UtmSetInterface $utm_set) {
  // Example: Change a specific parameter value.
  if (isset($parameters['utm_medium']) && $utm_set->id() === 'default') {
    $parameters['utm_medium'] = 'api_altered_medium';
  }

  // Example: Add a custom parameter.
  $parameters['new_custom_key'] = 'new_custom_value';

  // Example: Delete a parameter.
  unset($parameters['utm_term']);
}