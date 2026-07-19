<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * serialize() wire helpers for compiled JIT/AOT modules (#9440, #9180, #20773, php-in-PHP).
 *
 * Keep this file NestedJIT-safe: no HashTable keyed iterators (object method gap, #12908).
 * Session wire lives in {@see VmSessionSerializer} (#20773).
 * SSOT: {@see VmSerialize} via active VM context.
 * php-src: ext/standard/var.c — php_var_serialize
 */
final class SerializeJitHelper
{
    public static function encodeValue(Variable $value): string
    {
        $ctx = self::requireActiveContext();

        return VmSerialize::serializeValue($ctx, $value->resolveIndirect());
    }

    public static function encodeHashtable(HashTable $ht): string
    {
        $var = new Variable(Variable::TYPE_ARRAY);
        $var->array($ht);

        return self::encodeValue($var);
    }

    private static function requireActiveContext(): Context
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException('serialize() JIT helper requires active VM context (#9180)');
        }

        return $ctx;
    }
}
