<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_hash_algos via HashAlgosJitHelper PHP (#14909).
 *
 * Replaces ~88 LOC inline LLVM registry walk.
 * SSOT: {@see \PHPCompiler\ext\standard\VmHash::algos()}
 * php-src: ext/hash/hash.c — php_hash_algos()
 */
final class StringHashAlgos
{
    private const ABI_HASH_ALGOS = '__compiler_hash_algos';

    private const HELPER_PATH = '/ext/hash/HashAlgosJitHelper.php';

    private const ALGOS_HELPER = 'PHPCompiler\\ext\\hash\\HashAlgosJitHelper::algosArgv';

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
}
