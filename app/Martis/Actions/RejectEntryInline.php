<?php

namespace App\Martis\Actions;

/**
 * Per-row inline variant of RejectEntries. See ApproveEntryInline for why a
 * separate class/uriKey is needed to show the same action in both the bulk
 * dropdown and as a per-row button. `handle()` is inherited unchanged.
 */
class RejectEntryInline extends RejectEntries {}
