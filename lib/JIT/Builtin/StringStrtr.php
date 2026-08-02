<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableNestedExportLlvm;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmHashTableMethodLlvm;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_strtr* via Strtr*JitHelper PHP (#9392).
 *
 * JIT embed and AOT standalone compile {@see \PHPCompiler\ext\standard\StrtrArrayJitHelper}; thin LLVM
 * bridge forwards the ABI. Helper compile: {@see JitVmHelperLink::ensureCompiled} (#21844).
 * php-src: ext/standard/string.c
 */
final class StringStrtr
{
    private const TWO_STRING_HELPER_PATH = '/ext/standard/StrtrTwoStringJitHelper.php';

    private const ARRAY_HELPER_PATH = '/ext/standard/StrtrArrayJitHelper.php';

    private const STRTR_TWO_STRING = 'PHPCompiler\\ext\\standard\\StrtrTwoStringJitHelper::strtrTwoString';

    private const STRTR_ARRAY = 'PHPCompiler\\ext\\standard\\StrtrArrayJitHelper::strtrArray';

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_strtr',
        '__compiler_strtr_array',
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

        $twoStringProbe = $context->module->getNamedFunction('__compiler_strtr');
        $arrayProbe = $context->module->getNamedFunction('__compiler_strtr_array');
        if (null !== $twoStringProbe && $twoStringProbe->countBasicBlocks() > 0
            && null !== $arrayProbe && $arrayProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureTwoStringHelperCompiled($context);
        self::implementTwoStringBridge($context);
        self::ensureArrayHelperCompiled($context);
        self::implementArrayBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementTwoStringBridge(Context $context): void
    {
        $abiName = '__compiler_strtr';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('strtr_two_string_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::STRTR_TWO_STRING),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementArrayBridge(Context $context): void
    {
        $abiName = '__compiler_strtr_array';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $htPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('strtr_array_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::STRTR_ARRAY),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $resultRaw);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        if (self::STRTR_ARRAY === $logical) {
            self::ensureArrayHelperCompiled($context);
        } else {
            self::ensureTwoStringHelperCompiled($context);
        }

        return JitVmHelperLink::lookupCompiled($context, $logical, '#21844');
    }

    private static function ensureTwoStringHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::TWO_STRING_HELPER_PATH,
            [self::STRTR_TWO_STRING],
            '#21844'
        );
    }

    private static function ensureArrayHelperCompiled(Context $context): void
    {
        HashTableNestedExportLlvm::ensureLinked($context);
        NestedVmVariableMethodLlvm::ensureMethod($context, 'resolveindirect');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'tostring');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'toint');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'tofloat');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'tobool');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'toarray');
        // NestedJIT HashTable::exportKeyValuePairs for StrtrArrayJitHelper (#27056 / #12908).
        NestedVmHashTableMethodLlvm::ensureMethod($context, 'findindex');
        JitVmHelperLink::ensureCompiled(
            $context,
            self::ARRAY_HELPER_PATH,
            [self::STRTR_ARRAY],
            '#21844'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringStrtr bridge (#9392)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
