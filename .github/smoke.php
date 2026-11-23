<?php

declare(strict_types=1);

/** @param Closure(bool): void $ensure */
return static function (Closure $ensure, string $autoload, string $package): void {
    $stream = fopen('php://temp', 'w+b');
    try {
        fwrite($stream, '<gpx version="1.1" creator="consumer" xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg><trkpt lat="1" lon="2"><time>2026-11-06T00:00:00Z</time></trkpt><trkpt lat="1" lon="2.0001"><time>2026-11-06T00:00:02Z</time></trkpt></trkseg></trk></gpx>');
        rewind($stream);
        $reader = new Endurance\Gpx\Reader();
        $objects = $reader->objects($stream);
        $tracks = iterator_to_array($objects, false);
        $points = static function () use ($reader, $stream): iterable {
            rewind($stream);

            return $reader->points($stream);
        };
        $prepared = new Endurance\ActivityGpx\Import\PrepareGpxTrackActivity()->prepare(Endurance\Activity\ValueObject\ActivityId::generate(), $tracks[0], $objects->getReturn(), $points);
        $items = iterator_to_array($prepared->items(), false);
        $observations = array_filter($items, static fn ($item): bool => $item instanceof Endurance\Activity\Application\Import\ObservationItem);
        $ensure(2 === count($observations) && $prepared->report->analysis['distance']['value'] > 0);
    } finally {
        fclose($stream);
    }
};
