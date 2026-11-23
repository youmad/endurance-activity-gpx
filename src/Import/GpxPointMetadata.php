<?php

declare(strict_types=1);

namespace Endurance\ActivityGpx\Import;

use Endurance\Activity\Telemetry\MeasurementMetadata;

final readonly class GpxPointMetadata implements MeasurementMetadata
{
    public function __construct(public int $track, public int $segment, public int $point)
    {
    }

    public function metadataType(): string
    {
        return 'gpx.point';
    }

    public function metadataVersion(): int
    {
        return 1;
    }

    public function metadataData(): array
    {
        return ['track' => $this->track, 'segment' => $this->segment, 'point' => $this->point];
    }
}
