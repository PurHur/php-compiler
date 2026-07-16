<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamReadRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPLLVM\Value;

/**
 * LLVM ABI bridges + deferred inventory stubs for stream read runtime (#18672, #19559).
 *
 * Moved out of lib/JIT/Builtin/ — {@see StreamReadRuntime} stays the thin PHP-helper
 * orchestrator. SSOT semantics: {@see StreamReadJitHelper}
 * php-src: ext/standard/file.c, ext/standard/flock_compat.c
 */
final class JitStreamReadBridgeKernel
{
    public static function implementI32Bridge(
        Context $context,
        string $abiName,
        string $helperLogical,
        int $argCount
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $params = array_fill(0, $argCount, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, ...$params)
            );

        $entry = $fn->appendBasicBlock('stream_read_i32_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $args = [];
        for ($i = 0; $i < $argCount; ++$i) {
            $args[] = $context->builder->trunc($fn->getParam($i), $i32);
        }
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            StreamReadRuntime::helperFunction($context, $helperLogical),
            $args
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i32)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    public static function implementI64Bridge(
        Context $context,
        string $abiName,
        string $helperLogical,
        int $argCount
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $params = array_fill(0, $argCount, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, ...$params)
            );

        $entry = $fn->appendBasicBlock('stream_read_i64_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $args = [];
        for ($i = 0; $i < $argCount; ++$i) {
            $args[] = $context->builder->trunc($fn->getParam($i), $i32);
        }
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            StreamReadRuntime::helperFunction($context, $helperLogical),
            $args
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    public static function implementNullableStringBridge(
        Context $context,
        string $abiName,
        string $helperLogical,
        int $i64ArgCount
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $params = array_fill(0, $i64ArgCount, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, ...$params)
            );

        $entry = $fn->appendBasicBlock('stream_read_str_bridge_entry');
        $fail = $fn->appendBasicBlock('stream_read_str_bridge_fail');
        $body = $fn->appendBasicBlock('stream_read_str_bridge_body');
        $context->builder->positionAtEnd($entry);

        $args = [];
        for ($i = 0; $i < $i64ArgCount; ++$i) {
            $args[] = $context->builder->trunc($fn->getParam($i), $i32);
        }
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            StreamReadRuntime::helperFunction($context, $helperLogical),
            $args
        );
        $failed = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $context->builder->branchIf($failed, $fail, $body);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($body);
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    public static function implementStreamGetLineBridge(Context $context): void
    {
        $abiName = '__compiler_stream_get_line';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $i64, $i64, $strPtr)
            );

        $entry = $fn->appendBasicBlock('stream_get_line_bridge_entry');
        $fail = $fn->appendBasicBlock('stream_get_line_bridge_fail');
        $body = $fn->appendBasicBlock('stream_get_line_bridge_body');
        $context->builder->positionAtEnd($entry);

        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            StreamReadRuntime::helperFunction($context, StreamReadRuntime::STREAM_GET_LINE),
            [
                $context->builder->trunc($fn->getParam(0), $i32),
                $context->builder->trunc($fn->getParam(1), $i32),
                $fn->getParam(2),
            ]
        );
        $failed = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $context->builder->branchIf($failed, $fail, $body);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($body);
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    public static function implementDeferredStubs(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $minusOneI64 = $i64->constInt(-1, true);
        $zeroI32 = $i32->constInt(0, false);
        $nullStr = $strPtr->constNull();

        self::implementI32BinaryStub($context, '__compiler_flock', $zeroI32);
        self::implementI64UnaryStub($context, '__compiler_fpassthru', $minusOneI64);
        self::implementI32BinaryStub($context, '__compiler_ftruncate', $zeroI32);
        self::implementI64UnaryStub($context, '__compiler_ftell', $minusOneI64);
        self::implementStrUnaryStub($context, '__compiler_fgetc', $nullStr);
        self::implementStrBinaryStub($context, '__compiler_fgets', $nullStr);
        self::implementStrTernaryStub($context, '__compiler_stream_get_line', $nullStr);
        self::implementI64TernaryStub($context, '__compiler_fseek', $minusOneI64);
        self::implementStrTernaryStub($context, '__compiler_stream_get_contents', $nullStr);
        self::implementI64QuaternaryStub($context, '__compiler_stream_copy_to_stream', $minusOneI64);
        self::implementStrTernaryStub($context, '__compiler_stream_copy_to_string', $nullStr);
        StreamReadRuntime::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementI32BinaryStub(Context $context, string $name, Value $ret): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        self::implementRetStub($context, $name, $context->context->functionType($i32, false, $i64, $i64), $ret);
    }

    private static function implementI64UnaryStub(Context $context, string $name, Value $ret): void
    {
        $i64 = $context->getTypeFromString('int64');
        self::implementRetStub($context, $name, $context->context->functionType($i64, false, $i64), $ret);
    }

    private static function implementI64TernaryStub(Context $context, string $name, Value $ret): void
    {
        $i64 = $context->getTypeFromString('int64');
        self::implementRetStub($context, $name, $context->context->functionType($i64, false, $i64, $i64, $i64), $ret);
    }

    private static function implementI64QuaternaryStub(Context $context, string $name, Value $ret): void
    {
        $i64 = $context->getTypeFromString('int64');
        self::implementRetStub(
            $context,
            $name,
            $context->context->functionType($i64, false, $i64, $i64, $i64, $i64),
            $ret
        );
    }

    private static function implementStrUnaryStub(Context $context, string $name, Value $ret): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        self::implementRetStub($context, $name, $context->context->functionType($strPtr, false, $i64), $ret);
    }

    private static function implementStrBinaryStub(Context $context, string $name, Value $ret): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        self::implementRetStub($context, $name, $context->context->functionType($strPtr, false, $i64, $i64), $ret);
    }

    private static function implementStrTernaryStub(Context $context, string $name, Value $ret): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        self::implementRetStub($context, $name, $context->context->functionType($strPtr, false, $i64, $i64, $i64), $ret);
    }

    private static function implementRetStub(Context $context, string $name, $ft, Value $ret): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }
        $fn = $probe ?? $context->module->addFunction($name, $ft);
        $entry = $fn->appendBasicBlock('stream_read_stub_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($ret);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }
}
