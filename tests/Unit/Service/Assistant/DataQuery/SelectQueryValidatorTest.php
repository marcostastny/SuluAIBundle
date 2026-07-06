<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Tests\Unit\Service\Assistant\DataQuery;

use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\InvalidSelectQueryException;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\SelectQueryValidator;
use PHPUnit\Framework\TestCase;

class SelectQueryValidatorTest extends TestCase
{
    private const TABLES = ['fo_forms', 'fo_form_translations', 'fo_dynamics'];

    private SelectQueryValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SelectQueryValidator();
    }

    public function testAcceptsSimpleSelectAndInjectsLimit(): void
    {
        $sql = $this->validator->validate('SELECT id, created FROM fo_dynamics ORDER BY created DESC', self::TABLES, 100);

        $this->assertSame('SELECT id, created FROM fo_dynamics ORDER BY created DESC LIMIT 100', $sql);
    }

    public function testKeepsSmallerLimit(): void
    {
        $sql = $this->validator->validate('SELECT id FROM fo_dynamics LIMIT 10', self::TABLES, 100);

        $this->assertSame('SELECT id FROM fo_dynamics LIMIT 10', $sql);
    }

    public function testLowersOversizedLimit(): void
    {
        $sql = $this->validator->validate('SELECT id FROM fo_dynamics LIMIT 5000', self::TABLES, 100);

        // sql-parser's build() renders the limit as "LIMIT 0, 100".
        $this->assertMatchesRegularExpression('/LIMIT (0, )?100\b/i', $sql);
        $this->assertStringNotContainsString('5000', $sql);
    }

    public function testAcceptsJoinJsonExtractAndBackticks(): void
    {
        $sql = 'SELECT t.title, JSON_EXTRACT(d.data, \'$.email\') FROM `fo_dynamics` d '
            . 'JOIN fo_form_translations t ON t.idForms = d.formId WHERE d.locale = \'de\' LIMIT 20';

        $this->assertSame($sql, $this->validator->validate($sql, self::TABLES, 100));
    }

    public function testAcceptsSubqueryOverAllowedTables(): void
    {
        $sql = 'SELECT id FROM fo_dynamics WHERE formId IN (SELECT idForms FROM fo_form_translations WHERE title = \'Tischreservation\') LIMIT 5';

        $this->assertSame($sql, $this->validator->validate($sql, self::TABLES, 100));
    }

    public function testRejectsEmptyAndNonSelect(): void
    {
        $this->assertRejected('', 'empty');
        $this->assertRejected('DELETE FROM fo_dynamics', 'SELECT');
        $this->assertRejected('UPDATE fo_dynamics SET locale = \'de\'', 'SELECT');
        $this->assertRejected('SHOW TABLES', 'SELECT');
    }

    public function testRejectsMultipleStatements(): void
    {
        $this->assertRejected('SELECT id FROM fo_dynamics; SELECT id FROM fo_forms', 'one statement');
    }

    public function testRejectsDisallowedTables(): void
    {
        $this->assertRejected('SELECT * FROM se_users', 'se_users');
        $this->assertRejected('SELECT d.id FROM fo_dynamics d JOIN se_users u ON u.id = d.idUsersCreator', 'se_users');
        $this->assertRejected('SELECT id FROM fo_dynamics WHERE id IN (SELECT id FROM se_users)', 'se_users');
        $this->assertRejected('SELECT id FROM fo_dynamics UNION SELECT id FROM se_users', 'se_users');
        $this->assertRejected('SELECT a.id FROM fo_forms a, se_users b', 'se_users');
        $this->assertRejected('SELECT a.id FROM fo_forms AS a, se_users AS b', 'se_users');
    }

    public function testRejectsDangerousConstructs(): void
    {
        $this->assertRejected('SELECT id INTO OUTFILE \'/tmp/x\' FROM fo_dynamics', 'not allowed');
        $this->assertRejected('SELECT id FROM fo_dynamics FOR UPDATE', 'not allowed');
        $this->assertRejected('SELECT @@version FROM fo_dynamics', 'variable');
        $this->assertRejected('SELECT id FROM fo_dynamics WHERE id = @x', 'variable');
        $this->assertRejected('SELECT LOAD_FILE(\'/etc/passwd\') FROM fo_dynamics', 'not allowed');
        $this->assertRejected('SELECT SLEEP(10) FROM fo_dynamics', 'not allowed');
        // "mysql" hits the allowlist check before the dot is even seen.
        $this->assertRejected('SELECT id FROM mysql.user', 'mysql');
    }

    public function testRejectsCte(): void
    {
        $this->expectException(InvalidSelectQueryException::class);

        $this->validator->validate('WITH x AS (SELECT id FROM fo_dynamics) SELECT * FROM x', self::TABLES, 100);
    }

    private function assertRejected(string $sql, string $messagePart): void
    {
        try {
            $this->validator->validate($sql, self::TABLES, 100);
            $this->fail(\sprintf('Expected "%s" to be rejected.', $sql));
        } catch (InvalidSelectQueryException $e) {
            $this->assertStringContainsStringIgnoringCase($messagePart, $e->getMessage());
        }
    }
}
