<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Thin-standalone NestedJIT json_encode encoder (#27020 / #26977, php-in-PHP).
 *
 * Context-free: no VmJson / runtime-vm. NestedJIT: $pair[0]/$pair[1] only.
 * Packed lists → `[…]`; string keys → `{…}`; nested TYPE_ARRAY recurses.
 * php-src: ext/json/php_json.c — php_json_encode
 */
final class JsonEncodeNestedJitHelper
{
    public static function encodeValue(Variable $value, int $flags): ?string
    {
        $t = $value->type & 0x7f;
        if (Variable::TYPE_INTEGER === $t) {
            return (string) $value->toInt();
        }
        if (Variable::TYPE_NULL === $t) {
            return 'null';
        }
        if (Variable::TYPE_BOOLEAN === $t) {
            return $value->toBool() ? 'true' : 'false';
        }
        if (Variable::TYPE_FLOAT === $t) {
            return (string) $value->toFloat();
        }
        if (Variable::TYPE_STRING === $t) {
            return '"'.$value->toString().'"';
        }
        if (Variable::TYPE_ARRAY === $t || 7 === $t) {
            return self::encodeHashtable($value->toArray(), $flags);
        }

        return (string) $value->toInt();
    }

    public static function encodeHashtable(HashTable $ht, int $flags): ?string
    {
        $packed = true;
        $expect = 0;
        foreach ($ht->exportKeyValuePairs(true) as $probe) {
            $pk = $probe[0];
            $pkt = $pk->type & 0x7f;
            if (Variable::TYPE_INTEGER !== $pkt || $pk->toInt() !== $expect) {
                $packed = false;
                break;
            }
            ++$expect;
        }

        if ($packed) {
            $out = '[';
            $n = 0;
            foreach ($ht->exportKeyValuePairs(true) as $pair) {
                if ($n > 0) {
                    $out .= ',';
                }
                $enc = self::encodeValue($pair[1], $flags);
                $out .= null === $enc ? 'null' : $enc;
                ++$n;
            }

            return $out.']';
        }

        $out = '{';
        $n = 0;
        foreach ($ht->exportKeyValuePairs(true) as $pair) {
            if ($n > 0) {
                $out .= ',';
            }
            $key = $pair[0];
            $kt = $key->type & 0x7f;
            if (Variable::TYPE_INTEGER === $kt) {
                $out .= '"'.$key->toInt().'":';
            } else {
                $out .= '"'.$key->toString().'":';
            }
            $enc = self::encodeValue($pair[1], $flags);
            $out .= null === $enc ? 'null' : $enc;
            ++$n;
        }

        return $out.'}';
    }
}
