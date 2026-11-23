<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityGpx\Import;

use Youmad\Endurance\Activity\Telemetry\ActivityObservation;
use Youmad\Endurance\Activity\Telemetry\MeasurementReading;
use Youmad\Endurance\Activity\Telemetry\MeasurementSource;
use Youmad\Endurance\Activity\Telemetry\MeasurementType;
use Youmad\Endurance\Activity\Telemetry\MeasurementUnit;
use Youmad\Endurance\Activity\Telemetry\PositionMeasurement;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\ActivityAnalysis\TrackAnalyzer;
use Youmad\Endurance\ActivityGpx\Exception\UnsupportedGpxActivity;
use Youmad\Endurance\Foundation\ValueObject\Coordinate;
use Youmad\Endurance\Foundation\ValueObject\Instant;
use Youmad\Endurance\Gpx\Model\GpxPoint;

/** @internal */
final readonly class GpxActivityPointMapper
{
    public function observation(GpxPoint $point, TrackAnalyzer $analysis): ActivityObservation
    {
        $metadata = new GpxPointMetadata($point->pathIndex, $point->segmentIndex, $point->pointIndex);
        $source = MeasurementSource::unknown();
        $readings = [MeasurementReading::reported(new PositionMeasurement(new Coordinate($point->latitude, $point->longitude)), $source, $metadata)];
        foreach ($point->measurements as $type => $measurement) {
            $readings[] = MeasurementReading::reported(new ScalarMeasurement(MeasurementType::fromString($type), $measurement['value'], MeasurementUnit::fromSymbol($measurement['unit'])), $source, $metadata);
        }
        $step = $analysis->push($point->latitude, $point->longitude, self::microseconds(self::instant($point)), $point->segmentIndex, $point->measurements['speed']['value'] ?? null);
        $derivedMetadata = new GpxTrackAnalysisMetadata($point->pathIndex, $point->segmentIndex, $point->pointIndex);
        if (!isset($point->measurements['speed']) && null !== $step->speedMetresPerSecond) {
            $readings[] = MeasurementReading::derived(new ScalarMeasurement(MeasurementType::fromString('speed'), $step->speedMetresPerSecond, MeasurementUnit::fromSymbol('m/s')), $source, $derivedMetadata);
        }
        if ($step->hasDistance) {
            $readings[] = MeasurementReading::derived(new ScalarMeasurement(MeasurementType::fromString('distance'), $step->distanceMetres, MeasurementUnit::fromSymbol('m')), $source, $derivedMetadata);
        }
        // Repeat the structural index on every point to preserve boundaries across paging and equal times.
        $readings[] = MeasurementReading::derived(new ScalarMeasurement(MeasurementType::fromString('track_segment_index'), $point->segmentIndex, MeasurementUnit::none()), $source, $metadata);

        return ActivityObservation::fromReadings(self::instant($point), ...$readings);
    }

    public static function microseconds(Instant $time): int
    {
        $date = $time->toDateTimeImmutable();

        return (int) $date->format('U') * 1_000_000 + (int) $date->format('u');
    }

    public static function instant(GpxPoint $point): Instant
    {
        $value = $point->time;
        if (null === $value || 1 !== preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $value)) {
            throw new UnsupportedGpxActivity('Every GPX track point requires an absolute timestamp with a timezone and at most microsecond precision.');
        }
        if (!str_ends_with($value, 'Z')) {
            $hours = (int) substr($value, -5, 2);
            $minutes = (int) substr($value, -2);
            if ($hours > 14 || $minutes > 59 || (14 === $hours && 0 !== $minutes)) {
                throw new UnsupportedGpxActivity('Invalid GPX timezone offset.');
            }
        }
        try {
            $date = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            if (false !== $errors && (0 !== $errors['warning_count'] || 0 !== $errors['error_count'])) {
                throw new \Exception();
            }

            return Instant::fromDateTimeImmutable($date);
        } catch (\Exception $failure) {
            throw new UnsupportedGpxActivity('Invalid GPX track point time.', previous: $failure);
        }
    }
}
