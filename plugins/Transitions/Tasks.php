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
use Piwik\Plugins\Transitions\PreprocessedPages\PartialArchives;

class Tasks extends \Piwik\Plugin\Tasks
{
    /**
     * @return void
     */
    public function schedule()
    {
        $this->hourly('catchUpOnInvalidatedArchives');
    }

    public function catchUpOnInvalidatedArchives(): void
    {
        StaticContainer::get(PartialArchives::class)->catchUpOnInvalidatedArchives();
    }
}
