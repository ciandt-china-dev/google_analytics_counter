<?php

/**
 * @file
 * Contains google_analytics_counter\google_analytics_counter.views.inc.
 */

/**
 * Implements hook_views_data().
 */
function google_analytics_counter_views_data() {

  $data['google_analytics_counter_storage']['table']['group'] = t('Google Analytics Counter');
  $data['google_analytics_counter_storage']['table']['join'] = array(
    'node_field_data' => array(
      'left_field' => 'nid',
      'field' => 'nid',
    ),
  );

  $data['google_analytics_counter_storage']['table']['base'] = array(
    'field' => 'nid',
    'title' => t('Google Analytics Counter Storage'),
    'help' => t('Google Analytics Counter module table holding pageview counts.'),
  );

  $data['google_analytics_counter_storage']['nid'] = array(
    'title' => t('Nid'),
    'help' => t('Node IDs'),
    'field' => array(
      'id' => 'numeric',
    ),
  );

  $data['google_analytics_counter_storage']['pageview_total'] = array(
    'title' => t('Pageview Total'),
    'help' => t('Total pageview counts'),
    'field' => array(
      'id' => 'numeric',
    ),
    'filter' => array(
      'id' => 'numeric',
    ),
    'sort' => array(
      'id' => 'standard',
    ),
  );

  return $data;
}
