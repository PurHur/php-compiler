<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream read ABI via StreamReadJitHelper PHP (#9393, #12937).
 *
 * JIT embed and AOT standalone compile {@see \PHPCompiler\ext\standard\StreamReadJitHelper}; thin LLVM
 * bridges forward the ABI. SSOT: {@see \PHPCompiler\ext\standard\StreamReadJitHelper}
 * php-src: ext/standard/flock.c, ext/standard/streams.c
 */
final class StreamReadRuntime
{
    private const HELPER_PATH = '/ext/standard/StreamReadJitHelper.php';

    private const FLOCK = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::flockArgv';

    private const FPASSTHRU = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::fpassthruArgv';

    private const FTRUNCATE = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::ftruncateArgv';

    private const FTELL = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::ftellArgv';

    private const FGETC = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::fgetcArgv';

    private const FGETS = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::fgetsArgv';

    private const STREAM_GET_LINE = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::streamGetLineArgv';

    private const FSEEK = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::fseekArgv';

    private const STREAM_GET_CONTENTS = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::streamGetContentsArgv';

    private const STREAM_COPY_TO_STREAM = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::streamCopyToStreamArgv';

    private const STREAM_COPY_TO_STRING = 'PHPCompiler\\ext\\standard\\StreamReadJitHelper::streamCopyToStringArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FLOCK,
        self::FPASSTHRU,
        self::FTRUNCATE,
        self::FTELL,
        self::FGETC,
        self::FGETS,
        self::STREAM_GET_LINE,
        self::FSEEK,
        self::STREAM_GET_CONTENTS,
        self::STREAM_COPY_TO_STREAM,
        self::STREAM_COPY_TO_STRING,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_flock',
        '__compiler_fpassthru',
        '__compiler_ftruncate',
        '__compiler_ftell',
        '__compiler_fgetc',
        '__compiler_fgets',
        '__compiler_stream_get_line',
        '__compiler_fseek',
        '__compiler_stream_get_contents',
        '__compiler_stream_copy_to_stream',
        '__compiler_stream_copy_to_string',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context, true);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context, true);
    }

  /** vfscanf LLVM only needs read/seek ABI — skip lifecycle/ob deps during defineBuiltins (#13137). */
    public static function ensureVfscanfAbi(Context $context): void
    {
        self::implement($context, false);
    }

    public static function implement(Context $context, bool $withLifecycleDeps = true): void
    {
        $probe = $context->module->getNamedFunction('__compiler_flock');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        StreamFilter::ensureLinked($context);
        if ($withLifecycleDeps) {
            StreamLifecycleRuntime::ensureLinked($context);
            ObOutputRuntime::ensureLinked($context);
        }
        self::ensureJitHelperCompiled($context);
        self::implementI32Bridge($context, '__compiler_flock', self::FLOCK, 2);
        self::implementI64Bridge($context, '__compiler_fpassthru', self::FPASSTHRU, 1);
        self::implementI32Bridge($context, '__compiler_ftruncate', self::FTRUNCATE, 2);
        self::implementI64Bridge($context, '__compiler_ftell', self::FTELL, 1);
        self::implementNullableStringBridge($context, '__compiler_fgetc', self::FGETC, 1);
        self::implementNullableStringBridge($context, '__compiler_fgets', self::FGETS, 2);
        self::implementStreamGetLineBridge($context);
        self::implementI64Bridge($context, '__compiler_fseek', self::FSEEK, 3);
        self::implementNullableStringBridge($context, '__compiler_stream_get_contents', self::STREAM_GET_CONTENTS, 3);
        self::implementI64Bridge($context, '__compiler_stream_copy_to_stream', self::STREAM_COPY_TO_STREAM, 4);
        self::implementNullableStringBridge($context, '__compiler_stream_copy_to_string', self::STREAM_COPY_TO_STRING, 3);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementI32Bridge(
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
            self::helperFunction($context, $helperLogical),
            $args
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i32)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementI64Bridge(
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
            self::helperFunction($context, $helperLogical),
            $args
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
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

    private static function implementStreamGetLineBridge(Context $context): void
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
            self::helperFunction($context, self::STREAM_GET_LINE),
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

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamReadJitHelper compile (#9393)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StreamReadJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StreamReadJitHelper.php parseAndCompile failed (#9393)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT stream read (#9393)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StreamReadRuntime bridge (#9393)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
