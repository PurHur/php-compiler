<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\hash\JitHashAlgosKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_hash_algos via HashAlgosJitHelper PHP (#14909, #19355).
 *
 * Embed / non-user-script: {@see HashAlgosJitHelper} via {@see JitVmHelperLink}.
 * User-script standalone AOT: thin {@see JitHashAlgosKernel} registry —
 * nested helper TUs skip __init__ under PHP_COMPILER_AOT_USER_SCRIPT (#16075 / #3357).
 * SSOT: {@see \PHPCompiler\ext\standard\VmHash::algos()}
 * php-src: ext/hash/hash.c — php_hash_algos()
 */
final class StringHashAlgos
{
    private const ABI_HASH_ALGOS = '__compiler_hash_algos';

    private const HELPER_PATH = '/ext/hash/HashAlgosJitHelper.php';

    private const ALGOS_HELPER = 'PHPCompiler\\ext\\hash\\HashAlgosJitHelper::algosArgv';

    private const KERNEL_ENTRY = 'hash_algos_kernel_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ALGOS_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_HASH_ALGOS);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_HASH_ALGOS, $probe);

            return;
        }

        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            self::implementUserScriptKernel($context, $probe);

            return;
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#14909');
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::ALGOS_HELPER, '#14909');

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_HASH_ALGOS,
                $context->context->functionType($htPtr, false)
            );
        self::implementBridge($context, $fn, $helperFn);
        $context->registerFunction(self::ABI_HASH_ALGOS, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBridge(Context $context, LlvmFunction $fn, LlvmFunction $helperFn): void
    {
        $entry = $fn->appendBasicBlock('hash_algos_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $htRaw = $context->builder->call($helperFn);
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $context->builder->returnValue($ht);
    }

    private static function implementUserScriptKernel(Context $context, ?LlvmFunction $probe): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_HASH_ALGOS,
                $context->context->functionType($htPtr, false)
            );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::KERNEL_ENTRY);
        $context->builder->positionAtEnd($entry);
        JitHashAlgosKernel::emitAlgosBody($context, $fn);
        $context->registerFunction(self::ABI_HASH_ALGOS, $fn);
    }
}
