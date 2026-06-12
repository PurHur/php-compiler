<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT json_encode() lowering via VmJsonFormat LLVM helpers (#6852).
 */
final class JitJsonEncode
{
    private static int $blockSerial = 0;

    public static function encode(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return self::stringOrFalse(
                $context,
                $context->builder->call(
                    $context->lookupFunction('__compiler_json_encode_array'),
                    $context->helper->loadValue($arg)
                )
            );
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::stringOrFalse(
                $context,
                $context->builder->call(
                    $context->lookupFunction('__compiler_json_encode_value'),
                    $context->helper->loadValue($arg)
                )
            );
        }

        throw new \LogicException('json_encode() only supports arrays in this compiler build');
    }

    /** @return Value __value__* — false bool when {@param $result} is null (Zend json_encode failure). */
    private static function stringOrFalse(Context $context, Value $result): Value
    {
        $id = (string) (++self::$blockSerial);
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $result, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'json_encode_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'json_encode_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'json_encode_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $result
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
