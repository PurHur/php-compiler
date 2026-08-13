<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for gz stream ABI via GzStreamJitHelper PHP (#13420, #22431).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer Bz2StreamRuntime #22416).
 * Replaces deleted ~882 LOC libz LLVM monolith. SSOT: {@see \PHPCompiler\ext\standard\VmGzStream}.
 * php-src: ext/zlib/zlib.c
 */
final class GzStreamRuntime
{
    private const HELPER_PATH = '/ext/standard/GzStreamJitHelper.php';

    private const GZOPEN = 'PHPCompiler\\ext\\standard\\GzStreamJitHelper::gzopenArgv';

    private const GZWRITE = 'PHPCompiler\\ext\\standard\\GzStreamJitHelper::gzwriteArgv';

    private const GZREAD = 'PHPCompiler\\ext\\standard\\GzStreamJitHelper::gzreadArgv';

    private const GZGETC = 'PHPCompiler\\ext\\standard\\GzStreamJitHelper::gzgetcArgv';

    private const GZGETS = 'PHPCompiler\\ext\\standard\\GzStreamJitHelper::gzgetsArgv';

    private const GZCLOSE = 'PHPCompiler\\ext\\standard\\GzStreamJitHelper::gzcloseArgv';

    private const GZSEEK = 'PHPCompiler\\ext\\standard\\GzStreamJitHelper::gzseekArgv';

    private const GZTELL = 'PHPCompiler\\ext\\standard\\GzStreamJitHelper::gztellArgv';

    private const GZREWIND = 'PHPCompiler\\ext\\standard\\GzStreamJitHelper::gzrewindArgv';

    private const GZEOF = 'PHPCompiler\\ext\\standard\\GzStreamJitHelper::gzeofArgv';

    private const GZ_READ_ALL = 'PHPCompiler\\ext\\standard\\GzStreamJitHelper::gzReadAllArgv';

    private const GZ_PASSTHRU = 'PHPCompiler\\ext\\standard\\GzStreamJitHelper::gzPassthruArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GZOPEN,
        self::GZWRITE,
        self::GZREAD,
        self::GZGETC,
        self::GZGETS,
        self::GZCLOSE,
        self::GZSEEK,
        self::GZTELL,
        self::GZREWIND,
        self::GZEOF,
        self::GZ_READ_ALL,
        self::GZ_PASSTHRU,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_gzopen',
        '__compiler_gzwrite',
        '__compiler_gzread',
        '__compiler_gzgetc',
        '__compiler_gzgets',
        '__compiler_gzclose',
        '__compiler_gzseek',
        '__compiler_gztell',
        '__compiler_gzrewind',
        '__compiler_gzeof',
        '__compiler_gz_read_all',
        '__compiler_gz_passthru',
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

        if ($context->isThinStandaloneAotMain()) {
            // NestedJIT VmGzStreamPure statics do not persist — libz gzFile (#30787).
            \PHPCompiler\ext\standard\JitGzStreamKernel::implement($context);

            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_gzopen');
        if (null !== $probe && $probe->countBasicBlocks() > 0
            && !StreamIoRuntime::isDeferStub($probe)
            && self::allRuntimeFunctionsLinked($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_gzopen', static fn (Context $ctx, LlvmFunction $fn) => self::emitGzopenBridge($ctx, $fn));
        self::implementIfMissing($context, '__compiler_gzwrite', static fn (Context $ctx, LlvmFunction $fn) => self::emitGzwriteBridge($ctx, $fn));
        self::implementIfMissing($context, '__compiler_gzread', static fn (Context $ctx, LlvmFunction $fn) => self::emitNullableStringBridge($ctx, $fn, self::GZREAD, 2));
        self::implementIfMissing($context, '__compiler_gzgetc', static fn (Context $ctx, LlvmFunction $fn) => self::emitNullableStringBridge($ctx, $fn, self::GZGETC, 1));
        self::implementIfMissing($context, '__compiler_gzgets', static fn (Context $ctx, LlvmFunction $fn) => self::emitNullableStringBridge($ctx, $fn, self::GZGETS, 2));
        self::implementIfMissing($context, '__compiler_gzclose', static fn (Context $ctx, LlvmFunction $fn) => self::emitI32Bridge($ctx, $fn, self::GZCLOSE, 1));
        self::implementIfMissing($context, '__compiler_gzseek', static fn (Context $ctx, LlvmFunction $fn) => self::emitI64Bridge($ctx, $fn, self::GZSEEK, 3));
        self::implementIfMissing($context, '__compiler_gztell', static fn (Context $ctx, LlvmFunction $fn) => self::emitI64Bridge($ctx, $fn, self::GZTELL, 1));
        self::implementIfMissing($context, '__compiler_gzrewind', static fn (Context $ctx, LlvmFunction $fn) => self::emitI32Bridge($ctx, $fn, self::GZREWIND, 1));
        self::implementIfMissing($context, '__compiler_gzeof', static fn (Context $ctx, LlvmFunction $fn) => self::emitI32Bridge($ctx, $fn, self::GZEOF, 1));
        self::implementIfMissing($context, '__compiler_gz_read_all', static fn (Context $ctx, LlvmFunction $fn) => self::emitNullableStringBridge($ctx, $fn, self::GZ_READ_ALL, 1));
        self::implementIfMissing($context, '__compiler_gz_passthru', static fn (Context $ctx, LlvmFunction $fn) => self::emitI64Bridge($ctx, $fn, self::GZ_PASSTHRU, 1));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0 && !StreamIoRuntime::isDeferStub($probe)) {
            $context->registerFunction($name, $probe);

            return;
        }
        if (null !== $probe && StreamIoRuntime::isDeferStub($probe)) {
            foreach (array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
        }

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = match ($name) {
            '__compiler_gzopen' => $context->context->functionType($i64, false, $strPtr, $strPtr, $i64),
            '__compiler_gzwrite' => $context->context->functionType($i64, false, $i64, $strPtr, $i64),
            '__compiler_gzread', '__compiler_gzgets' => $context->context->functionType($strPtr, false, $i64, $i64),
            '__compiler_gzgetc', '__compiler_gz_read_all' => $context->context->functionType($strPtr, false, $i64),
            '__compiler_gzclose' => $context->context->functionType($i32, false, $i64),
            '__compiler_gzseek' => $context->context->functionType($i64, false, $i64, $i64, $i64),
            '__compiler_gztell' => $context->context->functionType($i64, false, $i64),
            '__compiler_gzrewind' => $context->context->functionType($i32, false, $i64),
            '__compiler_gzeof' => $context->context->functionType($i32, false, $i64),
            '__compiler_gz_passthru' => $context->context->functionType($i64, false, $i64),
            default => throw new \LogicException('GzStreamRuntime: unknown '.$name),
        };
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($name, $ft);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitGzopenBridge(Context $context, LlvmFunction $fn): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');

        $entry = $fn->appendBasicBlock('gzopen_bridge_entry');
        $fail = $fn->appendBasicBlock('gzopen_bridge_fail');
        $body = $fn->appendBasicBlock('gzopen_bridge_body');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $mode = $fn->getParam(1);
        $pathNull = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $modeNull = $context->builder->icmp(Builder::INT_EQ, $mode, $strPtr->constNull());
        $bad = $context->builder->or($pathNull, $modeNull);
        $context->builder->branchIf($bad, $fail, $body);

        $context->builder->positionAtEnd($body);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::GZOPEN),
            [
                $path,
                $mode,
                $context->builder->trunc($fn->getParam(2), $i32),
            ]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(-1, true));
    }

    private static function emitGzwriteBridge(Context $context, LlvmFunction $fn): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');

        $entry = $fn->appendBasicBlock('gzwrite_bridge_entry');
        $fail = $fn->appendBasicBlock('gzwrite_bridge_fail');
        $body = $fn->appendBasicBlock('gzwrite_bridge_body');
        $context->builder->positionAtEnd($entry);

        $data = $fn->getParam(1);
        $dataNull = $context->builder->icmp(Builder::INT_EQ, $data, $strPtr->constNull());
        $context->builder->branchIf($dataNull, $fail, $body);

        $context->builder->positionAtEnd($body);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::GZWRITE),
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
    }

    private static function emitI32Bridge(
        Context $context,
        LlvmFunction $fn,
        string $helperLogical,
        int $argCount
    ): void {
        $entry = $fn->appendBasicBlock('gz_i32_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
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
    }

    private static function emitI64Bridge(
        Context $context,
        LlvmFunction $fn,
        string $helperLogical,
        int $argCount
    ): void {
        $entry = $fn->appendBasicBlock('gz_i64_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
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
    }

    private static function emitNullableStringBridge(
        Context $context,
        LlvmFunction $fn,
        string $helperLogical,
        int $i64ArgCount
    ): void
    {
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');

        $entry = $fn->appendBasicBlock('gz_str_bridge_entry');
        $fail = $fn->appendBasicBlock('gz_str_bridge_fail');
        $body = $fn->appendBasicBlock('gz_str_bridge_body');
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
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22431');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        // Thin standalone AOT: skip helper-runtime cache — cached GzStreamJitHelper TU is
        // available_externally and silently returns 0 from gzwrite/gzputs (#30787 / peer #26888).
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22431',
            $context->isThinStandaloneAotMain()
        );
    }

    private static function allRuntimeFunctionsLinked(Context $context): bool
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                return false;
            }
            if (StreamIoRuntime::isDeferStub($fn)) {
                return false;
            }
        }

        return true;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks() || StreamIoRuntime::isDeferStub($fn)) {
                throw new \LogicException($name.' missing after GzStreamRuntime bridge (#13420)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
