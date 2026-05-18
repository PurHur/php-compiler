<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT;
use PHPCompiler\ModuleAbstract;

class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new str_repeat(),
            new decbin(),
            new abs(),
            new ceil(),
            new floor(),
            new round(),
            new number_format(),
            new sqrt(),
            new pi(),
            new deg2rad(),
            new rad2deg(),
            new log(),
            new exp(),
            new sin(),
            new cos(),
            new tan(),
            new is_nan(),
            new is_finite(),
            new is_infinite(),
            new pow(),
            new fmod(),
            new intval(),
            new floatval(),
            new boolval(),
            new gettype(),
            new strval(),
            new int_min(),
            new int_max(),
            new intdiv(),
            new ord(),
            new chr(),
            new strcmp(),
            new dechex(),
            new hexdec(),
            new decoct(),
            new octdec(),
            new bindec(),
            new is_numeric(),
            new is_scalar(),
            new lcfirst(),
            new ucfirst(),
            new strtolower(),
            new strtoupper(),
            new string_trim(),
            new string_ltrim(),
            new string_rtrim(),
            new substr(),
            new strrev(),
            new strpos(),
            new str_contains(),
            new str_starts_with(),
            new str_ends_with(),
            new strncmp(),
            new array_count(),
            new array_count('sizeof'),
            new array_key_exists(),
            new in_array(),
            new array_push(),
            new array_pop(),
            new array_shift(),
            new array_values(),
            new array_keys(),
            new array_merge(),
            new array_slice(),
            new explode(),
            new implode(),
            new str_replace(),
            new nl2br(),
            new array_reverse(),
            new array_search(),
            new array_sum(),
            new array_flip(),
            new array_unique(),
            new array_fill(),
            new array_combine(),
            new range(),
            new bin2hex(),
            new str_pad(),
            new str_split(),
            new htmlspecialchars(),
            new header_(),
            new urlencode(),
            new rawurlencode(),
            new parse_url(),
            new dirname(),
            new basename(),
            new realpath(),
            new getenv_(),
            new putenv_(),
            new scandir(),
            new glob_(),
        ];
    }

    public function jitInit(JIT\Context $context): void
    {
        try {
            $context->lookupFunction('strcmp');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p);
            $fn = $context->module->addFunction('strcmp', $ft);
            $context->registerFunction('strcmp', $fn);
        }
        try {
            $context->lookupFunction('strncmp');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $sizeT = $context->getTypeFromString('size_t');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p, $sizeT);
            $fn = $context->module->addFunction('strncmp', $ft);
            $context->registerFunction('strncmp', $fn);
        }
        try {
            $context->lookupFunction('strstr');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $ft = $context->context->functionType($i8p, false, $i8p, $i8p);
            $fn = $context->module->addFunction('strstr', $ft);
            $context->registerFunction('strstr', $fn);
        }
        try {
            $context->lookupFunction('strtol');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i8pp = $context->getTypeFromString('int8**');
            $i32 = $context->getTypeFromString('int32');
            $i64 = $context->getTypeFromString('int64');
            $ft = $context->context->functionType($i64, false, $i8p, $i8pp, $i32);
            $fn = $context->module->addFunction('strtol', $ft);
            $context->registerFunction('strtol', $fn);
        }
        try {
            $context->lookupFunction('strtod');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i8pp = $context->getTypeFromString('int8**');
            $double = $context->getTypeFromString('double');
            $ft = $context->context->functionType($double, false, $i8p, $i8pp);
            $fn = $context->module->addFunction('strtod', $ft);
            $context->registerFunction('strtod', $fn);
        }
        try {
            $context->lookupFunction('strlen');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $sizeT = $context->getTypeFromString('size_t');
            $ft = $context->context->functionType($sizeT, false, $i8p);
            $fn = $context->module->addFunction('strlen', $ft);
            $context->registerFunction('strlen', $fn);
        }
        try {
            $context->lookupFunction('realpath');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $ft = $context->context->functionType($i8p, false, $i8p, $i8p);
            $fn = $context->module->addFunction('realpath', $ft);
            $context->registerFunction('realpath', $fn);
        }
        $double = $context->getTypeFromString('double');
        try {
            $context->lookupFunction('fabs');
        } catch (\Throwable $e) {
            $ft = $context->context->functionType($double, false, $double);
            $fn = $context->module->addFunction('fabs', $ft);
            $context->registerFunction('fabs', $fn);
        }
        foreach (['ceil', 'floor', 'round', 'sqrt', 'log', 'exp', 'sin', 'cos', 'tan', 'pow', 'fmod'] as $name) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable $e) {
                $params = in_array($name, ['pow', 'fmod'], true) ? [$double, $double] : [$double];
                $ft = $context->context->functionType($double, false, ...$params);
                $fn = $context->module->addFunction($name, $ft);
                $context->registerFunction($name, $fn);
            }
        }
        $i32 = $context->getTypeFromString('int32');
        foreach (['isnan', 'isfinite', 'isinf'] as $name) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable $e) {
                $ft = $context->context->functionType($i32, false, $double);
                $fn = $context->module->addFunction($name, $ft);
                $context->registerFunction($name, $fn);
            }
        }
    }
}
