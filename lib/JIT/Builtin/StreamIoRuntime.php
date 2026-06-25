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
 * JIT/AOT embed link for stream I/O ABI via StreamIoJitHelper PHP (#10326).
 *
 * Standalone AOT keeps LLVM in {@see StreamIoStandaloneLlvm}.
 * SSOT: {@see \PHPCompiler\ext\standard\StreamIoJitHelper}
 * php-src: ext/standard/file.c, ext/standard/streamsfuncs.c
 */
final class StreamIoRuntime
{
    private const HELPER_PATH = '/ext/standard/StreamIoJitHelper.php';

    private const FOPEN = 'PHPCompiler\\ext\\standard\\StreamIoJitHelper::fopenArgv';

    private const POPEN = 'PHPCompiler\\ext\\standard\\StreamIoJitHelper::popenArgv';

    private const TMPFILE = 'PHPCompiler\\ext\\standard\\StreamIoJitHelper::tmpfileArgv';

    private const FREAD = 'PHPCompiler\\ext\\standard\\StreamIoJitHelper::freadArgv';

    private const FWRITE = 'PHPCompiler\\ext\\standard\\StreamIoJitHelper::fwriteArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FOPEN,
        self::POPEN,
        self::TMPFILE,
        self::FREAD,
        self::FWRITE,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_fwrite',
        '__compiler_fopen',
        '__compiler_popen',
        '__compiler_tmpfile',
        '__compiler_fread',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (self::allRuntimeFunctionsLinked($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementFwriteBridge($context);
        self::implementBinaryStringBridge($context, '__compiler_fopen', self::FOPEN);
        self::implementBinaryStringBridge($context, '__compiler_popen', self::POPEN);
        self::implementNullaryI64Bridge($context, '__compiler_tmpfile', self::TMPFILE);
        self::implementNullableStringBridge($context, '__compiler_fread', self::FREAD, 2);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function allRuntimeFunctionsLinked(Context $context): bool
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                return false;
            }
        }

        return true;
    }

    private static function implementNullaryI64Bridge(
        Context $context,
        string $abiName,
        string $helperLogical
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $fn = $context->module->addFunction(
            $abiName,
            $context->context->functionType($i64, false)
        );

        $entry = $fn->appendBasicBlock('stream_io_nullary_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            []
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBinaryStringBridge(
        Context $context,
        string $abiName,
        string $helperLogical
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = $context->module->addFunction(
            $abiName,
            $context->context->functionType($i64, false, $strPtr, $strPtr)
        );

        $entry = $fn->appendBasicBlock('stream_io_binstr_bridge_entry');
        $fail = $fn->appendBasicBlock('stream_io_binstr_bridge_fail');
        $body = $fn->appendBasicBlock('stream_io_binstr_bridge_body');
        $context->builder->positionAtEnd($entry);

        $left = $fn->getParam(0);
        $right = $fn->getParam(1);
        $badArgs = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $left, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $right, $strPtr->constNull())
        );
        $context->builder->branchIf($badArgs, $fail, $body);

        $context->builder->positionAtEnd($body);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            [$left, $right]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(-1, true));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementFwriteBridge(Context $context): void
    {
        $abiName = '__compiler_fwrite';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = $context->module->addFunction(
            $abiName,
            $context->context->functionType($i64, false, $i64, $strPtr, $i64)
        );

        $entry = $fn->appendBasicBlock('stream_io_fwrite_bridge_entry');
        $fail = $fn->appendBasicBlock('stream_io_fwrite_bridge_fail');
        $body = $fn->appendBasicBlock('stream_io_fwrite_bridge_body');
        $context->builder->positionAtEnd($entry);

        $data = $fn->getParam(1);
        $dataNull = $context->builder->icmp(Builder::INT_EQ, $data, $strPtr->constNull());
        $context->builder->branchIf($dataNull, $fail, $body);

        $context->builder->positionAtEnd($body);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::FWRITE),
            [
                $context->builder->trunc($fn->getParam(0), $i32),
                $data,
                $context->builder->trunc($fn->getParam(2), $i32),
            ]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(-1, true));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementNullableStringBridge(
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
        $fn = $context->module->addFunction(
            $abiName,
            $context->context->functionType($strPtr, false, ...$params)
        );

        $entry = $fn->appendBasicBlock('stream_io_str_bridge_entry');
        $fail = $fn->appendBasicBlock('stream_io_str_bridge_fail');
        $body = $fn->appendBasicBlock('stream_io_str_bridge_body');
        $context->builder->positionAtEnd($entry);

        $args = [];
        for ($i = 0; $i < $i64ArgCount; ++$i) {
            $args[] = $context->builder->trunc($fn->getParam($i), $i32);
        }
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
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

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamIoJitHelper compile (#10326)');
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
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StreamIoJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StreamIoJitHelper.php parseAndCompile failed (#10326)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT stream I/O (#10326)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StreamIoRuntime bridge (#10326)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
