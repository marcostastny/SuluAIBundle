<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant\DataQuery;

use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Token;

/**
 * Validates model-written SQL before execution: exactly one SELECT statement,
 * every referenced table on the allowlist, no writing/locking/file/variable
 * constructs, LIMIT capped. The AST check (single SELECT, LIMIT) is combined
 * with a linear token scan, which also covers subqueries inside WHERE
 * conditions that the AST keeps as raw strings.
 */
class SelectQueryValidator
{
    // "FOR UPDATE" / "LOCK IN SHARE MODE" arrive as single compound keyword
    // tokens from the tokenizer.
    private const FORBIDDEN_KEYWORDS = ['INTO', 'OUTFILE', 'DUMPFILE', 'INFILE', 'UPDATE', 'FOR UPDATE', 'LOCK', 'LOCK IN SHARE MODE', 'PROCEDURE', 'HANDLER', 'LOAD'];
    private const FORBIDDEN_FUNCTIONS = ['LOAD_FILE', 'SLEEP', 'BENCHMARK', 'GET_LOCK', 'MASTER_POS_WAIT'];

    /**
     * @param list<string> $allowedTables sanitized identifiers (DataQueryGate)
     *
     * @return string the validated SQL, with LIMIT injected or lowered to $maxLimit
     *
     * @throws InvalidSelectQueryException
     */
    public function validate(string $sql, array $allowedTables, int $maxLimit): string
    {
        $sql = \trim(\rtrim(\trim($sql), ';'));
        if ('' === $sql) {
            throw new InvalidSelectQueryException('The query is empty.');
        }

        $parser = new Parser($sql);
        if ([] !== $parser->errors) {
            throw new InvalidSelectQueryException('The SQL could not be parsed: ' . $parser->errors[0]->getMessage());
        }
        if (1 !== \count($parser->statements)) {
            throw new InvalidSelectQueryException('Exactly one statement is allowed.');
        }
        $statement = $parser->statements[0];
        if (!$statement instanceof SelectStatement) {
            throw new InvalidSelectQueryException('Only plain SELECT statements are allowed (no WITH/CTE, no other statement types).');
        }

        $this->scanTokens($parser, $allowedTables);

        $limit = $statement->limit;
        if (null === $limit) {
            return $sql . ' LIMIT ' . $maxLimit;
        }
        if ((int) $limit->rowCount > $maxLimit) {
            $statement->limit->rowCount = $maxLimit;

            return $statement->build();
        }

        return $sql;
    }

    /**
     * @param list<string> $allowedTables
     */
    private function scanTokens(Parser $parser, array $allowedTables): void
    {
        $allowedLower = \array_map('strtolower', $allowedTables);
        // 0 = outside a FROM list, 1 = expecting a table name,
        // 2 = table consumed, a comma may introduce the next one.
        $state = 0;

        foreach ($parser->list->tokens as $token) {
            if (\in_array($token->type, [Token::TYPE_WHITESPACE, Token::TYPE_COMMENT, Token::TYPE_DELIMITER], true)) {
                continue;
            }

            if (Token::TYPE_KEYWORD === $token->type) {
                $keyword = \strtoupper((string) $token->keyword);
                if (\in_array($keyword, self::FORBIDDEN_KEYWORDS, true) || \in_array($keyword, self::FORBIDDEN_FUNCTIONS, true)) {
                    throw new InvalidSelectQueryException(\sprintf('"%s" is not allowed in queries.', $keyword));
                }
                if ('AS' === $keyword && 2 === $state) {
                    // Alias inside a FROM list: keep watching for a comma.
                    continue;
                }
                $state = ('FROM' === $keyword || \str_ends_with($keyword, 'JOIN')) ? 1 : 0;

                continue;
            }

            if (Token::TYPE_SYMBOL === $token->type
                && ($token->flags & (Token::FLAG_SYMBOL_VARIABLE | Token::FLAG_SYMBOL_USER | Token::FLAG_SYMBOL_SYSTEM))
            ) {
                // @user and @@system variables; backtick identifiers only
                // carry FLAG_SYMBOL_BACKTICK and pass through.
                throw new InvalidSelectQueryException('User and system variables (@...) are not allowed.');
            }

            if (Token::TYPE_NONE === $token->type
                && \in_array(\strtoupper((string) $token->value), self::FORBIDDEN_FUNCTIONS, true)
            ) {
                throw new InvalidSelectQueryException(\sprintf('The function "%s" is not allowed.', \strtoupper((string) $token->value)));
            }

            if (0 === $state) {
                continue;
            }

            if (Token::TYPE_OPERATOR === $token->type) {
                $value = (string) $token->value;
                if (1 === $state && '(' === $value) {
                    // Derived table: its inner FROM is caught later in this
                    // same linear scan.
                    $state = 0;
                } elseif (2 === $state && ',' === $value) {
                    $state = 1;
                } elseif ('.' === $value) {
                    throw new InvalidSelectQueryException('Schema-qualified table names (db.table) are not allowed.');
                } else {
                    $state = 0;
                }

                continue;
            }

            if (1 === $state && $this->isIdentifier($token)) {
                $table = \strtolower(\trim((string) $token->value, '`'));
                if (!\in_array($table, $allowedLower, true)) {
                    throw new InvalidSelectQueryException(\sprintf(
                        'Table "%s" is not queryable. Allowed tables: %s.',
                        $table,
                        \implode(', ', $allowedTables)
                    ));
                }
                $state = 2;
            }
        }
    }

    private function isIdentifier(Token $token): bool
    {
        return Token::TYPE_NONE === $token->type
            || (Token::TYPE_SYMBOL === $token->type && ($token->flags & Token::FLAG_SYMBOL_BACKTICK));
    }
}
