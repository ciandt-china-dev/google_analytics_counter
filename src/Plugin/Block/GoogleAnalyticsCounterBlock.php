<?php
/**
 * @file
 * Contains \Drupal\search\Plugin\Block\SearchBlock.
 */

namespace Drupal\google_analytics_counter\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'count form' block.
 *
 * @Block(
 *   id = "google_analytics_counter_form_block",
 *   admin_label = @Translation("Google Analytics Counter")
 * )
 */
class GoogleAnalyticsCounterBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $block_content = $this->counterDisplay();
    if ($block_content == '') {
      // If unknown, for some reason.
      // Instead of t('N/A'). Suppose better to use 0 because it's true,
      // that path has been recorded zero times by GA.
      // Path may not exist or be private or too new.
      $block_content = 0;
    }
    return array(
      '#markup' => $block_content
    );
  }

  /**
   * Displays the count.
   */
  private function counterDisplay($path = '') {
    if ($path == '') {
      // We need a path that includes the language prefix, if any. E.g. en/my/path (of /en/my/path - the initial slash will be dealt with later).
      $path = parse_url("http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]", PHP_URL_PATH); // @TODO: Works OK on non-Apache servers?
    }

    $block_content = '';
    $block_content .= '<span class="google-analytics-counter">';
    $count = google_analytics_counter_get_sum_per_path($path);
    if ($count == '') {
      // If unknown, for some reason.
      $block_content .= 0; // Better than t('N/A').
    }
    else {
      $block_content .= $count;
    }
    $block_content .= '</span>';

    return $block_content;
  }
}