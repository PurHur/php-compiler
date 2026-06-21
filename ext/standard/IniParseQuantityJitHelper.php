<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for __compiler_ini_parse_quantity (#9237, php-in-PHP).
 *
 * SSOT: {@see VmIniQuantity::parseQuantity()} (php-src Zend/zend_ini.c).
 */
final class IniParseQuantityJitHelper
{
    public static function parseQuantity(string $shorthand): int
    {
        return VmIniQuantity::parseQuantity($shorthand);
    }
}
