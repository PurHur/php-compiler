<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
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
    private const ENCODE_HELPER_PATH = '/ext/xmlrpc/XmlrpcEncodeJitHelper.php';

    private const DECODE_HELPER_PATH = '/ext/xmlrpc/XmlrpcDecodeJitHelper.php';

    private const ENCODE_VALUE_HELPER = 'PHPCompiler\\ext\\xmlrpc\\XmlrpcEncodeJitHelper::encodeValue';

    private const DECODE_TO_JSON_HELPER = 'PHPCompiler\\ext\\xmlrpc\\XmlrpcDecodeJitHelper::decodeToJson';

    /** @var list<string> */
    private const ENCODE_COMPILED_HELPERS = [
        self::ENCODE_VALUE_HELPER,
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

        $probe = $context->module->getNamedFunction('__compiler_xmlrpc_encode_value');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::emitEncodeValueBridge($context);
        self::emitEncodeArrayBridge($context);
        self::emitDecodeBridgeStub($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
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
            self::ENCODE_HELPER_PATH,
            self::ENCODE_COMPILED_HELPERS,
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

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($strPtr, false, $htPtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('xmlrpc_encode_array_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $valueSlot = JitValueBox::alloc($context);
        $valueOut = JitValueBox::pointer($context, $valueSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $valueOut,
            $fn->getParam(0)
        );
        $result = $context->builder->call(
            self::lookupEncodeHelper($context),
            $valueOut
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function emitDecodeBridgeStub(Context $context): void
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

        $entry = $fn->appendBasicBlock('xmlrpc_decode_stub_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $fn->getParam(1),
            $i1->constInt(0, false)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
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
            self::ENCODE_HELPER_PATH,
            self::ENCODE_COMPILED_HELPERS,
            '#19048'
        );

        return JitVmHelperLink::lookupCompiled($context, self::ENCODE_VALUE_HELPER, '#19048');
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
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringXmlrpc bridge (#19048)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
