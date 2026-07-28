<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;

/** LLVM validation for fputcsv()/fgetcsv()/str_getcsv() CSV option strings (#4530, #12018, #24148). */
final class JitCsvArg
{
    /**
     * @return bool true when lowering may continue; false after a compile-time ValueError
     */
    public static function validateFgetcsvCall(Context $context, JITVariable ...$args): bool
    {
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            if (!self::validateArg($context, $args[2], 3, 'separator', false, 'fgetcsv')) {
                return false;
            }
        }
        if (isset($args[3]) && !NamedOptionalCallArgs::isOmittedOptional($args[3])) {
            if (!self::validateArg($context, $args[3], 4, 'enclosure', false, 'fgetcsv')) {
                return false;
            }
        }
        if (isset($args[4]) && !NamedOptionalCallArgs::isOmittedOptional($args[4])) {
            if (!self::validateArg($context, $args[4], 5, 'escape', true, 'fgetcsv')) {
                return false;
            }
        }

        return true;
    }

    /**
     * str_getcsv() — PHP 8.4+ single-byte checks (#24148; args are #2/#3/#4).
     *
     * @return bool true when lowering may continue; false after a compile-time ValueError
     */
    public static function validateStrGetcsvCall(Context $context, JITVariable ...$args): bool
    {
        if (!VmCsvArg::shouldValidateStrGetcsvSingleChar()) {
            return true;
        }
        if (isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            if (!self::validateArg($context, $args[1], 2, 'separator', false, 'str_getcsv')) {
                return false;
            }
        }
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            if (!self::validateArg($context, $args[2], 3, 'enclosure', false, 'str_getcsv')) {
                return false;
            }
        }
        if (isset($args[3]) && !NamedOptionalCallArgs::isOmittedOptional($args[3])) {
            if (!self::validateArg($context, $args[3], 4, 'escape', true, 'str_getcsv')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return bool true when lowering may continue; false after a compile-time ValueError
     */
    public static function validateFputcsvCall(Context $context, JITVariable ...$args): bool
    {
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            if (!self::validateArg($context, $args[2], 3, 'separator', false, 'fputcsv')) {
                return false;
            }
        }
        if (isset($args[3]) && !NamedOptionalCallArgs::isOmittedOptional($args[3])) {
            if (!self::validateArg($context, $args[3], 4, 'enclosure', false, 'fputcsv')) {
                return false;
            }
        }
        if (isset($args[4]) && !NamedOptionalCallArgs::isOmittedOptional($args[4])) {
            if (!self::validateArg($context, $args[4], 5, 'escape', true, 'fputcsv')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return bool true when OK / runtime-guard emitted; false after compile-time ValueError
     */
    private static function validateArg(
        Context $context,
        JITVariable $arg,
        int $argNum,
        string $paramName,
        bool $allowEmpty,
        string $function = 'fputcsv',
    ): bool {
        $message = $allowEmpty
            ? \sprintf('%s(): Argument #%d ($%s) must be empty or a single character', $function, $argNum, $paramName)
            : \sprintf('%s(): Argument #%d ($%s) must be a single character', $function, $argNum, $paramName);

        if (null !== ($arg->compileTimeString ?? null)) {
            $value = $arg->compileTimeString;
            $ok = $allowEmpty ? \strlen($value) <= 1 : 1 === \strlen($value);
            if ($ok) {
                return true;
            }
            // Known-bad literal — catchable ValueError; caller must not emit the builtin body (#24148).
            self::emitCompileTimeValueError($context, $message);

            return false;
        }

        TypeErrorRaise::registerDeclarations($context);
        $resume = $context->builder->getInsertBlock();
        TypeErrorRaise::ensureLinked($context);
        $context->builder->positionAtEnd($resume);

        $strPtr = $context->builder->load(
            $context->helper->loadValue($arg)
        );
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $blockPrefix = 'csv_arg_'.$function.'_'.$argNum;
        if ($allowEmpty) {
            $tooLong = $context->builder->icmp(Builder::INT_SGT, $len, $one);
            $ok = $context->builder->icmp(
                Builder::INT_EQ,
                $tooLong,
                $context->getTypeFromString('int1')->constInt(0, false)
            );
        } else {
            $ok = $context->builder->icmp(Builder::INT_EQ, $len, $one);
        }
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $ok,
            $blockPrefix,
            $message
        );

        return true;
    }

    private static function emitCompileTimeValueError(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        $resume = BasicBlockHelper::tryGetInsertBlock($context);
        TypeErrorRaise::ensureLinked($context);
        if (null !== $resume) {
            BasicBlockHelper::restoreInsertBlock($context, $resume);
        }

        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            $llvmFunc = BasicBlockHelper::parentFunction($context);
            TryCatchHelper::emitCatchableClassError($context, 'ValueError', $message);
            $dead = $llvmFunc->appendBasicBlock('csv_arg_lit_catch_dead');
            $context->builder->positionAtEnd($dead);

            return;
        }

        // Same shape as fputcsv::emitCompileTimeCsvValidationFailure — err block + dead after.
        $errBlock = BasicBlockHelper::append($context, 'csv_arg_lit_err');
        $afterBlock = BasicBlockHelper::append($context, 'csv_arg_lit_after');
        $context->builder->branch($errBlock);
        $context->builder->positionAtEnd($errBlock);
        TypeErrorRaise::emitValueError($context, $message);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
        } else {
            $context->builder->call($context->lookupFunction('abort'));
        }
        $context->builder->positionAtEnd($afterBlock);
    }
}
