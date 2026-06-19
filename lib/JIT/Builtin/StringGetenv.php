<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_getenv via GetenvJitHelper PHP overlay (#9092).
 *
 * PHP overlay via compiled helper; libc getenv on miss (thin trampoline).
 * php-src: ext/standard/basic_functions.c — zif_getenv
 */
final class StringGetenv
{
    private const HELPER_PATH = '/ext/standard/GetenvJitHelper.php';

    private const GETENV_HELPER = 'PHPCompiler\\ext\\standard\\GetenvJitHelper::getenv';

    private const PUTENV_HELPER = 'PHPCompiler\\ext\\standard\\GetenvJitHelper::putenv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GETENV_HELPER,
        self::PUTENV_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_getenv');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_getenv', $probe);

            return;
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            StringGetenvLibcBridge::implement($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementGetenvBridge($context);
        $context->builder->clearInsertionPosition();
    }

    public static function ensurePutenvLinked(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            StringGetenvLibcBridge::ensureLinked($context);

            return;
        }
        self::ensureJitHelperCompiled($context);
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GetenvJitHelper compile (#9092)');
        }

        return $fn;
    }

    public static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'GetenvJitHelper.php');
            if (null === $block) {
                throw new \LogicException('GetenvJitHelper.php parseAndCompile failed (#9092)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        } finally {
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9092)');
            }
        }
    }

    private static function implementGetenvBridge(Context $context): void
    {
        $fn = $context->lookupFunction('__compiler_getenv');
        $entry = $fn->appendBasicBlock('getenv_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $nameStr = $fn->getParam(0);
        $localOnly = $fn->getParam(1);
        $out = $fn->getParam(2);
        $valMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);

        $overlayPtr = $context->builder->call(
            self::helperFunction($context, self::GETENV_HELPER),
            $nameStr,
            $localOnly
        );
        $overlayType = $context->builder->load(
            $context->builder->structGep($overlayPtr, $valMap['type'])
        );
        $isFalse = $context->builder->icmp(
            Builder::INT_EQ,
            $overlayType,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );

        $overlayHit = $fn->appendBasicBlock('getenv_overlay_hit');
        $libcBb = $fn->appendBasicBlock('getenv_libc');
        $done = $fn->appendBasicBlock('getenv_done');
        $context->builder->branchIf($isFalse, $libcBb, $overlayHit);

        $context->builder->positionAtEnd($overlayHit);
        JitValueBox::copyIntoPointer($context, $out, $overlayPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($libcBb);
        $nameLen = $context->builder->load(
            $context->builder->structGep($nameStr, $context->structFieldMap['__string__']['length'])
        );
        $nameBytes = $context->builder->structGep($nameStr, $context->structFieldMap['__string__']['value']);
        $bufLen = $context->builder->add($nameLen, $i64->constInt(1, false));
        $nameBuf = $context->builder->alloca($i8, $bufLen, 'getenv_name');
        $nameCStr = $context->builder->pointerCast($nameBuf, $i8p);
        $context->intrinsic->memcpy($nameCStr, $nameBytes, $nameLen, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($nameCStr, $nameLen)
        );
        $env = $context->builder->call($context->lookupFunction('getenv'), $nameCStr);
        $envNull = $context->builder->icmp(Builder::INT_EQ, $env, $i8p->constNull());
        $missing = $fn->appendBasicBlock('getenv_missing');
        $found = $fn->appendBasicBlock('getenv_found');
        $context->builder->branchIf($envNull, $missing, $found);

        $context->builder->positionAtEnd($missing);
        $context->builder->store(
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false),
            $context->builder->structGep($out, $valMap['type'])
        );
        $valueField = $context->builder->structGep($out, $valMap['value']);
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $context->getTypeFromString('int32')->constInt(0, false),
            $zero
        );
        $context->builder->store($i8->constInt(0, false), $firstByte);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($found);
        $len = $context->builder->call($context->lookupFunction('strlen'), $env);
        $lenI64 = $len->typeOf() === $i64
            ? $len
            : $context->builder->zExt($len, $i64);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $env
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            $str
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->registerFunction('__compiler_getenv', $fn);
    }
}
