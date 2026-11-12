<?php

namespace App\Support\Backup;

use Illuminate\Database\Connection;

/**
 * A plain-PHP stand-in for pg_dump, for hosts where pg_dump can't run.
 *
 * Writes the public schema as SQL: sequences, tables with their columns and
 * defaults, every row, sequence positions, then keys, indexes and foreign
 * keys (added after the data so rows load in any order). The result
 * restores into an empty database.
 */
class PhpPostgresDumper
{
    private const ROWS_PER_INSERT = 100;
    private const ROWS_PER_QUERY = 1000;

    public function __construct(private Connection $db) {}

    /** @param callable(string):mixed $write */
    public function dump(callable $write): void
    {
        $tables = array_map(fn ($r) => $r->tablename, $this->db->select(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"
        ));
        $sequences = $this->db->select(
            "SELECT sequencename, last_value FROM pg_sequences WHERE schemaname = 'public' ORDER BY sequencename"
        );

        $write("-- Cherishly database backup (PHP dumper)\n"
            . '-- Made ' . now()->toDateTimeString() . " UTC. Restore into an EMPTY database.\n\n"
            . "SET client_encoding = 'UTF8';\nSET standard_conforming_strings = on;\n\nBEGIN;\n\n");

        foreach ($sequences as $s) {
            $write('CREATE SEQUENCE IF NOT EXISTS ' . $this->id($s->sequencename) . ";\n");
        }
        $write("\n");

        foreach ($tables as $table) {
            $write($this->createTable($table) . "\n");
        }

        foreach ($tables as $table) {
            $this->rows($table, $write);
        }

        foreach ($sequences as $s) {
            if ($s->last_value !== null) {
                $write('SELECT setval(' . $this->str($s->sequencename) . ', ' . (int) $s->last_value . ", true);\n");
            }
        }
        $write("\n");

        // Primary keys, unique and check constraints, then indexes, then foreign keys.
        $constraints = $this->db->select("
            SELECT c.conrelid::regclass::text AS tbl, c.conname, c.contype, pg_get_constraintdef(c.oid) AS def
            FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace
            WHERE n.nspname = 'public' AND c.contype IN ('p', 'u', 'c', 'f')
            ORDER BY CASE c.contype WHEN 'p' THEN 0 WHEN 'u' THEN 1 WHEN 'c' THEN 2 ELSE 3 END, tbl, c.conname
        ");
        $owned = [];
        foreach ($constraints as $c) {
            if ($c->contype === 'f') {
                continue;
            }
            $owned[$c->conname] = true;
            $write('ALTER TABLE ' . $c->tbl . ' ADD CONSTRAINT ' . $this->id($c->conname) . ' ' . $c->def . ";\n");
        }

        foreach ($this->db->select("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' ORDER BY tablename, indexname") as $i) {
            if (!isset($owned[$i->indexname])) { // already made by a key constraint
                $write($i->indexdef . ";\n");
            }
        }

        foreach ($constraints as $c) {
            if ($c->contype === 'f') {
                $write('ALTER TABLE ' . $c->tbl . ' ADD CONSTRAINT ' . $this->id($c->conname) . ' ' . $c->def . ";\n");
            }
        }

        $write("\nCOMMIT;\n");
    }

    private function createTable(string $table): string
    {
        $cols = $this->db->select("
            SELECT a.attname, format_type(a.atttypid, a.atttypmod) AS type, a.attnotnull,
                   pg_get_expr(d.adbin, d.adrelid) AS def
            FROM pg_attribute a
            LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
            WHERE a.attrelid = ?::regclass AND a.attnum > 0 AND NOT a.attisdropped
            ORDER BY a.attnum
        ", ['public.' . $this->id($table)]);

        $lines = array_map(fn ($c) => '    ' . $this->id($c->attname) . ' ' . $c->type
            . ($c->def !== null ? ' DEFAULT ' . $c->def : '')
            . ($c->attnotnull ? ' NOT NULL' : ''), $cols);

        return 'CREATE TABLE ' . $this->id($table) . " (\n" . implode(",\n", $lines) . "\n);\n";
    }

    private function rows(string $table, callable $write): void
    {
        $quoted = $this->id($table);
        $pk = $this->db->selectOne("
            SELECT a.attname FROM pg_index i
            JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0]
            WHERE i.indrelid = ?::regclass AND i.indisprimary
        ", ['public.' . $quoted])?->attname;
        $order = $pk ? ' ORDER BY ' . $this->id($pk) : '';

        for ($offset = 0; ; $offset += self::ROWS_PER_QUERY) {
            $rows = $this->db->select("SELECT * FROM {$quoted}{$order} LIMIT " . self::ROWS_PER_QUERY . " OFFSET {$offset}");
            if (!$rows) {
                break;
            }

            $columns = implode(', ', array_map([$this, 'id'], array_keys((array) $rows[0])));
            foreach (array_chunk($rows, self::ROWS_PER_INSERT) as $chunk) {
                $values = array_map(fn ($r) => '(' . implode(', ', array_map([$this, 'value'], (array) $r)) . ')', $chunk);
                $write("INSERT INTO {$quoted} ({$columns}) VALUES\n" . implode(",\n", $values) . ";\n");
            }

            if (count($rows) < self::ROWS_PER_QUERY) {
                break;
            }
        }
        $write("\n");
    }

    private function value(mixed $v): string
    {
        return match (true) {
            $v === null => 'NULL',
            is_bool($v) => $v ? 'true' : 'false',
            is_int($v), is_float($v) => (string) $v,
            is_resource($v) => "'\\x" . bin2hex((string) stream_get_contents($v)) . "'", // bytea
            default => $this->str((string) $v),
        };
    }

    private function str(string $s): string
    {
        return "'" . str_replace("'", "''", $s) . "'";
    }

    private function id(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
