<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmActiveContextJitHelper;
use PHPCompiler\Web\Superglobals;

/**
 * json_encode() for compiled JIT/AOT modules (#9267, #20816, php-in-PHP).
 *
 * NestedJIT-safe: no Context::runStackFrames() (#13245). Scalars/arrays export
 * with a null Frame; object props that need a Frame still go through VM.
 * Keep hashtable path via Variable::array() (Serialize #20773 shape).
 * SSOT: {@see VmJson::export()} + {@see VmJsonFormat::encodeExported()}
 * php-src: ext/json/php_json.c — php_json_encode
 */
final class JsonEncodeJitHelper
{
    public static function encodeValue(Variable $value, int $flags): ?string
    {
        // Inline context resolve — NestedJIT mis-types `: Context` returns as int (#20816).
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            $ctx = VmActiveContextJitHelper::resolve();
        }
        $vm = $ctx->runtime->vm;
        if (null === $vm) {
            $ctx->runtime->vm = new VM($ctx);
            $vm = $ctx->runtime->vm;
        }
        try {
            // null Frame avoids NestedJIT of stack-frame walk (#13245 / #20816).
            $exported = VmJson::export($value->resolveIndirect(), $ctx, $vm, null);
        } catch (VmJsonExportException $e) {
            VmJson::setLastError($e->errorCode);

            return null;
        }
        $encoded = VmJsonFormat::encodeExported($exported, $flags);
        if (false === $encoded) {
            return null;
        }

        return $encoded;
    }

    public static function encodeHashtable(HashTable $ht, int $flags): ?string
    {
        $var = new Variable(Variable::TYPE_ARRAY);
        $var->array($ht);

        return self::encodeValue($var, $flags);
    }
}
