<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\Transitions;

use Piwik\Container\StaticContainer;
use Piwik\Date;
use Piwik\Plugins\PrivacyManager\PrivacyManager;
use Piwik\Plugins\Transitions\PreprocessedPages\Service;

class Tasks extends \Piwik\Plugin\Tasks
{
    /**
     * @return void
     */
    public function schedule()
    {
        $this->daily('deleteRemovedPreprocessedPagesOfDeletedReports', null, self::LOW_PRIORITY);
    }

    /**
     * Removed pre-processed pages are kept while their stored records may be served, and deleted with old reports.
     */
    public function deleteRemovedPreprocessedPagesOfDeletedReports(): void
    {
        StaticContainer::get(Service::class)->deleteRemovedPagesOfDeletedReports(PrivacyManager::getPurgeDataSettings(), Date::factory('today'));
    }
}
