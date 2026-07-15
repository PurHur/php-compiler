<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableNestedExportLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmHashTableMethodLlvm;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_xmlrpc_* via Xmlrpc*JitHelper PHP (#19048).
 *
 * php-src: ext/xmlrpc/xmlrpc.c — PHP_FUNCTION(xmlrpc_encode), xmlrpc_decode
 */
final class StringXmlrpc
{
    private const ENCODE_SCALAR_HELPER_PATH = '/ext/xmlrpc/XmlrpcEncodeScalarJitHelper.php';

    private const ENCODE_TABLE_HELPER_PATH = '/ext/xmlrpc/XmlrpcEncodeTableJitHelper.php';

    private const DECODE_HELPER_PATH = '/ext/xmlrpc/XmlrpcDecodeJitHelper.php';

    private const ENCODE_VALUE_HELPER = 'PHPCompiler\\ext\\xmlrpc\\XmlrpcEncodeScalarJitHelper::encodeValue';

    private const ENCODE_LIST_HELPER = 'PHPCompiler\\ext\\xmlrpc\\XmlrpcEncodeTableJitHelper::encodeListHashTable';

    private const ENCODE_STRUCT_HELPER = 'PHPCompiler\\ext\\xmlrpc\\XmlrpcEncodeTableJitHelper::encodeStructHashTable';

    private const DECODE_TO_JSON_HELPER = 'PHPCompiler\\ext\\xmlrpc\\XmlrpcDecodeJitHelper::decodeToJson';

    /** @var list<string> */
    private const ENCODE_SCALAR_COMPILED_HELPERS = [
        self::ENCODE_VALUE_HELPER,
    ];

    /** @var list<string> */
    private const ENCODE_TABLE_COMPILED_HELPERS = [
        self::ENCODE_LIST_HELPER,
        self::ENCODE_STRUCT_HELPER,
    ];

    /** @var list<string> */
    private const DECODE_COMPILED_HELPERS = [
        self::DECODE_TO_JSON_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_xmlrpc_encode_value',
        '__compiler_xmlrpc_encode_array',
        '__compiler_xmlrpc_decode',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureEncodeLinked($context);
        self::ensureDecodeLinked($context);
    }

    public static function ensureEncodeLinked(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_xmlrpc_encode_value');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_xmlrpc_encode_value', $probe);
            $arrayFn = $context->module->getNamedFunction('__compiler_xmlrpc_encode_array');
            if (null !== $arrayFn && $arrayFn->countBasicBlocks() > 0) {
                $context->registerFunction('__compiler_xmlrpc_encode_array', $arrayFn);
            }

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::emitEncodeValueBridge($context);
        $encodeValueFn = $context->module->getNamedFunction('__compiler_xmlrpc_encode_value');
        if (null !== $encodeValueFn) {
            $context->registerFunction('__compiler_xmlrpc_encode_value', $encodeValueFn);
        }
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function ensureDecodeLinked(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_xmlrpc_decode');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_xmlrpc_decode', $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringJsonDecode::ensureLinked($context);
        self::emitDecodeBridge($context);
        $decodeFn = $context->module->getNamedFunction('__compiler_xmlrpc_decode');
        if (null !== $decodeFn) {
            $context->registerFunction('__compiler_xmlrpc_decode', $decodeFn);
        }
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function ensureArrayLinked(Context $context): void
    {
        self::ensureEncodeLinked($context);
        self::emitEncodeArrayBridge($context);
        $arrayFn = $context->module->getNamedFunction('__compiler_xmlrpc_encode_array');
        if (null !== $arrayFn) {
            $context->registerFunction('__compiler_xmlrpc_encode_array', $arrayFn);
        }
    }

    private static function emitEncodeValueBridge(Context $context): void
    {
        foreach (['resolveindirect', 'toint', 'tostring', 'tobool', 'tofloat'] as $method) {
            NestedVmVariableMethodLlvm::ensureMethod($context, $method);
        }
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_xmlrpc_encode_value',
            'xmlrpc_encode_value_bridge_entry',
            [$valuePtr],
            $strPtr,
            self::ENCODE_VALUE_HELPER,
            self::ENCODE_SCALAR_HELPER_PATH,
            self::ENCODE_SCALAR_COMPILED_HELPERS,
            '#19048'
        );
    }

    private static function emitEncodeArrayBridge(Context $context): void
    {
        $abiName = '__compiler_xmlrpc_encode_array';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureEncodeTableHelpersCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($strPtr, false, $htPtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('xmlrpc_encode_array_bridge_entry');
        $listBlock = $fn->appendBasicBlock('xmlrpc_encode_array_list');
        $structBlock = $fn->appendBasicBlock('xmlrpc_encode_array_struct');
        $doneBlock = $fn->appendBasicBlock('xmlrpc_encode_array_done');
        $context->builder->positionAtEnd($entry);

        $ht = $fn->getParam(0);
        $isList = self::packedListHeuristic($context, $ht);
        $context->builder->branchIf($isList, $listBlock, $structBlock);

        $htObj = $context->builder->bitcast($ht, $context->getTypeFromString('__object__*'));
        $listFn = JitVmHelperLink::lookupCompiled($context, self::ENCODE_LIST_HELPER, '#19048');
        $structFn = JitVmHelperLink::lookupCompiled($context, self::ENCODE_STRUCT_HELPER, '#19048');

        $resultSlot = BasicBlockHelper::entryAlloca($context, $strPtr);

        $context->builder->positionAtEnd($listBlock);
        $listResult = $context->builder->call($listFn, $htObj);
        $context->builder->store($listResult, $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($structBlock);
        $structResult = $context->builder->call($structFn, $htObj);
        $context->builder->store($structResult, $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $context->builder->returnValue($context->builder->load($resultSlot));
        $context->registerFunction($abiName, $fn);
    }

    private static function packedListHeuristic(Context $context, \PHPLLVM\Value $ht): \PHPLLVM\Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');
        $numElements = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $strKeys = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $zero = $context->getTypeFromString('size_t')->constInt(0, false);
        $empty = $context->builder->icmp(Builder::INT_EQ, $numElements, $zero);
        $noStrKeys = $context->builder->icmp(Builder::INT_EQ, $strKeys, $nodePtrTy->constNull());
        $dense = $context->builder->icmp(Builder::INT_EQ, $numElements, $nextFree);

        return $context->builder->or(
            $empty,
            $context->builder->and($noStrKeys, $dense)
        );
    }

    private static function emitDecodeBridge(Context $context): void
    {
        $abiName = '__compiler_xmlrpc_decode';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $valuePtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('xmlrpc_decode_bridge_entry');
        $failBlock = $fn->appendBasicBlock('xmlrpc_decode_fail');
        $okBlock = $fn->appendBasicBlock('xmlrpc_decode_ok');
        $doneBlock = $fn->appendBasicBlock('xmlrpc_decode_done');
        $context->builder->positionAtEnd($entry);
        $jsonPtr = $context->builder->call(
            self::lookupDecodeHelper($context),
            $fn->getParam(0)
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $jsonPtr, $strPtr->constNull());
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $fn->getParam(1),
            $i1->constInt(0, false)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__compiler_json_decode'),
            $jsonPtr,
            $fn->getParam(1)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function lookupEncodeHelper(Context $context): LlvmFunction
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::ENCODE_SCALAR_HELPER_PATH,
            self::ENCODE_SCALAR_COMPILED_HELPERS,
            '#19048'
        );

        return JitVmHelperLink::lookupCompiled($context, self::ENCODE_VALUE_HELPER, '#19048');
    }

    private static function ensureEncodeTableHelpersCompiled(Context $context): void
    {
        HashTableNestedExportLlvm::ensureLinked($context);
        foreach (['getnumelements', 'exportkeyvaluepairs', 'ispackedlist'] as $method) {
            NestedVmHashTableMethodLlvm::ensureMethod($context, $method);
        }
        foreach (['resolveindirect', 'toint', 'tostring', 'tobool', 'tofloat'] as $method) {
            NestedVmVariableMethodLlvm::ensureMethod($context, $method);
        }
        JitVmHelperLink::ensureCompiled(
            $context,
            self::ENCODE_TABLE_HELPER_PATH,
            self::ENCODE_TABLE_COMPILED_HELPERS,
            '#19048'
        );
    }

    private static function lookupDecodeHelper(Context $context): LlvmFunction
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::DECODE_HELPER_PATH,
            self::DECODE_COMPILED_HELPERS,
            '#19048'
        );

        return JitVmHelperLink::lookupCompiled($context, self::DECODE_TO_JSON_HELPER, '#19048');
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            if ('__compiler_xmlrpc_encode_array' === $name) {
                continue;
            }
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringXmlrpc bridge (#19048)');
            }
            $context->registerFunction($name, $fn);
        }
        $arrayFn = $context->module->getNamedFunction('__compiler_xmlrpc_encode_array');
        if (null !== $arrayFn && $arrayFn->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_xmlrpc_encode_array', $arrayFn);
        }
    }
}
