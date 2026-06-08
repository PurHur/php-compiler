<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Shared helpers for DateTime / DateTimeZone VM builtins (issue #3072, #7082).
 *
 * Uses host PHP date extension for parsing and formatting (php-src: ext/date/php_datetime.c).
 */
final class DateTimeSupport
{
    public const TZ_NAME_PROPERTY = '__dt_timezone_name';
    public const TS_PROPERTY = '__dt_timestamp';
    public const TZ_PROPERTY = '__dt_timezone';
    public const MICROSECOND_PROPERTY = '__dt_microsecond';

    public const CLASS_DATETIME = 'datetime';
    public const CLASS_DATETIMEIMMUTABLE = 'datetimeimmutable';
    public const CLASS_DATETIMEZONE = 'datetimezone';
    public const CLASS_DATETIMEINTERFACE = DateTimeInterfaceSupport::INTERFACE_LC;

    /** @var array<int, true> DateTime/DateTimeImmutable instances initialized via initDateTime* (#7276). */
    private static array $dateTimeLikeInitialized = [];

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

    public static function requireDateTimeImmutable(Variable $var, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError("{$label} must be of type DateTimeImmutable");
        }
        $obj = $var->toObject();
        if (self::CLASS_DATETIMEIMMUTABLE !== strtolower($obj->class->name)) {
            throw new \TypeError("{$label} must be of type DateTimeImmutable");
        }

        return $obj;
    }

    /** Accept DateTime or DateTimeImmutable (#7082); subclasses when $ctx is provided (#7276). */
    public static function requireDateTimeLike(Variable $var, string $label, ?Context $ctx = null): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError("{$label} must be of type DateTime or DateTimeImmutable");
        }
        $obj = $var->toObject();
        $lc = strtolower($obj->class->name);
        if (self::CLASS_DATETIME === $lc || self::CLASS_DATETIMEIMMUTABLE === $lc) {
            return $obj;
        }
        if (null !== $ctx) {
            if (InterfaceCheck::entryIsInstanceOf($obj->class, self::CLASS_DATETIME, $ctx)
                || InterfaceCheck::entryIsInstanceOf($obj->class, self::CLASS_DATETIMEIMMUTABLE, $ctx)) {
                return $obj;
            }
        } elseif (self::CLASS_DATETIME === $obj->class->parentLc
            || self::CLASS_DATETIMEIMMUTABLE === $obj->class->parentLc) {
            return $obj;
        }
        throw new \TypeError("{$label} must be of type DateTime or DateTimeImmutable");
    }

    public static function isDateTimeImmutable(ObjectEntry $obj): bool
    {
        return self::CLASS_DATETIMEIMMUTABLE === strtolower($obj->class->name);
    }

    public static function timezoneName(ObjectEntry $zone): string
    {
        return self::requireStringProperty($zone, self::TZ_NAME_PROPERTY, 'DateTimeZone')->toString();
    }

    /** php-src zim_DateTimeZone_getOffset — DateTimeInterface operand (#7131). */
    public static function requireDateTimeInterface(Variable $var, string $label, Context $ctx): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(
                "{$label} must be of type DateTimeInterface, "
                .ReflectionSupport::valueTypeLabelPublic($var).' given'
            );
        }
        $obj = $var->toObject();
        if (InterfaceCheck::entryIsInstanceOf($obj->class, self::CLASS_DATETIMEINTERFACE, $ctx)) {
            return $obj;
        }
        throw new \TypeError(
            "{$label} must be of type DateTimeInterface, "
            .$obj->class->name.' given'
        );
    }

    /** php-src zim_DateTimeZone_getOffset (#7131). */
    public static function timezoneOffset(ObjectEntry $zone, ObjectEntry $datetime): int
    {
        self::requireInitializedDateTimeLike($datetime, self::classLabel($datetime));
        $hostZone = new \DateTimeZone(self::timezoneName($zone));

        return $hostZone->getOffset(self::toHost($datetime));
    }

    /** php-src zim_DateTimeZone_getLocation (#7131). */
    public static function timezoneLocationInto(ObjectEntry $zone, Variable $returnVar): void
    {
        $location = (new \DateTimeZone(self::timezoneName($zone)))->getLocation();
        if (false === $location) {
            $returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($location as $key => $value) {
            $entry = new Variable();
            if (\is_string($value)) {
                $entry->string($value);
            } elseif (\is_int($value)) {
                $entry->int($value);
            } elseif (\is_float($value)) {
                $entry->float($value);
            } else {
                throw new \LogicException('DateTimeZone::getLocation() returned unexpected value type');
            }
            $ht->addNew((string) $key, $entry);
        }
        $returnVar->array($ht);
    }

    public static function initDateTimeZone(ObjectEntry $zone, string $timezone): void
    {
        try {
            $host = new \DateTimeZone($timezone);
        } catch (\Exception) {
            self::throwDateInvalidTimeZoneException($timezone);
        }
        self::requireStringProperty($zone, self::TZ_NAME_PROPERTY, 'DateTimeZone')->string($host->getName());
        $zone->constructed = true;
    }

    /** php-src ext/date/php_datetimezone.c — invalid id throws DateInvalidTimeZoneException (#7279). */
    public static function throwDateInvalidTimeZoneException(string $timezone): void
    {
        throw new NativeDateInvalidTimeZoneException(
            'DateTimeZone::__construct(): Unknown or bad timezone ('.$timezone.')'
        );
    }

    /** php-src ext/date/php_date.c — epoch overflow on getTimestamp() (#7276). */
    public static function throwDateRangeError(string $message): void
    {
        throw new NativeDateRangeError($message);
    }

    /** php-src ext/date/php_date.c — uninitialized date object (#7276). */
    public static function throwDateObjectError(string $message): void
    {
        throw new NativeDateObjectError($message);
    }

    public static function requireInitializedDateTimeLike(ObjectEntry $obj, string $method): void
    {
        if (isset(self::$dateTimeLikeInitialized[$obj->id])) {
            return;
        }
        self::throwUninitializedDateTimeLike($obj);
    }

    private static function markDateTimeLikeInitialized(ObjectEntry $dt): void
    {
        self::$dateTimeLikeInitialized[$dt->id] = true;
    }

    private static function throwUninitializedDateTimeLike(ObjectEntry $obj): void
    {
        self::throwDateObjectError(
            'Object of type '.$obj->class->name.' has not been correctly initialized by calling parent::__construct() in its constructor'
        );
    }

    /** php-src ext/date/php_date.c — malformed time string throws catchable Exception (#7113). */
    public static function throwDateMalformedStringException(string $message): void
    {
        throw new NativeDateMalformedStringException($message);
    }

    public static function initDateTime(ObjectEntry $dt, string $time, ?ObjectEntry $timezone = null): void
    {
        $tzName = null !== $timezone
            ? self::timezoneName($timezone)
            : \date_default_timezone_get();
        try {
            $host = new \DateTime($time, new \DateTimeZone($tzName));
        } catch (\Exception $e) {
            self::throwDateMalformedStringException($e->getMessage());
        }
        self::syncFromHost($dt, $host);
        $dt->constructed = true;
        self::markDateTimeLikeInitialized($dt);
    }

    public static function initDateTimeFromFormat(
        ObjectEntry $dt,
        string $format,
        string $time,
        ?ObjectEntry $timezone = null
    ): void {
        $tzName = null !== $timezone
            ? self::timezoneName($timezone)
            : \date_default_timezone_get();
        $host = \DateTimeImmutable::createFromFormat($format, $time, new \DateTimeZone($tzName));
        if (false === $host) {
            self::throwDateMalformedStringException(
                'DateTimeImmutable::createFromFormat(): Failed to parse time string ('.$time.')'
            );
        }
        self::syncFromHost($dt, $host);
        $dt->constructed = true;
        self::markDateTimeLikeInitialized($dt);
    }

    public static function format(ObjectEntry $dt, string $format): string
    {
        return self::toHost($dt)->format($format);
    }

    public static function getTimestamp(ObjectEntry $dt): int
    {
        self::requireInitializedDateTimeLike($dt, self::classLabel($dt).'::getTimestamp()');
        $host = self::toHost($dt);
        if (4 === \PHP_INT_SIZE) {
            $epoch = (float) $host->format('U');
            if ($epoch > \PHP_INT_MAX || $epoch < \PHP_INT_MIN) {
                self::throwDateRangeError('Epoch doesn\'t fit in a PHP integer');
            }
        }

        return self::requireIntProperty($dt, self::TS_PROPERTY, self::classLabel($dt))->toInt();
    }

    public static function getMicrosecond(ObjectEntry $dt): int
    {
        return self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, self::classLabel($dt))->toInt();
    }

    /** php-src zim_DateTime_setMicrosecond — mutable in-place (#7082). */
    public static function setMicrosecond(ObjectEntry $dt, int $microsecond): void
    {
        self::validateMicrosecond($microsecond);
        self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, self::classLabel($dt))
            ->int($microsecond);
    }

    /** php-src zim_DateTimeImmutable_setMicrosecond — returns new instance (#7082). */
    public static function withMicrosecond(ObjectEntry $dt, int $microsecond): ObjectEntry
    {
        self::validateMicrosecond($microsecond);
        $clone = self::cloneDateTimeObject($dt);
        self::requireIntProperty($clone, self::MICROSECOND_PROPERTY, self::classLabel($clone))
            ->int($microsecond);

        return $clone;
    }

    public static function setTimezone(ObjectEntry $dt, ObjectEntry $timezone): void
    {
        $host = self::toHost($dt);
        $host->setTimezone(new \DateTimeZone(self::timezoneName($timezone)));
        self::syncFromHost($dt, $host);
    }

    private static function validateMicrosecond(int $microsecond): void
    {
        if ($microsecond < 0 || $microsecond > 999_999) {
            throw new \ValueError(
                'DateTime::setMicrosecond(): Argument #1 ($microsecond) must be between 0 and 999999'
            );
        }
    }

    private static function cloneDateTimeObject(ObjectEntry $source): ObjectEntry
    {
        $clone = new ObjectEntry($source->class);
        self::requireIntProperty($clone, self::TS_PROPERTY, self::classLabel($source))
            ->int(self::requireIntProperty($source, self::TS_PROPERTY, self::classLabel($source))->toInt());
        self::requireStringProperty($clone, self::TZ_PROPERTY, self::classLabel($source))
            ->string(self::requireStringProperty($source, self::TZ_PROPERTY, self::classLabel($source))->toString());
        self::requireIntProperty($clone, self::MICROSECOND_PROPERTY, self::classLabel($source))
            ->int(self::requireIntProperty($source, self::MICROSECOND_PROPERTY, self::classLabel($source))->toInt());
        $clone->constructed = true;
        self::markDateTimeLikeInitialized($clone);

        return $clone;
    }

    private static function toHost(ObjectEntry $dt): \DateTimeInterface
    {
        $ts = self::requireIntProperty($dt, self::TS_PROPERTY, self::classLabel($dt))->toInt();
        $microsecond = self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, self::classLabel($dt))->toInt();
        $tzName = self::requireStringProperty($dt, self::TZ_PROPERTY, self::classLabel($dt))->toString();
        $host = \DateTimeImmutable::createFromFormat(
            'U.u',
            \sprintf('%d.%06d', $ts, $microsecond),
            new \DateTimeZone('UTC')
        );
        if (false === $host) {
            throw new \LogicException('DateTime backing conversion failed in this compiler build');
        }
        $mutable = \DateTime::createFromImmutable($host);
        $mutable->setTimezone(new \DateTimeZone($tzName));

        return $mutable;
    }

    private static function syncFromHost(ObjectEntry $dt, \DateTimeInterface $host): void
    {
        self::requireIntProperty($dt, self::TS_PROPERTY, self::classLabel($dt))->int($host->getTimestamp());
        self::requireStringProperty($dt, self::TZ_PROPERTY, self::classLabel($dt))
            ->string($host->getTimezone()->getName());
        $microsecond = \method_exists($host, 'getMicrosecond')
            ? (int) $host->getMicrosecond()
            : (int) $host->format('u');
        self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, self::classLabel($dt))->int($microsecond);
    }

    private static function classLabel(ObjectEntry $obj): string
    {
        return self::isDateTimeImmutable($obj) ? 'DateTimeImmutable' : 'DateTime';
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
