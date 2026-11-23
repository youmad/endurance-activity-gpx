<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityGpx\Import;

use Youmad\Endurance\Activity\Telemetry\MeasurementMetadata;
use Youmad\Endurance\ActivityAnalysis\TrackAnalyzer;

final readonly class GpxTrackAnalysisMetadata implements MeasurementMetadata
{
    public function __construct(public int $track, public int $segment, public int $point)
    {
    }

    public function metadataType(): string
    {
        return 'gpx.track-analysis';
    }

    public function metadataVersion(): int
    {
        return 1;
    }

    public function metadataData(): array
    {
        return ['track' => $this->track, 'segment' => $this->segment, 'point' => $this->point, 'algorithm' => TrackAnalyzer::VERSION];
    }
}
