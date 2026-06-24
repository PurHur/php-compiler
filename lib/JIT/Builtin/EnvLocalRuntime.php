<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_env_local_* via EnvLocalJitHelper PHP (#9814).
 *
 * Replaces {@see StringEnvLocal} LLVM overlay table (phpc_env_local_entries).
 * SSOT: {@see \PHPCompiler\ext\standard\GetenvJitHelper}
 * php-src: ext/standard/basic_functions.c — zif_putenv, zif_getenv
 */
final class EnvLocalRuntime
{
    private const HELPER_PATH = '/ext/standard/EnvLocalJitHelper.php';

    private const LOOKUP_HELPER = 'PHPCompiler\\ext\\standard\\EnvLocalJitHelper::lookupOverlay';

    private const REGISTER_HELPER = 'PHPCompiler\\ext\\standard\\EnvLocalJitHelper::registerPutenv';

    private const MERGE_HELPER = 'PHPCompiler\\ext\\standard\\EnvLocalJitHelper::mergeOverlay';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LOOKUP_HELPER,
        self::REGISTER_HELPER,
        self::MERGE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_env_local_lookup',
        '__compiler_env_register_putenv',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_env_local_lookup');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            EnvLocalStandaloneLlvm::implement($context);
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLibc($context);
        self::ensureJitHelperCompiled($context);
        self::implementLookupBridge($context);
        self::implementRegisterBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    public static function emitMergeOverlay(Context $context, Value $ht): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            EnvLocalStandaloneLlvm::emitMergeOverlay($context, $ht);

            return;
        }

        self::ensureJitHelperCompiled($context);
        $context->builder->call(
            self::helperFunction($context, self::MERGE_HELPER),
            $ht
        );
    }

    private static function implementLookupBridge(Context $context): void
    {
        $abiName = '__compiler_env_local_lookup';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($i8p, false, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('el_lookup_entry');
        $context->builder->positionAtEnd($entry);

        $nameCstr = $fn->getParam(0);
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $strMap = $context->structFieldMap['__string__'];
        $valMap = $context->structFieldMap['__value__'];
        $null = $i8p->constNull();

        $nameNull = $context->builder->icmp(Builder::INT_EQ, $nameCstr, $null);
        $missBb = $fn->appendBasicBlock('el_lookup_miss');
        $bodyBb = $fn->appendBasicBlock('el_lookup_body');
        $context->builder->branchIf($nameNull, $missBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $nameLen = $context->builder->call($context->lookupFunction('strlen'), $nameCstr);
        $nameLenI64 = $nameLen->typeOf() === $i64
            ? $nameLen
            : $context->builder->zExt($nameLen, $i64);
        $nameStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $nameLenI64,
            $nameCstr
        );
        $overlayBox = $context->builder->call(
            self::helperFunction($context, self::LOOKUP_HELPER),
            $nameStr
        );
        $overlayType = $context->builder->load(
            $context->builder->structGep($overlayBox, $valMap['type'])
        );
        $isMiss = $context->builder->icmp(
            Builder::INT_EQ,
            $overlayType,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $hitBb = $fn->appendBasicBlock('el_lookup_hit');
        $context->builder->branchIf($isMiss, $missBb, $hitBb);

        $context->builder->positionAtEnd($hitBb);
        $valueStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $overlayBox
        );
        $valueLen = $context->builder->load(
            $context->builder->structGep($valueStr, $strMap['length'])
        );
        $valueBytes = $context->builder->structGep($valueStr, $strMap['value']);
        $bufLen = $context->builder->add($valueLen, $i64->constInt(1, false));
        $dup = $context->builder->call($context->lookupFunction('malloc'), $bufLen);
        $dupNull = $context->builder->icmp(Builder::INT_EQ, $dup, $null);
        $dupFailBb = $fn->appendBasicBlock('el_lookup_dup_fail');
        $dupOkBb = $fn->appendBasicBlock('el_lookup_dup_ok');
        $doneBb = $fn->appendBasicBlock('el_lookup_done');
        $context->builder->branchIf($dupNull, $dupFailBb, $dupOkBb);

        $context->builder->positionAtEnd($dupOkBb);
        $context->intrinsic->memcpy($dup, $valueBytes, $valueLen, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($dup, $valueLen)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($dupFailBb);
        $context->builder->branch($missBb);

        $context->builder->positionAtEnd($missBb);
        $context->builder->returnValue($null);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnValue($dup);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementRegisterBridge(Context $context): void
    {
        $abiName = '__compiler_env_register_putenv';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($voidTy, false, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('el_reg_entry');
        $skipBb = $fn->appendBasicBlock('el_reg_skip');
        $bodyBb = $fn->appendBasicBlock('el_reg_body');
        $context->builder->positionAtEnd($entry);

        $settingCstr = $fn->getParam(0);
        $null = $i8p->constNull();
        $settingNull = $context->builder->icmp(Builder::INT_EQ, $settingCstr, $null);
        $context->builder->branchIf($settingNull, $skipBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->call($context->lookupFunction('strlen'), $settingCstr);
        $lenI64 = $len->typeOf() === $i64
            ? $len
            : $context->builder->zExt($len, $i64);
        $settingStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $settingCstr
        );
        $context->builder->call(
            self::helperFunction($context, self::REGISTER_HELPER),
            $settingStr
        );
        $context->builder->branch($skipBb);

        $context->builder->positionAtEnd($skipBb);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function ensureLibc(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $voidPtr = $context->getTypeFromString('void*');

        foreach ([
            ['strlen', $i64, [$i8p]],
            ['malloc', $voidPtr, [$i64]],
            ['__string__init', $context->getTypeFromString('__string__*'), [$i64, $i8p]],
            ['__value__readString', $context->getTypeFromString('__string__*'), [$context->getTypeFromString('__value__*')]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after EnvLocalJitHelper compile (#9814)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
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

        StringGetenv::ensureJitHelperCompiled($context);

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'EnvLocalJitHelper.php');
            if (null === $block) {
                throw new \LogicException('EnvLocalJitHelper.php parseAndCompile failed (#9814)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9814)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after EnvLocalRuntime bridge (#9814)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
