<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __superglobals__mirror_process_environ (#18984, #21579, #30225).
 *
 * Embed + thin standalone AOT: NestedJIT {@see EnvironMirrorNativeJitHelper} via
 * {@see JitVmHelperLink} (Rename #20603 / getenv #20758 shape — no thin libc fork).
 * Callers ({@see StringGetenvAll}, {@see \PHPCompiler\ext\standard\JitSuperglobalRefreshKernel})
 * share this ABI — no duplicate inline kernel in getenv_all.
 * SSOT: {@see \PHPCompiler\Web\Superglobals::applyProcessEnvironMirror()}
 * php-src: sapi/cli/php_cli.c
 */
final class EnvironMirrorRuntime
{
    private const ABI = '__superglobals__mirror_process_environ';

    public const HELPER_PATH = '/ext/standard/EnvironMirrorNativeJitHelper.php';

    public const MIRROR_HELPER = 'PHPCompiler\\ext\\standard\\EnvironMirrorNativeJitHelper::mirrorProcessEnvironNative';

    /** @var list<string> */
    public const COMPILED_HELPERS = [
        self::MIRROR_HELPER,
    ];

    private const BRIDGE_ENTRY = 'environ_mirror_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function emitFillCall(Context $context, Value $serverHt): void
    {
        self::ensureLinked($context);
        $context->builder->call($context->lookupFunction(self::ABI), $serverHt);
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        self::implementEmbedBridge($context, $probe);
    }

    private static function implementEmbedBridge(Context $context, ?LlvmFunction $probe): void
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18984'
        );

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $void = $context->getTypeFromString('void');
        $fn = null !== $probe && $probe->countBasicBlocks() > 0
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($void, false, $htPtr)
            );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        $early = $fn->appendBasicBlock('environ_mirror_early');
        $work = $fn->appendBasicBlock('environ_mirror_work');
        $context->builder->positionAtEnd($entry);

        $dest = $fn->getParam(0);
        $nullDest = $context->builder->icmp(Builder::INT_EQ, $dest, $htPtr->constNull());
        $context->builder->branchIf($nullDest, $early, $work);

        $context->builder->positionAtEnd($early);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $helperFn = JitVmHelperLink::lookupCompiled(
            $context,
            self::MIRROR_HELPER,
            '#18984'
        );
        $destI64 = JitNestedHelperCoerce::ptrToI64($context, $dest);
        $i64 = $context->getTypeFromString('int64');
        $destSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($destI64, $destSlot);
        $destArg = $context->builder->load($destSlot);
        JitNestedHelperCoerce::callHelper($context, $helperFn, [$destArg]);
        $context->builder->returnVoid();

        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** Register phpc_native_ht_* Internal JIT handlers before nested environ-mirror compile (#19157). */
    private static function ensureNativeHtInternalProxies(Context $context): void
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
}
