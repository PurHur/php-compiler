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
 * JIT/AOT link for bz2 stream ABI via Bz2StreamJitHelper PHP (#17301).
 *
 * SSOT: {@see \PHPCompiler\ext\bz2\VmBz2Stream}
 * php-src: ext/bz2/bz2.c
 */
final class Bz2StreamRuntime
{
    private const HELPER_PATH = '/ext/bz2/Bz2StreamJitHelper.php';

    private const BZOPEN = 'PHPCompiler\\ext\\bz2\\Bz2StreamJitHelper::bzopenArgv';

    private const BZWRITE = 'PHPCompiler\\ext\\bz2\\Bz2StreamJitHelper::bzwriteArgv';

    private const BZREAD = 'PHPCompiler\\ext\\bz2\\Bz2StreamJitHelper::bzreadArgv';

    private const BZCLOSE = 'PHPCompiler\\ext\\bz2\\Bz2StreamJitHelper::bzcloseArgv';

    private const BZERRNO = 'PHPCompiler\\ext\\bz2\\Bz2StreamJitHelper::bzerrnoArgv';

    private const BZERRSTR = 'PHPCompiler\\ext\\bz2\\Bz2StreamJitHelper::bzerrstrArgv';

    private const BZFLUSH = 'PHPCompiler\\ext\\bz2\\Bz2StreamJitHelper::bzflushArgv';

    private const BZERROR = 'PHPCompiler\\ext\\bz2\\Bz2StreamJitHelper::bzerrorArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BZOPEN,
        self::BZWRITE,
        self::BZREAD,
        self::BZCLOSE,
        self::BZERRNO,
        self::BZERRSTR,
        self::BZFLUSH,
        self::BZERROR,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_bzopen',
        '__compiler_bzwrite',
        '__compiler_bzread',
        '__compiler_bzclose',
        '__compiler_bzerrno',
        '__compiler_bzerrstr',
        '__compiler_bzflush',
        '__compiler_bzerror',
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

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_bzopen', static fn (Context $ctx, LlvmFunction $fn) => self::emitBzopenBridge($ctx, $fn));
        self::implementIfMissing($context, '__compiler_bzwrite', static fn (Context $ctx, LlvmFunction $fn) => self::emitBzwriteBridge($ctx, $fn));
        self::implementIfMissing($context, '__compiler_bzread', static fn (Context $ctx, LlvmFunction $fn) => self::emitNullableStringBridge($ctx, $fn, self::BZREAD, 2));
        self::implementIfMissing($context, '__compiler_bzclose', static fn (Context $ctx, LlvmFunction $fn) => self::emitI32Bridge($ctx, $fn, self::BZCLOSE, 1));
        self::implementIfMissing($context, '__compiler_bzerrno', static fn (Context $ctx, LlvmFunction $fn) => self::emitI64Bridge($ctx, $fn, self::BZERRNO, 1));
        self::implementIfMissing($context, '__compiler_bzerrstr', static fn (Context $ctx, LlvmFunction $fn) => self::emitNullableStringBridge($ctx, $fn, self::BZERRSTR, 1));
        self::implementIfMissing($context, '__compiler_bzflush', static fn (Context $ctx, LlvmFunction $fn) => self::emitI32Bridge($ctx, $fn, self::BZFLUSH, 1));
        self::implementIfMissing($context, '__compiler_bzerror', static fn (Context $ctx, LlvmFunction $fn) => self::emitHashtableBridge($ctx, $fn, self::BZERROR, 1));
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
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = match ($name) {
            '__compiler_bzopen' => $context->context->functionType($i64, false, $strPtr, $strPtr),
            '__compiler_bzwrite' => $context->context->functionType($i64, false, $i64, $strPtr, $i64),
            '__compiler_bzread' => $context->context->functionType($strPtr, false, $i64, $i64),
            '__compiler_bzclose' => $context->context->functionType($i32, false, $i64),
            '__compiler_bzerrno' => $context->context->functionType($i64, false, $i64),
            '__compiler_bzerrstr' => $context->context->functionType($strPtr, false, $i64),
            '__compiler_bzflush' => $context->context->functionType($i32, false, $i64),
            '__compiler_bzerror' => $context->context->functionType(
                $context->getTypeFromString('__hashtable__*'),
                false,
                $i64
            ),
            default => throw new \LogicException('Bz2StreamRuntime: unknown '.$name),
        };
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($name, $ft);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitBzopenBridge(Context $context, LlvmFunction $fn): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');

        $entry = $fn->appendBasicBlock('bzopen_bridge_entry');
        $fail = $fn->appendBasicBlock('bzopen_bridge_fail');
        $body = $fn->appendBasicBlock('bzopen_bridge_body');
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
            self::helperFunction($context, self::BZOPEN),
            [$path, $mode]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(-1, true));
    }

    private static function emitBzwriteBridge(Context $context, LlvmFunction $fn): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');

        $entry = $fn->appendBasicBlock('bzwrite_bridge_entry');
        $fail = $fn->appendBasicBlock('bzwrite_bridge_fail');
        $body = $fn->appendBasicBlock('bzwrite_bridge_body');
        $context->builder->positionAtEnd($entry);

        $data = $fn->getParam(1);
        $dataNull = $context->builder->icmp(Builder::INT_EQ, $data, $strPtr->constNull());
        $context->builder->branchIf($dataNull, $fail, $body);

        $context->builder->positionAtEnd($body);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::BZWRITE),
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
        $entry = $fn->appendBasicBlock('bz_i32_bridge_entry');
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
        $entry = $fn->appendBasicBlock('bz_i64_bridge_entry');
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

    private static function emitHashtableBridge(
        Context $context,
        LlvmFunction $fn,
        string $helperLogical,
        int $argCount
    ): void {
        $entry = $fn->appendBasicBlock('bz_ht_bridge_entry');
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
            JitNestedHelperCoerce::coerceToHashtablePtr($context, $raw)
        );
    }

    private static function emitNullableStringBridge(
        Context $context,
        LlvmFunction $fn,
        string $helperLogical,
        int $i64ArgCount
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');

        $entry = $fn->appendBasicBlock('bz_str_bridge_entry');
        $fail = $fn->appendBasicBlock('bz_str_bridge_fail');
        $body = $fn->appendBasicBlock('bz_str_bridge_body');
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
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after Bz2StreamJitHelper compile (#17301)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'Bz2StreamJitHelper.php');
            if (null === $block) {
                throw new \LogicException('Bz2StreamJitHelper.php parseAndCompile failed (#17301)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after Bz2StreamRuntime bridge (#17301)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
