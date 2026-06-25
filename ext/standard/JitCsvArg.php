<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM validation for fputcsv() CSV option strings (#4530, ext/standard/file.c). */
final class JitCsvArg
{
    public static function validateFputcsvCall(Context $context, JITVariable ...$args): void
    {
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            self::validateArg($context, $args[2], 3, 'separator', false);
        }
        if (isset($args[3]) && !NamedOptionalCallArgs::isOmittedOptional($args[3])) {
            self::validateArg($context, $args[3], 4, 'enclosure', false);
        }
        if (isset($args[4]) && !NamedOptionalCallArgs::isOmittedOptional($args[4])) {
            self::validateArg($context, $args[4], 5, 'escape', true);
        }
    }

    private static function validateArg(
        Context $context,
        JITVariable $arg,
        int $argNum,
        string $paramName,
        bool $allowEmpty,
    ): void {
        if (null !== ($arg->compileTimeString ?? null)) {
            return;
        }

        $message = $allowEmpty
            ? \sprintf('fputcsv(): Argument #%d ($%s) must be empty or a single character', $argNum, $paramName)
            : \sprintf('fputcsv(): Argument #%d ($%s) must be a single character', $argNum, $paramName);

        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $strPtr = $context->builder->load(
            $context->helper->loadValue($arg)
        );
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $failBlock = BasicBlockHelper::append($context, 'fputcsv_csv_arg_fail_'.$argNum);
        $okBlock = BasicBlockHelper::append($context, 'fputcsv_csv_arg_ok_'.$argNum);
        if ($allowEmpty) {
            $tooLong = $context->builder->icmp(Builder::INT_SGT, $len, $one);
            $context->builder->branchIf($tooLong, $failBlock, $okBlock);
        } else {
            $notOne = $context->builder->icmp(Builder::INT_NE, $len, $one);
            $context->builder->branchIf($notOne, $failBlock, $okBlock);
        }
        $context->builder->positionAtEnd($failBlock);
        TypeErrorRaise::emitValueError($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }
}
