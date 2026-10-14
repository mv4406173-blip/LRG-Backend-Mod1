<?php

namespace App\Services;

class LrgSchemaNormalizer
{
    public const FORMAT_NAME = 'LRG-Schema';

    public const FORMAT_VERSION = '1.0';

    public const MAX_STATEMENTS = 2000;

    public const MAX_TABLES = 200;

    public const MAX_COLUMNS_PER_TABLE = 300;

    /**
     * Parse SQL text into an LRG-Schema document. Never executes SQL.
     *
     * @return array{document: array, diagnostics: array<int, array>}
     */
    public function normalize(string $sql, string $dialect, string $sourceSha256): array
    {
        $tables = [];
        $relationships = [];
        $diagnostics = [];

        $statements = $this->splitStatements($sql);

        if (count($statements) > self::MAX_STATEMENTS) {
            $diagnostics[] = [
                'severity' => 'error',
                'code' => 'TOO_COMPLEX',
                'message' => 'The SQL file exceeds the maximum of '.self::MAX_STATEMENTS.' statements.',
                'source_location' => null,
            ];

            return $this->result($dialect, $sourceSha256, [], [], $diagnostics);
        }

        foreach ($statements as $index => $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') {
                continue;
            }

            if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE|GRANT|REVOKE|CREATE\s+USER|DROP\s+USER)\b/i', $stmt)) {
                $diagnostics[] = [
                    'severity' => 'info',
                    'code' => 'NON_SCHEMA_STATEMENT_IGNORED',
                    'message' => 'A non-schema statement was ignored and never executed.',
                    'source_location' => ['statement' => $index + 1],
                ];

                continue;
            }

            if (stripos($stmt, 'CREATE TABLE') === 0) {
                $table = $this->parseCreateTable($stmt, $diagnostics);
                if ($table) {
                    $tables[] = $table;
                }

                continue;
            }

            if (stripos($stmt, 'ALTER TABLE') === 0) {
                foreach ($this->parseAlterTableForeignKeys($stmt) as $rel) {
                    $relationships[] = $rel;
                }
            }
        }

        if (count($tables) > self::MAX_TABLES) {
            $diagnostics[] = [
                'severity' => 'error',
                'code' => 'TOO_MANY_TABLES',
                'message' => 'The SQL file exceeds the maximum of '.self::MAX_TABLES.' tables.',
                'source_location' => null,
            ];

            return $this->result($dialect, $sourceSha256, [], [], $diagnostics);
        }

        foreach ($tables as $table) {
            foreach ($table['constraints'] ?? [] as $constraint) {
                if (($constraint['type'] ?? null) === 'FOREIGN KEY') {
                    $relationships[] = [
                        'from_table' => $table['name'],
                        'from_column' => $constraint['columns'][0] ?? null,
                        'to_table' => $constraint['references']['table'] ?? null,
                        'to_column' => $constraint['references']['columns'][0] ?? null,
                        'on_delete' => $constraint['on_delete'] ?? null,
                        'on_update' => $constraint['on_update'] ?? null,
                    ];
                }
            }
        }

        $relationships = array_values(array_unique($relationships, SORT_REGULAR));

        if ($tables === []) {
            $diagnostics[] = [
                'severity' => 'error',
                'code' => 'NO_TABLES_FOUND',
                'message' => 'No CREATE TABLE statements were found in the provided SQL.',
                'source_location' => null,
            ];
        }

        $document = $this->result($dialect, $sourceSha256, $tables, $relationships, $diagnostics);

        foreach ($this->validateDocument($document['document']) as $issue) {
            $document['diagnostics'][] = $issue;
            $document['document']['diagnostics'][] = $issue;
        }

        return $document;
    }

    public function hasBlockingErrors(array $diagnostics): bool
    {
        foreach ($diagnostics as $diagnostic) {
            if (in_array($diagnostic['severity'] ?? '', ['error', 'critical'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{document: array, diagnostics: array<int, array>}
     */
    private function result(string $dialect, string $sourceSha256, array $tables, array $relationships, array $diagnostics): array
    {
        $document = [
            'format' => self::FORMAT_NAME,
            'version' => self::FORMAT_VERSION,
            'source' => [
                'dialect' => $dialect,
                'sha256' => $sourceSha256,
            ],
            'tables' => $tables,
            'relationships' => $relationships,
            'diagnostics' => $diagnostics,
        ];

        return [
            'document' => $document,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @return array<int, array{severity: string, code: string, message: string, source_location: mixed}>
     */
    private function validateDocument(array $document): array
    {
        $issues = [];

        if (($document['format'] ?? null) !== self::FORMAT_NAME) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'INVALID_ARTIFACT_FORMAT',
                'message' => 'The normalized document does not use the LRG-Schema format.',
                'source_location' => null,
            ];
        }

        if (($document['version'] ?? null) !== self::FORMAT_VERSION) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'INVALID_ARTIFACT_VERSION',
                'message' => 'The normalized document version is not supported.',
                'source_location' => null,
            ];
        }

        if (! is_array($document['tables'] ?? null) || ! is_array($document['relationships'] ?? null)) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'INVALID_ARTIFACT_STRUCTURE',
                'message' => 'The normalized document is missing required collections.',
                'source_location' => null,
            ];
        }

        foreach ($document['tables'] ?? [] as $table) {
            if (! is_string($table['name'] ?? null) || $table['name'] === '') {
                $issues[] = [
                    'severity' => 'error',
                    'code' => 'INVALID_TABLE',
                    'message' => 'A table is missing a valid name.',
                    'source_location' => null,
                ];
            }

            if (! is_array($table['columns'] ?? null) || $table['columns'] === []) {
                $issues[] = [
                    'severity' => 'error',
                    'code' => 'TABLE_WITHOUT_COLUMNS',
                    'message' => 'Table '.($table['name'] ?? '(unknown)').' has no columns.',
                    'source_location' => ['table' => $table['name'] ?? null],
                ];
            }
        }

        return $issues;
    }

    private function splitStatements(string $sql): array
    {
        return $this->splitSqlByDelimiter($sql, ';', true);
    }

    private function splitSqlByDelimiter(string $sql, string $delimiter, bool $stripComments): array
    {
        $parts = [];
        $current = '';
        $quote = null;
        $depth = 0;
        $lineComment = false;
        $blockComment = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $character = $sql[$i];
            $next = $sql[$i + 1] ?? null;

            if ($lineComment) {
                if ($character === "\n") {
                    $lineComment = false;
                    $current .= "\n";
                }

                continue;
            }

            if ($blockComment) {
                if ($character === '*' && $next === '/') {
                    $blockComment = false;
                    $i++;
                    $current .= ' ';
                }

                continue;
            }

            if ($quote !== null) {
                $current .= $character;

                if ($character === '\\' && $next !== null) {
                    $current .= $next;
                    $i++;

                    continue;
                }

                if ($character === $quote) {
                    if ($next === $quote) {
                        $current .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }

            if ($stripComments && $character === '/' && $next === '*') {
                $blockComment = true;
                $i++;
                $current .= ' ';

                continue;
            }

            if ($stripComments && $character === '#') {
                $lineComment = true;

                continue;
            }

            if ($stripComments
                && $character === '-'
                && $next === '-'
                && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) {
                $lineComment = true;
                $i++;

                continue;
            }

            if (in_array($character, ["'", '"', '`'], true)) {
                $quote = $character;
                $current .= $character;

                continue;
            }

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth = max(0, $depth - 1);
            }

            if ($character === $delimiter && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $character;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    private function parseCreateTable(string $stmt, array &$diagnostics): ?array
    {
        if (! preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?\s*\(/is', $stmt, $m)) {
            $diagnostics[] = [
                'severity' => 'warning',
                'code' => 'UNPARSEABLE_CREATE_TABLE',
                'message' => 'Could not extract a table name from the CREATE TABLE statement.',
                'source_location' => null,
            ];

            return null;
        }

        $tableName = $m[1];
        $bodyStart = strpos($stmt, '(');
        $bodyEnd = strrpos($stmt, ')');

        if ($bodyStart === false || $bodyEnd === false) {
            $diagnostics[] = [
                'severity' => 'error',
                'code' => 'MALFORMED_CREATE_TABLE',
                'message' => "Could not parse the body of table {$tableName}.",
                'source_location' => ['table' => $tableName],
            ];

            return null;
        }

        $body = substr($stmt, $bodyStart + 1, $bodyEnd - $bodyStart - 1);
        $columns = [];
        $constraints = [];

        foreach ($this->splitColumnDefs($body) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $upper = strtoupper($line);

            if (preg_match('/^(?:CONSTRAINT\s+[`"]?\w+[`"]?\s+)?PRIMARY\s+KEY\b/i', $line)) {
                $constraints[] = $this->parsePrimaryKeyConstraint($line);
            } elseif (preg_match('/^(?:CONSTRAINT\s+[`"]?\w+[`"]?\s+)?UNIQUE\b/i', $line)) {
                $constraints[] = $this->parseUniqueConstraint($line);
            } elseif (str_starts_with($upper, 'FOREIGN KEY') || preg_match('/^CONSTRAINT\b/i', $line)) {
                $fk = $this->parseForeignKeyConstraint($line);
                if ($fk) {
                    $constraints[] = $fk;
                } elseif ($check = $this->parseCheckConstraint($line)) {
                    $constraints[] = $check;
                }
            } elseif (str_starts_with($upper, 'CHECK')) {
                $constraints[] = $this->parseCheckConstraint($line);
            } elseif (str_starts_with($upper, 'KEY') || str_starts_with($upper, 'INDEX')) {
                $constraints[] = $this->parseIndexConstraint($line);
            } else {
                $col = $this->parseColumnDef($line, $diagnostics);
                if ($col) {
                    $columns[] = $col;
                }
            }
        }

        if (count($columns) > self::MAX_COLUMNS_PER_TABLE) {
            $diagnostics[] = [
                'severity' => 'error',
                'code' => 'TOO_MANY_COLUMNS',
                'message' => "Table {$tableName} exceeds the maximum of ".self::MAX_COLUMNS_PER_TABLE.' columns.',
                'source_location' => ['table' => $tableName],
            ];
        }

        return [
            'name' => $tableName,
            'columns' => $columns,
            'constraints' => $constraints,
        ];
    }

    private function splitColumnDefs(string $body): array
    {
        return $this->splitSqlByDelimiter($body, ',', false);
    }

    private function parseColumnDef(string $line, array &$diagnostics): ?array
    {
        if (! preg_match('/^[`"]?(\w+)[`"]?\s+(\w+(?:\s*\([^)]+\))?(?:\s+UNSIGNED)?)/i', $line, $m)) {
            $diagnostics[] = [
                'severity' => 'info',
                'code' => 'UNRECOGNIZED_COLUMN_DEF',
                'message' => 'A column definition could not be parsed; review its syntax.',
                'source_location' => null,
            ];

            return null;
        }

        $default = null;
        if (preg_match('/DEFAULT\s+(\'[^\']*\'|\d+\.\d+|\d+|NULL|CURRENT_TIMESTAMP(?:\(\))?)/i', $line, $dm)) {
            $default = trim($dm[1], "'");
        }

        return [
            'name' => $m[1],
            'type' => strtoupper(trim($m[2])),
            'nullable' => stripos($line, 'NOT NULL') === false && stripos($line, 'PRIMARY KEY') === false,
            'default' => $default,
            'auto_increment' => stripos($line, 'AUTO_INCREMENT') !== false,
            'primary_key' => preg_match('/\bPRIMARY\s+KEY\b/i', $line) === 1,
            'unique' => preg_match('/\bUNIQUE\b/i', $line) === 1,
        ];
    }

    private function parsePrimaryKeyConstraint(string $line): array
    {
        preg_match('/PRIMARY\s+KEY\s*\(([^)]+)\)/i', $line, $m);
        $columns = isset($m[1])
            ? array_map(fn ($column) => trim($column, '`" '), explode(',', $m[1]))
            : [];

        return ['type' => 'PRIMARY KEY', 'columns' => $columns];
    }

    private function parseUniqueConstraint(string $line): array
    {
        preg_match(
            '/(?:CONSTRAINT\s+[`"]?(\w+)[`"]?\s+)?UNIQUE\s+(?:(?:KEY|INDEX)\s+[`"]?(\w*)[`"]?\s*)?\(([^)]+)\)/i',
            $line,
            $m
        );
        $cols = isset($m[3]) ? array_map(fn ($c) => trim($c, '`" '), explode(',', $m[3])) : [];

        return ['type' => 'UNIQUE', 'name' => $m[1] ?? $m[2] ?? null, 'columns' => $cols];
    }

    private function parseCheckConstraint(string $line): ?array
    {
        if (! preg_match('/^(?:CONSTRAINT\s+[`"]?(\w+)[`"]?\s+)?CHECK\s*\(/i', $line, $m)) {
            return null;
        }

        return [
            'type' => 'CHECK',
            'name' => $m[1] ?? null,
            'expression' => trim(preg_replace('/^(?:CONSTRAINT\s+[`"]?\w+[`"]?\s+)?CHECK\s*/i', '', $line) ?? $line),
        ];
    }

    private function parseForeignKeyConstraint(string $line): ?array
    {
        if (! preg_match(
            '/(?:CONSTRAINT\s+[`"]?(\w+)[`"]?\s+)?FOREIGN\s+KEY\s*\(([^)]+)\)\s*REFERENCES\s+[`"]?(\w+)[`"]?\s*\(([^)]+)\)(?:\s+ON\s+DELETE\s+(\w+(?:\s+\w+)?))?(?:\s+ON\s+UPDATE\s+(\w+(?:\s+\w+)?))?/i',
            $line,
            $m
        )) {
            return null;
        }

        return [
            'type' => 'FOREIGN KEY',
            'name' => $m[1] ?? null,
            'columns' => array_map(fn ($c) => trim($c, '`" '), explode(',', $m[2])),
            'references' => [
                'table' => $m[3],
                'columns' => array_map(fn ($c) => trim($c, '`" '), explode(',', $m[4])),
            ],
            'on_delete' => isset($m[5]) && $m[5] !== '' ? strtoupper($m[5]) : null,
            'on_update' => isset($m[6]) && $m[6] !== '' ? strtoupper($m[6]) : null,
        ];
    }

    private function parseIndexConstraint(string $line): array
    {
        preg_match('/(?:KEY|INDEX)\s+[`"]?(\w+)[`"]?\s*\(([^)]+)\)/i', $line, $m);
        $cols = isset($m[2]) ? array_map(fn ($c) => trim($c, '`" '), explode(',', $m[2])) : [];

        return ['type' => 'INDEX', 'name' => $m[1] ?? null, 'columns' => $cols];
    }

    private function parseAlterTableForeignKeys(string $stmt): array
    {
        $rels = [];
        if (! preg_match('/ALTER\s+TABLE\s+[`"]?(\w+)[`"]?/i', $stmt, $tm)) {
            return $rels;
        }

        preg_match_all(
            '/FOREIGN\s+KEY\s*\(([^)]+)\)\s*REFERENCES\s+[`"]?(\w+)[`"]?\s*\(([^)]+)\)/i',
            $stmt,
            $ms,
            PREG_SET_ORDER
        );

        foreach ($ms as $m) {
            $fromCols = array_map(fn ($c) => trim($c, '`" '), explode(',', $m[1]));
            $refCols = array_map(fn ($c) => trim($c, '`" '), explode(',', $m[3]));
            $rels[] = [
                'from_table' => $tm[1],
                'from_column' => $fromCols[0] ?? null,
                'to_table' => $m[2],
                'to_column' => $refCols[0] ?? null,
                'on_delete' => null,
                'on_update' => null,
            ];
        }

        return $rels;
    }
}
