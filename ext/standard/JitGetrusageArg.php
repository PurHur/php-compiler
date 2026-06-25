<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for getrusage() $mode — typed int, no bool→int (php-src basic_functions.c; #11686). */
final class JitGetrusageArg
{
    public static function lowerMode(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            self::emitBoolTypeErrorAndAbort($context);

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedMode($context, $arg);
        }

        return JitSleep::zParamLong($context, $arg, 'getrusage', 1, 'mode');
    }

    private static function lowerBoxedMode(Context $context, JITVariable $arg): Value
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $boolTy = $i8->constInt(VmVariable::TYPE_BOOLEAN, false);
        $boolBlock = BasicBlockHelper::append($context, 'getrusage_mode_bool_err');
        $okBlock = BasicBlockHelper::append($context, 'getrusage_mode_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $boolTy),
            $boolBlock,
            $okBlock
        );
        $context->builder->positionAtEnd($boolBlock);
        self::emitBoolTypeErrorAndAbort($context);
        $context->builder->positionAtEnd($okBlock);

        return JitSleep::zParamLong($context, $arg, 'getrusage', 1, 'mode');
    }

    private static function emitBoolTypeErrorAndAbort(Context $context): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            'getrusage(): Argument #1 ($mode) must be of type int, bool given'
        );
        $context->builder->call($context->lookupFunction('abort'));
    }
}
