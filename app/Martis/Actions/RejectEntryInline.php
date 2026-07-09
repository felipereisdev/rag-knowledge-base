<?php

namespace App\Martis\Actions;

/**
 * Inline (per-row) variant of {@see RejectEntries}.
 *
 * See {@see ApproveEntryInline} for why the reject behaviour is registered
 * twice. This subclass (uriKey `reject-entry-inline`) supplies the inline ✗
 * button and inherits the exact `handle()` logic (status → "rejected") from
 * its parent.
 */
class RejectEntryInline extends RejectEntries
{
    public ?string $name = 'Reject';
}
