# Endurance Activity GPX

Import one recorded GPX track through Endurance Activity's staged writer.

```bash
composer require youmad/endurance-activity-gpx
```

Supply an Activity stream importer and an application callback that creates the
Activity with the validated start time:

```php
use Youmad\Endurance\ActivityGpx\Import\ImportGpxActivity;

$result = new ImportGpxActivity($activityStreamImporter)->import(
    activityId: $activityId,
    idempotencyKey: $stableRetryKey,
    source: $stream,
    onPrepared: fn ($start) => $ensureActivityStarted->handle($activityId, $start),
);
```

- Exactly one nonempty track is required. Every point needs a zoned absolute
  timestamp with at most six fractional digits, ordered across all segments.
  Multiple tracks and route-only/untimed inputs raise `UnsupportedGpxActivity`.
- The caller owns the input. A private disk spool validates the whole document
  before `onPrepared` and generation acquisition. Memory is bounded; temporary
  disk use grows with input. `onProgress` supports cancellation and lease checks.
- `onImported` runs inside activation. Retries use the same Activity/idempotency
  key; a completed import does not publish or invoke that callback again.
- Reports distinguish reported readings from derived GPS estimates. Segments
  preserve gaps; they do not imply pauses. No sessions, laps or timer events are
  invented, and elapsed time is not measured timer time.

For an already selected track, `PrepareGpxTrackActivity::prepare()` accepts a
completed GPX read and a repeatable point factory. Keep that source unchanged
until the prepared items have been fully consumed.

Types in `Internal` and elements marked `@internal` are not public API.

License: [MPL-2.0](LICENSE).
