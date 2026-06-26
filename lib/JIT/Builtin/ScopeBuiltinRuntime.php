<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for compact() warnings via ScopeBuiltinJitHelper PHP (#10184).
 *
 * Replaces libc snprintf warning formatting in {@see ScopeBuiltinEmitHelper}.
 * SSOT: {@see \PHPCompiler\ext\standard\VmScope}
 */
final class ScopeBuiltinRuntime
{
    private const HELPER_PATH = '/ext/standard/ScopeBuiltinJitHelper.php';

    private const COMPACT_UNDEF_HELPER = 'PHPCompiler\\ext\\standard\\ScopeBuiltinJitHelper::emitCompactUndefinedVariableWarning';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COMPACT_UNDEF_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function emitCompactUndefinedVariableWarning(Context $context, string $name): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::emitStandaloneCompactUndefinedWarning($context, $name);

            return;
        }

        self::ensureJitHelperCompiled($context);
        $context->builder->call(
            self::helperFunction($context),
            $context->constantFromString($name)
        );
    }

    public static function emitCompactUndefinedVariableWarningFromCstr(Context $context, Value $namePtr): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::emitStandaloneCompactUndefinedWarningFromCstr($context, $namePtr);

            return;
        }

        self::ensureJitHelperCompiled($context);
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->call($context->lookupFunction('strlen'), $namePtr);
        $lenI64 = $context->builder->zExt($len, $i64);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $namePtr
        );
        $context->builder->call(self::helperFunction($context), $strPtr);
    }

    private static function emitStandaloneCompactUndefinedWarning(Context $context, string $name): void
    {
        StringTriggerError::ensureLinked($context);
        $message = \PHPCompiler\ext\standard\ScopeBuiltinJitHelper::compactUndefinedVariableMessage($name);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $sizeT->constInt(\strlen($message), false),
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    private static function emitStandaloneCompactUndefinedWarningFromCstr(Context $context, Value $namePtr): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $buf = $context->builder->alloca($i8, 128, 'compact_undef_msg');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $fmtPtr = $context->builder->pointerCast(
            $context->constantFromString('compact(): Undefined variable $%s'),
            $i8p
        );
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufPtr,
            $sizeT->constInt(128, false),
            $fmtPtr,
            $namePtr
        );
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $bufPtr);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $bufPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p),
            $i32->constInt(0, false)
        );
    }

    private static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower(self::COMPACT_UNDEF_HELPER);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException(self::COMPACT_UNDEF_HELPER.' missing after ScopeBuiltinJitHelper compile (#10184)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ScopeBuiltinJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ScopeBuiltinJitHelper.php parseAndCompile failed (#10184)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#10184)');
            }
        }
    }
}
