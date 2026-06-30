<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Shared helpers for DatePeriod VM builtins (issue #14144, php-src ext/date/php_date.c).
 */
final class DatePeriodSupport
{
    public const CLASS_DATEPERIOD = 'dateperiod';

    public static function requireDatePeriod(
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
        if (self::CLASS_DATEPERIOD !== strtolower($obj->class->name)) {
            throw self::typeError($label, $argNum, $argName, $var, $obj->class->name);
        }

        return $obj;
    }

    /** php-src date_period_initialize — start/interval/recurrences form (#14144). */
    public static function initFromRecurrenceCount(
        ObjectEntry $period,
        ObjectEntry $start,
        ObjectEntry $interval,
        int $recurrences
    ): void {
        if ($recurrences < 1) {
            throw new \Exception('DatePeriod::__construct(): Recurrence count must be greater than 0');
        }
        self::setObjectProperty($period, 'start', $start);
        self::setNullProperty($period, 'current');
        self::setNullProperty($period, 'end');
        self::setObjectProperty($period, 'interval', $interval);
        self::requireIntProperty($period, 'recurrences')->int($recurrences + 1);
        self::requireBoolProperty($period, 'include_start_date')->bool(true);
        self::requireBoolProperty($period, 'include_end_date')->bool(false);
        $period->constructed = true;
    }

    /**
     * php-src ext/json/php_json.c — DatePeriod json encode wire (#14144).
     *
     * @return array<string, mixed>
     */
    public static function exportZendJsonWireDatePeriod(ObjectEntry $period): array
    {
        $startVar = self::requireProperty($period, 'start')->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $startVar->type) {
            throw new \LogicException('DatePeriod start property is missing in this compiler build');
        }
        $start = $startVar->toObject();
        $intervalVar = self::requireProperty($period, 'interval')->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $intervalVar->type) {
            throw new \LogicException('DatePeriod interval property is missing in this compiler build');
        }

        return [
            'start' => DateTimeSupport::exportZendJsonWireDateTimeLike($start),
            'current' => null,
            'end' => null,
            'interval' => DateIntervalSupport::exportZendJsonWireDateInterval($intervalVar->toObject()),
            'recurrences' => self::requireIntProperty($period, 'recurrences')->toInt(),
            'include_start_date' => self::requireBoolProperty($period, 'include_start_date')->toBool(),
            'include_end_date' => self::requireBoolProperty($period, 'include_end_date')->toBool(),
        ];
    }

    private static function setObjectProperty(ObjectEntry $period, string $name, ObjectEntry $value): void
    {
        $prop = self::requireProperty($period, $name);
        $prop->object($value);
    }

    private static function setNullProperty(ObjectEntry $period, string $name): void
    {
        $prop = self::requireProperty($period, $name);
        $prop->null();
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
                "{$label}: Argument #{$argNum}{$param} must be of type DatePeriod, {$given} given"
            );
        }

        return new \TypeError("{$label} must be of type DatePeriod, {$given} given");
    }

    private static function requireProperty(ObjectEntry $obj, string $name): Variable
    {
        return $obj->getProperty($name);
    }

    private static function requireIntProperty(ObjectEntry $obj, string $name): Variable
    {
        $var = $obj->getProperty($name)->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \LogicException("DatePeriod property {$name} is missing in this compiler build");
        }

        return $var;
    }

    private static function requireBoolProperty(ObjectEntry $obj, string $name): Variable
    {
        $var = $obj->getProperty($name)->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $var->type) {
            throw new \LogicException("DatePeriod property {$name} is missing in this compiler build");
        }

        return $var;
    }
}
