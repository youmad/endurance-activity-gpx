<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityGpx\Import;

use Youmad\Endurance\Activity\Application\Import\ActivityImportWarning;
use Youmad\Endurance\Activity\ValueObject\ActivityId;

final readonly class GpxActivityImportReport
{
    /**
     * @param list<ActivityImportWarning> $warnings
     * @param array<string, mixed>        $analysis
     */
    public function __construct(
        public ActivityId $activityId,
        public string $version,
        public int $pointCount,
        public int $segmentCount,
        public ?string $name,
        public int $ignoredRoutes = 0,
        public int $ignoredWaypoints = 0,
        public array $warnings = [],
        public array $analysis = [],
    ) {
    }
}
