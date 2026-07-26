<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Dynamic variable function call semantics shared by VM + JIT (#10135, #23591, php-in-PHP).
 *
 * php-src: Zend/zend_execute.c — ZEND_INIT_FCALL_BY_NAME / variable calls
 * php-src: Zend/zend_compile.h — ZEND_ACC_FORBIDDEN_WHEN_DYNAMIC
 */
final class VariableFunctionCall
{
    /**
     * Builtins marked ForbiddenWhenDynamic in php-src stubs.
     *
     * @var array<string, true>
     */
    private const FORBIDDEN_WHEN_DYNAMIC = [
        'compact' => true,
        'extract' => true,
        'get_defined_vars' => true,
        'func_get_args' => true,
        'func_num_args' => true,
        'func_get_arg' => true,
    ];

    /**
     * @return list<string>
     */
    public static function forbiddenWhenDynamicNames(): array
    {
        return \array_keys(self::FORBIDDEN_WHEN_DYNAMIC);
    }

    /**
     * @param list<string> $candidateNames lowercase compile-time callee hints
     *
     * @return int candidate index, or -1 when none match
     */
    public static function matchCandidateIndex(string $name, array $candidateNames): int
    {
        $needle = \strtolower($name);
        foreach ($candidateNames as $index => $candidate) {
            if ($needle === \strtolower($candidate)) {
                return $index;
            }
        }

        return -1;
    }

    public static function isForbiddenWhenDynamic(string $name): bool
    {
        return isset(self::FORBIDDEN_WHEN_DYNAMIC[\strtolower($name)]);
    }

    /** Zend zend_execute.c — "Cannot call %s() dynamically". */
    public static function forbiddenWhenDynamicMessage(string $name): string
    {
        return 'Cannot call '.\strtolower($name).'() dynamically';
    }
}
