<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Web\Superglobals;

/**
 * Zend zend_compare() for unlike kinds involving array/object/null (#12033).
 *
 * Kept out of {@see CompareJitHelper} so NestedJIT spaceship compile (#9381 / #21109)
 * does not lower this VM-only path into the JIT module.
 *
 * php-src: Zend/zend_operators.c — compare_function
 * SSOT consumers: {@see Variable::spaceshipOp()}
 */
final class CompareUnlikeHelper
{
    /**
     * VM SSOT for relational < > <= >= and spaceship when {@see Variable::spaceshipMixedScalars()}
     * would coerce through toNumeric() and throw on array/object operands.
     */
    public static function zendUnlikeValueSpaceship(
        Variable $left,
        Variable $right,
        ?\PHPCompiler\VM $vm = null
    ): int {
        $left = $left->resolveIndirect();
        $right = $right->resolveIndirect();

        if (Variable::TYPE_ARRAY === $left->type && Variable::TYPE_OBJECT === $right->type) {
            return -1;
        }
        if (Variable::TYPE_OBJECT === $left->type && Variable::TYPE_ARRAY === $right->type) {
            return 1;
        }

        if (Variable::TYPE_OBJECT === $left->type && Variable::TYPE_NULL === $right->type) {
            return 1;
        }
        if (Variable::TYPE_NULL === $left->type && Variable::TYPE_OBJECT === $right->type) {
            return -1;
        }

        if (Variable::TYPE_OBJECT === $left->type || Variable::TYPE_OBJECT === $right->type) {
            if (Variable::TYPE_BOOLEAN === $left->type || Variable::TYPE_BOOLEAN === $right->type) {
                $object = Variable::TYPE_OBJECT === $left->type ? $left : $right;
                $bool = Variable::TYPE_BOOLEAN === $left->type ? $left : $right;
                $casted = (int) $object->toBool();
                // Variable::$bool is private — use toBool() (#29629 / zend_operators.c).
                $cmp = CompareJitHelper::longSpaceship($casted, (int) $bool->toBool());

                return Variable::TYPE_OBJECT === $left->type ? $cmp : -$cmp;
            }
            if (Variable::TYPE_STRING === $left->type || Variable::TYPE_STRING === $right->type) {
                $stringableCmp = CompareStringableHelper::spaceship($vm, $left, $right);
                if (null !== $stringableCmp) {
                    return $stringableCmp;
                }
            }
            // Zend compare_function: plain object vs number → E_NOTICE + legacy 1 (#29121).
            if (
                Variable::TYPE_INTEGER === $left->type || Variable::TYPE_INTEGER === $right->type
                || Variable::TYPE_FLOAT === $left->type || Variable::TYPE_FLOAT === $right->type
            ) {
                return self::objectVsNumberSpaceship($left, $right, $vm);
            }

            return Variable::TYPE_OBJECT === $left->type ? 1 : -1;
        }

        if (Variable::TYPE_ARRAY === $left->type || Variable::TYPE_ARRAY === $right->type) {
            if (Variable::TYPE_NULL === $left->type || Variable::TYPE_NULL === $right->type) {
                $array = Variable::TYPE_ARRAY === $left->type ? $left : $right;
                $nullOnLeft = Variable::TYPE_NULL === $left->type;

                return self::zendIsTrue($array) ? ($nullOnLeft ? -1 : 1) : 0;
            }
            if (Variable::TYPE_BOOLEAN === $left->type || Variable::TYPE_BOOLEAN === $right->type) {
                $array = Variable::TYPE_ARRAY === $left->type ? $left : $right;
                $bool = Variable::TYPE_BOOLEAN === $left->type ? $left : $right;
                $arrayTruthy = self::zendIsTrue($array);
                // Variable::$bool is private — use toBool() (#29629 / zend_operators.c).
                $boolVal = (int) $bool->toBool();
                if ($arrayTruthy === (bool) $boolVal) {
                    return 0;
                }

                return Variable::TYPE_ARRAY === $left->type
                    ? ($arrayTruthy ? 1 : -1)
                    : ($arrayTruthy ? -1 : 1);
            }

            return Variable::TYPE_ARRAY === $left->type ? 1 : -1;
        }

        throw new \LogicException('zendUnlikeValueSpaceship requires array/object/null unlike-kind operands');
    }

    /**
     * Zend convert_object_to_type(IS_LONG|IS_DOUBLE) during compare_function (#29121).
     *
     * Notice text is "converted to int" vs int and "converted to float" vs float; value is 1 / 1.0.
     */
    private static function objectVsNumberSpaceship(
        Variable $left,
        Variable $right,
        ?\PHPCompiler\VM $vm
    ): int {
        $objectOnLeft = Variable::TYPE_OBJECT === $left->type;
        $object = $objectOnLeft ? $left : $right;
        $number = $objectOnLeft ? $right : $left;
        $asFloat = Variable::TYPE_FLOAT === $number->type;
        self::emitPlainObjectToNumberNotice($object, $asFloat ? 'float' : 'int', $vm);
        if ($asFloat) {
            $cmp = CompareJitHelper::doubleSpaceship(1.0, $number->toFloat());
        } else {
            $cmp = CompareJitHelper::longSpaceship(1, $number->toInt());
        }

        return $objectOnLeft ? $cmp : -$cmp;
    }

    /** @param 'int'|'float' $kind */
    private static function emitPlainObjectToNumberNotice(
        Variable $object,
        string $kind,
        ?\PHPCompiler\VM $vm
    ): void {
        $entry = $object->resolveIndirect()->toObject();
        if (ResourceSupport::isResourceObject($entry) || EnumCaseSupport::isEnumCase($entry)) {
            return;
        }
        $context = $vm?->context ?? Superglobals::getActiveContext();
        if (null === $context) {
            return;
        }
        $frame = null;
        try {
            $frame = VmExecutingFrame::requireFromActiveContext();
        } catch (\LogicException) {
            $frame = null;
        }
        $message = 'int' === $kind
            ? 'Object of class '.$entry->class->name.' could not be converted to int'
            : 'Object of class '.$entry->class->name.' could not be converted to float';
        $context->errors->triggerError(
            $message,
            ErrorReporter::E_NOTICE,
            null !== $frame && '' !== ($frame->scriptPath ?? '') ? $frame->scriptPath : null,
            $context,
            $frame
        );
    }

    /** Zend zend_is_true() for compare tail — empty array is false (#12033). */
    private static function zendIsTrue(Variable $var): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY === $var->type) {
            return 0 !== $var->toArray()->getNumElements();
        }

        return $var->toBool();
    }
}
