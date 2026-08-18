<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Operand;

/**
 * Compile-time check: duplicate names in a closure {@code use} list (#32153).
 *
 * php-src: Zend/zend_compile.c — zend_compile_closure_binding
 * {@code Cannot use variable $%pS twice} when zend_hash_add of the lexical table fails.
 */
final class ClosureUseDuplicateCompileCheck
{
    public static function messageFor(string $name): string
    {
        return sprintf('Cannot use variable $%s twice', $name);
    }

    /**
     * First duplicate lexical name in a closure {@code use} list, or null.
     *
     * Names are case-sensitive (PHP variables). {@code use ($a, &$a)} is still a
     * duplicate — php-src keys the lexical table by name, not by-ref mode.
     *
     * @param list<Operand> $useVars
     */
    public static function firstDuplicateName(array $useVars): ?string
    {
        /** @var array<string, true> */
        $seen = [];
        foreach ($useVars as $useVar) {
            $name = self::nameFromUseVar($useVar);
            if (null === $name || '' === $name) {
                continue;
            }
            if (isset($seen[$name])) {
                return $name;
            }
            $seen[$name] = true;
        }

        return null;
    }

    private static function nameFromUseVar(Operand $useVar): ?string
    {
        if ($useVar instanceof Operand\BoundVariable) {
            return self::operandString($useVar->name);
        }

        return self::operandString($useVar);
    }

    private static function operandString(Operand $op): ?string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return self::operandString($op->name);
        }

        return null;
    }
}
