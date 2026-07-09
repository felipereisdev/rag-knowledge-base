<?php

namespace App\Martis\Actions;

/**
 * Inline (per-row) variant of {@see ApproveEntries}.
 *
 * Martis serialises every action into the resource-index blob uncontextualised;
 * the SPA then splits them client-side: the bulk "Actions" dropdown is built
 * from `showOnIndex && !showInline`, while the per-row buttons are built from
 * `showInline`. A single action is therefore EITHER a dropdown item OR an
 * inline button, never both. To offer both surfaces we register the approve
 * behaviour twice under distinct uriKeys — this subclass (uriKey
 * `approve-entry-inline`) supplies the inline ✓ button and inherits the exact
 * `handle()` logic (status → "approved") from its parent.
 */
class ApproveEntryInline extends ApproveEntries
{
    public ?string $name = 'Approve';
}
