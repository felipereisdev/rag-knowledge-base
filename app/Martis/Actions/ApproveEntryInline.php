<?php

namespace App\Martis\Actions;

/**
 * Per-row inline variant of ApproveEntries.
 *
 * A single Martis action is inline XOR in the bulk dropdown (the dropdown is
 * built from `showOnIndex && !showInline`). To expose the same operation on
 * BOTH surfaces, register it under two distinct classes/uriKeys: the base
 * `ApproveEntries` (bulk dropdown) and this subclass registered with
 * `->onlyInline()` (per-row ✓ button). `handle()` is inherited unchanged.
 */
class ApproveEntryInline extends ApproveEntries {}
