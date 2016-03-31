<?php
/**
 * @file
 * Contains \Drupal\google_analytics_counter\Form\GoogleAnalyticsCounterAdminSettingsForm.
 */

namespace Drupal\google_analytics_counter\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\SafeMarkup;

/* Seconds in an hour. */
define('GOOGLE_ANALYTICS_COUNTER_HOUR', 60 * 60);
/* Seconds in a day. */
define('GOOGLE_ANALYTICS_COUNTER_DAY', GOOGLE_ANALYTICS_COUNTER_HOUR * 24);
/* Seconds in a week. */
define('GOOGLE_ANALYTICS_COUNTER_WEEK', GOOGLE_ANALYTICS_COUNTER_DAY * 7);

/**
 * Class GoogleAnalyticsCounterAdminSettingsForm.
 *
 * @package Drupal\google_analytics_counter\Form
 */
class GoogleAnalyticsCounterAdminSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'google_analytics_counter_admin_settings';
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
    $defaultcroninterval = 30;
    // Could be up to 10000 but keeping the default low so that it works even
    // for people without external cron.
    $chunk = 1000;
    $dayquota = 10000;

    $times = array();
    $intervals = array(1, 3, 5, 10, 20, 30, 60, 180, 360, 720, 1440);
    foreach ($intervals as $interval) {
      $times[] = $interval;
    }
    $form['cron_interval'] = array(
      '#type' => 'select',
      '#title' => t('Minimum time between Google Analytics data fetching'),
      '#default_value' => $config->get('cron_interval'),
      '#description' => t('Google Analytics statistical data is fetched and processed via a cron job. If your cron runs too frequently, you may waste your GA daily quota too fast. Set here the minimum time that needs to elapse before the Google Analytics Counter cron runs (even if your cron job runs more frequently). Specify the time in <em>minutes</em>. Default: %defaultcroninterval minutes.', array('%defaultcroninterval' => SafeMarkup::checkPlain($defaultcroninterval))),
      '#options' => array_combine($times, $times),
      '#required' => TRUE,
    );
    $times = array();
    $curquota = $config->get('api_dayquota');
    for ($chunks = 1; $chunks <= $curquota / 1000; $chunks++) {
      $times[] = $chunks * 1000;
    }
    $form['chunk_to_fetch'] = array(
      '#type' => 'select',
      '#title' => t('Number of items to fetch from Google Analytics in one request'),
      '#default_value' => $config->get('chunk_to_fetch'),
      '#description' => t('How many items will be fetched from Google Analytics in one request (during a cron run). The maximum allowed by Google is 10000. Default: %chunk items.', array('%chunk' => SafeMarkup::checkPlain($chunk))),
      '#options' => array_combine($times, $times),
      '#required' => TRUE,
    );

    $form['api_dayquota'] = array(
      '#type' => 'textfield',
      '#title' => t('Maximum GA API requests per day'),
      '#default_value' => $config->get('api_dayquota'),
      '#size' => 9,
      '#maxlength' => 9,
      '#description' => t('This is the <em>daily limit</em> of requests <em>per profile</em> to the Google Analytics API. You don\'t need to change this value until Google relaxes their quota policy. Current value: %dayquota.<br />It is reasonable to expect that Google will increase this low number sooner rather than later, so watch the <a href="https://developers.google.com/analytics/devguides/reporting/core/v3/limits-quotas#discovery" target="_blank">quota</a> page for changes.<br />To get the full quota, you must <a href="https://developers.google.com/analytics/devguides/reporting/core/v3/limits-quotas#full_quota" target="_blank">register your Analytics API</a>.', array('%dayquota' => SafeMarkup::checkPlain($dayquota))),
      '#required' => TRUE,
    );

    $times = array(
      GOOGLE_ANALYTICS_COUNTER_DAY,
      GOOGLE_ANALYTICS_COUNTER_WEEK,
      GOOGLE_ANALYTICS_COUNTER_WEEK * 4,
    );

    $date_formatter = \Drupal::service('date.formatter');

    $options = array_map(function ($item) use ($date_formatter) {
      return $date_formatter->formatInterval($item);
    }, $times);
    $options = array_combine($times, $options);
    $options['all'] = t('All')->render();
    $form['date_cycle'] = array(
      '#type' => 'select',
      '#title' => t('Time range of statistics from Google analytics'),
      '#description' => t("Get the time range of statistics for pageviews from Google analytics in past time.It don't include the time for today except you choose all."),
      '#options' => $options,
      '#default_value' => $config->get('date_cycle'),
      '#required' => TRUE,
    );

    // GA response cache options.
    $times = array();
    for ($hours = 1; $hours <= 24; $hours++) {
      $times[] = $hours * GOOGLE_ANALYTICS_COUNTER_HOUR;
    }
    for ($days = 1; $days <= 6; $days++) {
      $times[] = $days * GOOGLE_ANALYTICS_COUNTER_DAY;
    }
    for ($weeks = 1; $weeks <= 4; $weeks++) {
      $times[] = $weeks * GOOGLE_ANALYTICS_COUNTER_WEEK;
    }
    $options = array_map(function ($item) use ($date_formatter) {
      return $date_formatter->formatInterval($item);
    }, $times);
    $form['cache_length'] = array(
      '#type' => 'select',
      '#title' => t('Google Analytics query cache'),
      '#description' => t('Limit the minimum time to elapse between getting fresh data for the same query from Google Analytics. Defaults to 1 day.'),
      '#options' => array_combine($times, $options),
      '#default_value' => $config->get('cache_length'),
      '#required' => TRUE,
    );

    // If the statistics module is off, the own table storage MUST be used.
    // See also https://www.drupal.org/node/2275575
    if (!\Drupal::moduleHandler()->moduleExists('statistics')) {
      $default_value = 1;
    }
    else {
      $default_value = $config->get('storage');
    }
    $form['storage'] = array(
      '#type' => 'radios',
      '#title' => t('Data storage location'),
      '#options' => array(
        '1' => t("Use this module's database table. This is the recommended option."),
        '0' => t('Overwrite total pageview values in table node_counter provided by the core module Statistics. If the Statistics module is disabled, this option gets deactivated as well. Only really useful for backward compatibility on sites that previously used version 7.2 or older and do not wish to change configuration (e.g. views) immediately. This option is deprecated.'),
      ),
      '#default_value' => $default_value,
      // For backward compatibility keeping it on the deprecated option.
      '#required' => TRUE,
      // '#description' => t("").
    );
    // Disable the Statistics option if the module is off.
    // And force the own database table option.
    if (!\Drupal::moduleHandler()->moduleExists('statistics')) {
      // See http://drupal.stackexchange.com/a/17550/196
      $form['storage'][0]['#disabled'] = TRUE;
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('google_analytics_counter.settings')
      ->set('cron_interval', $form_state->getValue('cron_interval'))
      ->set('api_dayquota', $form_state->getValue('api_dayquota'))
      ->set('chunk_to_fetch', $form_state->getValue('chunk_to_fetch'))
      ->set('date_cycle', $form_state->getValue('date_cycle'))
      ->set('cache_length', $form_state->getValue('cache_length'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
