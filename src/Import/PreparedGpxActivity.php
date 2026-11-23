<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityGpx\Import;

use Youmad\Endurance\Activity\Application\Import\ActivityImportItem;
use Youmad\Endurance\Foundation\ValueObject\Instant;

/** Caller owns the unchanged repeatable point source until staging finishes. */
final readonly class PreparedGpxActivity
{
    /** @param \Closure():\Generator<int,ActivityImportItem> $items */
    public function __construct(public Instant $startedAt, public Instant $finishedAt, public GpxActivityImportReport $report, private \Closure $items)
    {
    }

    /** @return \Generator<int,ActivityImportItem> */
    public function items(): \Generator
    {
        return ($this->items)();
    }
}
