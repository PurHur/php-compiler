<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\TypeErrorRaise;

/**
 * `$needle in $haystack` JIT lowering (#4716, zend_compile.c).
 *
 * Reuses strict {@see ArrayBuiltinHelper::inArray()} (=== membership).
 */
final class InOperatorHelper
{
    public static function emitContains(Context $context, Variable $needle, Variable $haystack): Variable
    {
        self::guardHaystackIsArray($context, $needle, $haystack);
        $strict = $context->constantFromBool(true);
        $found = ArrayBuiltinHelper::inArray($context, $needle, $haystack, $strict, 'in_op');

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_BOOL,
            Variable::KIND_VALUE,
            $found
        );
    }

    private static function guardHaystackIsArray(
        Context $context,
        Variable $needle,
        Variable $haystack
    ): void {
        if (ArrayBuiltinHelper::isNativeArray($haystack->type)
            || Variable::TYPE_HASHTABLE === $haystack->type
        ) {
            return;
        }
        if (Variable::TYPE_VALUE === $haystack->type || JitValueBox::isValueOperand($haystack)) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $haystack);
            $map = $context->structFieldMap['__value__'];
            $typeByte = $context->builder->load(
                $context->builder->structGep($valuePtr, $map['type'])
            );
            $i8 = $context->getTypeFromString('int8');
            $okBlock = BasicBlockHelper::append($context, 'type_in_haystack_ok');
            $failBlock = BasicBlockHelper::append($context, 'type_in_haystack_fail');
            $context->builder->branchIf(
                $context->builder->icmp(
                    \PHPLLVM\Builder::INT_EQ,
                    $typeByte,
                    $i8->constInt(Variable::TYPE_HASHTABLE, false)
                ),
                $okBlock,
                $failBlock
            );
            $context->builder->positionAtEnd($failBlock);
            TypeErrorRaise::registerDeclarations($context);
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitRaise(
                $context,
                'Unsupported operand types: '
                .self::operandLabel($needle)
                .' in '
                .self::operandLabel($haystack)
            );
            $context->builder->call($context->lookupFunction('abort'));
            $context->builder->positionAtEnd($okBlock);

            return;
        }

        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            'Unsupported operand types: '
            .self::operandLabel($needle)
            .' in '
            .self::operandLabel($haystack)
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function operandLabel(Variable $var): string
    {
        if (Variable::TYPE_VALUE === $var->type || JitValueBox::isValueOperand($var)) {
            return 'mixed';
        }

        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_NATIVE_BOOL => 'bool',
            Variable::TYPE_NATIVE_LONG => 'int',
            Variable::TYPE_NATIVE_DOUBLE => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_OBJECT => 'object',
            default => ArrayBuiltinHelper::isNativeArray($var->type) || Variable::TYPE_HASHTABLE === $var->type
                ? 'array'
                : 'mixed',
        };
    }
}
