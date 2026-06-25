<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_attr_* lookup via AttributeRegistryJitHelper PHP (#10086).
 *
 * Args hashtable: {@see AttributeRegistryArgsJitHelper} when ctor args exist; null stub otherwise.
 */
final class AttributeRegistryLookupRuntime
{
    private const HELPER_PATH = '/ext/standard/AttributeRegistryJitHelper.php';

    private const ARGS_HELPER_PATH = '/ext/standard/AttributeRegistryArgsJitHelper.php';

    private const CLASS_COUNT = 'PHPCompiler\\ext\\standard\\AttributeRegistryJitHelper::classCount';

    private const CLASS_NAME_AT = 'PHPCompiler\\ext\\standard\\AttributeRegistryJitHelper::classNameAt';

    private const METHOD_COUNT = 'PHPCompiler\\ext\\standard\\AttributeRegistryJitHelper::methodCount';

    private const METHOD_NAME_AT = 'PHPCompiler\\ext\\standard\\AttributeRegistryJitHelper::methodNameAt';

    private const CLASS_ARGS_HT = 'PHPCompiler\\ext\\standard\\AttributeRegistryArgsJitHelper::classArgsHashtable';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CLASS_COUNT,
        self::CLASS_NAME_AT,
        self::METHOD_COUNT,
        self::METHOD_NAME_AT,
    ];

    /** @var list<string> */
    private const ARGS_COMPILED_HELPERS = [
        self::CLASS_ARGS_HT,
    ];

    public static function implement(
        Context $context,
        string $classNamesJson,
        string $methodNamesJson,
        string $classEntriesJson
    ): void {
        $probe = $context->module->getNamedFunction('__compiler_attr_class_count');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementClassCountBridge($context, $classNamesJson);
        self::implementClassNameAtBridge($context, $classNamesJson);
        self::implementMethodCountBridge($context, $methodNamesJson);
        self::implementMethodNameAtBridge($context, $methodNamesJson);
        self::implementClassArgsHashtableBridge($context, $classEntriesJson);
        $context->builder->clearInsertionPosition();
    }

    private static function implementClassCountBridge(Context $context, string $classNamesJson): void
    {
        $abiName = '__compiler_attr_class_count';
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($sizeT, false, $i8p);
        $fn = $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('attr_class_count_bridge');
        $context->builder->positionAtEnd($entry);

        $classStr = self::cstrToString($context, $fn->getParam(0));
        $table = $context->builder->load($context->constantStringFromString($classNamesJson));
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::CLASS_COUNT),
            [$classStr, $table]
        );
        $count = JitNestedHelperCoerce::coerceHelperScalarResult($context, $raw, $sizeT);
        $context->builder->returnValue($count);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementClassNameAtBridge(Context $context, string $classNamesJson): void
    {
        $abiName = '__compiler_attr_class_name_at';
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($i8p, false, $i8p, $sizeT);
        $fn = $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('attr_class_name_at_bridge');
        $context->builder->positionAtEnd($entry);

        $classStr = self::cstrToString($context, $fn->getParam(0));
        $idx = $fn->getParam(1);
        $table = $context->builder->load($context->constantStringFromString($classNamesJson));
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::CLASS_NAME_AT),
            [$classStr, $idx, $table]
        );
        $context->builder->returnValue(self::helperStringToCstr($context, $raw));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementMethodCountBridge(Context $context, string $methodNamesJson): void
    {
        $abiName = '__compiler_attr_method_count';
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($sizeT, false, $i8p, $i8p);
        $fn = $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('attr_method_count_bridge');
        $context->builder->positionAtEnd($entry);

        $classStr = self::cstrToString($context, $fn->getParam(0));
        $methodStr = self::cstrToString($context, $fn->getParam(1));
        $table = $context->builder->load($context->constantStringFromString($methodNamesJson));
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::METHOD_COUNT),
            [$classStr, $methodStr, $table]
        );
        $count = JitNestedHelperCoerce::coerceHelperScalarResult($context, $raw, $sizeT);
        $context->builder->returnValue($count);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementMethodNameAtBridge(Context $context, string $methodNamesJson): void
    {
        $abiName = '__compiler_attr_method_name_at';
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($i8p, false, $i8p, $i8p, $sizeT);
        $fn = $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('attr_method_name_at_bridge');
        $context->builder->positionAtEnd($entry);

        $classStr = self::cstrToString($context, $fn->getParam(0));
        $methodStr = self::cstrToString($context, $fn->getParam(1));
        $idx = $fn->getParam(2);
        $table = $context->builder->load($context->constantStringFromString($methodNamesJson));
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::METHOD_NAME_AT),
            [$classStr, $methodStr, $idx, $table]
        );
        $context->builder->returnValue(self::helperStringToCstr($context, $raw));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementClassArgsHashtableBridge(Context $context, string $classEntriesJson): void
    {
        $abiName = '__compiler_attr_class_args_hashtable';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        if (!self::classEntriesNeedArgsBridge($classEntriesJson)) {
            self::implementClassArgsHashtableNullBridge($context);

            return;
        }

        self::ensureArgsJitHelperCompiled($context);

        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($htPtr, false, $i8p, $sizeT);
        $fn = $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('attr_class_args_ht_bridge');
        $context->builder->positionAtEnd($entry);

        $classStr = self::cstrToString($context, $fn->getParam(0));
        $idx = $fn->getParam(1);
        $table = $context->builder->load($context->constantStringFromString($classEntriesJson));
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::argsHelperFunction($context, self::CLASS_ARGS_HT),
            [$classStr, $idx, $table]
        );
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $raw);
        $context->builder->returnValue($ht);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementClassArgsHashtableNullBridge(Context $context): void
    {
        $abiName = '__compiler_attr_class_args_hashtable';
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $voidp = $context->getTypeFromString('void*');
        $ft = $context->context->functionType($htPtr, false, $i8p, $sizeT);
        $fn = $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('attr_class_args_ht_null');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->pointerCast($voidp->constNull(), $htPtr)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function classEntriesNeedArgsBridge(string $json): bool
    {
        if ('' === $json || '{}' === $json) {
            return false;
        }
        $decoded = json_decode($json, true);
        if (!\is_array($decoded)) {
            return false;
        }
        foreach ($decoded as $entries) {
            if (!\is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (!\is_array($entry)) {
                    continue;
                }
                if (!empty($entry['args'])) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function cstrToString(Context $context, Value $keyCstr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $keyLen = $context->builder->call($context->lookupFunction('strlen'), $keyCstr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($keyLen, $i64),
            $keyCstr
        );
    }

    private static function helperStringToCstr(Context $context, Value $raw): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $nullStr = $strPtr->typeOf()->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $strPtr, $nullStr);
        $empty = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $map = $context->structFieldMap['__string__'];
        $chars = $context->builder->load($context->builder->structGep($strPtr, $map['value']));
        $cstr = $context->builder->pointerCast($chars, $i8p);

        return $context->builder->select($isNull, $empty, $cstr);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after AttributeRegistryJitHelper compile (#10086)');
        }

        return $fn;
    }

    private static function argsHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureArgsJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after AttributeRegistryArgsJitHelper compile (#10086)');
        }

        return $fn;
    }

    private static function ensureArgsJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::ARGS_COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::ARGS_HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'AttributeRegistryArgsJitHelper.php');
            if (null === $block) {
                throw new \LogicException('AttributeRegistryArgsJitHelper.php parseAndCompile failed (#10086)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::ARGS_COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#10086)');
            }
        }
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
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'AttributeRegistryJitHelper.php');
            if (null === $block) {
                throw new \LogicException('AttributeRegistryJitHelper.php parseAndCompile failed (#10086)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#10086)');
            }
        }
    }
}
