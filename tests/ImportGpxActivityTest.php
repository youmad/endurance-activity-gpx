<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityGpx\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityImportGenerationClaim;
use Youmad\Endurance\Activity\Application\Import\ActivityImportItemDispatcher;
use Youmad\Endurance\Activity\Application\Import\Handler\ActivityLifecycleItemHandler;
use Youmad\Endurance\Activity\Application\Import\Handler\ObservationItemHandler;
use Youmad\Endurance\Activity\Application\Port\ActivityImportGenerationRepository;
use Youmad\Endurance\Activity\Application\Port\ActivityRepository;
use Youmad\Endurance\Activity\Application\Port\ActivityTransaction;
use Youmad\Endurance\Activity\Application\Port\StagedActivityObservationWriter;
use Youmad\Endurance\Activity\Application\UseCase\ActivityImportGenerationCoordinator;
use Youmad\Endurance\Activity\Application\UseCase\ApplyActivityLifecycleEvent;
use Youmad\Endurance\Activity\Application\UseCase\ImportActivityStream;
use Youmad\Endurance\Activity\Entity\Activity;
use Youmad\Endurance\Activity\Telemetry\ActivityObservation;
use Youmad\Endurance\Activity\Telemetry\MeasurementOrigin;
use Youmad\Endurance\Activity\ValueObject\ActivityImportGenerationId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportIdempotencyKey;
use Youmad\Endurance\ActivityGpx\Exception\UnsupportedGpxActivity;
use Youmad\Endurance\ActivityGpx\Import\ImportGpxActivity;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final class ImportGpxActivityTest extends TestCase
{
    public function testImportsObservationsAndFinishWithoutInventedSessionsLapsOrTimerEvents(): void
    {
        $activity = Activity::start(Instant::fromDateTimeImmutable(new \DateTimeImmutable('2026-10-14T10:00:00Z')));
        $observations = [];
        $importer = $this->importer($activity, $observations);
        $xml = str_replace('</trk>', self::segment(self::point('2026-10-14T10:10:00Z')).'</trk>', self::track(self::point('2026-10-14T15:00:00+05:00').self::point('2026-10-14T10:00:01Z')));
        $stream = $this->stream(str_replace('<trk>', '<wpt lat="0" lon="0"/><rte/><trk>', $xml));
        $prepared = $finalized = false;
        try {
            $result = $importer->import($activity->id, ActivityImportIdempotencyKey::fromString('gpx-test'), $stream,
                onPrepared: static function (Instant $start) use (&$prepared, $activity): void {
                    self::assertTrue($activity->startedAt->equals($start));
                    $prepared = true;
                },
                onImported: static function () use (&$finalized): void { $finalized = true; });
            self::assertTrue($result->isImported());
            self::assertNotNull($result->report);
            self::assertTrue($prepared && $finalized);
            self::assertSame(3, $result->report->pointCount);
            self::assertSame(2, $result->report->segmentCount);
            self::assertSame('Ride', $result->report->name);
            self::assertSame(1, $result->report->ignoredRoutes);
            self::assertSame(1, $result->report->ignoredWaypoints);
            self::assertSame(0, $activity->sessionCount);
            self::assertNull($activity->timerStartedAt);
            self::assertNull($activity->summaryReportedAt);
            self::assertNull($activity->pausedAt);
            self::assertSame(0, $activity->recordedSessionTimerDuration->toMicroseconds());
            self::assertSame(600_000_000, $activity->elapsedDuration()?->toMicroseconds());
            self::assertCount(3, $observations);
            $marker = $observations[2]->readings()[1];
            self::assertSame('track_segment_index', $marker->measurement->type()->toString());
            self::assertSame(MeasurementOrigin::Derived, $marker->origin);
            self::assertSame(['track' => 1, 'segment' => 2, 'point' => 3], $marker->metadata?->metadataData());
            self::assertSame(0, fseek($stream, 0));
        } finally {
            fclose($stream);
        }
    }

    #[DataProvider('unsupported')]
    public function testRejectsUnsupportedContentBeforeActivityCreationOrGenerationClaim(string $content): void
    {
        $activities = $this->createMock(ActivityRepository::class);
        $activities->expects(self::never())->method('get');
        $generations = $this->createMock(ActivityImportGenerationRepository::class);
        $generations->expects(self::never())->method('claim');
        $transaction = $this->createStub(ActivityTransaction::class);
        $transaction->method('run')->willReturnCallback(static fn (\Closure $operation) => $operation());
        $importer = new ImportGpxActivity(new ImportActivityStream($activities, new ActivityImportGenerationCoordinator($generations, $transaction), new ActivityImportItemDispatcher()));
        $stream = $this->stream($content);
        try {
            $this->expectException(UnsupportedGpxActivity::class);
            $importer->import(\Youmad\Endurance\Activity\ValueObject\ActivityId::generate(), ActivityImportIdempotencyKey::fromString('bad'), $stream, onPrepared: static function (): void { self::fail('Unsupported input reached preparation callback.'); });
        } finally {
            fclose($stream);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unsupported(): iterable
    {
        yield 'no time' => [self::track('<trkpt lat="1" lon="2"/>')];
        yield 'no timezone' => [self::track(self::point('2026-10-14T10:00:00'))];
        yield 'invalid date' => [self::track(self::point('2026-02-30T10:00:00Z'))];
        yield 'invalid timezone' => [self::track(self::point('2026-10-14T10:00:00+05:99'))];
        yield 'backwards' => [self::track(self::point('2026-10-14T10:00:01Z').self::point('2026-10-14T10:00:00Z'))];
        yield 'empty' => [self::track('')];
        yield 'multiple tracks' => [str_replace('</gpx>', '<trk/></gpx>', self::track(self::point('2026-10-14T10:00:00Z')))];
        yield 'route only' => ['<gpx xmlns="http://www.topografix.com/GPX/1/1" version="1.1" creator="x"><rte><rtept lat="1" lon="2"/></rte></gpx>'];
    }

    public function testDuplicateGenerationDoesNotWriteObservationsOrRunCompletion(): void
    {
        $activity = Activity::start(Instant::fromDateTimeImmutable(new \DateTimeImmutable('2026-10-14T10:00:00Z')));
        $observations = [];
        $stream = $this->stream(self::track(self::point('2026-10-14T10:00:00Z')));
        try {
            $result = $this->importer($activity, $observations, true)->import($activity->id, ActivityImportIdempotencyKey::fromString('duplicate'), $stream, onImported: static function (): void { self::fail('Duplicate finalized twice.'); });
            self::assertFalse($result->isImported());
            self::assertSame([], $observations);
            self::assertNull($result->report);
        } finally {
            fclose($stream);
        }
    }

    /** @param list<ActivityObservation> $observations */
    private function importer(Activity $activity, array &$observations, bool $duplicate = false): ImportGpxActivity
    {
        $activities = $this->createMock(ActivityRepository::class);
        if (!$duplicate) {
            $activities->method('get')->willReturn($activity);
            $activities->expects(self::once())->method('save')->with($activity);
        } else {
            $activities->expects(self::never())->method('save');
        }
        $generation = ActivityImportGenerationId::generate();
        $generations = $this->createMock(ActivityImportGenerationRepository::class);
        $generations->method('claim')->willReturn($duplicate ? ActivityImportGenerationClaim::alreadyCompleted($generation) : ActivityImportGenerationClaim::acquired($generation));
        $generations->expects($duplicate ? self::never() : self::once())->method('activate');
        $transaction = $this->createStub(ActivityTransaction::class);
        $transaction->method('run')->willReturnCallback(static fn (\Closure $operation) => $operation());
        $writer = $this->createMock(StagedActivityObservationWriter::class);
        $writer->expects($duplicate ? self::never() : self::exactly(3))->method('append')->willReturnCallback(static function ($generation, $activity, ActivityObservation $observation) use (&$observations): void { $observations[] = $observation; });
        $dispatcher = new ActivityImportItemDispatcher(new ActivityLifecycleItemHandler(new ApplyActivityLifecycleEvent($activities, $transaction)), new ObservationItemHandler($writer));

        return new ImportGpxActivity(new ImportActivityStream($activities, new ActivityImportGenerationCoordinator($generations, $transaction), $dispatcher));
    }

    private static function point(string $time): string
    {
        return '<trkpt lat="56" lon="60"><time>'.$time.'</time></trkpt>';
    }

    private static function track(string $points): string
    {
        return '<gpx xmlns="http://www.topografix.com/GPX/1/1" version="1.1" creator="Test"><trk><name>Ride</name><trkseg>'.$points.'</trkseg></trk></gpx>';
    }

    private static function segment(string $points): string
    {
        return '<trkseg>'.$points.'</trkseg>';
    }

    /** @return resource */
    private function stream(string $xml): mixed
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $xml);
        rewind($stream);

        return $stream;
    }
}
