<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\ext\standard\VmDateTimeNative;

/**
 * Shared helpers for DateTime / DateTimeZone VM builtins (issue #3072, #7082).
 *
 * Native parsing/formatting via {@see VmDateTimeNative} — no host \\DateTime (issue #6164).
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

    /**
     * php-src ext/date/php_datetime.c — shared createFromFormat last-errors slot (#4660, #9920).
     *
     * @var array{
     *   warning_count: int,
     *   warnings: array<int, string>,
     *   error_count: int,
     *   errors: array<int, string>
     * }|false
     */
    private static array|false $createFromFormatLastErrors = false;

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

    public static function requireDateTime(
        Variable $var,
        string $label,
        ?int $argNum = null,
        ?string $argName = null
    ): ObjectEntry {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw self::dateTimeTypeError($label, $argNum, $argName, $var);
        }
        $obj = $var->toObject();
        if (self::CLASS_DATETIME !== strtolower($obj->class->name)) {
            throw self::dateTimeTypeError($label, $argNum, $argName, $var, $obj->class->name);
        }

        return $obj;
    }

    private static function dateTimeTypeError(
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
                "{$label}: Argument #{$argNum}{$param} must be of type DateTime, {$given} given"
            );
        }

        return new \TypeError("{$label} must be of type DateTime, {$given} given");
    }

    public static function requireDateTimeImmutable(
        Variable $var,
        string $label,
        ?int $argNum = null,
        ?string $argName = null
    ): ObjectEntry {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw self::dateTimeImmutableTypeError($label, $argNum, $argName, $var);
        }
        $obj = $var->toObject();
        if (self::CLASS_DATETIMEIMMUTABLE !== strtolower($obj->class->name)) {
            throw self::dateTimeImmutableTypeError($label, $argNum, $argName, $var, $obj->class->name);
        }

        return $obj;
    }

    private static function dateTimeImmutableTypeError(
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
                "{$label}: Argument #{$argNum}{$param} must be of type DateTimeImmutable, {$given} given"
            );
        }

        return new \TypeError("{$label} must be of type DateTimeImmutable, {$given} given");
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
    public static function requireDateTimeInterface(
        Variable $var,
        string $label,
        Context $ctx,
        ?int $argNum = null,
        ?string $argName = null
    ): ObjectEntry {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw self::dateTimeInterfaceTypeError($label, $argNum, $argName, $var);
        }
        $obj = $var->toObject();
        if (InterfaceCheck::entryIsInstanceOf($obj->class, self::CLASS_DATETIMEINTERFACE, $ctx)) {
            return $obj;
        }
        throw self::dateTimeInterfaceTypeError($label, $argNum, $argName, $var, $obj->class->name);
    }

    private static function dateTimeInterfaceTypeError(
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
                "{$label}: Argument #{$argNum}{$param} must be of type DateTimeInterface, {$given} given"
            );
        }

        return new \TypeError("{$label} must be of type DateTimeInterface, {$given} given");
    }

    /** php-src zim_DateTimeZone_getOffset (#7131). */
    public static function timezoneOffset(ObjectEntry $zone, ObjectEntry $datetime): int
    {
        self::requireInitializedDateTimeLike($datetime, self::classLabel($datetime));
        $timestamp = self::requireIntProperty($datetime, self::TS_PROPERTY, self::classLabel($datetime))->toInt();

        return VmDateTimeNative::timezoneOffsetSeconds(self::timezoneName($zone), $timestamp);
    }

    /** php-src zim_DateTimeZone_getLocation (#7131). */
    public static function timezoneLocationInto(ObjectEntry $zone, Variable $returnVar): void
    {
        $location = VmDateTimeNative::timezoneLocation(self::timezoneName($zone));
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
            $name = VmDateTimeNative::validateTimezoneId($timezone);
        } catch (NativeDateInvalidTimeZoneException) {
            self::throwDateInvalidTimeZoneException($timezone);
        }
        self::requireStringProperty($zone, self::TZ_NAME_PROPERTY, 'DateTimeZone')->string($name);
        $zone->constructed = true;
    }

    /**
     * Allocate a DateTimeZone object (ext/date/php_date.c — PHP_FUNCTION(timezone_open), #4634).
     *
     * @throws NativeDateInvalidTimeZoneException when the timezone id is invalid
     */
    public static function newDateTimeZoneVariable(Context $ctx, string $timezone): Variable
    {
        $class = $ctx->classes[self::CLASS_DATETIMEZONE] ?? null;
        if (null === $class) {
            throw new \LogicException('DateTimeZone is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        self::initDateTimeZone($entry, $timezone);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    /** php-src PHP_FUNCTION(date_create) — false on parse failure (#4124). */
    public static function tryNewDateTimeVariable(
        Context $ctx,
        string $time,
        ?ObjectEntry $timezone = null
    ): ?Variable {
        return self::tryNewDateTimeLikeVariable($ctx, self::CLASS_DATETIME, $time, $timezone);
    }

    /** php-src PHP_FUNCTION(date_create_immutable) — false on parse failure (#4124). */
    public static function tryNewDateTimeImmutableVariable(
        Context $ctx,
        string $time,
        ?ObjectEntry $timezone = null
    ): ?Variable {
        return self::tryNewDateTimeLikeVariable($ctx, self::CLASS_DATETIMEIMMUTABLE, $time, $timezone);
    }

    /** php-src PHP_FUNCTION(date_create_from_format) — false on parse failure (#6172). */
    public static function tryNewDateTimeFromFormatVariable(
        Context $ctx,
        string $format,
        string $time,
        ?ObjectEntry $timezone = null
    ): ?Variable {
        return self::tryNewDateTimeLikeFromFormatVariable($ctx, self::CLASS_DATETIME, $format, $time, $timezone);
    }

    /** php-src PHP_FUNCTION(date_create_immutable_from_format) — false on parse failure (#6172). */
    public static function tryNewDateTimeImmutableFromFormatVariable(
        Context $ctx,
        string $format,
        string $time,
        ?ObjectEntry $timezone = null
    ): ?Variable {
        return self::tryNewDateTimeLikeFromFormatVariable($ctx, self::CLASS_DATETIMEIMMUTABLE, $format, $time, $timezone);
    }

    private static function tryNewDateTimeLikeFromFormatVariable(
        Context $ctx,
        string $classKey,
        string $format,
        string $time,
        ?ObjectEntry $timezone
    ): ?Variable {
        $class = $ctx->classes[$classKey] ?? null;
        if (null === $class) {
            throw new \LogicException('DateTime is not registered in this compiler build');
        }
        $tzName = null !== $timezone
            ? self::timezoneName($timezone)
            : VmDate::defaultTimezoneGet();
        try {
            VmDateTimeNative::validateTimezoneId($tzName);
        } catch (NativeDateInvalidTimeZoneException) {
            return null;
        }
        $parsed = VmDateTimeNative::parseFromFormat($format, $time, $tzName);
        if (false === $parsed) {
            self::recordCreateFromFormatFailure($format, $time);

            return null;
        }
        self::clearCreateFromFormatLastErrors();
        $entry = new ObjectEntry($class);
        self::applyParsedState($entry, $parsed, $tzName);
        $entry->constructed = true;
        self::markDateTimeLikeInitialized($entry);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    /** php-src PHP_METHOD(DateTime, getLastErrors) — false when no recorded errors (#4660). */
    public static function writeCreateFromFormatLastErrors(Variable $returnVar): void
    {
        if (false === self::$createFromFormatLastErrors) {
            $returnVar->bool(false);

            return;
        }
        $returnVar->array(VmDate::lastErrorsToHashTable(self::$createFromFormatLastErrors));
    }

    private static function recordCreateFromFormatFailure(string $format, string $time): void
    {
        $components = VmDateTimeNative::parseFromFormatComponents($format, $time);
        self::$createFromFormatLastErrors = [
            'warning_count' => $components['warning_count'],
            'warnings' => $components['warnings'],
            'error_count' => $components['error_count'],
            'errors' => $components['errors'],
        ];
    }

    private static function clearCreateFromFormatLastErrors(): void
    {
        self::$createFromFormatLastErrors = false;
    }

    private static function tryNewDateTimeLikeVariable(
        Context $ctx,
        string $classKey,
        string $time,
        ?ObjectEntry $timezone
    ): ?Variable {
        $class = $ctx->classes[$classKey] ?? null;
        if (null === $class) {
            throw new \LogicException('DateTime is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        $tzName = null !== $timezone
            ? self::timezoneName($timezone)
            : VmDate::defaultTimezoneGet();
        try {
            VmDateTimeNative::validateTimezoneId($tzName);
            $parsed = VmDateTimeNative::parseDateTime($time, $tzName);
        } catch (NativeDateInvalidTimeZoneException|NativeDateMalformedStringException) {
            return null;
        }
        self::applyParsedState($entry, $parsed, $tzName);
        $entry->constructed = true;
        self::markDateTimeLikeInitialized($entry);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
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
            : VmDate::defaultTimezoneGet();
        try {
            VmDateTimeNative::validateTimezoneId($tzName);
            $parsed = VmDateTimeNative::parseDateTime($time, $tzName);
        } catch (NativeDateInvalidTimeZoneException) {
            self::throwDateInvalidTimeZoneException($tzName);
        } catch (NativeDateMalformedStringException $e) {
            self::throwDateMalformedStringException($e->getMessage());
        }
        self::applyParsedState($dt, $parsed, $tzName);
        $dt->constructed = true;
        self::markDateTimeLikeInitialized($dt);
    }

    /** php-src zim_DateTime_createFromTimestamp / zim_DateTimeImmutable_createFromTimestamp (#5973). */
    public static function initDateTimeFromTimestamp(ObjectEntry $dt, int $timestamp): void
    {
        $tzName = VmDate::defaultTimezoneGet();
        try {
            VmDateTimeNative::validateTimezoneId($tzName);
        } catch (NativeDateInvalidTimeZoneException) {
            self::throwDateInvalidTimeZoneException($tzName);
        }
        if (4 === \PHP_INT_SIZE) {
            if ($timestamp > \PHP_INT_MAX || $timestamp < \PHP_INT_MIN) {
                self::throwDateRangeError('Epoch doesn\'t fit in a PHP integer');
            }
        }
        self::applyParsedState($dt, ['timestamp' => $timestamp, 'microsecond' => 0], $tzName);
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
            : VmDate::defaultTimezoneGet();
        try {
            VmDateTimeNative::validateTimezoneId($tzName);
        } catch (NativeDateInvalidTimeZoneException) {
            self::throwDateInvalidTimeZoneException($tzName);
        }
        $parsed = VmDateTimeNative::parseFromFormat($format, $time, $tzName);
        if (false === $parsed) {
            self::throwDateMalformedStringException(
                'DateTimeImmutable::createFromFormat(): Failed to parse time string ('.$time.')'
            );
        }
        self::applyParsedState($dt, $parsed, $tzName);
        $dt->constructed = true;
        self::markDateTimeLikeInitialized($dt);
    }

    public static function format(ObjectEntry $dt, string $format): string
    {
        self::requireInitializedDateTimeLike($dt, self::classLabel($dt).'::format()');
        $timestamp = self::requireIntProperty($dt, self::TS_PROPERTY, self::classLabel($dt))->toInt();
        $microsecond = self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, self::classLabel($dt))->toInt();
        $tzName = self::requireStringProperty($dt, self::TZ_PROPERTY, self::classLabel($dt))->toString();

        return VmDateTimeNative::format($timestamp, $microsecond, $tzName, $format);
    }

    public static function getTimestamp(ObjectEntry $dt): int
    {
        self::requireInitializedDateTimeLike($dt, self::classLabel($dt).'::getTimestamp()');
        $epoch = self::requireIntProperty($dt, self::TS_PROPERTY, self::classLabel($dt))->toInt();
        if (4 === \PHP_INT_SIZE) {
            if ($epoch > \PHP_INT_MAX || $epoch < \PHP_INT_MIN) {
                self::throwDateRangeError('Epoch doesn\'t fit in a PHP integer');
            }
        }

        return $epoch;
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
        self::requireInitializedDateTimeLike($dt, self::classLabel($dt).'::setTimezone()');
        $tzName = self::timezoneName($timezone);
        try {
            VmDateTimeNative::validateTimezoneId($tzName);
        } catch (NativeDateInvalidTimeZoneException) {
            self::throwDateInvalidTimeZoneException($tzName);
        }
        self::requireStringProperty($dt, self::TZ_PROPERTY, self::classLabel($dt))->string($tzName);
    }

    /** php-src php_date_add — mutable in-place (#4604). */
    public static function addInterval(ObjectEntry $dt, ObjectEntry $interval): void
    {
        $label = self::classLabel($dt);
        self::requireInitializedDateTimeLike($dt, 'date_add()');
        $state = DateIntervalSupport::readState($interval);
        $tzName = self::requireStringProperty($dt, self::TZ_PROPERTY, $label)->toString();
        $timestamp = self::requireIntProperty($dt, self::TS_PROPERTY, $label)->toInt();
        $microsecond = self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, $label)->toInt();
        $updated = VmDateTimeNative::applyIntervalState($timestamp, $microsecond, $state, $tzName, true);
        self::requireIntProperty($dt, self::TS_PROPERTY, $label)->int($updated['timestamp']);
        self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, $label)->int($updated['microsecond']);
    }

    /** php-src php_date_sub — mutable in-place (#4604). */
    public static function subInterval(ObjectEntry $dt, ObjectEntry $interval): void
    {
        $label = self::classLabel($dt);
        self::requireInitializedDateTimeLike($dt, 'date_sub()');
        $state = DateIntervalSupport::readState($interval);
        $tzName = self::requireStringProperty($dt, self::TZ_PROPERTY, $label)->toString();
        $timestamp = self::requireIntProperty($dt, self::TS_PROPERTY, $label)->toInt();
        $microsecond = self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, $label)->toInt();
        $updated = VmDateTimeNative::applyIntervalState($timestamp, $microsecond, $state, $tzName, false);
        self::requireIntProperty($dt, self::TS_PROPERTY, $label)->int($updated['timestamp']);
        self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, $label)->int($updated['microsecond']);
    }

    /** php-src php_date_diff — returns new DateInterval (#4604). */
    public static function diffDateTimes(
        ObjectEntry $base,
        ObjectEntry $target,
        bool $absolute,
        Context $ctx
    ): ObjectEntry {
        self::requireInitializedDateTimeLike($base, 'date_diff()');
        self::requireInitializedDateTimeLike($target, 'date_diff()');
        $baseLabel = self::classLabel($base);
        $targetLabel = self::classLabel($target);
        $baseTs = self::requireIntProperty($base, self::TS_PROPERTY, $baseLabel)->toInt();
        $targetTs = self::requireIntProperty($target, self::TS_PROPERTY, $targetLabel)->toInt();
        $tzName = self::requireStringProperty($base, self::TZ_PROPERTY, $baseLabel)->toString();
        $diffState = VmDateTimeNative::diffTimestamps($baseTs, $targetTs, $tzName, $absolute);

        return DateIntervalSupport::createFromState($ctx, $diffState);
    }

    /** php-src zim_DateTime_modify — mutable in-place (#6132). */
    public static function modify(ObjectEntry $dt, string $modifier): void
    {
        $label = self::classLabel($dt);
        self::requireInitializedDateTimeLike($dt, "{$label}::modify()");
        $tzName = self::requireStringProperty($dt, self::TZ_PROPERTY, $label)->toString();
        $timestamp = self::requireIntProperty($dt, self::TS_PROPERTY, $label)->toInt();
        try {
            $updated = VmDateTimeNative::modifyRelative($timestamp, $modifier, $tzName);
        } catch (NativeDateMalformedStringException $e) {
            self::throwDateMalformedStringException($e->getMessage());
        }
        self::requireIntProperty($dt, self::TS_PROPERTY, $label)->int($updated);
    }

    /** php-src zim_DateTimeImmutable_modify — returns new instance (#6132). */
    public static function withModify(ObjectEntry $dt, string $modifier): ObjectEntry
    {
        $label = self::classLabel($dt);
        self::requireInitializedDateTimeLike($dt, "{$label}::modify()");
        $tzName = self::requireStringProperty($dt, self::TZ_PROPERTY, $label)->toString();
        $timestamp = self::requireIntProperty($dt, self::TS_PROPERTY, $label)->toInt();
        try {
            $updated = VmDateTimeNative::modifyRelative($timestamp, $modifier, $tzName);
        } catch (NativeDateMalformedStringException $e) {
            self::throwDateMalformedStringException($e->getMessage());
        }
        $clone = self::cloneDateTimeObject($dt);
        self::requireIntProperty($clone, self::TS_PROPERTY, $label)->int($updated);

        return $clone;
    }

    private static function validateMicrosecond(int $microsecond): void
    {
        if ($microsecond < 0 || $microsecond > 999_999) {
            throw new \ValueError(
                'DateTime::setMicrosecond(): Argument #1 ($microsecond) must be between 0 and 999999'
            );
        }
    }

    /** php-src zim_DateTime_createFromImmutable — clone immutable snapshot to mutable (#6518). */
    public static function createDateTimeFromImmutable(Variable $immutableArg, Context $ctx): ObjectEntry
    {
        $immutable = self::requireDateTimeImmutable(
            $immutableArg,
            'DateTime::createFromImmutable()',
            1,
            'object'
        );
        self::requireInitializedDateTimeLike($immutable, 'DateTimeImmutable');
        $class = $ctx->classes[self::CLASS_DATETIME] ?? null;
        if (null === $class) {
            throw new \LogicException('DateTime is not registered in this compiler build');
        }
        $mutable = new ObjectEntry($class);
        self::copyDateTimeState($immutable, $mutable);
        $mutable->constructed = true;
        self::markDateTimeLikeInitialized($mutable);

        return $mutable;
    }

    /** php-src zim_DateTimeImmutable_createFromMutable — clone mutable snapshot to immutable (#6197). */
    public static function createDateTimeImmutableFromMutable(Variable $mutableArg, Context $ctx): ObjectEntry
    {
        $mutable = self::requireDateTime(
            $mutableArg,
            'DateTimeImmutable::createFromMutable()',
            1,
            'object'
        );
        self::requireInitializedDateTimeLike($mutable, 'DateTime');
        $class = $ctx->classes[self::CLASS_DATETIMEIMMUTABLE] ?? null;
        if (null === $class) {
            throw new \LogicException('DateTimeImmutable is not registered in this compiler build');
        }
        $immutable = new ObjectEntry($class);
        self::copyDateTimeState($mutable, $immutable);
        $immutable->constructed = true;
        self::markDateTimeLikeInitialized($immutable);

        return $immutable;
    }

    /** php-src zim_DateTime_createFromInterface — clone DateTimeInterface to mutable (#5936). */
    public static function createDateTimeFromInterface(Variable $objectArg, Context $ctx): ObjectEntry
    {
        $source = self::requireDateTimeInterface(
            $objectArg,
            'DateTime::createFromInterface()',
            $ctx,
            1,
            'object'
        );
        self::requireInitializedDateTimeLike($source, self::classLabel($source));
        $class = $ctx->classes[self::CLASS_DATETIME] ?? null;
        if (null === $class) {
            throw new \LogicException('DateTime is not registered in this compiler build');
        }
        $mutable = new ObjectEntry($class);
        self::copyDateTimeState($source, $mutable);
        $mutable->constructed = true;
        self::markDateTimeLikeInitialized($mutable);

        return $mutable;
    }

    /** php-src zim_DateTimeImmutable_createFromInterface — clone DateTimeInterface to immutable (#5936). */
    public static function createDateTimeImmutableFromInterface(Variable $objectArg, Context $ctx): ObjectEntry
    {
        $source = self::requireDateTimeInterface(
            $objectArg,
            'DateTimeImmutable::createFromInterface()',
            $ctx,
            1,
            'object'
        );
        self::requireInitializedDateTimeLike($source, self::classLabel($source));
        $class = $ctx->classes[self::CLASS_DATETIMEIMMUTABLE] ?? null;
        if (null === $class) {
            throw new \LogicException('DateTimeImmutable is not registered in this compiler build');
        }
        $immutable = new ObjectEntry($class);
        self::copyDateTimeState($source, $immutable);
        $immutable->constructed = true;
        self::markDateTimeLikeInitialized($immutable);

        return $immutable;
    }

    private static function cloneDateTimeObject(ObjectEntry $source): ObjectEntry
    {
        $clone = new ObjectEntry($source->class);
        self::copyDateTimeState($source, $clone);
        $clone->constructed = true;
        self::markDateTimeLikeInitialized($clone);

        return $clone;
    }

    private static function copyDateTimeState(ObjectEntry $source, ObjectEntry $target): void
    {
        $sourceLabel = self::classLabel($source);
        $targetLabel = self::classLabel($target);
        self::requireIntProperty($target, self::TS_PROPERTY, $targetLabel)
            ->int(self::requireIntProperty($source, self::TS_PROPERTY, $sourceLabel)->toInt());
        self::requireStringProperty($target, self::TZ_PROPERTY, $targetLabel)
            ->string(self::requireStringProperty($source, self::TZ_PROPERTY, $sourceLabel)->toString());
        self::requireIntProperty($target, self::MICROSECOND_PROPERTY, $targetLabel)
            ->int(self::requireIntProperty($source, self::MICROSECOND_PROPERTY, $sourceLabel)->toInt());
    }

    /**
     * @param array{timestamp: int, microsecond: int} $parsed
     */
    private static function applyParsedState(ObjectEntry $dt, array $parsed, string $tzName): void
    {
        self::requireIntProperty($dt, self::TS_PROPERTY, self::classLabel($dt))->int($parsed['timestamp']);
        self::requireStringProperty($dt, self::TZ_PROPERTY, self::classLabel($dt))->string($tzName);
        self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, self::classLabel($dt))
            ->int($parsed['microsecond']);
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
