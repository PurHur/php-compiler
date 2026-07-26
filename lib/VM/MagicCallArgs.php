<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\VM;

/**
 * Pack user call arguments for `__call` / `__callStatic` dispatch.
 *
 * Zend (`Zend/zend_object_handlers.c`, `Zend/zend_execute_API.c`) collects the
 * original call's positional and named arguments into the magic `$arguments`
 * array — string keys are preserved; unknown names are not rejected.
 *
 * php-src-strict · #23336
 */
final class MagicCallArgs
{
    /**
     * Expand callArgEntries (including `...` unpack) into `__call`'s `$arguments` array.
     */
    public static function packUserArguments(VM $vm, Frame $frame): Variable
    {
        $entries = self::expandEntries($vm, $frame);
        $argsVar = new Variable();
        $argsVar->newArray();
        $packed = $argsVar->toArray();
        $nextIndex = 0;
        $hadNamed = false;
        /** @var array<string, true> $namedFilled */
        $namedFilled = [];

        foreach ($entries as $entry) {
            if ('p' === $entry[0]) {
                if ($hadNamed) {
                    throw new \Error('Cannot use positional argument after named argument');
                }
                /** @var Variable $value */
                $value = $entry[1];
                $copy = new Variable();
                $copy->copyFrom($value);
                $packed->addIndex($nextIndex++, $copy);
                continue;
            }

            $name = (string) $entry[1];
            /** @var Variable $value */
            $value = $entry[2];
            if (isset($namedFilled[$name])) {
                throw new \Error("Named parameter \${$name} overwrites previous argument");
            }
            $namedFilled[$name] = true;
            $hadNamed = true;
            $copy = new Variable();
            $copy->copyFrom($value);
            $packed->add($name, $copy);
        }

        return $argsVar;
    }

    /**
     * @return list<array{0: string, 1?: mixed, 2?: Variable}>
     */
    private static function expandEntries(VM $vm, Frame $frame): array
    {
        if ([] === $frame->callArgEntries) {
            return [];
        }

        $entries = [];
        foreach ($frame->callArgEntries as $entry) {
            if ('u' === $entry[0]) {
                foreach (self::expandUnpack($vm, $frame, $entry[1]) as $expanded) {
                    $entries[] = $expanded;
                }
                continue;
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Unpack for magic calls: string keys become named entries (no callee param lookup).
     *
     * @return list<array{0: string, 1?: mixed, 2?: Variable}>
     */
    private static function expandUnpack(VM $vm, Frame $frame, Variable $spread): array
    {
        // Reuse CallUnpack expansion with an open variadic so unknown string keys
        // become named entries instead of "Unknown named parameter".
        return CallUnpack::expandToEntries($vm, $frame, $spread, [], 0, null);
    }
}
