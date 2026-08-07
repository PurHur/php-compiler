<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\OpCode;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * GMP object registration + do_operation / compare handlers
 * (php-src ext/gmp/gmp.c; issues #3341, #21265).
 */
final class VmGmpObject
{
    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[VmGmp::CLASS_LC])) {
            return;
        }

        $strProto = new Variable(Variable::TYPE_STRING);
        $pub = CfgFunc::FLAG_PUBLIC;

        $entry = new ClassEntry('GMP');
        $entry->isInternal = true;
        // php-src 8.4+ `final class GMP` (ext/gmp/gmp.stub.php; #28135). Pre-8.4 host
        // advertisement keeps subclassable ClassEntry when profile < 8.4.
        if (\PHPCompiler\CompilerVersion::supportsGmp()) {
            $entry->isFinal = true;
        }
        $entry->properties[] = new ClassProperty(VmGmp::PROP_VALUE, null, $strProto, true);

        $entry->methods['__tostring'] = new GmpToString();
        $entry->methodVisibility['__tostring'] = $pub;

        $ctx->classes[VmGmp::CLASS_LC] = $entry;
    }

    public static function fromSignedDecimal(Context $ctx, string $signedDecimal): Variable
    {
        $class = $ctx->classes[VmGmp::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('GMP is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        VmGmp::initObject($entry, $signedDecimal);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function requireGmp(Variable $var, string $function, int $index, string $label): ObjectEntry
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type GMP, %s given',
                $function,
                $index + 1,
                $label,
                VmStreamArg::debugTypeName($resolved)
            ));
        }
        $object = $resolved->toObject();
        if (VmGmp::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type GMP, %s given',
                $function,
                $index + 1,
                $label,
                $object->class->name
            ));
        }

        return $object;
    }

    public static function isGmpObject(ObjectEntry $object): bool
    {
        return VmGmp::CLASS_LC === strtolower($object->class->name);
    }

    public static function isGmpVariable(Variable $var): bool
    {
        $var = $var->resolveIndirect();

        return Variable::TYPE_OBJECT === $var->type && self::isGmpObject($var->toObject());
    }

    /**
     * php-src gmp_do_operation — + - * / % ** << >> & | ^ overload (#21265).
     *
     * @return bool true when either operand is GMP and the op was handled
     */
    public static function tryDoOperation(
        Variable $result,
        int $opCode,
        Variable $left,
        Variable $right,
        Context $ctx
    ): bool {
        $left = $left->resolveIndirect();
        $right = $right->resolveIndirect();
        $leftIsGmp = self::isGmpVariable($left);
        $rightIsGmp = self::isGmpVariable($right);
        if (!$leftIsGmp && !$rightIsGmp) {
            return false;
        }
        switch ($opCode) {
            case OpCode::TYPE_PLUS:
            case OpCode::TYPE_MINUS:
            case OpCode::TYPE_MUL:
            case OpCode::TYPE_DIV:
            case OpCode::TYPE_MODULO:
            case OpCode::TYPE_POW:
            case OpCode::TYPE_SHIFT_LEFT:
            case OpCode::TYPE_SHIFT_RIGHT:
            case OpCode::TYPE_BITWISE_AND:
            case OpCode::TYPE_BITWISE_OR:
            case OpCode::TYPE_BITWISE_XOR:
                break;
            default:
                return false;
        }

        if (OpCode::TYPE_POW === $opCode
            || OpCode::TYPE_SHIFT_LEFT === $opCode
            || OpCode::TYPE_SHIFT_RIGHT === $opCode) {
            return self::doShiftOrPow($result, $opCode, $left, $right, $ctx);
        }

        $leftOperand = self::parseDoOperationOperand($left);
        $rightOperand = self::parseDoOperationOperand($right);
        if (null === $leftOperand || null === $rightOperand) {
            throw new \TypeError(\sprintf(
                'Unsupported operand types: %s %s %s',
                EnumCaseSupport::typeNameForVariable($left),
                self::opSymbol($opCode),
                EnumCaseSupport::typeNameForVariable($right)
            ));
        }

        $out = match ($opCode) {
            OpCode::TYPE_PLUS => VmGmp::add($leftOperand, $rightOperand),
            OpCode::TYPE_MINUS => VmGmp::sub($leftOperand, $rightOperand),
            OpCode::TYPE_MUL => VmGmp::mul($leftOperand, $rightOperand),
            OpCode::TYPE_DIV => VmGmp::divQ($leftOperand, $rightOperand),
            OpCode::TYPE_MODULO => VmGmp::mod($leftOperand, $rightOperand),
            OpCode::TYPE_BITWISE_AND => VmGmp::bitwiseAnd($leftOperand, $rightOperand),
            OpCode::TYPE_BITWISE_OR => VmGmp::bitwiseOr($leftOperand, $rightOperand),
            OpCode::TYPE_BITWISE_XOR => VmGmp::bitwiseXor($leftOperand, $rightOperand),
            default => throw new \LogicException('GMP do_operation opcode not supported in this compiler build'),
        };
        $result->copyFrom(self::fromSignedDecimal($ctx, $out));

        return true;
    }

    /**
     * Unary minus for GMP — php-src routes via do_operation (0 - n) / gmp_neg.
     */
    public static function tryUnaryMinus(Variable $result, Variable $expr, Context $ctx): bool
    {
        $expr = $expr->resolveIndirect();
        if (!self::isGmpVariable($expr)) {
            return false;
        }
        $value = VmGmp::objectToSignedDecimal($expr->toObject());
        $result->copyFrom(self::fromSignedDecimal($ctx, VmGmp::neg($value)));

        return true;
    }

    /**
     * Unary bitwise not (~) — php-src gmp_do_operation ZEND_BW_NOT / mpz_com.
     */
    public static function tryBitwiseNot(Variable $result, Variable $expr, Context $ctx): bool
    {
        $expr = $expr->resolveIndirect();
        if (!self::isGmpVariable($expr)) {
            return false;
        }
        $value = VmGmp::objectToSignedDecimal($expr->toObject());
        $result->copyFrom(self::fromSignedDecimal($ctx, VmGmp::com($value)));

        return true;
    }

    /**
     * php-src gmp_compare — relational / spaceship when a GMP is involved.
     *
     * @return int|null -1/0/1, or null when neither side is GMP / incomparable
     */
    public static function tryCompare(Variable $left, Variable $right): ?int
    {
        $left = $left->resolveIndirect();
        $right = $right->resolveIndirect();
        if (!self::isGmpVariable($left) && !self::isGmpVariable($right)) {
            return null;
        }
        $leftOperand = self::parseDoOperationOperand($left);
        $rightOperand = self::parseDoOperationOperand($right);
        if (null === $leftOperand || null === $rightOperand) {
            return null;
        }

        return VmGmp::cmp($leftOperand, $rightOperand);
    }

    /**
     * php-src shift_operator_helper for ** << >> (right side as non-negative long).
     */
    private static function doShiftOrPow(
        Variable $result,
        int $opCode,
        Variable $left,
        Variable $right,
        Context $ctx
    ): bool {
        $shift = self::parseShiftOrExponent($right, $opCode);
        if (null === $shift) {
            throw new \TypeError(\sprintf(
                'Unsupported operand types: %s %s %s',
                EnumCaseSupport::typeNameForVariable($left),
                self::opSymbol($opCode),
                EnumCaseSupport::typeNameForVariable($right)
            ));
        }
        if ($shift < 0) {
            throw new \ValueError(OpCode::TYPE_POW === $opCode
                ? 'Exponent must be greater than or equal to 0'
                : 'Shift must be greater than or equal to 0');
        }

        $leftOperand = self::parseDoOperationOperand($left);
        if (null === $leftOperand) {
            // php-src: left may be long when right is GMP for shift/pow helpers
            if (Variable::TYPE_INTEGER === $left->type) {
                $leftOperand = (string) $left->toInt();
            } else {
                throw new \TypeError(\sprintf(
                    'Unsupported operand types: %s %s %s',
                    EnumCaseSupport::typeNameForVariable($left),
                    self::opSymbol($opCode),
                    EnumCaseSupport::typeNameForVariable($right)
                ));
            }
        }

        $out = match ($opCode) {
            OpCode::TYPE_POW => VmGmp::pow($leftOperand, $shift),
            OpCode::TYPE_SHIFT_LEFT => VmGmp::shiftLeft($leftOperand, $shift),
            OpCode::TYPE_SHIFT_RIGHT => VmGmp::shiftRight($leftOperand, $shift),
            default => throw new \LogicException('GMP shift/pow opcode not supported in this compiler build'),
        };
        $result->copyFrom(self::fromSignedDecimal($ctx, $out));

        return true;
    }

    /**
     * Coerce operand to signed-decimal string (GMP|int|string|bool|float→long).
     */
    private static function parseDoOperationOperand(Variable $var): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT === $var->type) {
            $object = $var->toObject();
            if (!self::isGmpObject($object)) {
                return null;
            }

            return VmGmp::objectToSignedDecimal($object);
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return (string) $var->toInt();
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool() ? '1' : '0';
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return (string) (int) $var->toFloat();
        }
        if (Variable::TYPE_STRING === $var->type) {
            $raw = trim($var->toString());
            if ('' === $raw || !preg_match('/^[+-]?[0-9]+$/', $raw)) {
                throw new \ValueError('Number is not an integer string');
            }

            return VmGmp::strval($raw, 10);
        }
        if (Variable::TYPE_NULL === $var->type) {
            return '0';
        }

        return null;
    }

    /**
     * Right-hand side of ** / << / >> as non-negative long (php-src shift_operator_helper).
     */
    private static function parseShiftOrExponent(Variable $var, int $opCode): ?int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return (int) $var->toFloat();
        }
        if (Variable::TYPE_STRING === $var->type) {
            $raw = trim($var->toString());
            if ('' === $raw || !preg_match('/^[+-]?[0-9]+$/', $raw)) {
                throw new \ValueError('Number is not an integer string');
            }
            if (VmGmp::cmp($raw, (string) \PHP_INT_MAX) > 0 || VmGmp::cmp($raw, (string) \PHP_INT_MIN) < 0) {
                throw new \ValueError('Number is not an integer string');
            }

            return (int) $raw;
        }
        if (self::isGmpVariable($var)) {
            return VmGmp::toInt(VmGmp::objectToSignedDecimal($var->toObject()));
        }

        return null;
    }

    private static function opSymbol(int $opCode): string
    {
        return match ($opCode) {
            OpCode::TYPE_PLUS => '+',
            OpCode::TYPE_MINUS => '-',
            OpCode::TYPE_MUL => '*',
            OpCode::TYPE_DIV => '/',
            OpCode::TYPE_MODULO => '%',
            OpCode::TYPE_POW => '**',
            OpCode::TYPE_SHIFT_LEFT => '<<',
            OpCode::TYPE_SHIFT_RIGHT => '>>',
            OpCode::TYPE_BITWISE_AND => '&',
            OpCode::TYPE_BITWISE_OR => '|',
            OpCode::TYPE_BITWISE_XOR => '^',
            default => '?',
        };
    }
}
