<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitGetenvKernel;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_getenv via GetenvJitHelper PHP overlay (#9092, #8992, #20156).
 *
 * Embed / non-thin: {@see GetenvJitHelper} via {@see JitVmHelperLink} (putenv overlay).
 * Thin standalone AOT (`isThinStandaloneAotMain`, #20011 / #20141 shape): {@see JitGetenvKernel}
 * libc getenv — NestedJIT of GetenvJitHelper cannot see process environ under user-script AOT (#16075).
 * php-src: ext/standard/basic_functions.c — zif_getenv
 */
final class StringGetenv
{
    private const HELPER_PATH = '/ext/standard/GetenvJitHelper.php';

    private const GETENV_HELPER = 'PHPCompiler\\ext\\standard\\GetenvJitHelper::getenv';

    private const PUTENV_HELPER = 'PHPCompiler\\ext\\standard\\GetenvJitHelper::putenv';

    private const APACHE_SETENV_HELPER = 'PHPCompiler\\ext\\standard\\GetenvJitHelper::apacheSetenv';

    private const ABI_NAME = '__compiler_getenv';

    private const BRIDGE_ENTRY = 'getenv_bridge_entry';

    private const KERNEL_ENTRY = 'getenv_kernel_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GETENV_HELPER,
        self::PUTENV_HELPER,
        self::APACHE_SETENV_HELPER,
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
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)
            || JitVmHelperLink::hasNamedBridgeEntry($probe, self::KERNEL_ENTRY)) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        if ($context->isThinStandaloneAotMain()) {
            self::implementKernelBody($context, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::implementGetenvBridge($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    public static function ensurePutenvLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#20156');
    }

    public static function ensureJitHelperCompiled(Context $context): void
    {
        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20156'
        );
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

    private static function implementKernelBody(Context $context, ?LlvmFunction $probe): void
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $i8, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::KERNEL_ENTRY);
        $context->builder->positionAtEnd($entry);
        JitGetenvKernel::emitBody($context, $fn);
        $context->registerFunction(self::ABI_NAME, $fn);

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementGetenvBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
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
            self::helperFunction($context, self::GETENV_HELPER),
            [$nameStr, $localOnly]
        );
        $overlayPtr = JitNestedHelperCoerce::valueBoxPtrFromHelperResult($context, $raw);
        $isMissing = JitNestedHelperCoerce::isHelperResultNull($context, $raw);

        $overlayHit = $fn->appendBasicBlock('getenv_overlay_hit');
        $missing = $fn->appendBasicBlock('getenv_missing');
        $done = $fn->appendBasicBlock('getenv_done');
        $context->builder->branchIf($isMissing, $missing, $overlayHit);

        $context->builder->positionAtEnd($overlayHit);
        JitValueBox::copyIntoPointer($context, $out, $overlayPtr);
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
