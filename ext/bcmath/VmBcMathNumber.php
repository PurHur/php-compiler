<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\OpCode;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmNullNumberParamDeprecation;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Compiler\ParameterMetadata;
use PHPCompiler\VM;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * BcMath\Number OOP surface (php-src ext/bcmath/bcmath.c; issue #7220).
 *
 * Reuses {@see VmBcmath} string math — no runtime/*.c growth.
 */
final class VmBcMathNumber
{
    public const CLASS_LC = 'bcmath\\number';

    public const PROP_VALUE = 'value';

    public const PROP_SCALE = 'scale';

    /** php-src BC_MATH_NUMBER_EXPAND_SCALE (ext/bcmath/php_bcmath.h). */
    public const EXPAND_SCALE = 10;

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $strProto = new Variable(Variable::TYPE_STRING);
        $intProto = new Variable(Variable::TYPE_INTEGER);
        $pub = CfgFunc::FLAG_PUBLIC;

        $entry = new ClassEntry('BcMath\\Number');
        $entry->isInternal = true;
        $entry->readonly = true;
        $entry->properties[] = new ClassProperty(self::PROP_VALUE, null, $strProto, true);
        $entry->properties[] = new ClassProperty(self::PROP_SCALE, null, $intProto, true);

        $entry->constructor = new NumberConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        // php-src bcmath.stub.php — __construct(string|int $num); InternalArgInfo empty (#24626).
        $entry->methodParameterMetadata['__construct'] = [
            new ParameterMetadata('num', [], false, false, false, false, 'string|int', null),
        ];

        // php-src has no Number::from() — constructor is the only factory (#24613, re-#16814).
        $methods = [
            'add' => new NumberAdd(),
            'sub' => new NumberSub(),
            'mul' => new NumberMul(),
            'div' => new NumberDiv(),
            'mod' => new NumberMod(),
            // php-src BcMath\Number::divmod — quotient + remainder Number pair (#24611).
            'divmod' => new NumberDivmod(),
            // php-src BcMath\Number::powmod — modular exponentiation (#24612).
            'powmod' => new NumberPowmod(),
            'pow' => new NumberPow(),
            'sqrt' => new NumberSqrt(),
            'floor' => new NumberFloor(),
            'ceil' => new NumberCeil(),
            'round' => new NumberRound(),
            'compare' => new NumberCompare(),
            '__tostring' => new NumberToString(),
            // php-src value-only serialize payload — scale recovered on wakeup (#24614).
            '__serialize' => new NumberSerialize(),
            '__unserialize' => new NumberUnserialize(),
        ];
        foreach ($methods as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
        }
        $entry->methodNames['__serialize'] = '__serialize';
        $entry->methodNames['__unserialize'] = '__unserialize';

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function initObject(ObjectEntry $entry, string $value, ?int $scale = null): void
    {
        VmBcmath::assertValidNumber($value);
        // Store Zend-canonical value ("" / null→"" / "00.00" → "0" / "0.00"), not the raw operand (#24140).
        $canonical = VmBcmath::canonicalNumberString($value);
        $entry->getProperty(self::PROP_VALUE)->string($canonical);
        $entry->getProperty(self::PROP_SCALE)->int($scale ?? VmBcmath::decimalScale($value));
        $entry->constructed = true;
    }

    public static function fromComputedValue(Context $ctx, string $value, ?int $scale = null): Variable
    {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('BcMath\\Number is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        self::initObject($entry, $value, $scale);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function valueString(ObjectEntry $entry): string
    {
        $var = $entry->getProperty(self::PROP_VALUE)->resolveIndirect();
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \LogicException('BcMath\\Number backing property value is missing in this compiler build');
        }

        return $var->toString();
    }

    public static function objectScale(ObjectEntry $entry): int
    {
        $var = $entry->getProperty(self::PROP_SCALE)->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            return 0;
        }

        return $var->toInt();
    }

    public static function requireNumberReceiver(Variable $var, string $method): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError("{$method} must be of type BcMath\\Number");
        }
        $object = $var->toObject();
        if (self::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError("{$method} must be of type BcMath\\Number");
        }

        return $object;
    }

    public static function requireNumber(Variable $var, string $method, int $argNum = 0, string $paramName = 'num'): ObjectEntry
    {
        $resolved = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($resolved)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type BcMath\\Number|string|int, %s given',
                $method,
                $argNum,
                $paramName,
                EnumCaseSupport::typeNameForVariable($resolved)
            ));
        }
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type BcMath\\Number|string|int, %s given',
                $method,
                $argNum,
                $paramName,
                VmStreamArg::debugTypeName($resolved)
            ));
        }
        $object = $resolved->toObject();
        if (self::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type BcMath\\Number|string|int, %s given',
                $method,
                $argNum,
                $paramName,
                $object->class->name
            ));
        }
        if (!$object->constructed) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type BcMath\\Number|string|int, %s given',
                $method,
                $argNum,
                $paramName,
                $object->class->name
            ));
        }

        return $object;
    }

    public static function coerceOperand(
        Variable $var,
        string $method,
        int $argNum,
        string $paramName = 'num'
    ): string {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type BcMath\\Number|string|int, %s given',
                $method,
                $argNum,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            return self::valueString(self::requireNumber($var, $method, $argNum, $paramName));
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            $asString = (string) $var->toInt();
            VmBcmath::assertValidNumber($asString);

            return $asString;
        }
        // Z_PARAM_STR_OR_LONG: finite float→long (+ E_DEPRECATED on precision loss) → string (#24625).
        // Non-finite floats follow convert_to_string ("NAN"/"INF") like Zend STR_OR_LONG (#22947).
        if (Variable::TYPE_FLOAT === $var->type) {
            $float = $var->toFloat();
            if (\is_finite($float)) {
                $vm = VM::running();
                if (null !== $vm) {
                    VmMath::warnFloatToIntPrecisionLoss(
                        $float,
                        $vm->context,
                        $vm->currentExecutingFrame()
                    );
                }
                $asString = (string) VmMath::floatToZendLong($float);
                VmBcmath::assertValidNumber($asString);

                return $asString;
            }
        }

        // $argNum is 1-based (Zend Argument #N); coerceStringBuiltinArg expects 0-based (#24140).
        // Z_PARAM_STR_OR_LONG soft-null deprecation type is string|int (bcmath.stub.php).
        return VmString::coerceStringBuiltinArg(
            $var,
            $method,
            $argNum - 1,
            $paramName,
            'string|int'
        );
    }

    public static function optionalScaleArg(Variable $var, string $method, int $argNum, ?Frame $frame = null): ?int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            if (null !== $frame && version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=')) {
                VmNullNumberParamDeprecation::emit($frame, $method, $argNum, 'scale', '?int');
            }

            return null;
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($scale) must be of type ?int, %s given',
                $method,
                $argNum,
                VmStreamArg::debugTypeName($var)
            ));
        }
        $scale = $var->toInt();
        if ($scale < 0) {
            throw new \ValueError(\sprintf('%s(): Argument #%d ($scale) must be greater than or equal to 0', $method, $argNum));
        }

        return $scale;
    }

    public static function isNumberObject(ObjectEntry $object): bool
    {
        return self::CLASS_LC === strtolower($object->class->name) && $object->constructed;
    }

    public static function isNumberVariable(Variable $var): bool
    {
        $var = $var->resolveIndirect();

        return Variable::TYPE_OBJECT === $var->type && self::isNumberObject($var->toObject());
    }

    /**
     * php-src bcmath_number_do_operation — + - * / % ** overload (#20648).
     *
     * @return bool true when either operand is BcMath\Number and the op was handled
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
        $leftIsNumber = self::isNumberVariable($left);
        $rightIsNumber = self::isNumberVariable($right);
        if (!$leftIsNumber && !$rightIsNumber) {
            return false;
        }
        switch ($opCode) {
            case OpCode::TYPE_PLUS:
            case OpCode::TYPE_MINUS:
            case OpCode::TYPE_MUL:
            case OpCode::TYPE_DIV:
            case OpCode::TYPE_MODULO:
            case OpCode::TYPE_POW:
                break;
            default:
                return false;
        }

        $leftOperand = self::parseDoOperationOperand($left, true);
        $rightOperand = self::parseDoOperationOperand($right, false);
        if (null === $leftOperand || null === $rightOperand) {
            throw new \TypeError(\sprintf(
                'Unsupported operand types: %s %s %s',
                EnumCaseSupport::typeNameForVariable($left),
                self::opSymbol($opCode),
                EnumCaseSupport::typeNameForVariable($right)
            ));
        }

        [$leftValue, $leftScale] = $leftOperand;
        [$rightValue, $rightScale] = $rightOperand;
        [$outValue, $outScale] = self::computeBinary(
            $opCode,
            $leftValue,
            $leftScale,
            $rightValue,
            $rightScale,
            true
        );
        $result->copyFrom(self::fromComputedValue($ctx, $outValue, $outScale));

        return true;
    }

    /**
     * Unary minus for BcMath\Number — php-src routes via do_operation (0 - n).
     */
    public static function tryUnaryMinus(Variable $result, Variable $expr, Context $ctx): bool
    {
        $expr = $expr->resolveIndirect();
        if (!self::isNumberVariable($expr)) {
            return false;
        }
        $zero = new Variable(Variable::TYPE_INTEGER);
        $zero->int(0);

        return self::tryDoOperation($result, OpCode::TYPE_MINUS, $zero, $expr, $ctx);
    }

    /**
     * php-src bcmath_number_compare — relational / spaceship when a Number is involved.
     *
     * @return int|null -1/0/1, or null when neither side is Number / incomparable
     */
    public static function tryCompare(Variable $left, Variable $right): ?int
    {
        $left = $left->resolveIndirect();
        $right = $right->resolveIndirect();
        if (!self::isNumberVariable($left) && !self::isNumberVariable($right)) {
            return null;
        }
        $leftOperand = self::parseDoOperationOperand($left, true);
        $rightOperand = self::parseDoOperationOperand($right, false);
        if (null === $leftOperand || null === $rightOperand) {
            return null;
        }

        return VmBcmath::compNumber($leftOperand[0], $rightOperand[0], null);
    }

    /**
     * Shared auto-scale binary math for operators and method calc paths (php-src bcmath_number_*_internal).
     *
     * @return array{0: string, 1: int} result value and object scale
     */
    public static function computeBinary(
        int $opCode,
        string $left,
        int $leftScale,
        string $right,
        int $rightScale,
        bool $isOp
    ): array {
        switch ($opCode) {
            case OpCode::TYPE_PLUS:
                $scale = max($leftScale, $rightScale);
                $value = VmBcmath::add($left, $right, $scale);

                return [$value, $scale];
            case OpCode::TYPE_MINUS:
                $scale = max($leftScale, $rightScale);
                $value = VmBcmath::sub($left, $right, $scale);

                return [$value, $scale];
            case OpCode::TYPE_MUL:
                $scale = $leftScale + $rightScale;
                if ($scale < $leftScale) {
                    throw new \ValueError('scale of the result is too large');
                }
                $value = VmBcmath::mul($left, $right, $scale);

                return [$value, $scale];
            case OpCode::TYPE_DIV:
                $requested = $leftScale + self::EXPAND_SCALE;
                if ($requested < $leftScale) {
                    throw new \ValueError('scale of the result is too large');
                }
                $value = VmBcmath::div($left, $right, $requested);
                // php-src bc_rm_trailing_zeros + shrink object scale by unused expand digits
                $value = self::stripTrailingFracZeros($value);
                $scale = self::shrinkAutoExpandScale($value, $requested);

                return [$value, $scale];
            case OpCode::TYPE_MODULO:
                $scale = max($leftScale, $rightScale);
                $value = VmBcmath::mod($left, $right, $scale);

                return [$value, $scale];
            case OpCode::TYPE_POW:
                if (VmBcmath::decimalScale($right) !== 0) {
                    throw new \ValueError($isOp
                        ? 'exponent cannot have a fractional part'
                        : 'BcMath\\Number::pow(): Argument #1 ($exponent) exponent cannot have a fractional part');
                }
                $expo = (int) $right;
                if ($expo > 0) {
                    $scale = $leftScale * $expo;
                    if ($scale > PHP_INT_MAX || $scale < $leftScale) {
                        throw new \ValueError('scale of the result is too large');
                    }
                    $value = VmBcmath::pow($left, $right, $scale);

                    return [$value, $scale];
                }
                if ($expo < 0) {
                    $requested = $leftScale + self::EXPAND_SCALE;
                    if ($requested < $leftScale) {
                        throw new \ValueError('scale of the result is too large');
                    }
                    $value = self::stripTrailingFracZeros(VmBcmath::pow($left, $right, $requested));

                    return [$value, self::shrinkAutoExpandScale($value, $requested)];
                }
                $value = VmBcmath::pow($left, $right, 0);

                return [$value, 0];
            default:
                throw new \LogicException('BcMath\\Number do_operation opcode not supported in this compiler build');
        }
    }

    /**
     * php-src bcmath_number_parse_num + bc_num_from_obj_or_str_or_long for operator operands.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function parseDoOperationOperand(Variable $var, bool $isLeft): ?array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT === $var->type) {
            $object = $var->toObject();
            if (!self::isNumberObject($object)) {
                return null;
            }

            return [self::valueString($object), self::objectScale($object)];
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            $asString = (string) $var->toInt();

            return [$asString, 0];
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return [$var->toBool() ? '1' : '0', 0];
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            // php-src zend_parse_arg_long_slow — truncate toward zero
            $asLong = (int) $var->toFloat();

            return [(string) $asLong, 0];
        }
        if (Variable::TYPE_STRING === $var->type) {
            $str = $var->toString();
            try {
                VmBcmath::assertValidNumber($str);
            } catch (\ValueError $e) {
                throw new \ValueError($isLeft
                    ? 'Left string operand cannot be converted to BcMath\\Number'
                    : 'Right string operand cannot be converted to BcMath\\Number');
            }

            return [$str, VmBcmath::decimalScale($str)];
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
            default => '?',
        };
    }

    private static function stripTrailingFracZeros(string $num): string
    {
        if (!str_contains($num, '.')) {
            return $num;
        }
        $num = rtrim($num, '0');

        return rtrim($num, '.') ?: '0';
    }

    private static function shrinkAutoExpandScale(string $result, int $requestedScale): int
    {
        $resultScale = VmBcmath::decimalScale($result);
        $diff = $requestedScale - $resultScale;
        if ($diff <= 0) {
            return $requestedScale;
        }

        return $requestedScale - min($diff, self::EXPAND_SCALE);
    }
}
