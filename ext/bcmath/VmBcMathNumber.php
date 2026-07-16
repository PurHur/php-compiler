<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\ext\standard\VmString;
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
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;

        $entry = new ClassEntry('BcMath\\Number');
        $entry->isInternal = true;
        $entry->readonly = true;
        $entry->properties[] = new ClassProperty(self::PROP_VALUE, null, $strProto, true);
        $entry->properties[] = new ClassProperty(self::PROP_SCALE, null, $intProto, true);

        $entry->constructor = new NumberConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $entry->methods['from'] = new NumberFrom();
        $entry->methodVisibility['from'] = $pubStatic;

        $methods = [
            'add' => new NumberAdd(),
            'sub' => new NumberSub(),
            'mul' => new NumberMul(),
            'div' => new NumberDiv(),
            'mod' => new NumberMod(),
            'pow' => new NumberPow(),
            'sqrt' => new NumberSqrt(),
            'floor' => new NumberFloor(),
            'ceil' => new NumberCeil(),
            'round' => new NumberRound(),
            'compare' => new NumberCompare(),
            '__tostring' => new NumberToString(),
        ];
        foreach ($methods as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
        }

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function initObject(ObjectEntry $entry, string $value, ?int $scale = null): void
    {
        VmBcmath::assertValidNumber($value);
        $entry->getProperty(self::PROP_VALUE)->string($value);
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

        return VmString::coerceStringBuiltinArg($var, $method, $argNum, $paramName);
    }

    public static function optionalScaleArg(Variable $var, string $method, int $argNum): ?int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
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
}
