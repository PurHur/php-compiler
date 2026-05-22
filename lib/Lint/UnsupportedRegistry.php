<?php

declare(strict_types=1);

namespace PHPCompiler\Lint;

/**
 * Maps unsupported CFG kinds to GitHub tracking issues.
 *
 * @see docs/unsupported-syntax.md
 */
final class UnsupportedRegistry
{
    public const ISSUE_URL_BASE = 'https://github.com/PurHur/php-compiler/issues/';

    /** @var array<string, int> */
    private const KIND_TO_ISSUE = [
        'Expr_Throw' => 195,
        'Expr_New' => 136,
        'Stmt_Try' => 57,
        'Stmt_TryCatch' => 57,
        'Stmt_Catch' => 57,
        'Stmt_Finally' => 57,
        'Expr_AssignOp_Coalesce' => 99,
        'Expr_Match' => 143,
        'Expr_Yield' => 167,
        'Expr_YieldFrom' => 167,
        'Stmt_Enum' => 169,
        'Expr_ArrowFunction' => 142,
        'Expr_Closure' => 72,
        'Stmt_Trait' => 168,
        'Expr_NamedArgument' => 168,
        'Expr_PreInc' => 137,
        'Expr_PostInc' => 137,
        'Expr_PreDec' => 137,
        'Expr_PostDec' => 137,
    ];

    public static function trackingIssueForKind(string $kind): ?int
    {
        if (isset(self::KIND_TO_ISSUE[$kind])) {
            return self::KIND_TO_ISSUE[$kind];
        }
        foreach (self::KIND_TO_ISSUE as $prefix => $issue) {
            if (str_starts_with($kind, $prefix)) {
                return $issue;
            }
        }

        return null;
    }

    public static function issueUrl(int $issue): string
    {
        return self::ISSUE_URL_BASE.$issue;
    }

    /**
     * @return array<string, int>
     */
    public static function knownKinds(): array
    {
        return self::KIND_TO_ISSUE;
    }

    /**
     * @param list<\PHPCompiler\Lint\Issue> $issues
     *
     * @return array<string, list<\PHPCompiler\Lint\Issue>> absolute file path => issues
     */
    public static function groupIssuesByFile(array $issues): array
    {
        $byFile = [];
        foreach ($issues as $issue) {
            $byFile[$issue->file][] = $issue;
        }
        ksort($byFile);

        return $byFile;
    }

    /**
     * @param list<\PHPCompiler\Lint\Issue> $issues
     *
     * @return list<string> unique unsupported CFG kinds
     */
    public static function uniqueKinds(array $issues): array
    {
        $kinds = [];
        foreach ($issues as $issue) {
            $kinds[$issue->kind] = true;
        }
        $list = array_keys($kinds);
        sort($list);

        return $list;
    }
}
