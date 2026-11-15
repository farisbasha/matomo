<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\Transitions\PreprocessedPages;

use Piwik\ArchiveProcessor\Rules;
use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\DataAccess\ArchiveWriter;
use Piwik\DataAccess\Model;
use Piwik\Db;
use Piwik\Option;
use Piwik\Period;
use Piwik\Period\Day;
use Piwik\Period\Range;
use Piwik\Plugins\SitesManager\Model as SitesModel;
use Piwik\Segment;

/**
 * The partial archives holding pre-processed Transitions records, `done<segment hash>.Transitions`: written by the
 * rebuild of an added page and by builds within report requests.
 *
 * Core doesn't invalidate partial archives, so they are marked {@link Records::INVALIDATED} when core invalidates the
 * periods they hold. Their records are then no longer served, but they are kept, like core's invalidated archives,
 * until the period is archived again, which also builds the removed pages they hold records of. An invalidation core
 * only remembers, like that of a data subject's deleted visits, marks them once core applies it. Until then they are
 * served like core's own archives, and they stay like those if core skips it, as the logs of the day were deleted
 * meanwhile. A period archived again after its logs were deleted gets an archive without visits, which replaces them
 * as it replaces core's archives.
 */
final class PartialArchives
{
    private const INVALIDATION_MARKER_OPTION_PREFIX = 'Transitions.invalidated_';

    /**
     * Set while the catch-up on archives invalidated while the plugin was deactivated is pending, to the last numeric
     * table it finished, or {@link CATCH_UP_NOT_STARTED}.
     */
    private const CATCH_UP_OPTION = 'Transitions.invalidatedArchivesCatchUp';

    private const CATCH_UP_NOT_STARTED = '-';

    private const IDARCHIVES_PER_QUERY = 1000;

    private const SITES_PER_QUERY = 100;

    public function __construct(
        private readonly Dao $dao,
        private readonly Model $model
    ) {
    }

    /**
     * Marks the partial archives of the periods core invalidated as invalidated, for the segment, or for all segments
     * without one. A build of a day within a request that runs meanwhile notices it, see {@link getInvalidationMarker()}.
     *
     * @param list<int> $idSites
     * @param list<Period> $periods the periods core invalidated, see `Archiving.markArchivesAsInvalidated.end`
     */
    public function invalidateForPeriods(array $idSites, array $periods, ?Segment $segment): void
    {
        // ranges aren't archived, see PageTransitions
        $periods = array_values(array_filter($periods, fn(Period $period): bool => !$period instanceof Range));
        if (empty($periods) || empty($idSites)) {
            return;
        }

        $idSites = $this->dao->getSitesWithAnyPages(array_values(array_unique($idSites)));
        if (empty($idSites)) {
            return;
        }

        $periodsByTable = [];
        $hasDays = false;
        foreach ($periods as $period) {
            $table = ArchiveTableCreator::getNumericTable($period->getDateStart(), false);
            if (!empty($table)) {
                $periodsByTable[$table][$period->getId() . ',' . $period->getRangeString()] = $period;
            }
            $hasDays = $hasDays || $period->getLabel() === 'day';
        }

        if ($hasDays) {
            foreach ($idSites as $idSite) {
                $this->touchInvalidationMarker($idSite);
            }
        }

        foreach ($periodsByTable as $table => $periodsOfTable) {
            $this->invalidatePartialArchives($table, $idSites, array_values($periodsOfTable), $segment);
        }
    }

    /**
     * Core invalidates archives without telling this plugin while it is deactivated, so its activation schedules
     * {@link catchUpOnInvalidatedArchives()}. No stored records are served until it has run.
     */
    public function scheduleCatchUpOnInvalidatedArchives(): void
    {
        Option::set(self::CATCH_UP_OPTION, self::CATCH_UP_NOT_STARTED, 1);
    }

    public function isCatchUpOnInvalidatedArchivesPending(): bool
    {
        return Option::get(self::CATCH_UP_OPTION) !== false;
    }

    /**
     * Marks the partial archives of the periods and segments that have an invalidated archive, or a queued invalidation
     * of all or of this plugin's reports, if {@link scheduleCatchUpOnInvalidatedArchives()} was called. Those built
     * after the invalidation are marked too, so they aren't served until the period is archived again. It reads every
     * numeric row of the websites with pages, so it runs as a scheduled task, one table after the other, and continues
     * after the last finished table if it was stopped.
     */
    public function catchUpOnInvalidatedArchives(): void
    {
        Option::clearCachedOption(self::CATCH_UP_OPTION);
        $progress = Option::get(self::CATCH_UP_OPTION);
        if (!is_string($progress)) {
            return;
        }

        $idSites = $this->dao->getSitesWithAnyPages(array_values(array_map('intval', StaticContainer::get(SitesModel::class)->getSitesId())));
        $tables = empty($idSites) ? [] : ArchiveTableCreator::getTablesArchivesInstalled(ArchiveTableCreator::NUMERIC_TABLE);
        sort($tables);
        foreach ($tables as $table) {
            if ($progress !== self::CATCH_UP_NOT_STARTED && strcmp($table, $progress) <= 0) {
                continue;
            }

            foreach (array_chunk($idSites, self::SITES_PER_QUERY) as $idSitesOfChunk) {
                $rows = Db::fetchAll(
                    'SELECT p.idarchive, p.idsite, p.date1, p.date2, p.period, p.ts_archived FROM `' . $table . '` p'
                    . ' WHERE p.idsite IN (' . implode(',', $idSitesOfChunk) . ") AND p.name LIKE 'done%.Transitions' AND p.value = ?"
                    . ' AND (EXISTS (SELECT 1 FROM `' . $table . '` i WHERE i.idsite = p.idsite AND i.date1 = p.date1 AND i.date2 = p.date2'
                    . " AND i.period = p.period AND i.name IN (SUBSTRING_INDEX(p.name, '.', 1), p.name) AND i.value = ?)"
                    . ' OR EXISTS (SELECT 1 FROM `' . Common::prefixTable('archive_invalidations') . '` q WHERE q.idsite = p.idsite'
                    . ' AND q.date1 = p.date1 AND q.date2 = p.date2 AND q.period = p.period'
                    . " AND q.name IN (SUBSTRING_INDEX(p.name, '.', 1), p.name) AND (q.report IS NULL OR LENGTH(q.report) = 0)))",
                    [ArchiveWriter::DONE_PARTIAL, ArchiveWriter::DONE_INVALIDATED]
                );
                $this->markArchives($table, $rows);
            }

            $progress = $table;
            Option::set(self::CATCH_UP_OPTION, $progress, 1);
        }

        // unless the plugin was activated again meanwhile
        Option::clearCachedOption(self::CATCH_UP_OPTION);
        if (Option::get(self::CATCH_UP_OPTION) === $progress) {
            Option::delete(self::CATCH_UP_OPTION);
        }
    }

    /**
     * Queues the Transitions archive of a longer period whose records were summed from a subperiod's archive marked
     * {@link Records::INVALIDATED}, so `core:archive` builds it again after that subperiod, without the marker. Only the
     * period's archives of this plugin are marked invalidated, not the period's ranges or longer periods, which are
     * marked in turn when they are built from it.
     */
    public function invalidateLongerPeriod(int $idSite, Period $period, Segment $segment): void
    {
        $this->model->updateArchiveAsInvalidated(
            ArchiveTableCreator::getNumericTable($period->getDateStart(), false) ?: null,
            [$idSite],
            [$period],
            $segment,
            false,
            'Transitions'
        );
    }

    /**
     * Deletes the page's older partial archives of the day and segment, which hold only the page's records, once a
     * newer one is written.
     */
    public function deleteOlderPartialArchivesOfPage(int $idSite, Period $day, Segment $segment, int $idPage, int $idArchive): void
    {
        $table = ArchiveTableCreator::getNumericTable($day->getDateStart(), false);
        if (empty($table)) {
            return;
        }

        $pageviews = Records::getRecordName($idPage, Records::PAGEVIEWS);
        $rows = Db::fetchAll(
            'SELECT p.idarchive FROM `' . $table . '` p WHERE p.idsite = ? AND p.period = ? AND p.date1 = ? AND p.date2 = ?'
            . ' AND p.name = ? AND p.value = ? AND p.idarchive < ?'
            . ' AND EXISTS (SELECT 1 FROM `' . $table . '` r WHERE r.idarchive = p.idarchive AND r.name = ?)'
            . ' AND NOT EXISTS (SELECT 1 FROM `' . $table . '` o WHERE o.idarchive = p.idarchive AND o.name LIKE ? AND o.name <> ?)',
            [
                $idSite,
                Day::PERIOD_ID,
                $day->getDateStart()->toString(),
                $day->getDateEnd()->toString(),
                Rules::getDoneFlagArchiveContainsOnePlugin($segment, 'Transitions'),
                ArchiveWriter::DONE_PARTIAL,
                $idArchive,
                $pageviews,
                'Transitions\_%\_' . Records::PAGEVIEWS,
                $pageviews,
            ]
        );

        $this->deleteArchives($day, array_map(fn(array $row): int => (int) $row['idarchive'], $rows));
    }

    /**
     * @param list<int> $idArchives
     */
    public function deleteArchives(Period $period, array $idArchives): void
    {
        $table = ArchiveTableCreator::getNumericTable($period->getDateStart(), false);
        if (empty($table) || empty($idArchives)) {
            return;
        }

        $this->model->deleteArchiveIds($table, ArchiveTableCreator::getBlobTable($period->getDateStart(), false) ?: null, $idArchives);
    }

    /**
     * Changes whenever core applies an invalidation of days of the website for all plugins or for Transitions, if
     * the website has pages, also removed ones.
     */
    public function getInvalidationMarker(int $idSite): ?string
    {
        $name = $this->getInvalidationMarkerOptionName($idSite);
        Option::clearCachedOption($name);
        $value = Option::get($name);

        return is_string($value) ? $value : null;
    }

    public function forgetSite(int $idSite): void
    {
        Option::delete($this->getInvalidationMarkerOptionName($idSite));
    }

    /**
     * Forgets the websites deleted while the plugin was deactivated.
     */
    public function forgetDeletedSites(): void
    {
        $idSites = array_map('intval', StaticContainer::get(SitesModel::class)->getSitesId());
        foreach (array_keys(Option::getLike(self::INVALIDATION_MARKER_OPTION_PREFIX . '%')) as $name) {
            $name = (string) $name;
            if (!in_array((int) substr($name, strlen(self::INVALIDATION_MARKER_OPTION_PREFIX)), $idSites, true)) {
                Option::delete($name);
            }
        }
    }

    private function touchInvalidationMarker(int $idSite): void
    {
        Option::set($this->getInvalidationMarkerOptionName($idSite), Common::getRandomString(16));
    }

    private function getInvalidationMarkerOptionName(int $idSite): string
    {
        return self::INVALIDATION_MARKER_OPTION_PREFIX . $idSite;
    }

    /**
     * @param list<int> $idSites
     * @param list<Period> $periods
     */
    private function invalidatePartialArchives(string $table, array $idSites, array $periods, ?Segment $segment): void
    {
        $bind = [];
        $periodConditions = [];
        foreach ($periods as $period) {
            $periodConditions[] = '(period = ? AND date1 = ? AND date2 = ?)';
            array_push($bind, $period->getId(), $period->getDateStart()->toString(), $period->getDateEnd()->toString());
        }

        if ($segment === null) {
            $nameCondition = "name LIKE 'done%.Transitions'";
        } else {
            $nameCondition = 'name = ?';
            $bind[] = Rules::getDoneFlagArchiveContainsOnePlugin($segment, 'Transitions');
        }

        $rows = Db::fetchAll(
            'SELECT idarchive, idsite, date1, date2, period, ts_archived FROM `' . $table . '` WHERE idsite IN (' . implode(',', $idSites) . ')'
            . ' AND (' . implode(' OR ', $periodConditions) . ') AND ' . $nameCondition
            . ' AND value = ' . ArchiveWriter::DONE_PARTIAL,
            $bind
        );

        $this->markArchives($table, $rows);
    }

    /**
     * @param array<array<mixed>> $rows the archives' idarchive, idsite, date1, date2, period and ts_archived
     */
    private function markArchives(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::IDARCHIVES_PER_QUERY) as $chunk) {
            $values = [];
            $bind = [];
            foreach ($chunk as $row) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, 1)';
                array_push($bind, $row['idarchive'], $row['idsite'], $row['date1'], $row['date2'], $row['period'], $row['ts_archived'], Records::INVALIDATED);
            }

            // the partial archive stays DONE_PARTIAL, so core deletes it once a newer archive replaces it
            Db::query(
                'INSERT IGNORE INTO `' . $table . '` (idarchive, idsite, date1, date2, period, ts_archived, name, value) VALUES ' . implode(',', $values),
                $bind
            );
        }
    }
}
