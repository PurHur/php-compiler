<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringJsonDecode;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPLLVM\Builder;

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
        if (null !== $probe && JitVmHelperLink::hasNamedBridgeEntry($probe, 'xmlrpc_encode_value_bridge_entry')) {
            $context->registerFunction('__compiler_xmlrpc_encode_value', $probe);

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
        if (null !== $probe && JitVmHelperLink::hasNamedBridgeEntry($probe, 'xmlrpc_decode_bridge_entry')) {
            $context->registerFunction('__compiler_xmlrpc_decode', $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
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
    }

    private static function emitEncodeValueBridge(Context $context): void
    {
        foreach (['resolveindirect'] as $method) {
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

    private static function emitDecodeBridge(Context $context): void
    {
        $abiName = '__compiler_xmlrpc_decode';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && JitVmHelperLink::hasNamedBridgeEntry($probe, 'xmlrpc_decode_bridge_entry')) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        StringJsonDecode::ensureLinked($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::DECODE_HELPER_PATH,
            self::DECODE_COMPILED_HELPERS,
            '#19048'
        );
        $decodeHelper = JitVmHelperLink::lookupCompiled($context, self::DECODE_TO_JSON_HELPER, '#19048');

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $valuePtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, 'xmlrpc_decode_bridge_entry');
        $failBlock = $fn->appendBasicBlock('xmlrpc_decode_fail');
        $okBlock = $fn->appendBasicBlock('xmlrpc_decode_ok');
        $doneBlock = $fn->appendBasicBlock('xmlrpc_decode_done');
        $context->builder->positionAtEnd($entry);
        $jsonPtr = $context->builder->call(
            $decodeHelper,
            $fn->getParam(0)
        );
        $isNull = $context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $jsonPtr, $strPtr->constNull());
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $fn->getParam(1),
            $i1->constInt(0, false)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $decoded = $context->builder->call(
            $context->lookupFunction('__compiler_json_decode'),
            $jsonPtr
        );
        JitValueBox::copyIntoPointer($context, $fn->getParam(1), $decoded);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }
}
