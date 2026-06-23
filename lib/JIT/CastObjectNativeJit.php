<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\CastObjectFromHashtableJit;
use PHPCompiler\JIT\Builtin\CastObjectValueBoxJit;
use PHPCompiler\OpCode;

/**
 * Native-type (object) cast lowering — extracted from CastHelper (#10244).
 */
final class CastObjectNativeJit
{
    public static function emit(
        Context $context,
        Variable $src,
        Block $block,
        OpCode $op
    ): Variable {
        if (Variable::TYPE_OBJECT === $src->type) {
            return new Variable(
                $context,
                Variable::TYPE_OBJECT,
                Variable::KIND_VALUE,
                $context->helper->loadValue($src)
            );
        }
        if (Variable::TYPE_HASHTABLE === $src->type) {
            return CastObjectFromHashtableJit::emit($context, $src, $block, $op);
        }
        if (Variable::TYPE_VALUE === $src->type) {
            return CastObjectValueBoxJit::emit($context, $src, $block, $op);
        }

        throw new \LogicException(
            '(object) cast unsupported operand type in JIT: '.Variable::getStringType($src->type)
        );
    }
}
