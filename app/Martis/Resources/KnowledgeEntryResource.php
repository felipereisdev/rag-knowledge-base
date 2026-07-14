<?php

namespace App\Martis\Resources;

use App\Enums\ImportanceClassifierMode;
use App\Enums\ImportanceVerdict;
use App\Enums\KnowledgeCategory;
use App\Enums\KnowledgeSource;
use App\Enums\KnowledgeStatus;
use App\Martis\Actions\ApproveEntries;
use App\Martis\Actions\RejectEntries;
use App\Martis\Filters\AutoApprovedFilter;
use App\Martis\Filters\CategoryFilter;
use App\Martis\Filters\ProjectFilter;
use App\Martis\Filters\StatusFilter;
use App\Models\ImportanceAssessment;
use App\Models\KnowledgeEntry;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Martis\Actions\Action;
use Martis\Contracts\OverrideContract;
use Martis\DrawerOverride;
use Martis\Fields\BelongsTo;
use Martis\Fields\BelongsToMany;
use Martis\Fields\Boolean;
use Martis\Fields\DateTime;
use Martis\Fields\Field;
use Martis\Fields\Id;
use Martis\Fields\KeyValue;
use Martis\Fields\Markdown;
use Martis\Fields\Number;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Filters\DateRangeFilter;
use Martis\Filters\Filter;
use Martis\Layout\Tab;
use Martis\Layout\TabGroup;
use Martis\Resource;

class KnowledgeEntryResource extends Resource
{
    public function overrideCreate(): ?OverrideContract
    {
        return DrawerOverride::create();
    }

    public function overrideUpdate(): ?OverrideContract
    {
        return DrawerOverride::update();
    }

    public function overrideDetail(): ?OverrideContract
    {
        return DrawerOverride::detail();
    }

    public static function model(): string
    {
        return KnowledgeEntry::class;
    }

    /**
     * Show the knowledge title in the detail header and in relationship pickers,
     * instead of the default "Knowledge Entry #id" label.
     */
    public static function titleAttribute(): string
    {
        return 'title';
    }

    /**
     * Blocks the same race the bulk actions block (see
     * `App\Martis\Actions\Concerns\RefusesClassifyingEntries`), but for the row
     * edit form: `classifying` is absent from the settable status options
     * ({@see KnowledgeStatus::adminEditableOptions()}), which stops an admin from
     * *entering* it, but does nothing to stop them *leaving* it — a `PUT` with
     * any other status on a row still `classifying` would pass validation and
     * apply, racing `App\Jobs\ClassifyKnowledgeEntryJob` for the same row.
     *
     * So a `status` key in the payload that disagrees with the row's current
     * `classifying` value is refused outright (403), before validation runs.
     * A payload that never touches `status` — editing the title, say — is
     * unaffected: Martis's `ResourceController::fillFields()` only fills a
     * field the request actually sent, so leaving `status` out already leaves
     * the column untouched.
     */
    public function authorizedToUpdate(Request $request): bool
    {
        if ($this->leavesClassifyingViaStatus($request)) {
            return false;
        }

        return parent::authorizedToUpdate($request);
    }

    private function leavesClassifyingViaStatus(Request $request): bool
    {
        return $this->model instanceof KnowledgeEntry
            && $this->model->getAttribute('status') === KnowledgeStatus::Classifying->value
            && $request->has('status')
            && $request->input('status') !== KnowledgeStatus::Classifying->value;
    }

    /**
     * The status field is normally restricted to the admin-editable subset so
     * a human can never *enter* `classifying` by hand (see the comment above
     * its use in fields()). But the shipped drawer (`DrawerUpdate.tsx:95-108`)
     * seeds form state from the row's raw value and resubmits every scalar
     * field on save (`:197-218`), and `SelectField.tsx` does no on-mount
     * normalisation — so a row already parked in `classifying` needs the
     * field to actually contain that value, or two things break at once: the
     * Select shows no matching option, and the round-tripped
     * `status: "classifying"` gets rejected by `Rule::in(adminEditableValues())`
     * on a save that never touched status at all (previously a 422 on every
     * edit to a `classifying` row).
     *
     * So a `classifying` row gets the full option set (so the true value
     * renders), `->rules()` widened to accept `classifying` unchanged, and
     * `->immutable()` — Martis's `fillFields()` silently skips immutable
     * fields on update (`vendor/martis/martis/src/Http/Controllers/
     * ResourceController.php`, `fillFields()`), so even if this field's value
     * came back changed it would never be written. That's belt-and-braces:
     * self::authorizedToUpdate() already 403s a genuine attempt to move the
     * row to a different status, before validation or filling ever run.
     */
    private function statusField(): Select
    {
        $classifying = $this->model instanceof KnowledgeEntry
            && $this->model->getAttribute('status') === KnowledgeStatus::Classifying->value;

        $field = Select::make('status', __('rag.fields.status'))
            ->default(KnowledgeStatus::Pending->value)
            ->required()
            ->span(4);

        if ($classifying) {
            return $field
                ->optionsFromMap(KnowledgeStatus::options())
                ->rules(['sometimes', Rule::in(KnowledgeStatus::values())])
                ->immutable();
        }

        return $field
            ->optionsFromMap(KnowledgeStatus::adminEditableOptions())
            ->rules(['sometimes', Rule::in(KnowledgeStatus::adminEditableValues())]);
    }

    /**
     * Per-row inline ✓/✗ buttons AND the bulk "Actions" dropdown from a single
     * action each. Martis v1.28.3 made ->showInline() additive (it keeps
     * showOnIndex=true), so one showInline action appears in both surfaces — no
     * need to register the operation twice.
     *
     * NOTE: Martis caches the resource schema (forever TTL); the container
     * entrypoint runs `martis:cache:clear` on boot so changes here take effect.
     *
     * @return array<int, Action>
     */
    public function actions(Request $request): array
    {
        return [
            ApproveEntries::make()
                ->showInline()
                ->icon('check-circle')
                ->iconColor('#16a34a'),
            RejectEntries::make()
                ->showInline()
                ->icon('x-circle')
                ->iconColor('#dc2626'),
        ];
    }

    /**
     * Index filters: narrow entries by project, category, status and creation
     * date. Category/Status options mirror the form field enumerations flipped
     * to the SelectFilter label => value shape; Project lists live projects.
     * "Created Between" is a from/to range (either bound optional).
     *
     * @return array<int, Filter>
     */
    public function filters(Request $request): array
    {
        return [
            ProjectFilter::make(__('rag.filters.project'))
                ->searchable()
                ->placeholder(__('rag.filters.select')),
            CategoryFilter::make(__('rag.filters.category')),
            StatusFilter::make(__('rag.filters.status')),
            AutoApprovedFilter::make(__('importance.filters.auto_approved')),
            DateRangeFilter::make(__('rag.filters.created_between'))
                ->column('created_at'),
        ];
    }

    /**
     * Drawer form (create / update): a 12-column grid, with each field sized
     * using ->span(N). The detail drawer groups these fields separately in
     * fieldsForDetail(), while the index table remains a flat sortable table.
     */
    public function fields(Request $request): array
    {
        return [
            BelongsTo::make('project', __('rag.fields.project'))
                ->searchable()
                ->rules(['required', 'exists:projects,id'])
                ->span(6),
            Select::make('category', __('rag.fields.category'))
                ->optionsFromMap(KnowledgeCategory::options())
                ->default(KnowledgeCategory::Insight->value)
                ->required()
                ->rules(['sometimes', Rule::in(KnowledgeCategory::values())])
                ->span(6),
            Text::make('title', __('rag.fields.title'))
                ->searchable()
                ->required()
                ->rules(['required', 'string', 'max:255'])
                ->span(12),
            Markdown::make('content', __('rag.fields.content'))
                ->alwaysShow()
                ->nullable()
                ->rules(['nullable', 'string'])
                ->fillUsing(static function (KnowledgeEntry $entry, mixed $value, string $attribute): void {
                    $entry->setAttribute($attribute, $value ?? '');
                })
                ->span(12),

            // `classifying` is deliberately absent from the settable options: it
            // is the classifier pipeline's status, not a human's. An entry
            // parked there by hand has no job to move it on, is excluded from
            // indexing by the observer, and is refused by the approve/reject
            // actions — it would be stuck and invisible forever. Filtering by
            // it is still allowed (see StatusFilter); only *setting* it is not.
            // Leaving *out* of classifying through this same field is refused
            // too, but at the request level — see self::authorizedToUpdate().
            //
            // A row already *in* classifying still needs this field to render
            // its true value and to survive a save that never touched status —
            // see self::statusField() for why the option set and rules widen
            // for that one row.
            $this->statusField(),
            // Constrained to the enum the ingestion paths actually write. Left
            // free-text, an administrator could type "mcp" and produce an entry
            // no pipeline owns.
            Select::make('source', __('rag.fields.source'))
                ->optionsFromMap(KnowledgeSource::options())
                ->default(KnowledgeSource::Manual->value)
                ->help(__('rag.fields.source_help'))
                ->nullable()
                ->rules(['nullable', Rule::in(KnowledgeSource::values())])
                ->fillUsing(static function (KnowledgeEntry $entry, mixed $value, string $attribute): void {
                    // Creation from the panel is manual by definition.
                    $entry->setAttribute($attribute, $value ?? KnowledgeSource::Manual->value);
                })
                ->span(4),
            Text::make('author', __('rag.fields.author'))
                ->nullable()
                ->rules(['nullable', 'string', 'max:255'])
                ->fillUsing(static function (KnowledgeEntry $entry, mixed $value, string $attribute): void {
                    $entry->setAttribute($attribute, $value ?? '');
                })
                ->span(4),
            BelongsToMany::make(__('rag.fields.tags'), 'tags', TagResource::class)
                ->searchable()
                ->rules(['sometimes', 'array'])
                ->span(12),
            BelongsToMany::make(__('rag.fields.entities'), 'entities', EntityResource::class)
                ->searchable()
                ->rules(['sometimes', 'array'])
                ->span(12),
            // The KeyValue editor speaks flat `key => string` only. Since the
            // classifier writes a nested `metadata.importance` object, the raw
            // field would stringify it — a fatal "Array to string conversion" on
            // the detail page of every classified entry. So the editor shows the
            // flat, human-owned keys, and machine-owned nested keys are carried
            // through the save untouched instead of being flattened away.
            KeyValue::make('metadata', __('rag.fields.metadata'))
                ->rules(['sometimes', 'array'])
                ->resolveUsing(static fn (mixed $value, KnowledgeEntry $entry): array => self::flatMetadataRows($entry))
                ->fillUsing(static function (KnowledgeEntry $entry, mixed $value, string $attribute): void {
                    $entry->setAttribute($attribute, array_merge(
                        self::submittedMetadata($value),
                        self::machineMetadata($entry),
                    ));
                })
                ->span(12),
        ];
    }

    /**
     * The human-editable half of `metadata`, in the KeyValue row shape.
     *
     * @return list<array{key: string, value: string}>
     */
    private static function flatMetadataRows(KnowledgeEntry $entry): array
    {
        $rows = [];

        foreach ($entry->metadata ?? [] as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $rows[] = ['key' => (string) $key, 'value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value];
        }

        return $rows;
    }

    /**
     * The machine-owned half of `metadata` — the nested objects the classifier
     * and the importers write, which no form may drop.
     *
     * @return array<string, mixed>
     */
    private static function machineMetadata(KnowledgeEntry $entry): array
    {
        return array_filter($entry->metadata ?? [], 'is_array');
    }

    /**
     * KeyValue submits either rows (`[{key, value}]`) or a flat map, depending on
     * the client. Both collapse to a flat map of scalars here.
     *
     * @return array<string, mixed>
     */
    private static function submittedMetadata(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $metadata = [];

        foreach ($value as $key => $row) {
            if (is_array($row) && array_key_exists('key', $row)) {
                $rowKey = (string) $row['key'];

                if ($rowKey !== '') {
                    $metadata[$rowKey] = $row['value'] ?? '';
                }

                continue;
            }

            if (! is_array($row)) {
                $metadata[(string) $key] = $row;
            }
        }

        return $metadata;
    }

    /**
     * Flat columns for the index table.
     */
    public function fieldsForIndex(Request $request): array
    {
        return [
            Id::make('id'),
            BelongsTo::make('project', __('rag.fields.project'))
                ->sortable()
                ->searchable(),
            Text::make('title', __('rag.fields.title'))
                ->sortable()
                ->searchable(),
            Select::make('category', __('rag.fields.category'))
                ->optionsFromMap(KnowledgeCategory::options()),
            Select::make('status', __('rag.fields.status'))
                ->optionsFromMap(KnowledgeStatus::options()),
            DateTime::make('created_at', __('rag.fields.created_at'))
                ->sortable(),
        ];
    }

    /**
     * Detail drawer: five native Martis tabs instead of one long scalar list.
     * The dominant workflow is consulting approved knowledge, so the first tab is
     * the reading surface (status + rendered Markdown) and everything else —
     * context, relationships, the read-only importance audit, metadata — waits
     * behind its own tab.
     *
     * The audit sits *before* the raw metadata tab on purpose: it is the readable
     * rendering of `metadata.importance`, and keeping Metadata last preserves it
     * as the far bound of the flattened field list (see the detail-payload test).
     *
     * Scalar field instances are reused from fields() so validation, options and
     * labels stay in one place. `title` is deliberately absent: titleAttribute()
     * already renders it as the drawer header.
     */
    public function fieldsForDetail(Request $request): array
    {
        $fields = [];

        foreach ($this->fields($request) as $field) {
            if ($field instanceof Field) {
                $fields[$field->attribute()] = $field;
            }
        }

        return [
            TabGroup::make([
                Tab::make(__('rag.detail.content'), [
                    $fields['status'],
                    $fields['content'],
                ]),
                Tab::make(__('rag.detail.context'), [
                    $fields['project_id'],
                    $fields['category'],
                    $fields['source'],
                    $fields['author'],
                    DateTime::make('created_at', __('rag.fields.created_at'))
                        ->onlyOnDetail()
                        ->span(4),
                ]),
                Tab::make(__('rag.detail.relationships'), [
                    $fields['tags'],
                    $fields['entities'],
                ]),
                Tab::make(__('importance.audit.section'), $this->importanceFields()),
                Tab::make(__('rag.detail.metadata'), [
                    $fields['metadata'],
                ]),
            ]),
        ];
    }

    /**
     * Why the classifier decided what it decided — read-only, detail only.
     *
     * Sourced from `metadata.importance` (written by ClassifyKnowledgeEntryJob on
     * every outcome, including a fail-open), falling back to the linked
     * assessment row. An entry that was never classified — anything created here
     * by hand, or captured while the classifier was off — simply resolves every
     * field to null.
     *
     * Audit internals stay out on purpose: the normalized candidate (it embeds
     * the raw entry text and belongs to the cache identity, not to the admin),
     * the candidate hash, the per-criterion sub-scores and the duration. They are
     * on the assessment row for whoever tunes the classifier; they are noise here.
     *
     * @return list<Field>
     */
    private function importanceFields(): array
    {
        return [
            Number::make('importance_score', __('importance.audit.score'))
                ->onlyOnDetail()
                ->resolveUsing(static fn (mixed $value, KnowledgeEntry $entry): ?int => self::importanceInt($entry, 'final_score'))
                ->span(4),

            // Both go through tryFrom: metadata written by an older prompt or rule
            // set may carry a value this build no longer knows, and an audit panel
            // must degrade to blank rather than 500 the whole detail page.
            //
            // The verdict alone is read from the entry's metadata WITHOUT the
            // assessment fallback. An assessment row is shared by every entry with
            // the same cache identity, and the threshold is not part of that
            // identity, so its `verdict` is only the verdict at that row's first
            // computation — showing it here would attribute another entry's
            // decision to this one. No verdict in the metadata means this entry was
            // never decided (it failed open, or predates the classifier), and blank
            // is the honest answer.
            Text::make('importance_verdict', __('importance.audit.verdict'))
                ->onlyOnDetail()
                ->resolveUsing(static function (mixed $value, KnowledgeEntry $entry): ?string {
                    $stored = self::importance($entry)['verdict'] ?? null;
                    $verdict = is_string($stored) ? ImportanceVerdict::tryFrom($stored) : null;

                    return $verdict === null ? null : __('importance.verdicts.'.$verdict->value);
                })
                ->span(4),

            // Reads the ENTRY's own metadata only — no assessment fallback. The
            // assessment row is shared across every entry with the same cache
            // identity, so its notion of "approved" (if it had one) would
            // belong to whichever entry first computed it, not to this one.
            // `self::importance($entry)` is exactly the raw `metadata.importance`
            // map, never touching `$entry->importanceAssessment`, which is what
            // makes this safe. This is the whole point of the audit tab: the
            // one place a human can see what auto-approval let through
            // unreviewed.
            Boolean::make('importance_auto_approved', __('importance.fields.auto_approved'))
                ->onlyOnDetail()
                ->resolveUsing(static fn (mixed $value, KnowledgeEntry $entry): bool => (bool) (self::importance($entry)['auto_approved'] ?? false))
                ->span(4),

            Text::make('importance_mode', __('importance.audit.mode'))
                ->onlyOnDetail()
                ->resolveUsing(static function (mixed $value, KnowledgeEntry $entry): ?string {
                    $mode = ImportanceClassifierMode::tryFrom((string) self::importanceString($entry, 'mode'));

                    return $mode === null ? null : __('importance.modes.'.$mode->value);
                })
                ->span(4),

            Textarea::make('importance_reasons', __('importance.audit.reasons'))
                ->onlyOnDetail()
                ->resolveUsing(static fn (mixed $value, KnowledgeEntry $entry): ?string => self::lines(
                    self::importanceList($entry, 'reasons'),
                    static fn (array $reason): string => __('importance.audit.reason_line', [
                        'criterion' => (string) ($reason['criterion'] ?? ''),
                        'explanation' => (string) ($reason['explanation'] ?? ''),
                    ]),
                ))
                ->span(12),

            Textarea::make('importance_rules', __('importance.audit.rules'))
                ->onlyOnDetail()
                ->resolveUsing(static fn (mixed $value, KnowledgeEntry $entry): ?string => self::lines(
                    self::importanceList($entry, 'rules'),
                    static fn (array $rule): string => __('importance.audit.rule_line', [
                        'id' => (string) ($rule['id'] ?? ''),
                        'adjustment' => sprintf('%+d', (int) ($rule['adjustment'] ?? 0)),
                        'reason' => (string) ($rule['reason'] ?? ''),
                    ]),
                ))
                ->span(12),

            Text::make('importance_model', __('importance.audit.model'))
                ->onlyOnDetail()
                ->resolveUsing(static fn (mixed $value, KnowledgeEntry $entry): ?string => self::importanceString($entry, 'model'))
                ->span(4),

            Text::make('importance_prompt_version', __('importance.audit.prompt_version'))
                ->onlyOnDetail()
                ->resolveUsing(static fn (mixed $value, KnowledgeEntry $entry): ?string => self::importanceString($entry, 'prompt_version'))
                ->span(4),

            Text::make('importance_rules_version', __('importance.audit.rules_version'))
                ->onlyOnDetail()
                ->resolveUsing(static fn (mixed $value, KnowledgeEntry $entry): ?string => self::importanceString($entry, 'rules_version'))
                ->span(4),

            Text::make('importance_cache', __('importance.audit.cache'))
                ->onlyOnDetail()
                ->resolveUsing(static function (mixed $value, KnowledgeEntry $entry): ?string {
                    $cacheHit = self::importance($entry)['cache_hit'] ?? null;

                    return $cacheHit === null
                        ? null
                        : __($cacheHit ? 'importance.audit.cache_hit' : 'importance.audit.cache_miss');
                })
                ->span(4),

            Textarea::make('importance_error', __('importance.audit.error'))
                ->onlyOnDetail()
                ->resolveUsing(static function (mixed $value, KnowledgeEntry $entry): ?string {
                    $error = self::importance($entry)['classification_error'] ?? null;

                    return is_array($error)
                        ? __('importance.audit.error_line', [
                            'code' => (string) ($error['code'] ?? ''),
                            'message' => (string) ($error['message'] ?? ''),
                        ])
                        : null;
                })
                ->span(12),
        ];
    }

    /**
     * `metadata.importance`, or an empty map when the entry was never classified.
     *
     * @return array<string, mixed>
     */
    private static function importance(KnowledgeEntry $entry): array
    {
        $importance = $entry->metadata['importance'] ?? null;

        return is_array($importance) ? $importance : [];
    }

    /**
     * One audit value, preferring the entry's own metadata, then the
     * classification error (a fail-open records the model and the versions it
     * failed under there, and links no assessment), then the linked assessment
     * row.
     *
     * Only for the threshold-independent values. `verdict` must NOT be read
     * through here: the assessment row is shared across entries and its verdict
     * is only the one at first computation (see {@see ImportanceAssessment}).
     */
    private static function importanceValue(KnowledgeEntry $entry, string $key): mixed
    {
        $importance = self::importance($entry);

        if (($importance[$key] ?? null) !== null) {
            return $importance[$key];
        }

        $error = $importance['classification_error'] ?? null;

        if (is_array($error) && ($error[$key] ?? null) !== null) {
            return $error[$key];
        }

        $fallback = $entry->importanceAssessment?->getAttribute($key);

        return $fallback instanceof BackedEnum ? $fallback->value : $fallback;
    }

    private static function importanceInt(KnowledgeEntry $entry, string $key): ?int
    {
        $value = self::importanceValue($entry, $key);

        return is_numeric($value) ? (int) $value : null;
    }

    private static function importanceString(KnowledgeEntry $entry, string $key): ?string
    {
        $value = self::importanceValue($entry, $key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function importanceList(KnowledgeEntry $entry, string $key): array
    {
        $value = self::importanceValue($entry, $key);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  callable(array<string, mixed>): string  $format
     */
    private static function lines(array $items, callable $format): ?string
    {
        if ($items === []) {
            return null;
        }

        return implode("\n", array_map($format, $items));
    }
}
