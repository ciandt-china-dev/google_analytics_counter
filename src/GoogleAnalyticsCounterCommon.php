<?php
/**
 * @file
 * Parsing and writing the fetched data.
 */

namespace Drupal\google_analytics_counter;

use Drupal\Component\Utility\SafeMarkup;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Exception;

/**
 * Class GoogleAnalyticsCounterCommon.
 *
 * @package Drupal\google_analytics_counter
 */
class GoogleAnalyticsCounterCommon {

  /**
   * Instantiate a new GoogleAnalyticsCounterFeed object.
   *
   * @return object
   *   GoogleAnalyticsCounterFeed object to authorize access and request data
   *   from the Google Analytics Core Reporting API.
   */
  public static function newGaFeed() {
    $config = \Drupal::config('google_analytics_counter.settings');
    $config_edit = \Drupal::configFactory()
      ->getEditable('google_analytics_counter.settings');
    if ($config->get('access_token') && time() < $config->get('expires_at')) {
      // If the access token is still valid, return an authenticated GAFeed.
      return new GoogleAnalyticsCounterFeed($config->get('access_token'));
    }
    elseif ($config->get('refresh_token')) {
      // If the site has an access token and refresh token, but the access
      // token has expired, authenticate the user with the refresh token.
      $client_id = $config->get('client_id');
      $client_secret = $config->get('client_secret');
      $refresh_token = $config->get('refresh_token');

      try {
        $gac_feed = new GoogleAnalyticsCounterFeed();
        $gac_feed->refreshToken($client_id, $client_secret, $refresh_token);
        $config_edit->set('access_token', $gac_feed->accessToken)
          ->set('expires_at', $gac_feed->expiresAt)
          ->save();
        return $gac_feed;
      }
      catch (Exception $e) {
        drupal_set_message(t("There was an authentication error. Message: %message",
          array('%message' => $e->getMessage())), 'error', FALSE
        );
        return NULL;
      }
    }
    elseif (isset($_GET['code'])) {
      // If there is no access token or refresh token and client is returned
      // to the config page with an access code, complete the authentication.
      $client_id = $config->get('client_id');
      $client_secret = $config->get('client_secret');
      $redirect_uri = $config->get('redirect_uri');

      try {
        $gac_feed = new GoogleAnalyticsCounterFeed();
        $gac_feed->finishAuthentication($client_id, $client_secret, $redirect_uri);

        $config_edit->set('access_token', $gac_feed->accessToken)
          ->set('expires_at', $gac_feed->expiresAt)
          ->set('refresh_token', $gac_feed->refreshToken)
          ->save();
        $config_edit->clear('redirect_uri')
          ->save();
        drupal_set_message(t('You have been successfully authenticated.'), 'status', FALSE);
        $redirect = new RedirectResponse($redirect_uri);
        $redirect->send();
      }
      catch (Exception $e) {
        drupal_set_message(t("There was an authentication error. Message: %message",
          array('%message' => $e->getMessage())), 'error', FALSE
        );
        return NULL;
      }
    }
    else {
      return NULL;
    }
  }

  /**
   * Displays the count.
   */
  public static function displayGaCount($path = '') {
    if ($path == '') {
      // We need a path that includes the language prefix, if any.
      // E.g. en/my/path (of /en/my/path - the initial slash will be dealt with
      // later).
      // @TODO: Works OK on non-Apache servers?
      $path = parse_url("http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]", PHP_URL_PATH);
    }
    // Check all paths, to be sure.
    // $path = check_plain($path);
    $block_content = '';
    $block_content .= '<span class="google-analytics-counter">';
    $count = self::getSumPerPath($path);
    if ($count == '') {
      // If unknown, for some reason.
      // Better than t('N/A').
      $block_content .= 0;
    }
    else {
      $block_content .= $count;
    }
    $block_content .= '</span>';

    return $block_content;
  }

  /**
   * Sets the expiry timestamp for cached queries.Default is 1 day.
   *
   * @return int
   *   The UNIX timestamp to expire the query at.
   */
  public static function cacheTime() {
    return time() + \Drupal::config('google_analytics_counter.settings')
      ->get('cache_length');
  }

  /**
   * Convert seconds to hours, minutes and seconds.
   */
  public static function sec2hms($sec, $pad_hours = FALSE) {

    // Start with a blank string.
    $hms = "";

    // Do the hours first: there are 3600 seconds in an hour, so if we divide
    // the total number of seconds by 3600 and throw away the remainder, we're
    // left with the number of hours in those seconds.
    $hours = intval(intval($sec) / 3600);

    // Add hours to $hms (with a leading 0 if asked for).
    $hms .= ($pad_hours)
      ? str_pad($hours, 2, "0", STR_PAD_LEFT) . "h "
      : $hours . "h ";

    // Dividing the total seconds by 60 will give us the number of minutes
    // in total, but we're interested in *minutes past the hour* and to get
    // this, we have to divide by 60 again and then use the remainder.
    $minutes = intval(($sec / 60) % 60);

    // Add minutes to $hms (with a leading 0 if needed).
    $hms .= str_pad($minutes, 2, "0", STR_PAD_LEFT) . "m ";

    // Seconds past the minute are found by dividing the total number of seconds
    // by 60 and using the remainder.
    $seconds = intval($sec % 60);

    // Add seconds to $hms (with a leading 0 if needed).
    $hms .= str_pad($seconds, 2, "0", STR_PAD_LEFT);

    // done!
    return $hms . 's';
  }

  /**
   * Programatically revoke token.
   */
  public static function revoke() {
    $gac_feed = self::newGaFeed();
    if ($gac_feed->revokeToken()) {
      \Drupal::configFactory()->getEditable('google_analytics_counter.settings')
        ->set('access_token', '')
        ->set('refresh_token', '')
        ->set('client_id', '')
        ->set('client_secret', '')
        ->set('profile_id', '')
        ->set('redirect_uri', '')
        ->save();
      $url = '/admin/config/system/google-analytics-counter/dashboard';
      $redirect = new RedirectResponse($url);
      $redirect->send();
    }
  }

  /**
   * Get pageviews for nodes and write them either to the Drupal core table.
   *
   * Table: node_counter, or to the google_analytics_counter_storage.
   * This function is triggered by hook_cron().
   */
  public static function updateStorage() {
    $config_edit = \Drupal::configFactory()
      ->getEditable('google_analytics_counter.settings');
    $config = \Drupal::config('google_analytics_counter.settings');
    if ($config->get('storage') == 0
      && \Drupal::moduleHandler()->moduleExists('statistics')
      // See also https://www.drupal.org/node/2275575
    ) {
      // Using core node_counter table.
      $storage = 'node_counter';
    }
    else {
      // Using table google_analytics_counter_storage.
      $storage = 'google_analytics_counter_storage';
    }

    // Record how long did this chunk take to process.
    $chunkprocessbegin = time();

    // The total number of nodes.
    $resultcount = db_select('node', 'n')
      ->fields('n')
      ->countQuery()
      ->execute()
      ->fetchField();
    // Store it in a variable.
    $config_edit->set('totalnodes', $resultcount)
      ->save();

    // How many node counts to update one cron run.
    // We use the same chunk size as when getting paths in
    // google_analytics_counter_update_path_counts().
    $chunk = $config->get('chunk_to_fetch');
    // In case there are more than $chunk nodes to process, do just one
    // chunk at a time and register that in $step.
    $step = $config->get('node_data_step');
    // Which node to look for first. Must be between 0 - infinity.
    $pointer = $step * $chunk;

    $dbresults = db_select('node', 'n')
      ->fields('n', array('nid'))
      ->range($pointer, $chunk)
      ->execute();
    foreach ($dbresults as $dbresult) {
      $path = 'node/' . $dbresult->nid;

      // Get the count for this node (uncached).
      $sum_of_pageviews = self::getSumPerPath($path, FALSE);

      // Don't write zeroes.
      if ($sum_of_pageviews == 0) {
        continue;
      }

      // Write the count to the current storage table.
      if ($storage == 'node_counter') {
        db_merge('node_counter')
          ->key(array('nid' => $dbresult->nid))
          ->fields(array(
            'daycount' => 0,
            'totalcount' => $sum_of_pageviews,
            'timestamp' => REQUEST_TIME,
          ))
          ->execute();
      }
      else {
        db_merge('google_analytics_counter_storage')
          ->key(array('nid' => $dbresult->nid))
          ->fields(array(
            'pageview_total' => $sum_of_pageviews,
          ))
          ->execute();
      }
    }

    // Set the pointer.
    $pointer += $chunk;

    \Drupal::logger('Google Analytics Counter')
      ->info(t('Attempted updating %dbresults records in table %storage from Google Analytics data %first-%second.', array(
        '%dbresults' => 22,
        '%storage' => $storage,
        '%first' => ($pointer - $chunk + 1),
        '%second' => ($pointer - $chunk + 22),
      ))->render());

    // OK now increase or zero $step.
    if ($pointer < $resultcount) {
      // If there are more results than what we've reached with this chunk,
      // increase step to look further during the next run.
      $newstep = $step + 1;
    }
    else {
      $newstep = 0;
    }
    $config_edit->set('node_data_step', $newstep)
      // Record how long did this chunk take to process.
      ->set('chunk_node_process_time', time() - $chunkprocessbegin)
      ->save();
  }

  /**
   * Find how many distinct paths does Google Analytics have for this profile.
   *
   * This function is triggered by hook_cron().
   */
  public static function updatePathCounts() {
    $config = \Drupal::config('google_analytics_counter.settings');
    $config_edit = \Drupal::configFactory()
      ->getEditable('google_analytics_counter.settings');

    // Record how long did this chunk take to process.
    $chunkprocessbegin = time();

    // Needing to stay under the Google Analytics API quota,
    // let's count how many API retrievals were made in the last 24 hours.
    // @todo We should better take into consideration that the quota is reset at midnight PST (note: time() always returns UTC).
    $dayquota = $config->get('dayquota');
    if (REQUEST_TIME - $dayquota[0] >= 86400) {
      // If last API request was more than a day ago,set monitoring time to now.
      $dayquota[0] = REQUEST_TIME;
      $dayquota[1] = 0;
      $config_edit->set('dayquota', array(
        $dayquota[0],
        $dayquota[1],
      ))
        ->save();
    }
    // Are we over the GA API limit?
    $maxdailyrequests = $config->get('api_dayquota');
    if ($dayquota[1] > $maxdailyrequests) {
      \Drupal::logger('Google Analytics Counter')
        ->error(t('Google Analytics API quota of %maxdailyrequests requests has been reached. Will NOT fetch data from Google Analytics for the next %dayquota seconds. See <a href="/admin/config/system/google_analytics_counter">the Google Analytics Counter settings page</a> for more info.', array(
          '%maxdailyrequests' => SafeMarkup::checkPlain($maxdailyrequests),
          '%dayquota' => SafeMarkup::checkPlain(($dayquota[0] + 86400 - REQUEST_TIME)),
        ))->render());
      return;
    }

    // How many results to ask from GA in one request. Default on 1000
    // to fit most systems (for example those with no external cron).
    $chunk = $config->get('chunk_to_fetch');
    // In case there are more than $chunk path/counts to retrieve from GA,
    // do just one chunk at a time and register that in $step.
    $step = $config->get('data_step');
    // Which GA result to look for first. Must be between 1 - infinity.
    $pointer = $step * $chunk + 1;

    // The earliest valid start-date for Google Analytics is 2005-01-01.
    $date_cycle = $config->get('date_cycle');
    $start_date = $date_cycle == 0
      ? strtotime('2015-01-01') : strtotime(date('Y-m-d', time())) - $date_cycle;
    $request = array(
      'dimensions' => array('ga:pagePath'),
      // Date would not be necessary for totals, but we also calculate stats of
      // views per day, so we need it.
      'metrics' => array('ga:pageviews'),
      'start_date' => $start_date,
      'end_date' => strtotime('tomorrow'),
      // Using 'tomorrow' to offset any timezone shift
      // between the hosting and Google servers.
      'start_index' => $pointer,
      'max_results' => $chunk,
    );

    $cachehere = array(
      'cid' => 'google_analytics_counter_' . md5(serialize($request)),
      'expire' => self::cacheTime(),
      'refresh' => FALSE,
    );
    $new_data = @self::reportData($request, $cachehere);

    // Don't write anything to google_analytics_counter if this GA data comes
    // from cache (would be writing the same again).
    if (!$new_data->fromCache) {

      // This was a live request. Increase the GA request limit tracker.
      $config_edit->set('google_analytics_counter.dayquota', array(
        $dayquota[0],
        ($dayquota[1] + 1),
      ))
        ->save();

      // If NULL then there is no error.
      if (!empty($new_data->error)) {
        \Drupal::logger('Google Analytics Counter')
          ->error(t('Problem fetching data from Google Analytics: %new_dataerror.Did you authenticate any Google Analytics profile? See<a href="/admin/config/system/google-analytics-counter/authentication">here</a>.',
            array('%new_dataerror' => $new_data->error))->render()
          );
        // Nothing to do; return.
      }
      else {
        $resultsretrieved = $new_data->results->rows;
        foreach ($resultsretrieved as $val) {
          // http://drupal.org/node/310085
          db_merge('google_analytics_counter')
            ->key(array('pagepath_hash' => md5($val['pagePath'])))
            ->fields(array(
              'pagepath' => SafeMarkup::checkPlain($val['pagePath']),
              // Added check_plain; see https://www.drupal.org/node/2381703
              'pageviews' => SafeMarkup::checkPlain($val['pageviews']),
              // Added check_plain; see https://www.drupal.org/node/2381703
            ))
            ->execute();
        }
      }
    }

    // The total number of records for this profile.
    $resultcount = @$new_data->results->totalResults;
    // The total number of hits for all records for this profile.
    $totalhits = @$new_data->results->totalsForAllResults['pageviews'];

    // Store it in a variable.
    $config_edit->set('totalpaths', $resultcount)
      ->set('totalhits', $totalhits)
      ->save();

    // Set the pointer.
    $pointer += $chunk;

    \Drupal::logger('Google Analytics Counter')
      ->info(t('Retrieved %sizeof items from Google Analytics data for paths %first-%second.', array(
        '%sizeof' => count(@$new_data->results->rows),
        '%first' => ($pointer - $chunk),
        '%second' => ($pointer - $chunk - 1 + count(@$new_data->results->rows)),
      ))->render()
      );

    // OK now increase or zero $step.
    if ($pointer <= $resultcount) {
      // If there are more results than what we've reached with this chunk,
      // increase step to look further during the next run.
      $newstep = $step + 1;
    }
    else {
      $newstep = 0;
    }
    $config_edit->set('data_step', $newstep)
      // Record how long did this chunk take to process.
      ->set('chunk_process_time', time() - $chunkprocessbegin)
      ->save();
  }

  /**
   * Calculate pageviews for one path (with any aliases).
   */
  private static function getSumPerPath($path, $cacheon = TRUE) {
    // Recognize special path 'all' to get the sum of all pageviews
    // for the profile.
    if ($path == 'all') {
      return \Drupal::config('google_analytics_counter.settings')->get('totalhits');
    }

    // Esp. in case function is called directly.
    $path = SafeMarkup::checkPlain($path)->jsonSerialize();

    // Remove initial slash, if any.
    if (substr($path, 0, 1) == '/') {
      $path = substr($path, 1);
    }

    // Get list of allowed languages to detect front pages
    // such as http://mydomain.tld/en.
    // Must come AFTER the possible initial slash is removed!
    $langs = \Drupal::languageManager()->getLanguages();
    $frontpages = array();
    foreach ($langs as $lang => $object) {
      $frontpages[] = $lang;
    }
    $frontpages[] = '';
    $frontpages[] = '/';

    if (in_array($path, $frontpages)) {
      $path = \Drupal::config('system.site')->get('page.front');
    }

    // If it's a node we'll distinguish the language part of it, if any.
    // Either format en/node/55 or node/55.
    $path_no_slashes_at_ends = trim($path, '/');
    $splitpath = explode('/', $path_no_slashes_at_ends);
    $lang_prefix = '';
    if ((count($splitpath) == 3 and strlen($splitpath[0]) == 2
        and $splitpath[1] == 'node' and is_numeric($splitpath[2]))
      or
      (count($splitpath) == 2 and $splitpath[0] == 'node' and is_numeric($splitpath[1]))
    ) {
      if (count($splitpath) == 3) {
        $nidhere = $splitpath[2];
      }
      else {
        if (count($splitpath) == 2) {
          $nidhere = $splitpath[1];
        }
      }
      $dbresults = db_select('node', 'n')
        ->fields('n', array('nid', 'langcode'))
        ->condition('nid', $nidhere, '=')
        ->execute();
      foreach ($dbresults as $dbresult) {
        if ($dbresult->langcode <> 'und' and $dbresult->langcode <> '') {
          $lang_prefix = $dbresult->langcode . '/';
          // If this is a language-prefixed node we need its path without
          // the prefix for later.
          if (count($splitpath) == 3) {
            $path = $splitpath[1] . '/' . $splitpath[2];
          }
        }
        // Is just 1 result anyway.
        break;
      }
    }

    // Now if it's a node but has a prefixed or unprefixed alias,
    // e.g. en/my/path or my/path, we should also try to determine
    // if it's a node and then count it's node/nid with it!
    if ($lang_prefix == '') {
      // E.g. en/view or nl/my/view or xx/view.
      if (count($splitpath) > 1 and strlen($splitpath[0]) == 2 and !is_numeric($splitpath[0])) {

        // Now we need to find which nid does it correspond
        // (the language prefix + the alias).
        $withoutprefix = $splitpath;
        $lang = array_shift($withoutprefix);
        $withoutprefix = implode('/', $withoutprefix);
        $nodepath = \Drupal::service('path.alias_manager')
          ->getPathByAlias($withoutprefix);
        if ($nodepath !== FALSE) {
          $path = $nodepath;
          $lang_prefix = $lang . '/';
        }
      }
    }

    // Now, it's also possible that it's a node alias but without prefix!
    // E.g. my/path but in fact it's en/node/nid!
    if ($lang_prefix == '') {
      $path_no_slashes_at_ends = trim($path, '/');
      $nodepath = \Drupal::service('path.alias_manager')
        ->getPathByAlias($path_no_slashes_at_ends);
      if ($nodepath !== FALSE) {
        $path = $nodepath;
        $splitnodepath = explode('/', $nodepath);
        if (count($splitnodepath) == 2 and $splitnodepath[0] == 'node' and is_numeric($splitnodepath[1])) {
          $dbresults = db_select('node', 'n')
            ->fields('n', array('nid', 'langcode'))
            ->condition('nid', $splitnodepath[1], '=')
            ->execute();
          foreach ($dbresults as $dbresult) {
            if ($dbresult->langcode <> 'und' and $dbresult->langcode <> '') {
              $lang_prefix = $dbresult->langcode . '/';
            }
            // Is just 1 result anyway.
            break;
          }
          // $lang_prefix = $lang.'/';.
        }
      }
    }

    // But it also could be a redirect path!
    // @todo The module don't has drupal 8 revision.
    if (function_exists('redirect_load_by_source')) {
      $path_no_slashes_at_ends = trim($path, '/');
      $redirect_object = redirect_load_by_source($path_no_slashes_at_ends, $GLOBALS['language']->language, drupal_get_query_parameters());
      if (is_object($redirect_object)) {
        if (is_string($redirect_object->redirect)) {
          $path = $redirect_object->redirect;
        }
        if (is_string($redirect_object->language)) {
          $lang_prefix = $redirect_object->language . '/';
        }
      }
    }

    // All right, finally we can calculate the sum of pageviews.
    // This process is cached.
    $cacheid = md5($lang_prefix . $path);
    // $cacheon = FALSE; // Useful for debugging.
    if ($cache = \Drupal::cache()
        ->get('google_analytics_counter_page_' . $cacheid) and $cacheon
    ) {
      $sum_of_pageviews = $cache->data;
    }
    else {
      // Get pageviews for this path and all its aliases.
      // NOTE: Here $path does NOT have an initial slash because it's coming
      // from either check_plain($_GET['q']) (block) or from a tag like
      // [gac|node/N]. Remove a trailing slash (e.g. from node/3/) otherwise
      // _google_analytics_counter_path_aliases() does not find anything.
      $path_no_slashes_at_ends = trim($path, '/');
      $unprefixedaliases = self::pathAliases($path_no_slashes_at_ends);
      $allpaths = array();
      $allpaths_dpm = array();
      foreach ($unprefixedaliases as $val) {
        // Google Analytics stores initial slash as well, so let's prefix them.
        // With language prefix, if available, e.g. /en/node/55.
        $allpaths[] = md5('/' . $lang_prefix . $val);
        $allpaths_dpm[] = '/' . $lang_prefix . $val;
        // And its variant with trailing slash
        // (https://www.drupal.org/node/2396057).
        // With language prefix, if available, e.g. /en/node/55.
        $allpaths[] = md5('/' . $lang_prefix . $val . '/');
        $allpaths_dpm[] = '/' . $lang_prefix . $val . '/';
        if ($lang_prefix <> '') {
          // Now, if we are counting NODE with language prefix, we also need to
          // count the pageviews for that node without the prefix --
          // it could be that before it had no language prefix
          // but it still was the same node!
          // BUT this will not work for non-nodes, e.g. views.
          // There we depend on the path
          // e.g. /en/myview because it would be tricky to get a valid language
          // prefix out of the path. E.g. /en/myview could be a path of a view
          // where "en" does not mean the English language. In other words,
          // while prefix before node/id does not change the page
          // (it's the same node), with views or other custom pages the prefix
          // may actually contain completely different content.
          $allpaths[] = md5('/' . $val);
          $allpaths_dpm[] = '/' . $val;
          // And its variant with trailing slash
          // (https://www.drupal.org/node/2396057).
          $allpaths[] = md5('/' . $val . '/');
          $allpaths_dpm[] = '/' . $val . '/';
          // @TODO ... obviously, here we should treat the possibility of the NODE/nid having a different language prefix. A niche case (how often do existing NODES change language?)
        }
      }

      // Find possible redirects for this path using redirect_load_multiple()
      // from module Redirect http://drupal.org/project/redirect.
      // @todo Redirect module is currently being ported to Drupal 8,
      // @todo but is not usable yet.
      if (function_exists('redirect_load_multiple')) {
        $path_no_slashes_at_ends = trim($path, '/');
        $redirectobjects = redirect_load_multiple(FALSE, array('redirect' => $path_no_slashes_at_ends));
        foreach ($redirectobjects as $redirectobject) {
          $allpaths[] = md5('/' . $redirectobject->source);
          $allpaths_dpm[] = '/' . $redirectobject->source;
          // And its variant with trailing slash
          // (https://www.drupal.org/node/2396057).
          $allpaths[] = md5('/' . $redirectobject->source . '/');
          $allpaths_dpm[] = '/' . $redirectobject->source . '/';
          $allpaths[] = md5('/' . $redirectobject->language . '/' . $redirectobject->source);
          $allpaths_dpm[] = '/' . $redirectobject->language . '/' . $redirectobject->source;
          // And its variant with trailing slash
          // (https://www.drupal.org/node/2396057).
          $allpaths[] = md5('/' . $redirectobject->language . '/' . $redirectobject->source . '/');
          $allpaths_dpm[] = '/' . $redirectobject->language . '/' . $redirectobject->source . '/';
        }
      }

      // Very useful for debugging. In face each variant: node/NID, alias,
      // redirect, non-node ... with or without trailing slash,
      // with or without language ... should always give the same count
      // (sum of counts of all path variants).
      // Get path counts for each of the path aliases.
      // Search hash values of path -- faster (primary key). E.g.
      // SELECT pageviews FROM `google_analytics_counter` where pagepath_hash
      // IN ('ee1c787bc14bec9945de3240101e99','d884e66c2316317ef6294dc12aca9c').
      $pathcounts = db_select('google_analytics_counter', 'gac')
        ->fields('gac', array('pageviews'))
        ->condition('pagepath_hash', $allpaths, 'IN')
        ->execute();
      $sum_of_pageviews = 0;
      foreach ($pathcounts as $pathcount) {
        $sum_of_pageviews += $pathcount->pageviews;
      }

      \Drupal::cache()
        ->set('google_analytics_counter_page_' . $cacheid, $sum_of_pageviews);
    }

    return $sum_of_pageviews;
  }

  /**
   * Return a list of paths that are aliased with the given path.
   */
  private static function pathAliases($node_path) {

    // Get the normal node path if it is a node.].
    $node_path = \Drupal::service('path.alias_manager')
      ->getPathByAlias($node_path);

    // Grab all aliases.
    $aliases = array($node_path);

    $result = db_query("SELECT * FROM {url_alias} WHERE source = :source",
      array(':source' => $node_path)
    )->fetchAll();
    foreach ($result as $row) {
      $aliases[] = $row->alias;
    }

    // If this is the front page, add the base path too,
    // and index.php for good measure.
    // There may be other ways that the user is accessing the front page
    // but we can't account for them all.
    if ($node_path == \Drupal::config('system.site')->get('page.front')) {
      $aliases[] = '';
      $aliases[] = '/';
      $aliases[] = 'index.php';
    }

    return $aliases;
  }

  /**
   * Request report data.
   *
   * @param array $params
   *   An associative array containing:
   *   - profile_id: required [default=config('profile_id')]
   *   - metrics: required.
   *   - dimensions: optional [default=none]
   *   - sort_metric: optional [default=none]
   *   - filters: optional [default=none]
   *   - segment: optional [default=none]
   *   - start_date: optional [default=GA release date]
   *   - end_date: optional [default=today]
   *   - start_index: optional [default=1]
   *   - max_results: optional [default=10,000].
   * @param array $cache_options
   *   An optional associative array containing:
   *   - cid: optional [default=md5 hash]
   *   - expire: optional [default=CACHE_TEMPORARY]
   *   - refresh: optional [default=FALSE].
   *
   * @return object
   *   A new GoogleAnalyticsCounterFeed object
   */
  private static function reportData($params = array(), $cache_options = array()) {
    $params_defaults = array(
      'profile_id' => 'ga:' . \Drupal::config('google_analytics_counter.settings')->get('profile_id'),
    );

    $params += $params_defaults;

    $ga_feed = self::newGaFeed();
    $ga_feed->queryReportFeed($params, $cache_options);

    return $ga_feed;
  }

}
