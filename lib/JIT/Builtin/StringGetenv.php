<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_getenv (#9092, #8992, #20156, #20644, #29313).
 *
 * Embed + thin standalone AOT: {@see GetenvLookupJitHelper} via {@see JitVmHelperLink}
 * (Rename #20603 shape — no thin libc ABI fork). NestedJIT leaf: libc getenv(3)
 * via {@see invokeNestedLeaf} (chdir #29219 shape — kernel deleted).
 * Putenv overlay helpers remain on {@see GetenvJitHelper} (ensurePutenvLinked).
 * Putenv NestedJIT leaf: libc setenv/unsetenv via {@see invokePutenvNestedLeaf} (#29334);
 * strchr(3) is module-local after LibcExtern always-on drop (#31519);
 * setenv(3)/unsetenv(3) are module-local after LibcExtern always-on drop (#31558).
 * php-src: ext/standard/basic_functions.c — zif_getenv
 */
final class StringGetenv
{
    private const LOOKUP_HELPER_PATH = '/ext/standard/GetenvLookupJitHelper.php';

    private const LOOKUP_HELPER = 'PHPCompiler\\ext\\standard\\GetenvLookupJitHelper::fromEnviron';

    private const OVERLAY_HELPER_PATH = '/ext/standard/GetenvJitHelper.php';

    private const PUTENV_HELPER_PATH = '/ext/standard/PutenvJitHelper.php';

    private const PUTENV_HELPER = 'PHPCompiler\\ext\\standard\\PutenvJitHelper::putenv';

    private const APACHE_SETENV_HELPER = 'PHPCompiler\\ext\\standard\\GetenvJitHelper::apacheSetenv';

    private const OVERLAY_GETENV_HELPER = 'PHPCompiler\\ext\\standard\\GetenvJitHelper::getenv';

    private const ABI_NAME = '__compiler_getenv';

    private const BRIDGE_ENTRY = 'getenv_bridge_entry';

    /** @var list<string> */
    private const LOOKUP_COMPILED = [
        self::LOOKUP_HELPER,
    ];

    /** @var list<string> */
    private const OVERLAY_COMPILED = [
        self::OVERLAY_GETENV_HELPER,
        self::APACHE_SETENV_HELPER,
    ];

    /** Slim putenv NestedJIT leaf (#23414) — separate TU from GetenvJitHelper. */
    private const PUTENV_COMPILED = [
        self::PUTENV_HELPER,
    ];

    /** @var list<string> */
    private const APACHE_SETENV_COMPILED = [
        self::APACHE_SETENV_HELPER,
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
        // NestedJIT must not recurse into GetenvLookupJitHelper; helper-runtime .o is OK (#23970).
        // Do not treat Type.php's empty declaration (countBasicBlocks>0, no terminator) as a
        // completed body — that left U __compiler_getenv at argv-driver link (#26756).
        if (NestedJitCompileScope::isActive() && !\PHPCompiler\AOT\HelperRuntimeCache::enabled()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureLookupHelperCompiled($context);
        self::implementGetenvBridge($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    public static function ensurePutenvLinked(Context $context): void
    {
        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::PUTENV_HELPER_PATH,
            self::PUTENV_COMPILED,
            '#23414'
        );
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        if (self::LOOKUP_HELPER === $logical) {
            self::ensureLookupHelperCompiled($context);
        } elseif (self::PUTENV_HELPER === $logical) {
            self::ensurePutenvLinked($context);
        } elseif (self::APACHE_SETENV_HELPER === $logical) {
            self::ensureApacheSetenvLinked($context);
        } else {
            self::ensureOverlayHelperCompiled($context);
        }

        return JitVmHelperLink::lookupCompiled($context, $logical, '#20644');
    }

    public static function ensureLookupHelperCompiled(Context $context): void
    {
        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::LOOKUP_HELPER_PATH,
            self::LOOKUP_COMPILED,
            '#20644'
        );
    }

    public static function ensureOverlayHelperCompiled(Context $context): void
    {
        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::OVERLAY_HELPER_PATH,
            self::OVERLAY_COMPILED,
            '#20644'
        );
    }

    public static function ensureApacheSetenvLinked(Context $context): void
    {
        self::ensurePutenvLinked($context);
        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            [self::PUTENV_HELPER_PATH, self::OVERLAY_HELPER_PATH],
            self::APACHE_SETENV_COMPILED,
            '#23414'
        );
    }

    public static function ensureJitHelperCompiled(Context $context): void
    {
        self::ensureLookupHelperCompiled($context);
    }

    public static function ensureNativeHtInternalProxies(Context $context): void
    {
        $internals = [
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key(),
        ];
        foreach ($internals as $internal) {
            $lc = strtolower($internal->getName());
            $existing = $context->functionProxies[$lc] ?? null;
            if (null === $existing || $existing instanceof \PHPCompiler\JIT\Call\ExternalMethod) {
                $context->functionProxies[$lc] = $internal;
            }
        }
    }

    /**
     * NestedJIT libc setenv/unsetenv leaf for putenv() (#29334 / #23414).
     *
     * Used while NestedJIT compiles {@see PutenvJitHelper} `@putenv` so the helper
     * bridge is not re-entered (getenv #29313 / chdir #29219 shape).
     * Takes the full "NAME=value" assignment (or "NAME" for unset).
     */
    public static function invokePutenvNestedLeaf(Context $context, Value $assignmentStr): void
    {
        LibcExtern::register($context);
        self::ensureLibcStrchr($context);
        self::ensureLibcSetenvUnsetenv($context);
        $map = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);

        $len = $context->builder->load(
            $context->builder->structGep($assignmentStr, $map['length'])
        );
        $bytes = $context->builder->structGep($assignmentStr, $map['value']);
        $lenSize = $len->typeOf() === $sizeT
            ? $len
            : $context->builder->truncOrBitCast($len, $sizeT);
        $bufLen = $context->builder->add($lenSize, $one);
        $buf = $context->builder->call($context->lookupFunction('malloc'), $bufLen);
        $cStr = $context->builder->pointerCast($buf, $i8p);
        $context->intrinsic->memcpy($cStr, $bytes, $len, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($cStr, $len)
        );

        $eq = $context->builder->call(
            $context->lookupFunction('strchr'),
            $cStr,
            $i32->constInt(ord('='), false)
        );
        $hasEq = $context->builder->icmp(Builder::INT_NE, $eq, $i8p->constNull());
        $setBb = BasicBlockHelper::append($context, 'putenv_nested_setenv');
        $unsetBb = BasicBlockHelper::append($context, 'putenv_nested_unsetenv');
        $doneBb = BasicBlockHelper::append($context, 'putenv_nested_done');
        $context->builder->branchIf($hasEq, $setBb, $unsetBb);

        $context->builder->positionAtEnd($setBb);
        $context->builder->store($i8->constInt(0, false), $eq);
        $valueStart = $context->builder->inBoundsGEP($eq, $one);
        $context->builder->call(
            $context->lookupFunction('setenv'),
            $cStr,
            $valueStart,
            $i32->constInt(1, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($unsetBb);
        $context->builder->call(
            $context->lookupFunction('unsetenv'),
            $cStr
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->call($context->lookupFunction('free'), $cStr);
    }

    /**
     * Module-local strchr(3) after LibcExtern always-on drop (#31519).
     */
    private static function ensureLibcStrchr(Context $context): void
    {
        try {
            $context->lookupFunction('strchr');
        } catch (\Throwable) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $fn = $context->module->addFunction(
                'strchr',
                $context->context->functionType($i8p, false, $i8p, $i32)
            );
            $context->registerFunction('strchr', $fn);
        }
    }

    /**
     * Module-local setenv(3)/unsetenv(3) after LibcExtern always-on drop (#31558).
     */
    private static function ensureLibcSetenvUnsetenv(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        try {
            $context->lookupFunction('setenv');
        } catch (\Throwable) {
            $fn = $context->module->addFunction(
                'setenv',
                $context->context->functionType($i32, false, $i8p, $i8p, $i32)
            );
            $context->registerFunction('setenv', $fn);
        }
        try {
            $context->lookupFunction('unsetenv');
        } catch (\Throwable) {
            $fn = $context->module->addFunction(
                'unsetenv',
                $context->context->functionType($i32, false, $i8p)
            );
            $context->registerFunction('unsetenv', $fn);
        }
    }

    /**
     * NestedJIT libc getenv(3) leaf — returns owned {@see __string__*} or null (#29313).
     *
     * Used while NestedJIT compiles {@see GetenvLookupJitHelper} / PendingHeaders `@getenv`
     * so the helper bridge is not re-entered (chdir #29219 shape).
     */
    public static function invokeNestedLeaf(Context $context, Value $nameStr): Value
    {
        LibcExtern::register($context);

        $fn = $context->builder->getInsertBlock()->getParent();
        $strMap = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');

        $hitBb = $fn->appendBasicBlock('getenv_nested_libc_hit');
        $missBb = $fn->appendBasicBlock('getenv_nested_libc_miss');
        $doneBb = $fn->appendBasicBlock('getenv_nested_libc_done');

        $nameBytes = $context->builder->structGep($nameStr, $strMap['value']);
        $envRaw = $context->builder->call($context->lookupFunction('getenv'), $nameBytes);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $envRaw, $i8p->constNull());
        $context->builder->branchIf($isNull, $missBb, $hitBb);

        $context->builder->positionAtEnd($hitBb);
        $len = $context->builder->call($context->lookupFunction('strlen'), $envRaw);
        $lenI64 = $len->typeOf() === $i64
            ? $len
            : $context->builder->zExt($len, $i64);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $envRaw
        );
        $hitEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($missBb);
        $nullStr = $strPtrTy->constNull();
        $missEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtrTy, 'getenv_nested_libc_result');
        $phi->addIncoming($owned, $hitEnd);
        $phi->addIncoming($nullStr, $missEnd);

        return $phi;
    }

    private static function implementGetenvBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $i8, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);

        $nameStr = $fn->getParam(0);
        $localOnly = $fn->getParam(1);
        $out = $fn->getParam(2);

        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LOOKUP_HELPER),
            [$nameStr, $localOnly]
        );
        $isMissing = JitNestedHelperCoerce::isHelperResultNull($context, $raw);

        $hit = $fn->appendBasicBlock('getenv_hit');
        $missing = $fn->appendBasicBlock('getenv_missing');
        $done = $fn->appendBasicBlock('getenv_done');
        $context->builder->branchIf($isMissing, $missing, $hit);

        $context->builder->positionAtEnd($hit);
        $str = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            $str
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($missing);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->registerFunction(self::ABI_NAME, $fn);
    }
}
