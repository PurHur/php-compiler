<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Shared dns_get_mx() / getmxrr() by-ref array population (#4125, #3662, #22707).
 *
 * php-src overwrites &$mxhosts / &$weight regardless of incoming type for weight (#22707);
 * mxhosts still uses {@see validateArrayByRefArg} where callers opt in.
 */
final class VmDnsMx
{
    public static function validateArrayByRefArg(Variable $arg, string $fn, int $index, string $param): void
    {
        $resolved = $arg->resolveIndirect();
        if (
            Variable::TYPE_ARRAY !== $resolved->type
            && Variable::TYPE_UNDEFINED !== $resolved->type
            && Variable::TYPE_NULL !== $resolved->type
        ) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array, %s given',
                $fn,
                $index + 1,
                $param,
                VmParseStr::zendTypeLabel($resolved)
            ));
        }
    }

    /**
     * @param array{hosts: list<string>, weights: list<int>}|false|null $result
     */
    public static function applyMxByRef(
        $result,
        Variable $hostsArg,
        ?Variable $weightsArg = null
    ): bool {
        if (false === $result) {
            self::assignArrayByRef($hostsArg, new HashTable());
            if (null !== $weightsArg) {
                self::assignArrayByRef($weightsArg, new HashTable());
            }

            return false;
        }

        $hostsHt = new HashTable();
        $weightsHt = new HashTable();
        foreach ($result['hosts'] as $index => $host) {
            $hostVar = new Variable(Variable::TYPE_STRING);
            $hostVar->string($host);
            $hostsHt->add((string) $index, $hostVar);

            $weightVar = new Variable(Variable::TYPE_INTEGER);
            $weightVar->int($result['weights'][$index]);
            $weightsHt->add((string) $index, $weightVar);
        }

        self::assignArrayByRef($hostsArg, $hostsHt);
        if (null !== $weightsArg) {
            self::assignArrayByRef($weightsArg, $weightsHt);
        }

        return true;
    }

    private static function assignArrayByRef(Variable $arg, HashTable $ht): void
    {
        $replacement = new Variable(Variable::TYPE_ARRAY);
        $replacement->array($ht);
        $arg->copyFrom($replacement);
    }
}
