<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * Evaluate attribute constructor arguments that must be compile-time constants (#3206).
 *
 * php-src: zend_compile_attribute / constant expression rules (subset).
 */
final class AttributeConstantEvaluator
{
    /**
     * @return array{name: ?string, value: mixed}
     */
    public static function evalArg(Node\Arg $arg): array
    {
        return [
            'name' => null !== $arg->name ? $arg->name->toString() : null,
            'value' => self::evalExpr($arg->value),
        ];
    }

    public static function evalExpr(Expr $expr): mixed
    {
        if ($expr instanceof Scalar\String_) {
            return $expr->value;
        }
        if ($expr instanceof Scalar\LNumber) {
            return (int) $expr->value;
        }
        if ($expr instanceof Scalar\DNumber) {
            return (float) $expr->value;
        }
        if ($expr instanceof Expr\ConstFetch) {
            $name = strtolower($expr->name->toString());
            if ('null' === $name) {
                return null;
            }
            if ('true' === $name) {
                return true;
            }
            if ('false' === $name) {
                return false;
            }
        }
        if ($expr instanceof Expr\UnaryMinus && $expr->expr instanceof Scalar\LNumber) {
            return -(int) $expr->expr->value;
        }

        throw new \LogicException(
            'Attribute constructor arguments must be compile-time constant expressions in this compiler build'
        );
    }
}
