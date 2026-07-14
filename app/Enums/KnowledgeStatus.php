<?php

namespace App\Enums;

enum KnowledgeStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Classifying = 'classifying';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases(),
        );
    }

    /**
     * Every status, translated. Read-only surfaces only (filters, index badges):
     * `classifying` belongs here because an administrator must be able to *see*
     * entries in flight, but see {@see self::adminEditableOptions()} for the
     * statuses they may *set*.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(
            self::values(),
            array_map(
                static fn (self $status): string => __('rag.statuses.'.$status->value),
                self::cases(),
            ),
        );
    }

    /**
     * The statuses a human may assign by hand.
     *
     * `classifying` is owned by the classifier pipeline: only
     * `App\Services\KnowledgeWriter` puts an entry there, and only
     * `App\Jobs\ClassifyKnowledgeEntryJob` takes it out. An entry parked
     * in `classifying` from the admin panel has no job to drive it anywhere, is
     * excluded from indexing by
     * `App\Observers\KnowledgeEntryObserver::INDEXED_STATUSES`, and is
     * refused by the approve/reject actions — it would be stuck and invisible
     * forever.
     *
     * @return list<self>
     */
    public static function adminEditable(): array
    {
        $editable = [];

        foreach (self::cases() as $status) {
            if ($status !== self::Classifying) {
                $editable[] = $status;
            }
        }

        return $editable;
    }

    /**
     * @return list<string>
     */
    public static function adminEditableValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::adminEditable(),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function adminEditableOptions(): array
    {
        return array_combine(
            self::adminEditableValues(),
            array_map(
                static fn (self $status): string => __('rag.statuses.'.$status->value),
                self::adminEditable(),
            ),
        );
    }
}
