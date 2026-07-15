<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Extract {@see __object__*} from nested-JIT ObjectEntry receivers (#19048). */
final class ObjectNestedReceiver
{
    public static function objectFromReceiver(Context $context, Variable $receiver): Value
    {
        $objPtrTy = $context->getTypeFromString('__object__*');
        if (Variable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }

        return $context->builder->bitcast($context->helper->loadValue($receiver), $objPtrTy);
    }
}
