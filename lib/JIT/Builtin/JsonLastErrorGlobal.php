<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * Shared phpc_json_last_error LLVM global for json encode/decode JIT helpers (#3606, #13228).
 *
 * php-src: ext/json/php_json.c — JSON_ERROR_* / json_last_error()
 */
final class JsonLastErrorGlobal
{
    private const GLOBAL_LAST_ERROR = 'phpc_json_last_error';

    private const ERROR_NONE = 0;

    public const ERROR_INF_OR_NAN = 7;

    /** @var Value|null */
    private static $lastErrorGlobal = null;

    public static function ensureLastErrorGlobal(Context $context): Value
    {
        $i32 = $context->getTypeFromString('int32');
        if (null === $context->module->getNamedGlobal(self::GLOBAL_LAST_ERROR)) {
            self::$lastErrorGlobal = $context->module->addGlobal($i32, self::GLOBAL_LAST_ERROR);
            self::$lastErrorGlobal->setInitializer($i32->constInt(self::ERROR_NONE, false));
        } else {
            self::$lastErrorGlobal = $context->module->getNamedGlobal(self::GLOBAL_LAST_ERROR);
        }

        return self::$lastErrorGlobal;
    }

    public static function errorInfOrNan(): int
    {
        return self::ERROR_INF_OR_NAN;
    }
}
