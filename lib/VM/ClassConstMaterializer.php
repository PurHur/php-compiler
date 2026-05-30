<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\Frame;
use PHPCompiler\OpCode;
use PHPCompiler\VM as VmEngine;

/**
 * Evaluate class constant initializer opcodes (e.g. {@code new stdClass()}) at class definition.
 *
 * @see Zend/zend_compile.c zend_compile_const_expr (php-src)
 */
final class ClassConstMaterializer
{
    public static function materializeSlot(VmEngine $vm, Block $bodyBlock, int $valueSlot): Variable
    {
        $frame = $bodyBlock->getFrame($vm->context);
        foreach ($bodyBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS_CONST === $op->type && $valueSlot === $op->arg2) {
                break;
            }
            if ($vm->isClassBodyConstInitOpcode($op->type)) {
                $vm->executeClassBodyConstInitOpcode($frame, $op);
            }
        }

        return self::detachConstantValue($frame->scope[$valueSlot]);
    }

    /**
     * Store an immortal copy of a class constant value (shared identity on fetch).
     */
    public static function detachConstantValue(Variable $src): Variable
    {
        $src = $src->resolveIndirect();
        $stored = new Variable($src->type);
        switch ($src->type) {
            case Variable::TYPE_NULL:
                $stored->null();
                break;
            case Variable::TYPE_STRING:
                $stored->string($src->toString());
                break;
            case Variable::TYPE_INTEGER:
                $stored->int($src->toInt());
                break;
            case Variable::TYPE_FLOAT:
                $stored->float($src->toFloat());
                break;
            case Variable::TYPE_BOOLEAN:
                $stored->bool($src->toBool());
                break;
            case Variable::TYPE_OBJECT:
                $stored->object($src->toObject());
                break;
            case Variable::TYPE_ARRAY:
                $stored->array($src->toArray());
                break;
            default:
                throw new \LogicException(
                    'Unsupported class constant value type: '.$src->type
                );
        }

        return $stored;
    }
}
