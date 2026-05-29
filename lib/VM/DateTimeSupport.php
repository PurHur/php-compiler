<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Shared helpers for DateTime / DateTimeZone VM builtins (issue #3072).
 *
 * Uses host PHP date extension for parsing and formatting (php-src: ext/date/php_datetime.c).
 */
final class DateTimeSupport
{
    public const TZ_NAME_PROPERTY = '__dt_timezone_name';
    public const TS_PROPERTY = '__dt_timestamp';
    public const TZ_PROPERTY = '__dt_timezone';

    public const CLASS_DATETIME = 'datetime';
    public const CLASS_DATETIMEZONE = 'datetimezone';

    public static function requireDateTimeZone(Variable $var, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError("{$label} must be of type DateTimeZone");
        }
        $obj = $var->toObject();
        if (self::CLASS_DATETIMEZONE !== strtolower($obj->class->name)) {
            throw new \TypeError("{$label} must be of type DateTimeZone");
        }

        return $obj;
    }

    public static function requireDateTime(Variable $var, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError("{$label} must be of type DateTime");
        }
        $obj = $var->toObject();
        if (self::CLASS_DATETIME !== strtolower($obj->class->name)) {
            throw new \TypeError("{$label} must be of type DateTime");
        }

        return $obj;
    }

    public static function timezoneName(ObjectEntry $zone): string
    {
        return self::requireStringProperty($zone, self::TZ_NAME_PROPERTY, 'DateTimeZone')->toString();
    }

    public static function initDateTimeZone(ObjectEntry $zone, string $timezone): void
    {
        try {
            $host = new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new \LogicException('DateTimeZone::__construct(): Unknown or bad timezone ('.$timezone.')');
        }
        self::requireStringProperty($zone, self::TZ_NAME_PROPERTY, 'DateTimeZone')->string($host->getName());
        $zone->constructed = true;
    }

    public static function initDateTime(ObjectEntry $dt, string $time, ?ObjectEntry $timezone = null): void
    {
        $tzName = null !== $timezone
            ? self::timezoneName($timezone)
            : \date_default_timezone_get();
        try {
            $host = new \DateTime($time, new \DateTimeZone($tzName));
        } catch (\Exception $e) {
            throw new \LogicException('DateTime::__construct(): Failed to parse time string ('.$time.')');
        }
        self::syncFromHost($dt, $host);
        $dt->constructed = true;
    }

    public static function format(ObjectEntry $dt, string $format): string
    {
        return self::toHost($dt)->format($format);
    }

    public static function getTimestamp(ObjectEntry $dt): int
    {
        return self::requireIntProperty($dt, self::TS_PROPERTY, 'DateTime')->toInt();
    }

    public static function setTimezone(ObjectEntry $dt, ObjectEntry $timezone): void
    {
        $host = self::toHost($dt);
        $host->setTimezone(new \DateTimeZone(self::timezoneName($timezone)));
        self::syncFromHost($dt, $host);
    }

    private static function toHost(ObjectEntry $dt): \DateTime
    {
        $ts = self::requireIntProperty($dt, self::TS_PROPERTY, 'DateTime')->toInt();
        $tzName = self::requireStringProperty($dt, self::TZ_PROPERTY, 'DateTime')->toString();
        $host = new \DateTime('@'.$ts);
        $host->setTimezone(new \DateTimeZone($tzName));

        return $host;
    }

    private static function syncFromHost(ObjectEntry $dt, \DateTime $host): void
    {
        self::requireIntProperty($dt, self::TS_PROPERTY, 'DateTime')->int($host->getTimestamp());
        self::requireStringProperty($dt, self::TZ_PROPERTY, 'DateTime')->string($host->getTimezone()->getName());
    }

    private static function requireStringProperty(ObjectEntry $obj, string $name, string $classLabel): Variable
    {
        $var = $obj->getProperty($name)->resolveIndirect();
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \LogicException("{$classLabel} backing property {$name} is missing in this compiler build");
        }

        return $var;
    }

    private static function requireIntProperty(ObjectEntry $obj, string $name, string $classLabel): Variable
    {
        $var = $obj->getProperty($name)->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \LogicException("{$classLabel} backing property {$name} is missing in this compiler build");
        }

        return $var;
    }
}
