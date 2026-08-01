<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Scalar;
use PHPCompiler\ext\standard\DateConstants;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmPhpCoreConstants;
use PHPCompiler\VM\AttributeSupport;
use PHPCompiler\VM\Context as VmContext;
use PHPCompiler\VM\Variable;

/**
 * Evaluate attribute constructor arguments that must be compile-time constants (#3206, #3340, #21725, #26030, #26627).
 *
 * php-src: zend_compile_attribute / zend_ast_evaluate constant expression rules (subset).
 */
final class AttributeConstantEvaluator
{
    /** Compilation-unit path for folding `__DIR__` / `__FILE__` in attribute args (#26030). */
    private static string $scriptFile = '';

    /**
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public static function withScriptFile(string $scriptFile, callable $fn): mixed
    {
        $prev = self::$scriptFile;
        self::$scriptFile = $scriptFile;
        try {
            return $fn();
        } finally {
            self::$scriptFile = $prev;
        }
    }

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
        if ($expr instanceof Scalar\MagicConst\Dir) {
            return self::evalMagicDir();
        }
        if ($expr instanceof Scalar\MagicConst\File) {
            return self::evalMagicFile();
        }
        if ($expr instanceof Scalar\MagicConst\Line) {
            $line = $expr->getStartLine();

            return $line > 0 ? $line : 1;
        }
        if ($expr instanceof Expr\ConstFetch) {
            return self::evalConstFetch($expr);
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
            return self::evalClassConstFetch($expr); // int|string|CompileTimeEnumCase
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

    /**
     * Built-in / stdlib ConstFetch — mirrors Compiler::tryFoldGlobalConstFetch (#26030).
     */
    private static function evalConstFetch(Expr\ConstFetch $expr): mixed
    {
        $name = $expr->name->toString();
        $lc = strtolower($name);
        if ('null' === $lc) {
            return null;
        }
        if ('true' === $lc) {
            return true;
        }
        if ('false' === $lc) {
            return false;
        }

        $core = VmPhpCoreConstants::fetch($name);
        if (null !== $core) {
            return self::variableToPhpScalar($core);
        }
        $errorInt = VmContext::errorReportingConstant($name);
        if (null !== $errorInt) {
            return $errorInt;
        }
        if ('inf' === $lc) {
            return INF;
        }
        if ('nan' === $lc) {
            return NAN;
        }
        $stdlibInt = StdlibConstants::coreIntByName($lc);
        if (null !== $stdlibInt) {
            return $stdlibInt;
        }
        $dateStr = DateConstants::CORE_STRING_BY_NAME[$lc] ?? null;
        if (null !== $dateStr) {
            return $dateStr;
        }
        $stdlibStr = StdlibConstants::CORE_STRING_BY_NAME[$lc] ?? null;
        if (null !== $stdlibStr) {
            return $stdlibStr;
        }

        throw new \LogicException(
            'Attribute constructor arguments must be compile-time constant expressions in this compiler build'
        );
    }

    private static function variableToPhpScalar(Variable $var): mixed
    {
        return match ($var->type) {
            Variable::TYPE_NULL => null,
            Variable::TYPE_INTEGER => $var->toInt(),
            Variable::TYPE_FLOAT => $var->toFloat(),
            Variable::TYPE_BOOLEAN => $var->toBool(),
            Variable::TYPE_STRING => $var->toString(),
            default => throw new \LogicException(
                'Attribute constructor arguments must be compile-time constant expressions in this compiler build'
            ),
        };
    }

    private static function evalMagicFile(): string
    {
        $file = self::$scriptFile;
        if ('' === $file) {
            throw new \LogicException(
                'Attribute constructor arguments must be compile-time constant expressions in this compiler build'
            );
        }
        if (is_file($file)) {
            $real = realpath($file);
            if (false !== $real) {
                return $real;
            }
        }

        return $file;
    }

    private static function evalMagicDir(): string
    {
        return dirname(self::evalMagicFile());
    }

    private static function evalClassConstFetch(Expr\ClassConstFetch $expr): int|string|CompileTimeEnumCase
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

        // php-src: Foo::class / self::class / parent::class are valid attribute const exprs
        // (zend_compile.c). PHP-CFG already resolves self/parent to concrete names before
        // AttributeMetadata runs; static::class remains and is a compile-time fatal (#26627).
        if ('class' === strtolower($constName)) {
            if ('static' === strtolower($className)) {
                throw new \LogicException(
                    'static::class cannot be used for compile-time class name resolution'
                );
            }

            return $className;
        }

        // php-src: backed/unit enum case fetches are valid attribute const exprs (#9988, zend_compile.c).
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
