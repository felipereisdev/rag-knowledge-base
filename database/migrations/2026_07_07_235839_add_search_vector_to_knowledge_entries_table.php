<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add a regular tsvector column (not generated, because Postgres prohibits
        // subqueries in generated column expressions — we need tags from a join).
        DB::statement("ALTER TABLE knowledge_entries ADD COLUMN search_vector tsvector");

        // GIN index for fast FTS queries
        DB::statement("CREATE INDEX idx_entries_search ON knowledge_entries USING gin(search_vector)");

        // Function to compute search_vector from title + content + tag names
        DB::statement("
            CREATE OR REPLACE FUNCTION knowledge_entries_search_vector_update() RETURNS trigger AS \$\$
            BEGIN
                NEW.search_vector :=
                    to_tsvector('english',
                        coalesce(NEW.title, '') || ' ' ||
                        coalesce(NEW.content, '') || ' ' ||
                        coalesce(
                            (SELECT string_agg(t.name, ' ')
                             FROM tags t
                             JOIN entry_tags et ON et.tag_id = t.id
                             WHERE et.entry_id = NEW.id),
                        ''));
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql
        ");

        // Trigger fires on INSERT and UPDATE of knowledge_entries
        DB::statement("
            CREATE TRIGGER knowledge_entries_search_vector_trigger
            BEFORE INSERT OR UPDATE ON knowledge_entries
            FOR EACH ROW EXECUTE FUNCTION knowledge_entries_search_vector_update()
        ");

        // Also update search_vector when tags change (entry_tags INSERT/DELETE/UPDATE)
        DB::statement("
            CREATE OR REPLACE FUNCTION entry_tags_search_vector_update() RETURNS trigger AS \$\$
            BEGIN
                IF (TG_OP = 'DELETE') THEN
                    UPDATE knowledge_entries SET search_vector = knowledge_entries.search_vector
                    WHERE id = OLD.entry_id;
                    RETURN OLD;
                ELSIF (TG_OP = 'INSERT' OR TG_OP = 'UPDATE') THEN
                    UPDATE knowledge_entries SET search_vector = knowledge_entries.search_vector
                    WHERE id = NEW.entry_id;
                    RETURN NEW;
                END IF;
                RETURN NULL;
            END;
            \$\$ LANGUAGE plpgsql
        ");

        DB::statement("
            CREATE TRIGGER entry_tags_search_vector_trigger
            AFTER INSERT OR UPDATE OR DELETE ON entry_tags
            FOR EACH ROW EXECUTE FUNCTION entry_tags_search_vector_update()
        ");

        // Backfill existing rows (none yet, but safe to run)
        DB::statement("UPDATE knowledge_entries SET search_vector = search_vector");
    }

    public function down(): void
    {
        DB::statement("DROP TRIGGER IF EXISTS entry_tags_search_vector_trigger ON entry_tags");
        DB::statement("DROP FUNCTION IF EXISTS entry_tags_search_vector_update");
        DB::statement("DROP TRIGGER IF EXISTS knowledge_entries_search_vector_trigger ON knowledge_entries");
        DB::statement("DROP FUNCTION IF EXISTS knowledge_entries_search_vector_update");
        DB::statement("DROP INDEX IF EXISTS idx_entries_search");
        DB::statement("ALTER TABLE knowledge_entries DROP COLUMN IF EXISTS search_vector");
    }
};
