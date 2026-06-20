<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\VM\InOperatorJitHelper;
use PHPLLVM\Value;

/**
 * `$needle in $haystack` JIT lowering (#4716, #10172, #10342).
 *
 * SSOT: {@see \PHPCompiler\VM\InOperator}, {@see \PHPCompiler\VM\InOperatorJitHelper}
 */
final class InOperatorHelper
{
    private const HELPER_PATH = '/VM/InOperatorJitHelper.php';

    private const VALUE_BOX_HELPER = 'PHPCompiler\\VM\\InOperatorJitHelper::valueBoxHaystackIsArray';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::VALUE_BOX_HELPER,
    ];

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
            self::ensureValueBoxBridgeLinked($context);
            $isArray = self::callValueBoxHaystackIsArray($context, $typeByte);
            $okBlock = BasicBlockHelper::append($context, 'type_in_haystack_ok');
            $failBlock = BasicBlockHelper::append($context, 'type_in_haystack_fail');
            $context->builder->branchIf($isArray, $okBlock, $failBlock);
            $context->builder->positionAtEnd($failBlock);
            self::emitHaystackTypeError($context, $needle, $haystack);
            $context->builder->positionAtEnd($okBlock);

            return;
        }

        self::emitHaystackTypeError($context, $needle, $haystack);
    }

    private static function emitHaystackTypeError(Context $context, Variable $needle, Variable $haystack): void
    {
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

        return InOperatorJitHelper::jitOperandLabel(
            $var->type,
            ArrayBuiltinHelper::isNativeArray($var->type)
        );
    }

    private static function ensureValueBoxBridgeLinked(Context $context): void
    {
        $abiName = '__in_op__valueBoxHaystackIsArray';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            $abiName,
            'in_op_value_box_bridge_entry',
            [$i8],
            $i1,
            self::VALUE_BOX_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#10342'
        );
        $context->builder->clearInsertionPosition();
    }

    private static function callValueBoxHaystackIsArray(Context $context, Value $typeByte): Value
    {
        self::ensureValueBoxBridgeLinked($context);
        $fn = $context->lookupFunction('__in_op__valueBoxHaystackIsArray');
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->call(
            $fn,
            $context->builder->trunc($typeByte, $i8)
        );
    }
}
