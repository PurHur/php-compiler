<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\hash\JitHashAlgosKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_hash_hmac_algos via HashAlgosJitHelper PHP (#18908, #19355, #20050).
 *
 * Embed / non-thin: {@see HashAlgosJitHelper} via {@see JitVmHelperLink}.
 * Thin standalone AOT main: {@see JitHashAlgosKernel} registry (#20028 Rename shape).
 * SSOT: {@see \PHPCompiler\ext\standard\VmHash::hmacAlgos()}
 * php-src: ext/hash/hash.c — php_hash_hmac_algos()
 */
final class StringHashHmacAlgos
{
    private const ABI_HASH_HMAC_ALGOS = '__compiler_hash_hmac_algos';

    private const HELPER_PATH = '/ext/hash/HashAlgosJitHelper.php';

    private const HMAC_ALGOS_HELPER = 'PHPCompiler\\ext\\hash\\HashAlgosJitHelper::hmacAlgosArgv';

    private const KERNEL_ENTRY = 'hash_hmac_algos_kernel_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HMAC_ALGOS_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_HASH_HMAC_ALGOS);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'hash_hmac_algos_bridge_entry')
            || JitVmHelperLink::hasNamedBridgeEntry($probe, self::KERNEL_ENTRY)) {
            $context->registerFunction(self::ABI_HASH_HMAC_ALGOS, $probe);

            return;
        }

        if ($context->isThinStandaloneAotMain()) {
            self::implementThinKernel($context, $probe);

            return;
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#20050');
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::HMAC_ALGOS_HELPER, '#20050');

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_HASH_HMAC_ALGOS,
                $context->context->functionType($htPtr, false)
            );
        self::implementBridge($context, $fn, $helperFn);
        $context->registerFunction(self::ABI_HASH_HMAC_ALGOS, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBridge(Context $context, LlvmFunction $fn, LlvmFunction $helperFn): void
    {
        $entry = $fn->appendBasicBlock('hash_hmac_algos_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $htRaw = $context->builder->call($helperFn);
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $context->builder->returnValue($ht);
    }

    private static function implementThinKernel(Context $context, ?LlvmFunction $probe): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_HASH_HMAC_ALGOS,
                $context->context->functionType($htPtr, false)
            );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::KERNEL_ENTRY);
        $context->builder->positionAtEnd($entry);
        JitHashAlgosKernel::emitHmacAlgosBody($context, $fn);
        $context->registerFunction(self::ABI_HASH_HMAC_ALGOS, $fn);
    }
}
