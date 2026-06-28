<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * json_encode() for compiled JIT/AOT modules (#9267, php-in-PHP).
 *
 * SSOT: {@see VmJson::export()} + {@see VmJsonFormat::encodeExported()}
 * php-src: ext/json/php_json.c — php_json_encode
 */
final class JsonEncodeJitHelper
{
    public static function encodeValue(Variable $value, int $flags): ?string
    {
        $ctx = self::requireActiveContext();
        $vm = $ctx->runtime->vm;
        if (null === $vm) {
            throw new \LogicException('json_encode() JIT helper requires active VM (#9267)');
        }
        $frames = $ctx->runStackFrames();
        $frame = $frames[0] ?? null;
        try {
            $exported = VmJson::export($value->resolveIndirect(), $ctx, $vm, $frame);
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

    private static function requireActiveContext(): Context
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException('json_encode() JIT helper requires active VM context (#9267)');
        }

        return $ctx;
    }
}
