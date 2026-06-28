<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT embed link for __compiler_preg_* via PregJitHelper PHP (#9542).
 *
 * Standalone AOT preg_* (except preg_replace_callback) uses this PHP bridge (#9542, #12982).
 * preg_replace_callback still uses a thin LLVM slice from {@see StringPregMatchStandaloneLlvm}.
 * php-src: ext/pcre/php_pcre.c
 */
final class PregMatchRuntime
{
    private const HELPER_PATH = '/ext/standard/PregJitHelper.php';

    private const LAST_ERROR_HELPER = 'PHPCompiler\\ext\\standard\\PregJitHelper::lastError';

    private const LAST_ERROR_MSG_HELPER = 'PHPCompiler\\ext\\standard\\PregJitHelper::lastErrorMsg';

    private const MATCH_HELPER = 'PHPCompiler\\ext\\standard\\PregJitHelper::matchArgv';

    private const MATCH_ALL_HELPER = 'PHPCompiler\\ext\\standard\\PregJitHelper::matchAllArgv';

    private const MATCH_EX_HELPER = 'PHPCompiler\\ext\\standard\\PregJitHelper::matchExArgv';

    private const TAKE_MATCH_EX_HT = 'PHPCompiler\\ext\\standard\\PregJitHelper::takeLastMatchExHashTable';

    private const MATCH_ALL_EX_HELPER = 'PHPCompiler\\ext\\standard\\PregJitHelper::matchAllExArgv';

    private const TAKE_MATCH_ALL_EX_HT = 'PHPCompiler\\ext\\standard\\PregJitHelper::takeLastMatchAllExHashTable';

    private const REPLACE_HELPER = 'PHPCompiler\\ext\\standard\\PregJitHelper::replaceArgv';

    private const SPLIT_HELPER = 'PHPCompiler\\ext\\standard\\PregJitHelper::splitArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LAST_ERROR_HELPER,
        self::LAST_ERROR_MSG_HELPER,
        self::MATCH_HELPER,
        self::MATCH_ALL_HELPER,
        self::MATCH_EX_HELPER,
        self::TAKE_MATCH_EX_HT,
        self::MATCH_ALL_EX_HELPER,
        self::TAKE_MATCH_ALL_EX_HT,
        self::REPLACE_HELPER,
        self::SPLIT_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_preg_last_error',
        '__compiler_preg_last_error_msg',
        '__compiler_preg_match',
        '__compiler_preg_match_ex',
        '__compiler_preg_match_all',
        '__compiler_preg_match_all_ex',
        '__compiler_preg_replace',
        '__compiler_preg_replace_callback',
        '__compiler_preg_split',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_preg_match');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureRuntimeHelpers($context);
        self::ensureJitHelperCompiled($context);
        self::implementLastErrorBridge($context);
        self::implementLastErrorMsgBridge($context);
        self::implementI64PairBridge($context, '__compiler_preg_match', self::MATCH_HELPER);
        self::implementI64PairBridge($context, '__compiler_preg_match_all', self::MATCH_ALL_HELPER);
        self::implementMatchExBridge($context, '__compiler_preg_match_ex', self::MATCH_EX_HELPER, self::TAKE_MATCH_EX_HT);
        self::implementMatchExBridge($context, '__compiler_preg_match_all_ex', self::MATCH_ALL_EX_HELPER, self::TAKE_MATCH_ALL_EX_HT);
        self::implementReplaceBridge($context);
        self::implementSplitBridge($context);
        StringPregMatchStandaloneLlvm::implementReplaceCallbackOnly($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementLastErrorBridge(Context $context): void
    {
        $abiName = '__compiler_preg_last_error';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $context->context->functionType($i64, false));
        $entry = $fn->appendBasicBlock('preg_last_error_entry');
        $context->builder->positionAtEnd($entry);
        $raw = $context->builder->call(self::helperFunction($context, self::LAST_ERROR_HELPER));
        $context->builder->returnValue(JitNestedHelperCoerce::scalarToI64($context, $raw, $raw->typeOf()));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementLastErrorMsgBridge(Context $context): void
    {
        $abiName = '__compiler_preg_last_error_msg';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $context->context->functionType($strPtr, false));
        $entry = $fn->appendBasicBlock('preg_last_error_msg_entry');
        $context->builder->positionAtEnd($entry);
        $raw = $context->builder->call(self::helperFunction($context, self::LAST_ERROR_MSG_HELPER));
        $context->builder->returnValue(JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementI64PairBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $strPtr, $strPtr)
            );
        $entry = $fn->appendBasicBlock('preg_i64_pair_entry');
        $context->builder->positionAtEnd($entry);
        $raw = $context->builder->call(
            self::helperFunction($context, $helperLogical),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue(JitNestedHelperCoerce::scalarToI64($context, $raw, $raw->typeOf()));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementMatchExBridge(
        Context $context,
        string $abiName,
        string $matchHelper,
        string $takeHtHelper
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $negOne = $i64->constInt(-1, true);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $strPtr, $strPtr, $valuePtr, $i64, $i64)
            );

        $entry = $fn->appendBasicBlock('preg_match_ex_entry');
        $failBb = $fn->appendBasicBlock('preg_match_ex_fail');
        $okBb = $fn->appendBasicBlock('preg_match_ex_ok');
        $context->builder->positionAtEnd($entry);
        $count = $context->builder->call(
            self::helperFunction($context, $matchHelper),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(3),
            $fn->getParam(4)
        );
        $countI64 = JitNestedHelperCoerce::scalarToI64($context, $count, $count->typeOf());
        $isError = $context->builder->icmp(Builder::INT_EQ, $countI64, $negOne);
        $context->builder->branchIf($isError, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $fn->getParam(2)
        );
        $context->builder->returnValue($negOne);

        $context->builder->positionAtEnd($okBb);
        $ht = $context->builder->call(self::helperFunction($context, $takeHtHelper));
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $emptyBb = $fn->appendBasicBlock('preg_match_ex_empty_ht');
        $writeBb = $fn->appendBasicBlock('preg_match_ex_write_ht');
        $context->builder->branchIf($htNull, $emptyBb, $writeBb);

        $context->builder->positionAtEnd($emptyBb);
        $emptyHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $fn->getParam(2),
            $emptyHt
        );
        $context->builder->returnValue($countI64);

        $context->builder->positionAtEnd($writeBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $fn->getParam(2),
            $ht
        );
        $context->builder->returnValue($countI64);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementReplaceBridge(Context $context): void
    {
        $abiName = '__compiler_preg_replace';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $i64)
            );
        $entry = $fn->appendBasicBlock('preg_replace_entry');
        $failBb = $fn->appendBasicBlock('preg_replace_fail');
        $okBb = $fn->appendBasicBlock('preg_replace_ok');
        $context->builder->positionAtEnd($entry);
        $raw = $context->builder->call(
            self::helperFunction($context, self::REPLACE_HELPER),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2),
            $fn->getParam(3)
        );
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $context->builder->branchIf($isNull, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue(JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementSplitBridge(Context $context): void
    {
        $abiName = '__compiler_preg_split';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($htPtr, false, $strPtr, $strPtr, $i64, $i64)
            );
        $entry = $fn->appendBasicBlock('preg_split_entry');
        $failBb = $fn->appendBasicBlock('preg_split_fail');
        $okBb = $fn->appendBasicBlock('preg_split_ok');
        $context->builder->positionAtEnd($entry);
        $ht = $context->builder->call(
            self::helperFunction($context, self::SPLIT_HELPER),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2),
            $fn->getParam(3)
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $context->builder->branchIf($isNull, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($ht);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after PregJitHelper compile (#9542)');
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

        $runtime = $context->runtime;
        $root = \dirname(__DIR__, 3);
        $paths = [
            $root.'/ext/standard/StdlibConstants.php',
            $root.'/ext/standard/VmPregPattern.php',
            $root.'/ext/standard/VmPregNative.php',
            $root.'/ext/standard/VmPregMatches.php',
            $root.'/ext/standard/VmPreg.php',
            $root.self::HELPER_PATH,
        ];
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $paths): void {
            $jit = new JIT($context);
            foreach ($paths as $includePath) {
                $real = \realpath($includePath) ?: $includePath;
                if ($context->hasJitIncludedFileCompiled($real)) {
                    continue;
                }
                $block = $runtime->parseAndCompile(
                    (string) \file_get_contents($includePath),
                    \basename($includePath)
                );
                if (null === $block) {
                    throw new \LogicException(\basename($includePath).' parseAndCompile failed (#9542)');
                }
                $jit->compile($block);
                $context->markJitIncludedFileCompiled($real);
            }
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT preg (#9542)');
            }
        }
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $void = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        foreach ([
            ['__value__writeHashtable', $void, [$valuePtr, $htPtr]],
            ['__hashtable__alloc', $htPtr, []],
        ] as [$name, $ret, $params]) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction($name, $context->context->functionType($ret, false, ...$params));
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after PregMatchRuntime bridge (#9542)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
