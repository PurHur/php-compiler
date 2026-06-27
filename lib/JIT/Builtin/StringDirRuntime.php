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
 * JIT/AOT embed link for directory handle ABI via DirHandleJitHelper PHP (#11811).
 *
 * JIT embed and AOT standalone compile {@see \PHPCompiler\ext\standard\DirHandleJitHelper}; thin LLVM
 * bridges forward the ABI (#11811, #12870).
 * SSOT: {@see \PHPCompiler\ext\standard\DirHandleJitHelper}
 * php-src: ext/standard/dir.c
 */
final class StringDirRuntime
{
    private const HELPER_PATH = '/ext/standard/DirHandleJitHelper.php';

    private const IS_DIR_RESOURCE = 'PHPCompiler\\ext\\standard\\DirHandleJitHelper::isDirResourceArgv';

    private const OPENDIR = 'PHPCompiler\\ext\\standard\\DirHandleJitHelper::opendirArgv';

    private const READDIR = 'PHPCompiler\\ext\\standard\\DirHandleJitHelper::readdirArgv';

    private const CLOSEDIR = 'PHPCompiler\\ext\\standard\\DirHandleJitHelper::closedirArgv';

    private const REWINDDIR = 'PHPCompiler\\ext\\standard\\DirHandleJitHelper::rewinddirArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IS_DIR_RESOURCE,
        self::OPENDIR,
        self::READDIR,
        self::CLOSEDIR,
        self::REWINDDIR,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_is_dir_resource',
        '__compiler_opendir',
        '__compiler_readdir',
        '__compiler_closedir',
        '__compiler_rewinddir',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_opendir');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_is_dir_resource', static fn (Context $ctx, LlvmFunction $fn) => self::emitI32Bridge($ctx, $fn, self::IS_DIR_RESOURCE, 1));
        self::implementIfMissing($context, '__compiler_opendir', static fn (Context $ctx, LlvmFunction $fn) => self::emitOpendirBridge($ctx, $fn));
        self::implementIfMissing($context, '__compiler_readdir', static fn (Context $ctx, LlvmFunction $fn) => self::emitNullableStringBridge($ctx, $fn, self::READDIR, 1));
        self::implementIfMissing($context, '__compiler_closedir', static fn (Context $ctx, LlvmFunction $fn) => self::emitI32Bridge($ctx, $fn, self::CLOSEDIR, 1));
        self::implementIfMissing($context, '__compiler_rewinddir', static fn (Context $ctx, LlvmFunction $fn) => self::emitI32Bridge($ctx, $fn, self::REWINDDIR, 1));
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

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = match ($name) {
            '__compiler_opendir' => $context->context->functionType($i64, false, $strPtr),
            '__compiler_readdir' => $context->context->functionType($strPtr, false, $i64),
            default => $context->context->functionType($i32, false, $i64),
        };
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($name, $ft);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitI32Bridge(
        Context $context,
        LlvmFunction $fn,
        string $helperLogical,
        int $argCount
    ): void {
        $entry = $fn->appendBasicBlock('dir_i32_bridge_entry');
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

    private static function emitOpendirBridge(Context $context, LlvmFunction $fn): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');

        $entry = $fn->appendBasicBlock('opendir_bridge_entry');
        $fail = $fn->appendBasicBlock('opendir_bridge_fail');
        $body = $fn->appendBasicBlock('opendir_bridge_body');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $pathNull = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($pathNull, $fail, $body);

        $context->builder->positionAtEnd($body);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::OPENDIR),
            [$path]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(-1, true));
    }

    private static function emitNullableStringBridge(
        Context $context,
        LlvmFunction $fn,
        string $helperLogical,
        int $i64ArgCount
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');

        $entry = $fn->appendBasicBlock('dir_str_bridge_entry');
        $fail = $fn->appendBasicBlock('dir_str_bridge_fail');
        $body = $fn->appendBasicBlock('dir_str_bridge_body');
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
            throw new \LogicException($logical.' missing after DirHandleJitHelper compile (#11811)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'DirHandleJitHelper.php');
            if (null === $block) {
                throw new \LogicException('DirHandleJitHelper.php parseAndCompile failed (#11811)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT dir handles (#11811)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StringDirRuntime bridge (#11811)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
