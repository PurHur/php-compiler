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
 * JIT/AOT link for __compiler_hash_hmac_algos via HashAlgosJitHelper PHP (#18908, #19355).
 *
 * Embed / non-user-script: {@see HashAlgosJitHelper} via {@see JitVmHelperLink}.
 * User-script standalone AOT: thin {@see JitHashAlgosKernel} registry —
 * nested helper TUs skip __init__ under PHP_COMPILER_AOT_USER_SCRIPT (#16075 / #3357).
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
        $probe = $context->module->getNamedFunction(self::ABI_HASH_HMAC_ALGOS);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_HASH_HMAC_ALGOS, $probe);

            return;
        }

        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            self::implementUserScriptKernel($context, $probe);

            return;
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#18908');
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::HMAC_ALGOS_HELPER, '#18908');

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

    private static function implementUserScriptKernel(Context $context, ?LlvmFunction $probe): void
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
