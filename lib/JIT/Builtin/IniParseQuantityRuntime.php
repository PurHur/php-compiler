<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM __compiler_ini_parse_quantity — mirrors {@see \PHPCompiler\ext\standard\VmIniQuantity}.
 *
 * php-src: Zend/zend_ini.c — zend_ini_parse_quantity
 */
final class IniParseQuantityRuntime
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_ini_parse_quantity');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLibc($context);
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $i8p);
        $fn = $context->module->addFunction('__compiler_ini_parse_quantity', $ft);
        self::implementParseQuantity($context, $fn);
        self::registerLinkedRuntime($context);
    }

    private static function ensureLibc(Context $context): void
    {
        foreach (['strtoll', 'strlen'] as $name) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $i8p = $context->getTypeFromString('int8*');
                $i64 = $context->getTypeFromString('int64');
                $i32 = $context->getTypeFromString('int32');
                $ft = match ($name) {
                    'strtoll' => $context->context->functionType($i64, false, $i8p, $i8p, $i32),
                    'strlen' => $context->context->functionType($i64, false, $i8p),
                    default => throw new \LogicException('unexpected libc fn'),
                };
                $context->registerFunction($name, $context->module->addFunction($name, $ft));
            }
        }
    }

    private static function implementParseQuantity(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('ini_qty_entry');
        $context->builder->positionAtEnd($entry);

        $str = $fn->getParam(0);
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'ini_qty_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);

        $parsed = $context->builder->call(
            $context->lookupFunction('strtoll'),
            $str,
            $endPtrSlot,
            $i32->constInt(0, false)
        );

        $len = $context->builder->call($context->lookupFunction('strlen'), $str);
        $lastIdx = $context->builder->sub($len, $i64->constInt(1, false));
        $lastCharPtr = $context->builder->inBoundsGep($str, $lastIdx);
        $lastChar = $context->builder->load($lastCharPtr);
        $i8 = $context->getTypeFromString('int8');

        $factorK = $i64->constInt(1 << 10, false);
        $factorM = $i64->constInt(1 << 20, false);
        $factorG = $i64->constInt(1 << 30, false);
        $factorOne = $i64->constInt(1, false);

        $isK = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $lastChar, $i8->constInt(ord('k'), false)),
            $context->builder->icmp(Builder::INT_EQ, $lastChar, $i8->constInt(ord('K'), false))
        );
        $isM = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $lastChar, $i8->constInt(ord('m'), false)),
            $context->builder->icmp(Builder::INT_EQ, $lastChar, $i8->constInt(ord('M'), false))
        );
        $isG = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $lastChar, $i8->constInt(ord('g'), false)),
            $context->builder->icmp(Builder::INT_EQ, $lastChar, $i8->constInt(ord('G'), false))
        );

        $factor = $context->builder->select($isK, $factorK, $factorOne);
        $factor = $context->builder->select($isM, $factorM, $factor);
        $factor = $context->builder->select($isG, $factorG, $factor);

        $hasSuffix = $context->builder->or($isK, $isM);
        $hasSuffix = $context->builder->or($hasSuffix, $isG);
        $factor = $context->builder->select($hasSuffix, $factor, $factorOne);

        $result = $context->builder->mul($parsed, $factor);
        $context->builder->return($result);
        $context->builder->clearInsertionPosition();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_ini_parse_quantity');
        if (null === $fn) {
            throw new \LogicException('__compiler_ini_parse_quantity missing after IniParseQuantityRuntime LLVM implement');
        }
        $context->registerFunction('__compiler_ini_parse_quantity', $fn);
    }
}
