<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION knowledge_entries_search_vector_update() RETURNS trigger AS $$
            DECLARE
                search_config regconfig;
            BEGIN
                SELECT CASE
                    WHEN replace(lower(coalesce(projects.language, '')), '_', '-') = 'pt'
                        OR replace(lower(coalesce(projects.language, '')), '_', '-') LIKE 'pt-%'
                        THEN 'portuguese'::regconfig
                    WHEN replace(lower(coalesce(projects.language, '')), '_', '-') = 'es'
                        OR replace(lower(coalesce(projects.language, '')), '_', '-') LIKE 'es-%'
                        THEN 'spanish'::regconfig
                    ELSE 'english'::regconfig
                END
                INTO search_config
                FROM projects
                WHERE projects.id = NEW.project_id;

                NEW.search_vector :=
                    to_tsvector(coalesce(search_config, 'english'::regconfig),
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
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement('UPDATE knowledge_entries SET search_vector = search_vector');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION projects_language_search_vector_update() RETURNS trigger AS $$
            BEGIN
                UPDATE knowledge_entries
                SET search_vector = knowledge_entries.search_vector
                WHERE project_id = NEW.id;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement('DROP TRIGGER IF EXISTS projects_language_search_vector_trigger ON projects');
        DB::statement(<<<'SQL'
            CREATE TRIGGER projects_language_search_vector_trigger
            AFTER UPDATE OF language ON projects
            FOR EACH ROW
            WHEN (OLD.language IS DISTINCT FROM NEW.language)
            EXECUTE FUNCTION projects_language_search_vector_update()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS projects_language_search_vector_trigger ON projects');
        DB::statement('DROP FUNCTION IF EXISTS projects_language_search_vector_update');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION knowledge_entries_search_vector_update() RETURNS trigger AS $$
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
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement('UPDATE knowledge_entries SET search_vector = search_vector');
    }
};
