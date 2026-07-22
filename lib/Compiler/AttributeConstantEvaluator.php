<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Scalar;
use PHPCompiler\VM\AttributeSupport;

/**
 * Evaluate attribute constructor arguments that must be compile-time constants (#3206, #3340, #21725).
 *
 * php-src: zend_compile_attribute / zend_ast_evaluate constant expression rules (subset).
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
        if ($expr instanceof Expr\UnaryMinus) {
            $v = self::evalExpr($expr->expr);
            if (!\is_int($v) && !\is_float($v)) {
                throw new \LogicException(
                    'Attribute constructor arguments must be compile-time constant expressions in this compiler build'
                );
            }

            return -$v;
        }
        if ($expr instanceof Expr\UnaryPlus) {
            $v = self::evalExpr($expr->expr);
            if (!\is_int($v) && !\is_float($v)) {
                throw new \LogicException(
                    'Attribute constructor arguments must be compile-time constant expressions in this compiler build'
                );
            }

            return +$v;
        }
        if ($expr instanceof Expr\New_) {
            return self::evalNew($expr);
        }
        if ($expr instanceof Expr\Array_) {
            return self::evalArray($expr);
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
        // php-src zend_compile_attribute / zend_ast_evaluate — arithmetic const exprs (#21725).
        if ($expr instanceof BinaryOp\Plus) {
            return self::evalNumericBinary($expr, '+');
        }
        if ($expr instanceof BinaryOp\Minus) {
            return self::evalNumericBinary($expr, '-');
        }
        if ($expr instanceof BinaryOp\Mul) {
            return self::evalNumericBinary($expr, '*');
        }
        if ($expr instanceof BinaryOp\Div) {
            return self::evalNumericBinary($expr, '/');
        }
        if ($expr instanceof BinaryOp\Mod) {
            return self::evalNumericBinary($expr, '%');
        }
        if ($expr instanceof BinaryOp\Pow) {
            return self::evalNumericBinary($expr, '**');
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
        // Profile-aware; never host \Attribute (#20727).
        return AttributeSupport::builtinConstValue($lcConst);
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

    /**
     * Arithmetic const exprs for attribute args (php-src zend_ast_evaluate subset).
     *
     * @return int|float
     */
    private static function evalNumericBinary(BinaryOp $expr, string $op): int|float
    {
        $left = self::evalExpr($expr->left);
        $right = self::evalExpr($expr->right);
        if ((!(\is_int($left) || \is_float($left))) || (!(\is_int($right) || \is_float($right)))) {
            throw new \LogicException(
                'Attribute constructor arguments must be compile-time constant expressions in this compiler build'
            );
        }

        return match ($op) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '/' => $left / $right,
            '%' => $left % $right,
            '**' => $left ** $right,
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

    /**
     * Array literals in attribute ctor args / nested `new` args (php-src zend_ast_evaluate; #22391).
     *
     * @return array<int|string, mixed>
     */
    private static function evalArray(Expr\Array_ $expr): array
    {
        $result = [];
        $nextIndex = 0;
        foreach ($expr->items as $item) {
            if (null === $item) {
                throw new \LogicException(
                    'Attribute constructor arguments must be compile-time constant expressions in this compiler build'
                );
            }
            if ($item->unpack) {
                throw new \LogicException(
                    'Attribute constructor arguments must be compile-time constant expressions in this compiler build'
                );
            }
            $value = self::evalExpr($item->value);
            if (null === $item->key) {
                $result[$nextIndex] = $value;
                ++$nextIndex;

                continue;
            }
            $key = self::evalExpr($item->key);
            if (!\is_int($key) && !\is_string($key)) {
                throw new \LogicException(
                    'Attribute constructor arguments must be compile-time constant expressions in this compiler build'
                );
            }
            $result[$key] = $value;
            if (\is_int($key) && $key >= $nextIndex) {
                $nextIndex = $key + 1;
            }
        }

        return $result;
    }
}
