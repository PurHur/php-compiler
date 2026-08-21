<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamReadRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;

/**
 * LLVM ABI bridges for stream read runtime via NestedJIT StreamReadJitHelper (#18672, #20982, #33155, #33164, #33166, #33168, #33170, #33176, #33182).
 *
 * Moved out of lib/JIT/Builtin/ — {@see StreamReadRuntime} stays the thin PHP-helper
 * orchestrator (no deferred stub fork — peer StreamLifecycle #20966).
 *
 * Owns `__compiler_ftruncate` / `__compiler_ftell` / `__compiler_fgetc` / `__compiler_fgets` /
 * `__compiler_stream_get_line` / `__compiler_fseek` / `__compiler_stream_copy_to_stream`
 * module-locally (getNamedFunction first in
 * {@see implementI32Bridge} / {@see implementI64Bridge} / {@see implementNullableStringBridge} /
 * {@see implementStreamGetLineBridge}). Do not re-add empty always-on shells in Type — leftover
 * decls mint ftruncate.1 / ftell.1 / fgetc.1 / fgets.1 / stream_get_line.1 / fseek.1 /
 * stream_copy_to_stream.1 (#31894 / #32122).
 *
 * SSOT semantics: {@see StreamReadJitHelper}
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
}
