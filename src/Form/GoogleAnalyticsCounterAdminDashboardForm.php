<?php
/**
 * @file
 * Contains \Drupal\google_analytics_counter\Form\GoogleAnalyticsCounterAdminDashboardForm.
 */

namespace Drupal\google_analytics_counter\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\SafeMarkup;
use Drupal\Core\Url;
use Drupal\Core\Link;

class GoogleAnalyticsCounterAdminDashboardForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'google_analytics_counter_admin_dashboard';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['google_analytics_counter.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = \Drupal::config('google_analytics_counter.settings');
    $result = '';

    $result .= t('<p><h3>More information relevant to Google Analytics statistics for this site:</h3>');

    $authenticated = FALSE;
    // It's a weak test but better than none.
    if ($config->get('google_analytics_counter.profile_id') <> '') {
      $authenticated = TRUE;
    }
    else {
      $result .= t('<font color="red">No Google Analytics profile has been authenticated! Google Analytics Counter can not fetch any new data. Please ' . l(t('authenticate here'), 'admin/config/system/google_analytics_counter/authentication') . '.</font>');
      // Don't show anything else.
      return $result;
    }

    $result .= t('<p>Total number of hits registered by Google Analytics under this profile: %google_analytics_counter_totalhits. This is cumulative; counts for paths that may no longer exist on the website still have historical traces in Google Analytics.', array('%google_analytics_counter_totalhits' => SafeMarkup::checkPlain(number_format($config->get('google_analytics_counter.totalhits')))));

    $result .= t('<p>Number of paths on this site as currently recorded by Google Analytics: %google_analytics_counter_totalpaths. This is cumulative; paths that may no longer exist on the website still have historical traces in Google Analytics.', array('%google_analytics_counter_totalpaths' => SafeMarkup::checkPlain(number_format($config->get('google_analytics_counter.totalpaths')))));

    $num_of_results = $this->getCount('google_analytics_counter');
    $result .= t('<br />Number of paths currently stored in local database table: %num_of_results. This table is initially built and then regularly updated during cron runs.', array('%num_of_results' => number_format($num_of_results)));

    $result .= t('<p>Total number of nodes on this site: %google_analytics_counter_totalnodes.', array('%google_analytics_counter_totalnodes' => SafeMarkup::checkPlain(number_format($config->get('google_analytics_counter.totalnodes')))));

    if ($config->get('google_analytics_counter.storage', 0) == 0
      && \Drupal::moduleHandler()->moduleExists('statistics')) {
      // See also https://www.drupal.org/node/2275575
      $table = 'node_counter';
    }
    else {
      $table = 'google_analytics_counter_storage';
    }
    $num_of_results = $this->getCount($table);
    $result .= t('<br />Number of nodes with known pageview counts on this site: %num_of_results.', array('%num_of_results' => SafeMarkup::checkPlain(number_format($num_of_results))));

    $apicalls = $config->get('google_analytics_counter.dayquota');
    $result .= t('<p>Number of requests made to Google Analytics: %apicalls1. Only calls made by this module are counted here. Other modules and apps may be making more requests. ', array('%apicalls1' => SafeMarkup::checkPlain(number_format($apicalls[1]))));
    $remainingcalls = $config->get('google_analytics_counter.api_dayquota', 10000) - $apicalls[1];
    if ($remainingcalls < 1) {
      $remainingcalls = '?';
    }
    else {
      $remainingcalls = number_format($remainingcalls);
    }
    $result .= t('Remaining requests available in the current 24-hour period: %remainingcalls. ', array('%remainingcalls' => SafeMarkup::checkPlain($remainingcalls)));
    if ($apicalls[0] == 0) {
      $temp = 60 * 60 * 24;
    }
    else {
      $temp = 60 * 60 * 24 - (REQUEST_TIME - $apicalls[0]);
    }
    $result .= t('The current 24-hour period ends in: %google_analytics_counter_sec2hms.', array('%google_analytics_counter_sec2hms' => SafeMarkup::checkPlain(google_analytics_counter_sec2hms($temp))));

    $temp = $config->get('google_analytics_counter.chunk_process_time') + $config->get('google_analytics_counter.chunk_node_process_time');
    if ($temp < 0) {
      $temp = 0;
    }
    $result .= t('<br/>The most recent retrieval of %google_analytics_counter_chunk_to_fetch paths from Google Analytics and node counts from its local mirror took %google_analytics_counter_sec2hms (%google_analytics_counter_chunk_process_time+%google_analytics_counter_chunk_node_process_times). ', array(
      '%google_analytics_counter_chunk_to_fetch' => SafeMarkup::checkPlain(number_format($config->get('google_analytics_counter.chunk_to_fetch'))),
      '%google_analytics_counter_sec2hms' => SafeMarkup::checkPlain(google_analytics_counter_sec2hms($temp)),
      '%google_analytics_counter_chunk_process_time' => SafeMarkup::checkPlain($config->get('google_analytics_counter.chunk_process_time')),
      '%google_analytics_counter_chunk_node_process_time' => SafeMarkup::checkPlain($config->get('google_analytics_counter.chunk_node_process_time'))
    ));
    $temp = $config->get('google_analytics_counter.cron_next_execution') - REQUEST_TIME;
    //if ($temp < 0) {
    if (true) {
      $temp = 0;

      $result .= t('The next one will take place in %google_analytics_counter_sec2hms.', array('%google_analytics_counter_sec2hms' => SafeMarkup::checkPlain(google_analytics_counter_sec2hms($temp))));

      $url = Url::fromRoute('system.run_cron');
      $link_options = array(
        'query' => array(
          'destination' => array(
            'admin/config/system/google_analytics_counter/dashboard'
          ),
        ),
      );
      $url->setOptions($link_options);
      $text = Link::fromTextAndUrl(t('Run cron immediately'), $url);
      $result .= '<p>' . $text->toString()->getGeneratedLink() . '.';
      $url = Url::fromRoute('google_analytics_counter.admin_dashborad_reset');
      $text = Link::fromTextAndUrl(t('Reset all module settings'), $url);
      $result .= '<p>[' . $text->toString()
          ->getGeneratedLink() . t('. Useful in some cases, e.g. if in trouble with OAuth authentication.' . ']');
      $form['description'] = array(
        '#markup' => $result,
        'html' => TRUE,
      );
    }
    $form['#theme'] = 'system_config_form';

    return $form;
  }

  private function getCount($table) {
    $db = \Drupal::database();
    return $db->select($table, 'alias')
      ->fields('alias')
      ->countQuery()
      ->execute()
      ->fetchField();
  }
}