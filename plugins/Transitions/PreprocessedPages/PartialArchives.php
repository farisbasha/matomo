<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\Transitions\PreprocessedPages;

use Piwik\Archive;
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
use Piwik\Period\Factory as PeriodFactory;
use Piwik\Period\Range;
use Piwik\Piwik;
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
    /**
     * The {@link Records::INVALIDATED} value of a longer period built while the catch-up is pending, which the
     * catch-up queues once it has finished. Other marked archives hold 1, unmarked longer periods 0.
     */
    public const MARKED_WHILE_CATCH_UP_PENDING = 2;

    private const INVALIDATION_MARKER_OPTION_PREFIX = 'Transitions.invalidated_';

    /**
     * Set while the catch-up on archives invalidated while the plugin was deactivated is pending, to a token of the
     * activation, `:` and the last numeric table it finished, or {@link CATCH_UP_NOT_STARTED}.
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
        Option::set(self::CATCH_UP_OPTION, Common::getRandomString(16) . ':' . self::CATCH_UP_NOT_STARTED, 1);
    }

    public function isCatchUpOnInvalidatedArchivesPending(): bool
    {
        // the plugin may have been activated again since this process read it
        Option::clearCachedOption(self::CATCH_UP_OPTION);

        return Option::get(self::CATCH_UP_OPTION) !== false;
    }

    /**
     * Marks the partial archives of the periods and segments that have an invalidated archive, or a queued invalidation
     * of all or of this plugin's reports, if {@link scheduleCatchUpOnInvalidatedArchives()} was called. Those built
     * after the invalidation are marked too, so they aren't served until the period is archived again: core keeps the
     * older time of a queued invalidation when it adds another one to it. It reads every numeric row of the websites
     * with pages, so it runs as a scheduled task, one table after the other, and continues after the last finished
     * table if it was stopped. If the plugin is activated again meanwhile, it stops, and the next run starts again.
     * Once it has finished, it queues the longer periods built meanwhile.
     */
    public function catchUpOnInvalidatedArchives(): void
    {
        Option::clearCachedOption(self::CATCH_UP_OPTION);
        $value = Option::get(self::CATCH_UP_OPTION);
        if (!is_string($value) || !str_contains($value, ':')) {
            return;
        }
        [$token, $progress] = explode(':', $value, 2);

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

            $newValue = $token . ':' . $table;
            if (!$this->replaceCatchUpProgress($value, $newValue)) {
                return;
            }
            $value = $newValue;
        }

        // unless the plugin was activated again meanwhile
        Option::delete(self::CATCH_UP_OPTION, $value);
        Option::clearCachedOption(self::CATCH_UP_OPTION);
        if (Option::get(self::CATCH_UP_OPTION) !== false) {
            return;
        }

        foreach ($tables as $table) {
            foreach (array_chunk($idSites, self::SITES_PER_QUERY) as $idSitesOfChunk) {
                $this->queueArchivesMarkedWhileCatchUpPending($table, $idSitesOfChunk);
            }
        }
    }

    /**
     * @param list<int> $idSites
     */
    private function queueArchivesMarkedWhileCatchUpPending(string $table, array $idSites): void
    {
        $rows = Db::fetchAll(
            'SELECT DISTINCT d.idsite, d.period, d.date1, d.date2, d.name FROM `' . $table . '` m'
            . ' JOIN `' . $table . "` d ON d.idarchive = m.idarchive AND d.name LIKE 'done%'"
            . ' WHERE m.idsite IN (' . implode(',', $idSites) . ') AND m.period > ? AND m.name = ? AND m.value = ?',
            [Day::PERIOD_ID, Records::INVALIDATED, self::MARKED_WHILE_CATCH_UP_PENDING]
        );

        $segmentsByHash = [];
        foreach ($rows as $row) {
            $idSite = (int) $row['idsite'];
            if (!isset($segmentsByHash[$idSite])) {
                $segmentsByHash[$idSite] = ['' => new Segment('', [$idSite])];
                foreach (Rules::getSegmentsToProcess([$idSite]) as $definition) {
                    $segmentsByHash[$idSite][Segment::getSegmentHash($definition)] = new Segment($definition, [$idSite]);
                }
            }

            $hash = substr(explode('.', (string) $row['name'])[0], strlen('done'));
            $segment = $segmentsByHash[$idSite][$hash] ?? null;
            $periodLabel = array_search((int) $row['period'], Piwik::$idPeriods, true);
            if ($segment === null || !is_string($periodLabel)) {
                continue;
            }

            $this->invalidateLongerPeriod($idSite, PeriodFactory::build($periodLabel, (string) $row['date1']), $segment);
        }
    }

    /**
     * Writes the catch-up's progress unless the plugin was activated again since it was read.
     */
    private function replaceCatchUpProgress(string $value, string $newValue): bool
    {
        $isReplaced = Db::query(
            'UPDATE `' . Common::prefixTable('option') . '` SET option_value = ? WHERE option_name = ? AND option_value = ?',
            [$newValue, self::CATCH_UP_OPTION, $value]
        )->rowCount() > 0;
        Option::clearCachedOption(self::CATCH_UP_OPTION);

        return $isReplaced;
    }

    /**
     * Returns the periods whose records are read from an archive marked {@link Records::INVALIDATED}.
     *
     * @param list<Period> $periods
     * @return list<Period>
     */
    public function getMarkedPeriods(int $idSite, array $periods, Segment $segment): array
    {
        if (empty($periods)) {
            return [];
        }

        $archive = Archive::factory($segment, $periods, [$idSite], false, true);
        if (!$archive instanceof Archive) {
            return [];
        }
        $archive->forceFetchingWithoutLaunchingArchiving();

        // read alone, as a value missing next to another is returned as 0
        $markers = $archive->getNumeric([Records::INVALIDATED]);
        $markers = is_array($markers) ? $markers : [];

        return array_values(array_filter($periods, function (Period $period) use ($markers): bool {
            $markersOfPeriod = $markers[$period->getRangeString()] ?? null;
            $value = is_array($markersOfPeriod) ? $markersOfPeriod[Records::INVALIDATED] ?? 0 : 0;

            return is_numeric($value) && $value > 0;
        }));
    }

    /**
     * Queues the marked week, month and year containing a period that was archived again without the marker, so they
     * are built again from it. It is called from the period's own build only, so a period never queues itself, and a
     * longer period is queued again only once one of its subperiods was archived again.
     */
    public function queueMarkedLongerPeriods(int $idSite, Period $period, Segment $segment): void
    {
        $longerPeriods = [];
        foreach (['week', 'month', 'year'] as $label) {
            $longerPeriod = PeriodFactory::build($label, $period->getDateStart());
            if ($longerPeriod->getId() > $period->getId()) {
                $longerPeriods[] = $longerPeriod;
            }
        }

        foreach ($this->getMarkedPeriods($idSite, $longerPeriods, $segment) as $longerPeriod) {
            $this->invalidateLongerPeriod($idSite, $longerPeriod, $segment);
        }
    }

    /**
     * Queues the Transitions archive of a longer period whose records were summed from a subperiod's archive marked
     * {@link Records::INVALIDATED}, so `core:archive` builds it again, without the marker. Only the period's archives of
     * this plugin are marked invalidated, not the period's ranges or longer periods. A queued invalidation is not added
     * again.
     */
    private function invalidateLongerPeriod(int $idSite, Period $period, Segment $segment): void
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

            // the partial archive stays DONE_PARTIAL, so core deletes it once a newer archive replaces it; a longer
            // period's archive already has the marker, as 0
            Db::query(
                'INSERT INTO `' . $table . '` (idarchive, idsite, date1, date2, period, ts_archived, name, value) VALUES ' . implode(',', $values)
                . ' ON DUPLICATE KEY UPDATE value = 1',
                $bind
            );
        }
    }
}
