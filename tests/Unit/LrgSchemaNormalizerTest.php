<?php

namespace Tests\Unit;

use App\Services\LrgSchemaNormalizer;
use PHPUnit\Framework\TestCase;

class LrgSchemaNormalizerTest extends TestCase
{
    public function test_it_extracts_tables_and_foreign_keys_without_executing_sql(): void
    {
        $sql = <<<'SQL'
CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    PRIMARY KEY (id)
);
CREATE TABLE reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
INSERT INTO users (email) VALUES ('x@y.z');
SQL;

        $result = (new LrgSchemaNormalizer)->normalize($sql, 'mysql', hash('sha256', $sql));

        $this->assertSame('LRG-Schema', $result['document']['format']);
        $this->assertCount(2, $result['document']['tables']);
        $this->assertSame('reservations', $result['document']['relationships'][0]['from_table']);
        $this->assertFalse((new LrgSchemaNormalizer)->hasBlockingErrors($result['diagnostics']));
    }

    public function test_it_rejects_files_without_tables(): void
    {
        $result = (new LrgSchemaNormalizer)->normalize('SELECT 1', 'mysql', 'abc');

        $this->assertTrue((new LrgSchemaNormalizer)->hasBlockingErrors($result['diagnostics']));
        $this->assertSame('NO_TABLES_FOUND', $result['diagnostics'][0]['code']);
    }

    public function test_it_preserves_delimiters_in_string_defaults_and_named_constraints(): void
    {
        $sql = <<<'SQL'
-- A semicolon in this comment must not split the statement.
CREATE TABLE notes (
    id INT NOT NULL,
    message VARCHAR(120) NOT NULL DEFAULT 'alpha,beta; gamma',
    CONSTRAINT pk_notes PRIMARY KEY (id),
    CONSTRAINT uq_notes_message UNIQUE (message),
    CONSTRAINT ck_notes_message CHECK (length(message) > 0)
);
SQL;

        $result = (new LrgSchemaNormalizer)->normalize($sql, 'mysql', hash('sha256', $sql));
        $table = $result['document']['tables'][0];

        $this->assertFalse((new LrgSchemaNormalizer)->hasBlockingErrors($result['diagnostics']));
        $this->assertSame('alpha,beta; gamma', $table['columns'][1]['default']);
        $this->assertSame(
            ['PRIMARY KEY', 'UNIQUE', 'CHECK'],
            array_column($table['constraints'], 'type')
        );
        $this->assertSame('uq_notes_message', $table['constraints'][1]['name']);
    }
}
