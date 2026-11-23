<?php

declare(strict_types=1);

namespace Endurance\ActivityGpx\Import;

use Endurance\Activity\Application\Import\ActivityImportItem;
use Endurance\Activity\Application\Import\ActivityLifecycleAction;
use Endurance\Activity\Application\Import\ActivityLifecycleItem;
use Endurance\Activity\Application\Import\ObservationItem;
use Endurance\Activity\Application\UseCase\ImportActivityStream;
use Endurance\Activity\ValueObject\ActivityId;
use Endurance\Activity\ValueObject\ActivityImportIdempotencyKey;
use Endurance\ActivityAnalysis\TrackAnalyzer;
use Endurance\ActivityGpx\Exception\UnsupportedGpxActivity;
use Endurance\Foundation\ValueObject\Instant;
use Endurance\Gpx\Model\GpxPoint;
use Endurance\Gpx\Reader;

final readonly class ImportGpxActivity
{
    public function __construct(private ImportActivityStream $activities, private Reader $reader = new Reader())
    {
    }

    /**
     * Fully validate a private spool before onPrepared and generation acquisition.
     *
     * @param resource                                     $source     caller-owned, need not be seekable
     * @param \Closure(Instant): void|null                 $onPrepared
     * @param \Closure(GpxActivityImportReport): void|null $onImported runs inside activation transaction
     * @param \Closure(): void|null                        $onProgress
     */
    public function import(
        ActivityId $activityId,
        ActivityImportIdempotencyKey $idempotencyKey,
        mixed $source,
        ?\Closure $onPrepared = null,
        ?\Closure $onImported = null,
        ?\Closure $onProgress = null,
    ): GpxActivityImportResult {
        if (!is_resource($source) || 'stream' !== get_resource_type($source)) {
            throw new \InvalidArgumentException('GPX source must be a stream.');
        }
        $spool = tmpfile();
        if (false === $spool) {
            throw new \RuntimeException('GPX preparation storage is unavailable.');
        }
        try {
            while (!feof($source)) {
                $bytes = fread($source, 65_536);
                if (false === $bytes || ('' === $bytes && !feof($source))) {
                    throw new \RuntimeException('Cannot read GPX source.');
                }
                if (strlen($bytes) !== fwrite($spool, $bytes)) {
                    throw new \RuntimeException('Cannot prepare GPX source.');
                }
                $onProgress?->__invoke();
            }
            if (!rewind($spool)) {
                throw new \RuntimeException('Cannot rewind prepared GPX.');
            }
            $analysis = new TrackAnalyzer();
            $startedAt = $finishedAt = null;
            $count = $segments = $previousSegment = 0;
            $name = null;
            $points = $this->reader->points($spool);
            foreach ($points as $point) {
                $onProgress?->__invoke();
                if ('track' !== $point->kind) {
                    continue;
                }
                if (1 !== $point->pathIndex) {
                    throw new UnsupportedGpxActivity('Activity import requires one recorded track; multiple tracks require a separate import mode.');
                }
                $time = self::instant($point);
                if (null !== $finishedAt && $time->isBefore($finishedAt)) {
                    throw new UnsupportedGpxActivity('GPX track times must be chronological, including between segments.');
                }
                $analysis->push($point->latitude, $point->longitude, self::microseconds($time), $point->segmentIndex, $point->measurements['speed']['value'] ?? null);
                $startedAt ??= $time;
                $finishedAt = $time;
                $name ??= $point->name;
                ++$count;
                if ($previousSegment !== $point->segmentIndex) {
                    ++$segments;
                }
                $previousSegment = $point->segmentIndex;
            }
            $document = $points->getReturn();
            if (1 !== $document->trackCount || null === $startedAt || null === $finishedAt) {
                throw new UnsupportedGpxActivity('Activity import requires exactly one non-empty recorded track.');
            }
            $name = (null === $name || '' === $name) ? $document->name : $name;
            $trackSummary = $document->trackSummaries[1] ?? [];
            $statistics = $analysis->statistics(isset($trackSummary['length']) ? (float) $trackSummary['length'] : null, isset($trackSummary['duration']) ? (int) round((float) $trackSummary['duration'] * 1000) : null);
            $report = new GpxActivityImportReport($activityId, $document->version, $count, $segments, $name, $document->routeCount, $document->waypointCount, analysis: $statistics);
            $onPrepared?->__invoke($startedAt);
            if (!rewind($spool)) {
                throw new \RuntimeException('Cannot rewind prepared GPX.');
            }
            $result = $this->activities->handle(
                $activityId, $idempotencyKey, $this->items($spool, $startedAt, $finishedAt, $onProgress),
                null === $onImported ? null : static fn () => $onImported($report),
            );

            return new GpxActivityImportResult($result, $result->isImported() ? $report : null);
        } finally {
            fclose($spool);
        }
    }

    /** @param resource $spool @return \Generator<int, ActivityImportItem> */
    private function items(mixed $spool, Instant $start, Instant $finish, ?\Closure $progress): \Generator
    {
        $analysis = new TrackAnalyzer();
        $mapper = new GpxActivityPointMapper();
        yield new ActivityLifecycleItem(ActivityLifecycleAction::Start, $start);
        foreach ($this->reader->points($spool) as $point) {
            $progress?->__invoke();
            if ('track' !== $point->kind) {
                continue;
            }
            yield new ObservationItem($mapper->observation($point, $analysis));
        }
        yield new ActivityLifecycleItem(ActivityLifecycleAction::Finish, $finish);
    }

    private static function microseconds(Instant $time): int
    {
        return GpxActivityPointMapper::microseconds($time);
    }

    private static function instant(GpxPoint $point): Instant
    {
        return GpxActivityPointMapper::instant($point);
    }
}
