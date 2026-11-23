<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityGpx\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ObservationItem;
use Youmad\Endurance\Activity\Telemetry\MeasurementOrigin;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\ActivityGpx\Exception\UnsupportedGpxActivity;
use Youmad\Endurance\ActivityGpx\Import\PrepareGpxTrackActivity;
use Youmad\Endurance\Gpx\Model\GpxDocument;
use Youmad\Endurance\Gpx\Model\GpxObject;
use Youmad\Endurance\Gpx\Model\GpxPoint;

final class PrepareGpxTrackActivityTest extends TestCase
{
    private static function point(int $index = 1, ?string $time = '2026-10-17T15:00:00.123456+05:00', int $path = 2, int $segment = 2): GpxPoint
    {
        return new GpxPoint('track', $path, $segment, $index, 'Selected', null, 1.0, 2.0, $time, ['heart_rate' => ['value' => 143.0, 'unit' => 'bpm']]);
    }

    public function testUsesOnlySelectedSummaryAndKeepsOriginalPointProvenanceAndMicroseconds(): void
    {
        $points = [self::point(), self::point(2, '2026-10-17T10:00:01.123456Z', segment: 4)];
        $document = new GpxDocument('1.1', 'test', 'Unrelated document name', 9, 7, 8, [1 => ['length' => 99999], 2 => ['length' => 123.0]]);
        $prepared = new PrepareGpxTrackActivity()->prepare(ActivityId::generate(), new GpxObject('track', 2, 'Selected', null, 2, 4, 2), $document, fn () => $points);
        self::assertSame('Selected', $prepared->report->name);
        self::assertSame(2, $prepared->report->segmentCount);
        self::assertSame(123.0, $prepared->report->analysis['distance']['value']);
        self::assertSame('reported', $prepared->report->analysis['distance']['origin']);
        self::assertSame('123456', $prepared->startedAt->toDateTimeImmutable()->format('u'));
        $observations = array_values(array_filter(iterator_to_array($prepared->items()), fn ($i) => $i instanceof ObservationItem));
        self::assertCount(2, $observations);
        $reading = $observations[0]->observation->readings()[0];
        self::assertSame(MeasurementOrigin::Reported, $reading->origin);
        self::assertSame(['track' => 2, 'segment' => 2, 'point' => 1], $reading->metadata?->metadataData());
        self::assertSame(['track' => 2, 'segment' => 4, 'point' => 2], $observations[1]->observation->readings()[0]->metadata?->metadataData());
    }

    /** @param list<GpxPoint> $points */
    #[DataProvider('badStreams')]
    public function testRejectsUnusableTimeOrMismatchedSelectedInput(array $points): void
    {
        $this->expectException(UnsupportedGpxActivity::class);
        new PrepareGpxTrackActivity()->prepare(ActivityId::generate(), new GpxObject('track', 2, null, null, 2, 4, 2), new GpxDocument('1.1', 'test', null, 2, 0, 0), fn () => $points);
    }

    /** @return iterable<string,array{list<GpxPoint>}> */
    public static function badStreams(): iterable
    {
        yield 'missing time' => [[self::point(time: null)]];
        yield 'raw time' => [[self::point(time: 'raw')]];
        yield 'no timezone' => [[self::point(time: '2026-10-17T10:00:00')]];
        yield 'invalid offset' => [[self::point(time: '2026-10-17T10:00:00+14:01')]];
        yield 'invalid date' => [[self::point(time: '2026-02-30T10:00:00Z')]];
        yield 'too precise' => [[self::point(time: '2026-10-17T10:00:00.1234567Z')]];
        yield 'chronology' => [[self::point(), self::point(2, '2026-10-17T09:00:00Z')]];
        yield 'other track' => [[self::point(path: 1)]];
        yield 'wrong point index' => [[self::point(2)]];
        yield 'wrong segment' => [[self::point(segment: 5)]];
        yield 'incomplete' => [[self::point()]];
    }
}
