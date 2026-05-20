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
    /** @var array<string, int> */
    private const KIND_TO_ISSUE = [
        'Stmt_Foreach' => 53,
        'Iterator_Reset' => 53,
        'Iterator_Valid' => 53,
        'Iterator_Next' => 53,
        'Iterator_Key' => 53,
        'Iterator_Current' => 53,
        'Iterator_Value' => 53,
        'Expr_BinaryOp_Coalesce' => 99,
        'Expr_Coalesce' => 99,
        'Expr_Throw' => 195,
        'Expr_New' => 136,
        'Stmt_Try' => 195,
        'Stmt_Catch' => 195,
        'Stmt_Finally' => 195,
        'Expr_Ternary' => 114,
        'Expr_AssignOp_' => 136,
        'Expr_BinaryOp_ShiftLeft' => 136,
        'Expr_BinaryOp_ShiftRight' => 136,
        'Stmt_Break' => 115,
        'Stmt_Continue' => 115,
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

    /**
     * @return array<string, int>
     */
    public static function knownKinds(): array
    {
        return self::KIND_TO_ISSUE;
    }
}
