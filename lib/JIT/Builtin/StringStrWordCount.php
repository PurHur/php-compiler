<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for str_word_count() via StrWordCountJitHelper PHP (#14651).
 *
 * Replaces ~419-line LLVM in ext/standard/JitStrWordCount.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_word_count)
 */
final class StringStrWordCount
{
    private const HELPER_PATH = '/ext/standard/StrWordCountJitHelper.php';

    private const COUNT_HELPER = 'PHPCompiler\\ext\\standard\\StrWordCountJitHelper::countArgv';

    private const WORDS_HELPER = 'PHPCompiler\\ext\\standard\\StrWordCountJitHelper::wordsArgv';

    private const ABI_COUNT = 'phpc_str_word_count_count';

    private const ABI_WORDS = 'phpc_str_word_count_words';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COUNT_HELPER,
        self::WORDS_HELPER,
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

        $countProbe = $context->module->getNamedFunction(self::ABI_COUNT);
        if (null !== $countProbe && $countProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COUNT,
            'str_word_count_count_bridge_entry',
            [$strPtr],
            $i64,
            self::COUNT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14651'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_WORDS,
            'str_word_count_words_bridge_entry',
            [$strPtr, $i64, $strPtr],
            $htPtr,
            self::WORDS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14651'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * Build a compile-time __hashtable__ from VM word list / offset map (formats 1 and 2).
     */
    public static function hashTableFromVmResult(Context $context, array $result, int $format): Value
    {
        $ht = new HashTable();
        if (1 === $format) {
            foreach ($result as $word) {
                $value = new Variable();
                $value->string($word);
                $ht->append($value);
            }
        } else {
            foreach ($result as $pos => $word) {
                $value = new Variable();
                $value->string($word);
                $ht->addIndex((int) $pos, $value);
            }
        }
        $jit = HashTableHelper::variableFromVmHashTable($context, $ht);

        return $jit->value;
    }

    /**
     * Z_PARAM_LONG $format — strict null → TypeError; soft null → 0 (#31287).
     *
     * Prefer {@see \PHPCompiler\ext\standard\JitSleep::zParamLong} at call sites;
     * kept for NestedJIT / legacy callers.
     */
    public static function jitFormatArg(Context $context, JITVariable $arg): Value
    {
        return \PHPCompiler\ext\standard\JitSleep::zParamLong(
            $context,
            $arg,
            'str_word_count',
            2,
            'format'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([self::ABI_COUNT, self::ABI_WORDS] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StringStrWordCount bridge (#14651)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
