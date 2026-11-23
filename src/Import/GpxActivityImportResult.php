<?php

declare(strict_types=1);

namespace Endurance\ActivityGpx\Import;

use Endurance\Activity\Application\Import\ActivityImportResult;

final readonly class GpxActivityImportResult
{
    public function __construct(public ActivityImportResult $activityImport, public ?GpxActivityImportReport $report)
    {
    }

    public function isImported(): bool
    {
        return $this->activityImport->isImported();
    }
}
