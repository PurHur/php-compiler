<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * json_decode() / json_validate() for compiled JIT/AOT modules (#9359, php-in-PHP).
 *
 * SSOT: {@see VmJsonFormat::decode()} + {@see VmJsonScanner::validate()}
 * php-src: ext/json/php_json.c — php_json_decode_ex / php_json_validate
 */
final class JsonDecodeJitHelper
{
    private const DEFAULT_DEPTH = 512;

    public static function decode(string $json): Variable
    {
        $ctx = self::requireActiveContext();
        VmJson::setLastError(0);
        $decoded = VmJsonFormat::decode($json, true, self::DEFAULT_DEPTH, 0);
        if (VmJson::lastError() !== 0) {
            $null = new Variable();
            $null->null();

            return $null;
        }

        return VmJson::importDecoded($decoded, true, $ctx);
    }

    /** @return int 1 valid, 0 syntax error, -1 depth exceeded (__compiler_json_validate ABI) */
    public static function validate(string $json, int $depth): int
    {
        if ($depth < 1) {
            return VmJsonScanner::RESULT_SYNTAX;
        }

        return VmJsonScanner::validate($json, $depth, 0);
    }

    public static function lastError(): int
    {
        return VmJson::lastError();
    }

    public static function lastErrorMsg(): string
    {
        return VmJson::lastErrorMsg();
    }

    private static function requireActiveContext(): \PHPCompiler\VM\Context
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException('json_decode() JIT helper requires active VM context (#9359)');
        }

        return $ctx;
    }
}
