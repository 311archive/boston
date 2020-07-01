<?php

namespace Drupal\bos311\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'Powered By' Block for Boston 311.
 *
 * @Block(
 *   id = "bos311_powered_by",
 *   admin_label = @Translation("Boston 311 Powered By"),
 *   category = @Translation("Powered By"),
 * )
 */
class PoweredBy311 extends BlockBase {

    /**
     * {@inheritdoc}
     */
    public function build() {
        return [
            '#markup' => $this->t('Powered by <strong><a href="drupal.org">Drupal 9</a></strong>. Data scraped from <strong><a href="https://mayors24.cityofboston.gov/open311/v2/">Boston 311 API</a></strong>. Location data retrieved from <strong><a href="https://nominatim.org/">Open Street Map\'s Nominatim service</a></strong>. (which is excellent  and for which we are very grateful)'),
        ];
    }

}