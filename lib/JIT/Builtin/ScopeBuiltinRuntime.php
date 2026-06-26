<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
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
    private static int $standaloneBlockSeq = 0;

    private const HELPER_PATH = '/ext/standard/ScopeBuiltinJitHelper.php';

    private const COMPACT_UNDEF_HELPER = 'PHPCompiler\\ext\\standard\\ScopeBuiltinJitHelper::emitCompactUndefinedVariableWarning';

    private const COMPACT_INVALID_ARG_HELPER = 'PHPCompiler\\ext\\standard\\ScopeBuiltinJitHelper::emitCompactInvalidArgumentWarning';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COMPACT_UNDEF_HELPER,
        self::COMPACT_INVALID_ARG_HELPER,
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
            self::helperFunction($context, self::COMPACT_UNDEF_HELPER),
            $context->constantFromString($name)
        );
    }

    public static function emitCompactInvalidArgumentWarning(Context $context, int $argNum, Value $typeByte): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::emitStandaloneCompactInvalidArgumentWarning($context, $argNum, $typeByte);

            return;
        }

        self::ensureJitHelperCompiled($context);
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $context->builder->call(
            self::helperFunction($context, self::COMPACT_INVALID_ARG_HELPER),
            $i64->constInt($argNum, false),
            $context->builder->trunc($typeByte, $i8)
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
        $context->builder->call(self::helperFunction($context, self::COMPACT_UNDEF_HELPER), $strPtr);
    }

    private static function emitStandaloneCompactInvalidArgumentWarning(
        Context $context,
        int $argNum,
        Value $typeByte
    ): void {
        StringTriggerError::ensureLinked($context);
        $i8 = $context->getTypeFromString('int8');
        $tag = 'cia'.(string) ++self::$standaloneBlockSeq;
        $done = BasicBlockHelper::append($context, 'compact_invalid_done_'.$tag);
        $afterInt = BasicBlockHelper::append($context, 'compact_invalid_after_int_'.$tag);
        $afterFloat = BasicBlockHelper::append($context, 'compact_invalid_after_float_'.$tag);
        $afterBool = BasicBlockHelper::append($context, 'compact_invalid_after_bool_'.$tag);
        $afterString = BasicBlockHelper::append($context, 'compact_invalid_after_string_'.$tag);
        $afterArray = BasicBlockHelper::append($context, 'compact_invalid_after_array_'.$tag);
        $intBlock = BasicBlockHelper::append($context, 'compact_invalid_int_'.$tag);
        $floatBlock = BasicBlockHelper::append($context, 'compact_invalid_float_'.$tag);
        $boolBlock = BasicBlockHelper::append($context, 'compact_invalid_bool_'.$tag);
        $stringBlock = BasicBlockHelper::append($context, 'compact_invalid_string_'.$tag);
        $arrayBlock = BasicBlockHelper::append($context, 'compact_invalid_array_'.$tag);
        $unknownBlock = BasicBlockHelper::append($context, 'compact_invalid_unknown_'.$tag);

        $isInt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $context->builder->branchIf($isInt, $intBlock, $afterInt);

        $context->builder->positionAtEnd($intBlock);
        self::emitStandaloneCompactInvalidArgumentWarningMessage($context, $argNum, 'int');
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterInt);
        $isFloat = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $context->builder->branchIf($isFloat, $floatBlock, $afterFloat);

        $context->builder->positionAtEnd($floatBlock);
        self::emitStandaloneCompactInvalidArgumentWarningMessage($context, $argNum, 'float');
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterFloat);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        self::emitStandaloneCompactInvalidArgumentWarningMessage($context, $argNum, 'bool');
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterBool);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        self::emitStandaloneCompactInvalidArgumentWarningMessage($context, $argNum, 'string');
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $context->builder->branchIf($isArray, $arrayBlock, $afterArray);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitStandaloneCompactInvalidArgumentWarningMessage($context, $argNum, 'array');
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterArray);
        $context->builder->branch($unknownBlock);

        $context->builder->positionAtEnd($unknownBlock);
        self::emitStandaloneCompactInvalidArgumentWarningMessage($context, $argNum, 'unknown type');
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function emitStandaloneCompactInvalidArgumentWarningMessage(
        Context $context,
        int $argNum,
        string $typeName
    ): void {
        $message = \PHPCompiler\ext\standard\ScopeBuiltinJitHelper::compactInvalidArgumentMessage($argNum, $typeName);
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

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ScopeBuiltinJitHelper compile (#10184)');
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
