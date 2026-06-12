<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmDateInterval;

/**
 * Shared helpers for DateInterval VM builtins (issue #7278, php-src ext/date/php_date.c).
 */
final class DateIntervalSupport
{
    public const CLASS_DATEINTERVAL = 'dateinterval';

    public static function requireDateInterval(
        Variable $var,
        string $label,
        ?int $argNum = null,
        ?string $argName = null
    ): ObjectEntry {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw self::typeError($label, $argNum, $argName, $var);
        }
        $obj = $var->toObject();
        if (self::CLASS_DATEINTERVAL !== strtolower($obj->class->name)) {
            throw self::typeError($label, $argNum, $argName, $var, $obj->class->name);
        }

        return $obj;
    }

    public static function initDateInterval(ObjectEntry $interval, string $spec): void
    {
        $parsed = VmDateInterval::parseSpec($spec);
        self::requireIntProperty($interval, 'y')->int($parsed['y']);
        self::requireIntProperty($interval, 'm')->int($parsed['m']);
        self::requireIntProperty($interval, 'd')->int($parsed['d']);
        self::requireIntProperty($interval, 'h')->int($parsed['h']);
        self::requireIntProperty($interval, 'i')->int($parsed['i']);
        self::requireIntProperty($interval, 's')->int($parsed['s']);
        self::requireFloatProperty($interval, 'f')->float($parsed['f']);
        self::requireIntProperty($interval, 'invert')->int($parsed['invert']);
        self::requireBoolProperty($interval, 'days')->bool(false);
        $interval->constructed = true;
    }

    public static function format(ObjectEntry $interval, string $format): string
    {
        return VmDateInterval::format(self::readState($interval), $format);
    }

    /**
     * @return array{y: int, m: int, d: int, h: int, i: int, s: int, f: float, invert: int, days: bool|int}
     */
    public static function readState(ObjectEntry $interval): array
    {
        $daysVar = self::requireProperty($interval, 'days');
        $days = $daysVar->resolveIndirect();
        $daysValue = false;
        if (Variable::TYPE_INTEGER === $days->type) {
            $daysValue = $days->toInt();
        } elseif (Variable::TYPE_BOOLEAN !== $days->type || $days->toBool()) {
            throw new \LogicException('DateInterval days property is missing in this compiler build');
        }

        return [
            'y' => self::requireIntProperty($interval, 'y')->toInt(),
            'm' => self::requireIntProperty($interval, 'm')->toInt(),
            'd' => self::requireIntProperty($interval, 'd')->toInt(),
            'h' => self::requireIntProperty($interval, 'h')->toInt(),
            'i' => self::requireIntProperty($interval, 'i')->toInt(),
            's' => self::requireIntProperty($interval, 's')->toInt(),
            'f' => self::requireFloatProperty($interval, 'f')->toFloat(),
            'invert' => self::requireIntProperty($interval, 'invert')->toInt(),
            'days' => $daysValue,
        ];
    }

    private static function typeError(
        string $label,
        ?int $argNum,
        ?string $argName,
        Variable $var,
        ?string $objectClass = null
    ): \TypeError {
        $given = null !== $objectClass
            ? $objectClass
            : ReflectionSupport::valueTypeLabelPublic($var);
        if (null !== $argNum) {
            $param = null !== $argName ? " (\${$argName})" : '';

            return new \TypeError(
                "{$label}: Argument #{$argNum}{$param} must be of type DateInterval, {$given} given"
            );
        }

        return new \TypeError("{$label} must be of type DateInterval, {$given} given");
    }

    private static function requireProperty(ObjectEntry $obj, string $name): Variable
    {
        return $obj->getProperty($name);
    }

    private static function requireIntProperty(ObjectEntry $obj, string $name): Variable
    {
        $var = $obj->getProperty($name)->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \LogicException("DateInterval property {$name} is missing in this compiler build");
        }

        return $var;
    }

    private static function requireFloatProperty(ObjectEntry $obj, string $name): Variable
    {
        $var = $obj->getProperty($name)->resolveIndirect();
        if (Variable::TYPE_FLOAT !== $var->type) {
            throw new \LogicException("DateInterval property {$name} is missing in this compiler build");
        }

        return $var;
    }

    private static function requireBoolProperty(ObjectEntry $obj, string $name): Variable
    {
        $var = $obj->getProperty($name)->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $var->type) {
            throw new \LogicException("DateInterval property {$name} is missing in this compiler build");
        }

        return $var;
    }
}
