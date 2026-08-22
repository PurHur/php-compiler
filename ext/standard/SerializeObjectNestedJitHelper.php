<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * Thin-standalone NestedJIT serialize() object pieces (#27030 / #33692).
 *
 * Keep each helper to one HT or (string,int) — NestedJIT mis-types richer arities.
 * AOT HT export uses JIT tags (bool=2, double=3) — not VM float=2 / bool=3 (#33520 / #33687).
 * php-src: ext/standard/var.c — php_var_serialize object branch
 */
final class SerializeObjectNestedJitHelper
{
    /**
     * @param mixed $className content used; length comes from $classLen (LLVM)
     */
    public static function formatObjectHeader($className, int $classLen): string
    {
        if (null === $className) {
            $className = '';
        } else {
            $className = $className.'';
        }
        if ($classLen < 0) {
            $classLen = 0;
        }

        return 'O:'.((string) $classLen).':"'.$className.'":';
    }

    /** @return string `N:{…}` property bag with count */
    public static function encodeObjectProps(HashTable $props): string
    {
        $body = '';
        $n = 0;
        foreach ($props->exportKeyValuePairs(true) as $pair) {
            // NestedJIT: (string) cast on pair slots (peer StrtrArrayJitHelper #27056).
            $ks = (string) $pair[0];
            $body .= 's:'.((string) \strlen($ks)).':"'.$ks.'";';
            $val = $pair[1];
            $t = $val->type & 0x7f;
            if (1 === $t) {
                $body .= 'i:'.((string) $val->toInt()).';';
            } elseif (0 === $t) {
                $body .= 'N;';
            } elseif (2 === $t) {
                // JIT TYPE_NATIVE_BOOL (=2). Prefer if/else — NestedJIT i1 ternary
                // can stick on the true arm (#33687 / VariableToBool + #21892).
                if ($val->toBool()) {
                    $body .= 'b:1;';
                } else {
                    $body .= 'b:0;';
                }
            } elseif (3 === $t) {
                // JIT TYPE_NATIVE_DOUBLE (=3) — VM TYPE_BOOLEAN collides (#33520 / #33692).
                $body .= 'd:'.((string) $val->toFloat()).';';
            } elseif (4 === $t) {
                $vs = (string) $val;
                $body .= 's:'.((string) \strlen($vs)).':"'.$vs.'";';
            } else {
                $body .= 'i:'.((string) $val->toInt()).';';
            }
            ++$n;
        }

        return ((string) $n).':{'.$body.'}';
    }
}
