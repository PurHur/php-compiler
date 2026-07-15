<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** VM interpreter helpers for EnvLocal overlay — not nested-JIT compiled (#9814). */
final class EnvLocalJitHelperVm
{
    public static function mergeLocalOverlayInto(HashTable $ht): void
    {
        foreach (GetenvJitHelper::localOverlayEntries() as $name => $value) {
            if ('' === $name) {
                continue;
            }
            $var = new Variable();
            $var->string($value);
            $ht->update($name, $var);
        }
    }
}
