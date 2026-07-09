<?php

namespace App\Enums;

/**
 * The technologies a project can be tagged with — programming languages and
 * databases — stored in `projects.project_type` (a JSON-array MultiSelect).
 *
 * Single source of truth for the ProjectResource MultiSelect options and the
 * rag_update_project MCP tool validation. The catalog is grouped so the admin
 * dropdown renders "Languages" and "Databases" sections.
 *
 * Not a PHP enum on purpose: with ~100 entries an enum would force each value
 * to be declared twice (case + label/group). The grouped catalog keeps a
 * single source of truth.
 */
final class ProjectTechnology
{
    /**
     * group => [ value => label ]. Values are lowercase, stable identifiers.
     *
     * @return array<string, array<string, string>>
     */
    public static function catalog(): array
    {
        return [
            'Languages' => [
                'python' => 'Python',
                'javascript' => 'JavaScript',
                'typescript' => 'TypeScript',
                'java' => 'Java',
                'csharp' => 'C#',
                'cpp' => 'C++',
                'c' => 'C',
                'go' => 'Go',
                'rust' => 'Rust',
                'ruby' => 'Ruby',
                'php' => 'PHP',
                'swift' => 'Swift',
                'kotlin' => 'Kotlin',
                'scala' => 'Scala',
                'dart' => 'Dart',
                'elixir' => 'Elixir',
                'erlang' => 'Erlang',
                'haskell' => 'Haskell',
                'clojure' => 'Clojure',
                'fsharp' => 'F#',
                'objective-c' => 'Objective-C',
                'perl' => 'Perl',
                'lua' => 'Lua',
                'r' => 'R',
                'julia' => 'Julia',
                'groovy' => 'Groovy',
                'ocaml' => 'OCaml',
                'nim' => 'Nim',
                'zig' => 'Zig',
                'crystal' => 'Crystal',
                'v' => 'V',
                'solidity' => 'Solidity',
                'matlab' => 'MATLAB',
                'fortran' => 'Fortran',
                'cobol' => 'COBOL',
                'assembly' => 'Assembly',
                'shell' => 'Shell',
                'powershell' => 'PowerShell',
                'sql' => 'SQL',
                'html' => 'HTML',
                'css' => 'CSS',
                'pascal' => 'Pascal',
                'lisp' => 'Lisp',
                'scheme' => 'Scheme',
                'elm' => 'Elm',
                'gleam' => 'Gleam',
                'haxe' => 'Haxe',
                'd' => 'D',
                'ada' => 'Ada',
                'prolog' => 'Prolog',
                'apex' => 'Apex',
                'abap' => 'ABAP',
            ],
            'Databases' => [
                'postgresql' => 'PostgreSQL',
                'mysql' => 'MySQL',
                'mariadb' => 'MariaDB',
                'sqlite' => 'SQLite',
                'oracle' => 'Oracle',
                'sqlserver' => 'SQL Server',
                'db2' => 'Db2',
                'cockroachdb' => 'CockroachDB',
                'tidb' => 'TiDB',
                'singlestore' => 'SingleStore',
                'vertica' => 'Vertica',
                'mongodb' => 'MongoDB',
                'redis' => 'Redis',
                'valkey' => 'Valkey',
                'keydb' => 'KeyDB',
                'memcached' => 'Memcached',
                'cassandra' => 'Cassandra',
                'scylladb' => 'ScyllaDB',
                'hbase' => 'HBase',
                'couchdb' => 'CouchDB',
                'couchbase' => 'Couchbase',
                'dynamodb' => 'DynamoDB',
                'cosmosdb' => 'Cosmos DB',
                'firestore' => 'Firestore',
                'arangodb' => 'ArangoDB',
                'rethinkdb' => 'RethinkDB',
                'ravendb' => 'RavenDB',
                'elasticsearch' => 'Elasticsearch',
                'opensearch' => 'OpenSearch',
                'solr' => 'Solr',
                'meilisearch' => 'Meilisearch',
                'typesense' => 'Typesense',
                'clickhouse' => 'ClickHouse',
                'duckdb' => 'DuckDB',
                'snowflake' => 'Snowflake',
                'bigquery' => 'BigQuery',
                'redshift' => 'Redshift',
                'influxdb' => 'InfluxDB',
                'timescaledb' => 'TimescaleDB',
                'questdb' => 'QuestDB',
                'neo4j' => 'Neo4j',
                'dgraph' => 'Dgraph',
                'pinecone' => 'Pinecone',
                'qdrant' => 'Qdrant',
                'weaviate' => 'Weaviate',
                'milvus' => 'Milvus',
                'chroma' => 'Chroma',
                'pgvector' => 'pgvector',
                'supabase' => 'Supabase',
                'planetscale' => 'PlanetScale',
                'firebird' => 'Firebird',
                'h2' => 'H2',
            ],
            'Other' => [
                'other' => 'Other',
            ],
        ];
    }

    /**
     * Grouped options for a MultiSelect ->options() call.
     *
     * Martis expects the grouped shape `group => [label => value]`, whereas the
     * catalog is stored as the more natural `value => label`. Flip each group's
     * inner map so the field stores the lowercase identifier and displays the
     * proper-cased label.
     *
     * @return array<string, array<string, string>>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::catalog() as $group => $map) {
            $out[$group] = array_flip($map);
        }

        return $out;
    }

    /**
     * Flat value => label map across all groups.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_merge(...array_values(self::catalog()));
    }

    /**
     * All valid value strings.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_keys(self::labels());
    }

    public static function isValid(string $value): bool
    {
        return array_key_exists($value, self::labels());
    }

    /** Human label for a value, or null if unknown. */
    public static function labelFor(string $value): ?string
    {
        return self::labels()[$value] ?? null;
    }
}
