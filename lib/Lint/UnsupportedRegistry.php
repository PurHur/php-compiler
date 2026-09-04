<?php

declare(strict_types=1);

namespace PHPCompiler\Lint;

/**
 * Maps unsupported CFG kinds and known limitation feature ids to GitHub issues.
 *
 * @see docs/unsupported-syntax.md
 * @see https://github.com/PurHur/php-compiler/issues/36396
 */
final class UnsupportedRegistry
{
    public const ISSUE_URL_BASE = 'https://github.com/PurHur/php-compiler/issues/';

    /** @var array<string, int> */
    private const KIND_TO_ISSUE = [
        'Stmt_Try' => 57,
        'Stmt_TryCatch' => 57,
        'Stmt_Catch' => 57,
        'Stmt_Finally' => 57,
    ];

    /**
     * Catalogued known limitations (compile/JIT rejection sites).
     *
     * Each entry must include feature, matrixRow, issue, and alternative.
     *
     * @var array<string, array{feature: string, matrixRow: string, issue: int, alternative: string}>
     */
    private const FEATURES = [
        'range-non-int-endpoints' => [
            'feature' => 'range() start/end that are not int, float, or single-char string',
            'matrixRow' => 'docs/capabilities.md#range',
            'issue' => 4258,
            'alternative' => 'use integer, float, or single-character string bounds (php-src ext/standard/array.c)',
        ],
        'range-float-path-operands' => [
            'feature' => 'range() float path with non-numeric operands',
            'matrixRow' => 'docs/capabilities.md#range',
            'issue' => 27158,
            'alternative' => 'pass native int/float bounds (or null) so the float path can lower',
        ],
        'jit-unsupported-vm-constant' => [
            'feature' => 'Unsupported compile-time constant for JIT',
            'matrixRow' => 'docs/capabilities-syntax.md#literals',
            'issue' => 36396,
            'alternative' => 'fold the constant to int/float/string/array/null/enum-case before JIT lowering',
        ],
        'array-unique-flags' => [
            'feature' => 'array_unique() flags outside SORT_REGULAR/STRING/NUMERIC',
            'matrixRow' => 'docs/capabilities.md#array_unique',
            'issue' => 27066,
            'alternative' => 'use SORT_REGULAR, SORT_STRING, or SORT_NUMERIC (php-src php_array_unique)',
        ],
        'array-callback-deferred' => [
            'feature' => 'array_* callback form not lowerable for JIT/AOT',
            'matrixRow' => 'docs/capabilities.md#array_map',
            'issue' => 3073,
            'alternative' => 'use a string builtin/user-function name or a closure; array/invokable callables are deferred',
        ],
        'set-error-handler-callback' => [
            'feature' => 'set_error_handler() callback form not lowerable for JIT/AOT',
            'matrixRow' => 'docs/capabilities.md#set_error_handler',
            'issue' => 1379,
            'alternative' => 'use a compile-time string function name or a closure; array/invokable callables are deferred',
        ],
        'set-exception-handler-callback' => [
            'feature' => 'set_exception_handler() callback form not lowerable for JIT/AOT',
            'matrixRow' => 'docs/capabilities.md#set_exception_handler',
            'issue' => 1379,
            'alternative' => 'use null or a compile-time string function name',
        ],
        'exit-status-type' => [
            'feature' => 'exit()/die() status type outside string|int',
            'matrixRow' => 'docs/capabilities-syntax.md#exit',
            'issue' => 22492,
            'alternative' => 'pass a string message or integer status (PHP 8.4+ rejects array; see Zend/zend_compile.c)',
        ],
        'try-catch' => [
            'feature' => 'try/catch/finally',
            'matrixRow' => 'docs/unsupported-syntax.md#try-catch',
            'issue' => 57,
            'alternative' => 'handle errors without try/catch, or run under VM until AOT unwind lands',
        ],
    ];

    /**
     * @return array{feature: string, matrixRow: string, issue: int, alternative: string}
     */
    public static function feature(string $featureId): array
    {
        if (!isset(self::FEATURES[$featureId])) {
            throw new \InvalidArgumentException('Unknown unsupported feature id: '.$featureId);
        }

        return self::FEATURES[$featureId];
    }

    /**
     * @return array<string, array{feature: string, matrixRow: string, issue: int, alternative: string}>
     */
    public static function knownFeatures(): array
    {
        return self::FEATURES;
    }

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
     * Explain text for a lint kind when a catalogued feature or try/catch map exists.
     */
    public static function explainForKind(string $kind): ?string
    {
        if (str_starts_with($kind, 'Stmt_Try')
            || str_starts_with($kind, 'Stmt_Catch')
            || str_starts_with($kind, 'Stmt_Finally')
        ) {
            $row = self::FEATURES['try-catch'];

            return UnsupportedFeature::format(
                $row['feature'],
                $row['matrixRow'],
                $row['issue'],
                $row['alternative']
            );
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
