<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for number_format() (int/float/numeric string, 0–4 args; subset of PHP).
 *
 * php-src: ext/standard/number_format.c — Z_PARAM_LONG / Z_PARAM_STR
 */
final class JitNumberFormat
{
    public static function format(Context $context, JITVariable ...$args): Value
    {
        $argc = count($args);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('number_format() requires one to four arguments');
        }

        if ($context->callerStrictTypes) {
            self::rejectNullNum($context, $args[0]);
        }

        $number = JitFdiv::lowerSingleOperand($context, $args[0], 1, 'num', 'number_format', 'float');
        $i64 = $context->getTypeFromString('int64');
        $decimals = ($argc >= 2 && !NamedOptionalCallArgs::isOmittedOptional($args[1]))
            ? JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'number_format', 2, 'decimals')
            : $i64->constInt(0, false);
        $decSep = ($argc >= 3 && !NamedOptionalCallArgs::isOmittedOptional($args[2]))
            ? JitStringBuiltinArg::lower($context, $args[2], 'number_format', 2, 'decimal_separator', '?string')
            : $context->builder->load($context->constantStringFromString('.'));
        $thouSep = (4 === $argc && !NamedOptionalCallArgs::isOmittedOptional($args[3]))
            ? JitStringBuiltinArg::lower($context, $args[3], 'number_format', 3, 'thousands_separator', '?string')
            : $context->builder->load($context->constantStringFromString(','));

        return $context->builder->call(
            $context->lookupFunction('__compiler_number_format'),
            $number,
            $decimals,
            $decSep,
            $thouSep
        );
    }

    private static function rejectNullNum(Context $context, JITVariable $arg): void
    {
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            self::emitNullNumTypeErrorAndAbort($context);

            return;
        }
        if (JITVariable::TYPE_VALUE !== $arg->type) {
            return;
        }
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $okBlock = BasicBlockHelper::append($context, 'number_format_num_null_ok');
        $failBlock = BasicBlockHelper::append($context, 'number_format_num_null_fail');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_NULL, false)
            ),
            $failBlock,
            $okBlock
        );
        $context->builder->positionAtEnd($failBlock);
        self::emitNullNumTypeErrorAndAbort($context);
        $context->builder->positionAtEnd($okBlock);
    }

    private static function emitNullNumTypeErrorAndAbort(Context $context): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, 'number_format(): Argument #1 ($num) must be of type float, null given');
        $context->builder->call($context->lookupFunction('abort'));
    }

}
