<?php

namespace App\Martis\Resources;

use App\Enums\ImportanceClassifierMode;
use App\Enums\ImportanceVerdict;
use App\Enums\KnowledgeCategory;
use App\Enums\KnowledgeSource;
use App\Enums\KnowledgeStatus;
use App\Martis\Actions\ApproveEntries;
use App\Martis\Actions\RejectEntries;
use App\Martis\Filters\CategoryFilter;
use App\Martis\Filters\ProjectFilter;
use App\Martis\Filters\StatusFilter;
use App\Models\KnowledgeEntry;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Martis\Actions\Action;
use Martis\Contracts\OverrideContract;
use Martis\DrawerOverride;
use Martis\Fields\BelongsTo;
use Martis\Fields\BelongsToMany;
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
use Martis\Layout\Section;
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

            // `classifying` is deliberately absent: it is the classifier
            // pipeline's status, not a human's. An entry parked there by hand
            // has no job to move it on, is excluded from indexing by the
            // observer, and is refused by the approve/reject actions — it would
            // be stuck and invisible forever. Filtering by it is still allowed
            // (see StatusFilter); only *setting* it is not.
            Select::make('status', __('rag.fields.status'))
                ->optionsFromMap(KnowledgeStatus::adminEditableOptions())
                ->default(KnowledgeStatus::Pending->value)
                ->required()
                ->rules(['sometimes', Rule::in(KnowledgeStatus::adminEditableValues())])
                ->span(4),
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
     * Keep scalar fields in the default drawer layout. Wrap the relationship
     * panels in a headerless section so they render at full width instead of
     * passing through the drawer's scalar label/value grid, and append the
     * read-only importance audit.
     */
    public function fieldsForDetail(Request $request): array
    {
        $detailFields = [];
        $relationshipFields = [];

        foreach ($this->fields($request) as $field) {
            if ($field instanceof Field && in_array($field->attribute(), ['tags', 'entities'], true)) {
                $relationshipFields[] = $field;

                continue;
            }

            $detailFields[] = $field;
        }

        $detailFields[] = Section::make(null, $relationshipFields)->columns(12);
        $detailFields[] = Section::make(__('importance.audit.section'), $this->importanceFields())->columns(12);

        return $detailFields;
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
            Text::make('importance_verdict', __('importance.audit.verdict'))
                ->onlyOnDetail()
                ->resolveUsing(static function (mixed $value, KnowledgeEntry $entry): ?string {
                    $verdict = ImportanceVerdict::tryFrom((string) self::importanceString($entry, 'verdict'));

                    return $verdict === null ? null : __('importance.verdicts.'.$verdict->value);
                })
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
