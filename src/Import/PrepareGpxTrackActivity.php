<?php

declare(strict_types=1);

namespace Endurance\ActivityGpx\Import;

use Endurance\Activity\Application\Import\ActivityLifecycleAction;
use Endurance\Activity\Application\Import\ActivityLifecycleItem;
use Endurance\Activity\Application\Import\ObservationItem;
use Endurance\Activity\ValueObject\ActivityId;
use Endurance\ActivityAnalysis\TrackAnalyzer;
use Endurance\ActivityGpx\Exception\UnsupportedGpxActivity;
use Endurance\Gpx\Model\GpxDocument;
use Endurance\Gpx\Model\GpxObject;
use Endurance\Gpx\Model\GpxPoint;

final readonly class PrepareGpxTrackActivity
{
    /** @param \Closure():iterable<GpxPoint> $points Repeatable unchanged caller-owned source */
    public function prepare(ActivityId $id, GpxObject $track, GpxDocument $document, \Closure $points, ?\Closure $progress = null): PreparedGpxActivity
    {
        if ('track' !== $track->kind || $track->pointCount < 1 || $track->timedPointCount !== $track->pointCount) {
            throw new UnsupportedGpxActivity('Activity requires a nonempty selected track with a timestamp on every point.');
        }
        $start = $finish = null;
        $analysis = new TrackAnalyzer();
        $segments = $previousSegment = 0;
        foreach ($this->checked($track, $points(), $progress) as $point) {
            $time = GpxActivityPointMapper::instant($point);
            $analysis->push($point->latitude, $point->longitude, GpxActivityPointMapper::microseconds($time), $point->segmentIndex, $point->measurements['speed']['value'] ?? null);
            $start ??= $time;
            $finish = $time;
            $segments += $previousSegment !== $point->segmentIndex ? 1 : 0;
            $previousSegment = $point->segmentIndex;
        }
        if (null === $start) {
            throw new UnsupportedGpxActivity('Empty selected GPX track.');
        }
        $summary = $document->trackSummaries[$track->pathIndex] ?? [];
        $statistics = $analysis->statistics(isset($summary['length']) ? (float) $summary['length'] : null, isset($summary['duration']) ? (int) round((float) $summary['duration'] * 1000) : null);
        $report = new GpxActivityImportReport($id, $document->version, $track->pointCount, $segments, $track->name, $document->routeCount, $document->waypointCount, analysis: $statistics);
        $items = function () use ($track, $points, $progress, $start, $finish): \Generator {
            $analysis = new TrackAnalyzer();
            $mapper = new GpxActivityPointMapper();
            yield new ActivityLifecycleItem(ActivityLifecycleAction::Start, $start);
            $last = null;
            foreach ($this->checked($track, $points(), $progress) as $point) {
                $time = GpxActivityPointMapper::instant($point);
                if (null === $last && !$time->equals($start)) {
                    throw new UnsupportedGpxActivity('Selected source changed after preparation.');
                }
                $last = $time;
                yield new ObservationItem($mapper->observation($point, $analysis));
            }
            if (null === $last || !$last->equals($finish)) {
                throw new UnsupportedGpxActivity('Selected source changed after preparation.');
            }
            yield new ActivityLifecycleItem(ActivityLifecycleAction::Finish, $finish);
        };

        return new PreparedGpxActivity($start, $finish, $report, $items);
    }

    /** @param iterable<GpxPoint> $points @return \Generator<int,GpxPoint> */
    private function checked(GpxObject $track, iterable $points, ?\Closure $progress): \Generator
    {
        $count = $segment = 0;
        $last = null;
        foreach ($points as $point) {
            $progress?->__invoke();
            if ('track' !== $point->kind || $point->pathIndex !== $track->pathIndex || $point->pointIndex !== ++$count || $count > $track->pointCount || $point->segmentIndex < max(1, $segment) || $point->segmentIndex > $track->segmentCount) {
                throw new UnsupportedGpxActivity('Selected GPX track identity, point count or segment ordering mismatch.');
            }
            $time = GpxActivityPointMapper::instant($point);
            if (null !== $last && $time->isBefore($last)) {
                throw new UnsupportedGpxActivity('GPX track times must be chronological, including between segments.');
            }
            $last = $time;
            $segment = $point->segmentIndex;
            yield $point;
        }
        if ($count !== $track->pointCount) {
            throw new UnsupportedGpxActivity('Incomplete selected GPX track.');
        }
        $progress?->__invoke();
    }
}
