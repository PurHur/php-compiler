<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT helper for dirname() $levels validation (php-src ext/standard/dir.c). */
final class JitDirname
{
    private const LEVELS_ERROR = 'dirname(): Argument #2 ($levels) must be greater than or equal to 1';

    public static function coerceLevels(Context $context, JITVariable $arg): Value
    {
        return JitIntdiv::lowerIntBuiltinArg($context, $arg, 'dirname', 2, 'levels');
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
}
