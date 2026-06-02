<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT helper for dirname() $levels validation (php-src ext/standard/dir.c). */
final class JitDirname
{
    private const LEVELS_ERROR = 'dirname(): Argument #2 ($levels) must be greater than or equal to 1';

    public static function coerceLevels(Context $context, JITVariable $arg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return $context->builder->fptosi($context->helper->loadValue($arg), $i64);
        }
        if (JITVariable::TYPE_STRING === $arg->type) {
            $literal = JitStringArg::compileTimeLiteral($arg);
            if (null !== $literal) {
                return $i64->constInt((int) $literal, false);
            }
            $str = JitStringArg::lower($context, $arg, 'dirname() levels');

            return self::strtolString($context, $str);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        return JitLongArg::lower($context, $arg, 'dirname() levels');
    }

    public static function emitRuntimeLevelsGuard(Context $context, Value $levels): void
    {
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $invalid = $context->builder->icmp(Builder::INT_SLT, $levels, $one);
        $okBlock = BasicBlockHelper::append($context, 'dirname_levels_ok');
        $errBlock = BasicBlockHelper::append($context, 'dirname_levels_err');
        $context->builder->branchIf($invalid, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::LEVELS_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }

    private static function strtolString(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];
        $charPtr = $context->builder->structGep($str, $map['value']);
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $end = $context->builder->alloca($i8p, 1, 'dirname_levels_strtol_end');
        $context->builder->store($i8p->constNull(), $end);

        return $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $end,
            $i64->constInt(10, false)
        );
    }
}
