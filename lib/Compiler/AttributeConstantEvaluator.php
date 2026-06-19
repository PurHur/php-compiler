<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Scalar;
use PHPCompiler\VM\AttributeSupport;

/**
 * Evaluate attribute constructor arguments that must be compile-time constants (#3206, #3340).
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
        if ($expr instanceof Expr\UnaryPlus && $expr->expr instanceof Scalar\LNumber) {
            return (int) $expr->expr->value;
        }
        if ($expr instanceof Expr\New_) {
            return self::evalNew($expr);
        }
        if ($expr instanceof Expr\ClassConstFetch) {
            return self::evalClassConstFetch($expr); // int|CompileTimeEnumCase
        }
        if ($expr instanceof BinaryOp\BitwiseOr) {
            return self::evalIntBinary($expr, '|');
        }
        if ($expr instanceof BinaryOp\BitwiseAnd) {
            return self::evalIntBinary($expr, '&');
        }
        if ($expr instanceof BinaryOp\BitwiseXor) {
            return self::evalIntBinary($expr, '^');
        }

        throw new \LogicException(
            'Attribute constructor arguments must be compile-time constant expressions in this compiler build'
        );
    }

    private static function evalClassConstFetch(Expr\ClassConstFetch $expr): int|CompileTimeEnumCase
    {
        if (!$expr->class instanceof Node\Name) {
            throw new \LogicException(
                'Attribute constructor arguments must be compile-time constant expressions in this compiler build'
            );
        }
        if (!$expr->name instanceof Node\Identifier) {
            throw new \LogicException(
                'Attribute constructor arguments must be compile-time constant expressions in this compiler build'
            );
        }

        $className = ltrim($expr->class->toString(), '\\');
        $constName = $expr->name->toString();
        if ('attribute' === strtolower($className)) {
            $value = self::attributeBuiltinConstValue(strtolower($constName));
            if (null === $value) {
                throw new \LogicException(
                    'Attribute constructor arguments must be compile-time constant expressions in this compiler build'
                );
            }

            return $value;
        }

        // php-src: backed/unit enum case fetches are valid attribute const exprs (#9988, zend_compile.c).
        if ('class' === strtolower($constName)) {
            throw new \LogicException(
                'Attribute constructor arguments must be compile-time constant expressions in this compiler build'
            );
        }

        return new CompileTimeEnumCase($className, $constName);
    }

    private static function attributeBuiltinConstValue(string $lcConst): ?int
    {
        return match ($lcConst) {
            'target_class' => AttributeSupport::TARGET_CLASS,
            'target_function' => AttributeSupport::TARGET_FUNCTION,
            'target_method' => AttributeSupport::TARGET_METHOD,
            'target_property' => AttributeSupport::TARGET_PROPERTY,
            'target_class_constant' => AttributeSupport::TARGET_CLASS_CONSTANT,
            'target_parameter' => AttributeSupport::TARGET_PARAMETER,
            'target_constant' => AttributeSupport::TARGET_CONSTANT,
            'target_all' => AttributeSupport::TARGET_ALL,
            'is_repeatable' => AttributeSupport::IS_REPEATABLE,
            default => null,
        };
    }

    private static function evalIntBinary(BinaryOp $expr, string $op): int
    {
        $left = self::evalExpr($expr->left);
        $right = self::evalExpr($expr->right);
        if (!\is_int($left) || !\is_int($right)) {
            throw new \LogicException(
                'Attribute constructor arguments must be compile-time constant expressions in this compiler build'
            );
        }

        return match ($op) {
            '|' => $left | $right,
            '&' => $left & $right,
            '^' => $left ^ $right,
            default => throw new \LogicException(
                'Attribute constructor arguments must be compile-time constant expressions in this compiler build'
            ),
        };
    }

    private static function evalNew(Expr\New_ $expr): CompileTimeNew
    {
        if (!$expr->class instanceof Node\Name) {
            throw new \LogicException(
                'Dynamic class name in attribute constructor new expression is not supported'
            );
        }
        $args = [];
        foreach ($expr->args as $arg) {
            $args[] = self::evalArg($arg);
        }

        return new CompileTimeNew($expr->class->toString(), $args);
    }
}
