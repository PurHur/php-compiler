<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableNestedExportLlvm;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\NestedVmHashTableMethodLlvm;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_serialize_* via SerializeNestedJitHelper PHP (#9180, #20773, #27030).
 *
 * Object ABI builds the `O:len:"Class":` header via NestedJIT with LLVM-loaded length,
 * then concatenates NestedJIT property bag (peer JsonEncode #27020 shape for arrays).
 * php-src: ext/standard/var.c — php_var_serialize
 */
final class StringSerialize
{
    private const HELPER_PATH = '/ext/standard/SerializeNestedJitHelper.php';

    private const OBJECT_HELPER_PATH = '/ext/standard/SerializeObjectNestedJitHelper.php';

    private const ENCODE_VALUE_HELPER = 'PHPCompiler\\ext\\standard\\SerializeNestedJitHelper::encodeValue';

    private const ENCODE_HT_HELPER = 'PHPCompiler\\ext\\standard\\SerializeNestedJitHelper::encodeHashtable';

    private const OBJECT_HEADER_HELPER = 'PHPCompiler\\ext\\standard\\SerializeObjectNestedJitHelper::formatObjectHeader';

    private const OBJECT_PROPS_HELPER = 'PHPCompiler\\ext\\standard\\SerializeObjectNestedJitHelper::encodeObjectProps';

    private const VALUE_BRIDGE_ENTRY = 'serialize_value_bridge_entry';

    private const HT_BRIDGE_ENTRY = 'serialize_ht_bridge_entry';

    private const OBJECT_BRIDGE_ENTRY = 'serialize_object_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENCODE_VALUE_HELPER,
        self::ENCODE_HT_HELPER,
    ];

    /** @var list<string> */
    private const OBJECT_COMPILED_HELPERS = [
        self::OBJECT_HEADER_HELPER,
        self::OBJECT_PROPS_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_serialize_value',
        '__compiler_serialize_hashtable',
        '__compiler_serialize_object',
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

        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);
        NestedVmVariableMethodLlvm::ensureMethod($context, 'resolveindirect');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'array');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'tostring');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'toint');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'tofloat');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'tobool');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'toarray');
        HashTableNestedExportLlvm::ensureLinked($context);
        NestedVmHashTableMethodLlvm::ensureMethod($context, 'findindex');
        NestedVmHashTableMethodLlvm::ensureMethod($context, 'ispackedlist');
        NestedVmHashTableMethodLlvm::ensureMethod($context, 'getnumelements');
        NestedVmHashTableMethodLlvm::ensureMethod($context, 'find');
        \PHPCompiler\ext\standard\JitStreamLifecycleKernel::ensureLinkedForUserScriptLowering($context);

        $valueProbe = $context->module->getNamedFunction('__compiler_serialize_value');
        $htProbe = $context->module->getNamedFunction('__compiler_serialize_hashtable');
        $objProbe = $context->module->getNamedFunction('__compiler_serialize_object');
        if (JitVmHelperLink::hasNamedBridgeEntry($valueProbe, self::VALUE_BRIDGE_ENTRY)
            && JitVmHelperLink::hasNamedBridgeEntry($htProbe, self::HT_BRIDGE_ENTRY)
            && JitVmHelperLink::hasNamedBridgeEntry($objProbe, self::OBJECT_BRIDGE_ENTRY)) {
            self::registerLinkedRuntime($context);

            return;
        }
        if (null !== $valueProbe && $valueProbe->countBasicBlocks() > 0
            && null !== $htProbe && $htProbe->countBasicBlocks() > 0
            && null !== $objProbe && $objProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_serialize_value',
            self::VALUE_BRIDGE_ENTRY,
            [$valuePtr, $i64],
            $strPtr,
            self::ENCODE_VALUE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27030'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_serialize_hashtable',
            self::HT_BRIDGE_ENTRY,
            [$htPtr, $i64],
            $strPtr,
            self::ENCODE_HT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27030'
        );
        self::implementObjectBridge($context);
        self::registerLinkedRuntime($context);
    }

    public static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27030'
        );
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after SerializeNestedJitHelper compile (#27030)');
        }

        return $fn;
    }

    private static function implementObjectBridge(Context $context): void
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $abiName = '__compiler_serialize_object';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::OBJECT_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $htPtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        JitVmHelperLink::ensureCompiled(
            $context,
            self::OBJECT_HELPER_PATH,
            self::OBJECT_COMPILED_HELPERS,
            '#27030'
        );

        try {
            $context->lookupFunction('__string__alloc');
        } catch (\Throwable) {
            $sizeT = $context->getTypeFromString('size_t');
            $alloc = $context->module->addFunction(
                '__string__alloc',
                $context->context->functionType($strPtr, false, $sizeT)
            );
            $context->registerFunction('__string__alloc', $alloc);
        }

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::OBJECT_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $className = $fn->getParam(0);
        $props = $fn->getParam(1);
        $strMap = $context->structFieldMap['__string__'];
        $classLen = $context->builder->load(
            $context->builder->structGep($className, $strMap['length'])
        );

        $headerFn = self::objectHelperFunction($context, self::OBJECT_HEADER_HELPER);
        $classArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $className,
            $headerFn->getParam(0)->typeOf()
        );
        $lenArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $classLen,
            $headerFn->getParam(1)->typeOf()
        );
        $headerRaw = $context->builder->call($headerFn, $classArg, $lenArg);
        $header = JitNestedHelperCoerce::coerceBridgeResult($context, $headerRaw, $strPtr);

        $propsFn = self::objectHelperFunction($context, self::OBJECT_PROPS_HELPER);
        $htArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $props,
            $propsFn->getParam(0)->typeOf()
        );
        $bagRaw = $context->builder->call($propsFn, $htArg);
        $bag = JitNestedHelperCoerce::coerceBridgeResult($context, $bagRaw, $strPtr);

        $context->builder->returnValue(self::concatStr($context, $header, $bag));
        $context->registerFunction($abiName, $fn);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    private static function objectHelperFunction(Context $context, string $logical): LlvmFunction
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::OBJECT_HELPER_PATH,
            self::OBJECT_COMPILED_HELPERS,
            '#27030'
        );
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after SerializeObjectNestedJitHelper compile (#27030)');
        }

        return $fn;
    }

    private static function concatStr(Context $context, $left, $right)
    {
        $map = $context->structFieldMap['__string__'];
        $leftSize = $context->builder->load($context->builder->structGep($left, $map['length']));
        $rightSize = $context->builder->load($context->builder->structGep($right, $map['length']));
        $size = $context->builder->add($leftSize, $rightSize);
        $result = $context->builder->call($context->lookupFunction('__string__alloc'), $size);
        $context->intrinsic->builder = $context->builder;
        $dest = $context->builder->structGep($result, $map['value']);
        $leftChar = $context->builder->structGep($left, $map['value']);
        $context->intrinsic->memcpy($dest, $leftChar, $leftSize, false);
        $dest2 = $context->builder->gep($dest, $leftSize);
        $rightChar = $context->builder->structGep($right, $map['value']);
        $context->intrinsic->memcpy($dest2, $rightChar, $rightSize, false);

        return $result;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringSerialize bridge (#27030)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
