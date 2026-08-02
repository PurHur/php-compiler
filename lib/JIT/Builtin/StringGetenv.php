<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_getenv (#9092, #8992, #20156, #20644).
 *
 * Embed + thin standalone AOT: {@see GetenvLookupJitHelper} via {@see JitVmHelperLink}
 * (Rename #20603 shape — no thin libc ABI fork). NestedJIT leaf: {@see phpc_getenv_kernel}.
 * Putenv overlay helpers remain on {@see GetenvJitHelper} (ensurePutenvLinked).
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
            new \PHPCompiler\ext\standard\phpc_getenv_kernel(),
            new \PHPCompiler\ext\standard\phpc_putenv_kernel(),
            new \PHPCompiler\ext\standard\phpc_native_environ_mirror_into_ht(),
        ];
        foreach ($internals as $internal) {
            $lc = strtolower($internal->getName());
            $existing = $context->functionProxies[$lc] ?? null;
            if (null === $existing || $existing instanceof \PHPCompiler\JIT\Call\ExternalMethod) {
                $context->functionProxies[$lc] = $internal;
            }
        }
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
