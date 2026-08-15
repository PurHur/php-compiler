<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\ext\standard\VmDateTimeNative;
use PHPCompiler\ext\standard\VmSerialize;

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

    /**
     * Compiler-only DateTime* storage keys — not present on Zend objects.
     *
     * php-src get_mangled_object_vars() reads zend_get_properties_no_lazy_init (raw
     * property table); DateTime state lives in C, so the table is empty (#22445).
     */
    public static function isInternalStorageProperty(string $name): bool
    {
        return self::TS_PROPERTY === $name
            || self::TZ_PROPERTY === $name
            || self::MICROSECOND_PROPERTY === $name
            || self::TZ_NAME_PROPERTY === $name;
    }

    /**
     * Strip {@see isInternalStorageProperty()} keys from get_mangled_object_vars / get_object_vars / foreach (#22445, #23432).
     *
     * @param array<string, Variable> $props
     * @return array<string, Variable>
     */
    public static function filterInternalStorageFromMangledVars(array $props): array
    {
        $out = [];
        foreach ($props as $key => $value) {
            if (self::isInternalStorageProperty((string) $key)) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

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

    /**
     * php-src ext/date/php_date.c — unix-timestamp strings with `@` prefix ("@0", "@1700000000").
     *
     * When the `@` prefix is used, Zend uses a numeric timezone offset (+00:00) regardless of the
     * default timezone or the optional timezone argument.
     *
     * @return array{timestamp: int, microsecond: int}|null
     */
    private static function tryParseAtUnixTimestampString(string $time): ?array
    {
        if ('' === $time || '@' !== $time[0]) {
            return null;
        }
        if (!\preg_match('/^@([+-]?(?:\\d+)(?:\\.\\d+)?)$/', $time, $m)) {
            return null;
        }
        $raw = $m[1];
        $num = false !== \str_contains($raw, '.') ? (float) $raw : (int) $raw;

        return self::splitTimestampNumber($num);
    }

    public static function requireDateTimeZone(
        Variable $var,
        string $label,
        ?int $argNum = null,
        ?string $argName = null
    ): ObjectEntry {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw self::dateTimeZoneTypeError($label, $argNum, $argName, $var);
        }
        $obj = $var->toObject();
        if (self::CLASS_DATETIMEZONE !== strtolower($obj->class->name)) {
            throw self::dateTimeZoneTypeError($label, $argNum, $argName, $var, $obj->class->name);
        }

        return $obj;
    }

    private static function dateTimeZoneTypeError(
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
                "{$label}: Argument #{$argNum}{$param} must be of type DateTimeZone, {$given} given"
            );
        }

        return new \TypeError("{$label} must be of type DateTimeZone, {$given} given");
    }

    public static function requireDateTime(
        Variable $var,
        string $label,
        ?int $argNum = null,
        ?string $argName = null,
        ?Context $ctx = null
    ): ObjectEntry {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw self::dateTimeTypeError($label, $argNum, $argName, $var);
        }
        $obj = $var->toObject();
        if (!self::objectIsMutableDateTime($obj, $ctx)) {
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
        ?string $argName = null,
        ?Context $ctx = null
    ): ObjectEntry {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw self::dateTimeImmutableTypeError($label, $argNum, $argName, $var);
        }
        $obj = $var->toObject();
        if (!self::objectIsImmutableDateTime($obj, $ctx)) {
            throw self::dateTimeImmutableTypeError($label, $argNum, $argName, $var, $obj->class->name);
        }

        return $obj;
    }

    /** php-src instanceof DateTime — mutable class or subclass (#16204, #7276). */
    private static function objectIsMutableDateTime(ObjectEntry $obj, ?Context $ctx): bool
    {
        if (self::isDateTimeImmutable($obj)) {
            return false;
        }
        $lc = strtolower($obj->class->name);
        if (self::CLASS_DATETIME === $lc) {
            return true;
        }
        if (null !== $ctx) {
            return InterfaceCheck::entryIsInstanceOf($obj->class, self::CLASS_DATETIME, $ctx)
                && !InterfaceCheck::entryIsInstanceOf($obj->class, self::CLASS_DATETIMEIMMUTABLE, $ctx);
        }

        return self::CLASS_DATETIME === $obj->class->parentLc;
    }

    /** php-src instanceof DateTimeImmutable — immutable class or subclass (#16204). */
    private static function objectIsImmutableDateTime(ObjectEntry $obj, ?Context $ctx): bool
    {
        $lc = strtolower($obj->class->name);
        if (self::CLASS_DATETIMEIMMUTABLE === $lc) {
            return true;
        }
        if (null !== $ctx) {
            return InterfaceCheck::entryIsInstanceOf($obj->class, self::CLASS_DATETIMEIMMUTABLE, $ctx);
        }

        return self::CLASS_DATETIMEIMMUTABLE === $obj->class->parentLc;
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
        if (null !== $zone->dateTimeZoneName && '' !== $zone->dateTimeZoneName) {
            return $zone->dateTimeZoneName;
        }

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

    /** php-src PHP_FUNCTION(date_offset_get) — getOffset(getTimezone()) (#11876). */
    public static function dateOffsetGet(ObjectEntry $datetime): int
    {
        $label = self::classLabel($datetime);
        self::requireInitializedDateTimeLike($datetime, 'date_offset_get()');
        $tzName = self::requireStringProperty($datetime, self::TZ_PROPERTY, $label)->toString();
        $timestamp = self::requireIntProperty($datetime, self::TS_PROPERTY, $label)->toInt();

        return VmDateTimeNative::timezoneOffsetSeconds($tzName, $timestamp);
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

    /**
     * php-src zim_DateTimeZone_getTransitions / timezone_transitions_get (#6041, #11211).
     *
     * @param list<array{ts: int, time: string, offset: int, isdst: bool, abbr: string}>|false $transitions
     */
    public static function timezoneTransitionsInto(array|false $transitions, Variable $returnVar): void
    {
        if (false === $transitions) {
            $returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($transitions as $index => $transition) {
            $row = new HashTable();
            foreach ($transition as $key => $value) {
                $cell = new Variable();
                if (\is_int($value)) {
                    $cell->int($value);
                } elseif (\is_bool($value)) {
                    $cell->bool($value);
                } else {
                    $cell->string((string) $value);
                }
                $row->addNew((string) $key, $cell);
            }
            $entry = new Variable();
            $entry->array($row);
            $ht->addNew((string) $index, $entry);
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
        $zone->dateTimeZoneName = $name;
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
        $effectiveTz = $parsed['timezone'] ?? $tzName;
        $components = VmDateTimeNative::parseFromFormatComponents($format, $time);
        if ($components['warning_count'] > 0) {
            self::$createFromFormatLastErrors = [
                'warning_count' => $components['warning_count'],
                'warnings' => $components['warnings'],
                'error_count' => 0,
                'errors' => [],
            ];
        } else {
            self::clearCreateFromFormatLastErrors();
        }
        $entry = new ObjectEntry($class);
        self::applyParsedState($entry, $parsed, $effectiveTz);
        $entry->constructed = true;
        self::markDateTimeLikeInitialized($entry);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    /**
     * Compile-time snapshot of the createFromFormat last-errors bag for JIT/AOT (#30749).
     *
     * Same slot as {@see writeCreateFromFormatLastErrors}; peers date_create_from_format /
     * DateTime::createFromFormat update it during lowering so date_get_last_errors() /
     * DateTime::getLastErrors() can materialize a constant hashtable (or false).
     *
     * @return array{
     *   warning_count: int,
     *   warnings: array<int, string>,
     *   error_count: int,
     *   errors: array<int, string>
     * }|false
     */
    public static function peekCreateFromFormatLastErrors(): array|false
    {
        return self::$createFromFormatLastErrors;
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

    /** php-src ext/date/php_date.c — date_create() failure populates getLastErrors() without E_WARNING (#16488). */
    private static function recordDateTimeParseFailure(string $time): void
    {
        $components = VmDateTimeNative::parseDate($time);
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
        $atTimestamp = self::tryParseAtUnixTimestampString($time);
        if (null !== $atTimestamp) {
            $tzName = '+00:00';
            try {
                VmDateTimeNative::validateTimezoneId($tzName);
            } catch (NativeDateInvalidTimeZoneException) {
                return null;
            }
            self::applyParsedState($entry, $atTimestamp, $tzName);
            $entry->constructed = true;
            self::markDateTimeLikeInitialized($entry);
            $var = new Variable(Variable::TYPE_OBJECT);
            $var->object($entry);

            return $var;
        }
        $tzName = null !== $timezone
            ? self::timezoneName($timezone)
            : VmDate::defaultTimezoneGet();
        try {
            VmDateTimeNative::validateTimezoneId($tzName);
            $parsed = VmDateTimeNative::parseDateTime($time, $tzName);
        } catch (NativeDateInvalidTimeZoneException|NativeDateMalformedStringException) {
            self::recordDateTimeParseFailure($time);

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

    /**
     * php-src date_object_clone_date — transfer initialized flag after cloneShallow (#22892).
     *
     * Instance __dt_* slots are already copied; the side table is keyed by ObjectEntry::$id.
     */
    public static function cloneInto(ObjectEntry $src, ObjectEntry $dest): void
    {
        if (!isset(self::$dateTimeLikeInitialized[$src->id])) {
            return;
        }
        self::markDateTimeLikeInitialized($dest);
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
        $atTimestamp = self::tryParseAtUnixTimestampString($time);
        if (null !== $atTimestamp) {
            $tzName = '+00:00';
            try {
                VmDateTimeNative::validateTimezoneId($tzName);
            } catch (NativeDateInvalidTimeZoneException) {
                self::throwDateInvalidTimeZoneException($tzName);
            }
            self::applyParsedState($dt, $atTimestamp, $tzName);
            $dt->constructed = true;
            self::markDateTimeLikeInitialized($dt);

            return;
        }
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
        $effectiveTz = $parsed['timezone'] ?? $tzName;
        unset($parsed['timezone']);
        self::applyParsedState($dt, $parsed, $effectiveTz);
        $dt->constructed = true;
        self::markDateTimeLikeInitialized($dt);
    }

    /** php-src zim_DateTime_createFromTimestamp / zim_DateTimeImmutable_createFromTimestamp (#5973, #9984). */
    public static function initDateTimeFromTimestamp(ObjectEntry $dt, int|float $timestamp): void
    {
        $tzName = VmDate::defaultTimezoneGet();
        try {
            VmDateTimeNative::validateTimezoneId($tzName);
        } catch (NativeDateInvalidTimeZoneException) {
            self::throwDateInvalidTimeZoneException($tzName);
        }
        $parts = self::splitTimestampNumber($timestamp);
        $seconds = $parts['timestamp'];
        if (4 === \PHP_INT_SIZE) {
            if ($seconds > \PHP_INT_MAX || $seconds < \PHP_INT_MIN) {
                self::throwDateRangeError('Epoch doesn\'t fit in a PHP integer');
            }
        }
        self::applyParsedState(
            $dt,
            ['timestamp' => $seconds, 'microsecond' => $parts['microsecond']],
            $tzName
        );
        $dt->constructed = true;
        self::markDateTimeLikeInitialized($dt);
    }

    /**
     * php-src ext/date/php_date.c — float timestamp → epoch seconds + usec (#9984).
     *
     * @return array{timestamp: int, microsecond: int}
     */
    public static function splitTimestampNumber(int|float $timestamp): array
    {
        if (\is_int($timestamp)) {
            return ['timestamp' => $timestamp, 'microsecond' => 0];
        }
        if (!\is_finite($timestamp)) {
            throw new \ValueError('Invalid timestamp');
        }
        $seconds = (int) $timestamp;
        $fraction = $timestamp - $seconds;
        $microsecond = (int) \round($fraction * 1_000_000);
        if (1_000_000 === $microsecond) {
            $seconds += $timestamp >= 0.0 ? 1 : -1;
            $microsecond = 0;
        } elseif ($microsecond < 0) {
            --$seconds;
            $microsecond += 1_000_000;
        }

        return ['timestamp' => $seconds, 'microsecond' => $microsecond];
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
        $effectiveTz = $parsed['timezone'] ?? $tzName;
        self::applyParsedState($dt, $parsed, $effectiveTz);
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

    /** php-src zim_DateTime_getTimezone — returns DateTimeZone for stored tz id (#10946). */
    public static function getTimezoneObject(ObjectEntry $dt, Context $ctx): ObjectEntry
    {
        $label = self::classLabel($dt);
        self::requireInitializedDateTimeLike($dt, "{$label}::getTimezone()");
        $tzName = self::requireStringProperty($dt, self::TZ_PROPERTY, $label)->toString();

        return self::newDateTimeZoneVariable($ctx, $tzName)->toObject();
    }

    /** php-src zim_DateTime_setTimestamp — mutable in-place (#10946). */
    public static function setTimestamp(ObjectEntry $dt, int $timestamp): void
    {
        $label = self::classLabel($dt);
        self::requireInitializedDateTimeLike($dt, "{$label}::setTimestamp()");
        if (4 === \PHP_INT_SIZE) {
            if ($timestamp > \PHP_INT_MAX || $timestamp < \PHP_INT_MIN) {
                self::throwDateRangeError('Epoch doesn\'t fit in a PHP integer');
            }
        }
        self::requireIntProperty($dt, self::TS_PROPERTY, $label)->int($timestamp);
        self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, $label)->int(0);
    }

    /** php-src zim_DateTimeImmutable_setTimestamp — returns new instance (#10946). */
    public static function withTimestamp(ObjectEntry $dt, int $timestamp): ObjectEntry
    {
        $clone = self::cloneDateTimeObject($dt);
        self::setTimestamp($clone, $timestamp);

        return $clone;
    }

    /** php-src zim_DateTimeImmutable_add — returns new instance (#10946). */
    public static function withAddInterval(ObjectEntry $dt, ObjectEntry $interval): ObjectEntry
    {
        $clone = self::cloneDateTimeObject($dt);
        self::addInterval($clone, $interval);

        return $clone;
    }

    /** php-src zim_DateTimeImmutable_sub — returns new instance (#10946). */
    public static function withSubInterval(ObjectEntry $dt, ObjectEntry $interval): ObjectEntry
    {
        $clone = self::cloneDateTimeObject($dt);
        self::subInterval($clone, $interval);

        return $clone;
    }

    public static function getMicrosecond(ObjectEntry $dt): int
    {
        return self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, self::classLabel($dt))->toInt();
    }

    /** php-src zim_DateTime_setMicrosecond — mutable in-place (#7082). */
    public static function setMicrosecond(ObjectEntry $dt, int $microsecond): void
    {
        self::validateMicrosecond($microsecond, self::classLabel($dt));
        self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, self::classLabel($dt))
            ->int($microsecond);
    }

    /** php-src zim_DateTime_setDate — mutable in-place (#12469). */
    public static function setDate(ObjectEntry $dt, int $year, int $month, int $day): void
    {
        $label = self::classLabel($dt);
        self::requireInitializedDateTimeLike($dt, "{$label}::setDate()");
        $tzName = self::requireStringProperty($dt, self::TZ_PROPERTY, $label)->toString();
        $timestamp = self::requireIntProperty($dt, self::TS_PROPERTY, $label)->toInt();
        $microsecond = self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, $label)->toInt();
        $updated = VmDateTimeNative::replaceDateComponents(
            $timestamp,
            $microsecond,
            $tzName,
            $year,
            $month,
            $day
        );
        self::requireIntProperty($dt, self::TS_PROPERTY, $label)->int($updated['timestamp']);
        self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, $label)->int($updated['microsecond']);
    }

    /** php-src zim_DateTimeImmutable_setDate — returns new instance (#12469). */
    public static function withDate(ObjectEntry $dt, int $year, int $month, int $day): ObjectEntry
    {
        $clone = self::cloneDateTimeObject($dt);
        self::setDate($clone, $year, $month, $day);

        return $clone;
    }

    /** php-src zim_DateTime_setISODate / php_date_isodate_set — mutable in-place (#19847). */
    public static function setISODate(ObjectEntry $dt, int $year, int $week, int $dayOfWeek = 1): void
    {
        $label = self::classLabel($dt);
        self::requireInitializedDateTimeLike($dt, "{$label}::setISODate()");
        $tzName = self::requireStringProperty($dt, self::TZ_PROPERTY, $label)->toString();
        $timestamp = self::requireIntProperty($dt, self::TS_PROPERTY, $label)->toInt();
        $microsecond = self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, $label)->toInt();
        $updated = VmDateTimeNative::replaceISODateComponents(
            $timestamp,
            $microsecond,
            $tzName,
            $year,
            $week,
            $dayOfWeek
        );
        self::requireIntProperty($dt, self::TS_PROPERTY, $label)->int($updated['timestamp']);
        self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, $label)->int($updated['microsecond']);
    }

    /** php-src zim_DateTimeImmutable_setISODate — returns new instance (#19847). */
    public static function withISODate(ObjectEntry $dt, int $year, int $week, int $dayOfWeek = 1): ObjectEntry
    {
        $clone = self::cloneDateTimeObject($dt);
        self::setISODate($clone, $year, $week, $dayOfWeek);

        return $clone;
    }

    /** php-src zim_DateTime_setTime — mutable in-place (#12469). */
    public static function setTime(
        ObjectEntry $dt,
        int $hour,
        int $minute,
        int $second = 0,
        int $microsecond = 0
    ): void {
        $label = self::classLabel($dt);
        self::requireInitializedDateTimeLike($dt, "{$label}::setTime()");
        $tzName = self::requireStringProperty($dt, self::TZ_PROPERTY, $label)->toString();
        $timestamp = self::requireIntProperty($dt, self::TS_PROPERTY, $label)->toInt();
        $currentMicrosecond = self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, $label)->toInt();
        $updated = VmDateTimeNative::replaceTimeComponents(
            $timestamp,
            $currentMicrosecond,
            $tzName,
            $hour,
            $minute,
            $second,
            $microsecond
        );
        self::requireIntProperty($dt, self::TS_PROPERTY, $label)->int($updated['timestamp']);
        self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, $label)->int($updated['microsecond']);
    }

    /** php-src zim_DateTimeImmutable_setTime — returns new instance (#12469). */
    public static function withTime(
        ObjectEntry $dt,
        int $hour,
        int $minute,
        int $second = 0,
        int $microsecond = 0
    ): ObjectEntry {
        $clone = self::cloneDateTimeObject($dt);
        self::setTime($clone, $hour, $minute, $second, $microsecond);

        return $clone;
    }

    /** php-src zim_DateTimeImmutable_setMicrosecond — returns new instance (#7082). */
    public static function withMicrosecond(ObjectEntry $dt, int $microsecond): ObjectEntry
    {
        self::validateMicrosecond($microsecond, self::classLabel($dt));
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

    /** php-src zim_DateTimeImmutable_setTimezone — returns new instance (#22824). */
    public static function withTimezone(ObjectEntry $dt, ObjectEntry $timezone): ObjectEntry
    {
        $clone = self::cloneDateTimeObject($dt);
        self::setTimezone($clone, $timezone);

        return $clone;
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
        $baseUs = self::requireIntProperty($base, self::MICROSECOND_PROPERTY, $baseLabel)->toInt();
        $targetUs = self::requireIntProperty($target, self::MICROSECOND_PROPERTY, $targetLabel)->toInt();
        $tzName = self::requireStringProperty($base, self::TZ_PROPERTY, $baseLabel)->toString();
        $diffState = VmDateTimeNative::diffTimestamps(
            $baseTs,
            $targetTs,
            $tzName,
            $absolute,
            $baseUs,
            $targetUs
        );

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

    /** @return bool false when modifier string is unparseable (php-src date_object_modify, #10733). */
    public static function tryModify(ObjectEntry $dt, string $modifier): bool
    {
        $label = self::classLabel($dt);
        self::requireInitializedDateTimeLike($dt, "{$label}::modify()");
        $tzName = self::requireStringProperty($dt, self::TZ_PROPERTY, $label)->toString();
        $timestamp = self::requireIntProperty($dt, self::TS_PROPERTY, $label)->toInt();
        try {
            $updated = VmDateTimeNative::modifyRelative($timestamp, $modifier, $tzName);
        } catch (NativeDateMalformedStringException) {
            return false;
        }
        self::requireIntProperty($dt, self::TS_PROPERTY, $label)->int($updated);

        return true;
    }

    /** @return ObjectEntry|false */
    public static function tryWithModify(ObjectEntry $dt, string $modifier): ObjectEntry|false
    {
        $label = self::classLabel($dt);
        self::requireInitializedDateTimeLike($dt, "{$label}::modify()");
        $tzName = self::requireStringProperty($dt, self::TZ_PROPERTY, $label)->toString();
        $timestamp = self::requireIntProperty($dt, self::TS_PROPERTY, $label)->toInt();
        try {
            $updated = VmDateTimeNative::modifyRelative($timestamp, $modifier, $tzName);
        } catch (NativeDateMalformedStringException) {
            return false;
        }
        $clone = self::cloneDateTimeObject($dt);
        self::requireIntProperty($clone, self::TS_PROPERTY, $label)->int($updated);

        return $clone;
    }

    /** php-src zim_DateTimeImmutable_modify — returns new instance (#6132). */
    public static function withModify(ObjectEntry $dt, string $modifier): ObjectEntry
    {
        $clone = self::tryWithModify($dt, $modifier);
        if (false === $clone) {
            self::throwDateMalformedStringException(
                'Failed to parse time string ('.$modifier.') at position 0 ('.('' !== $modifier ? $modifier[0] : '').'): Unexpected character'
            );
        }

        return $clone;
    }

    /**
     * php-src ext/date/php_date.c — PHP_METHOD(DateTime, setMicrosecond) /
     * PHP_METHOD(DateTimeImmutable, setMicrosecond) (#31118).
     *
     * zend_argument_error(date_ce_date_range_error, 1, "must be between 0 and 999999, " ZEND_LONG_FMT " given", us)
     */
    public static function setMicrosecondRangeErrorMessage(string $className, int $microsecond): string
    {
        return $className.'::setMicrosecond(): Argument #1 ($microsecond) must be between 0 and 999999, '
            .$microsecond.' given';
    }

    private static function validateMicrosecond(int $microsecond, string $className): void
    {
        if ($microsecond < 0 || $microsecond > 999_999) {
            self::throwDateRangeError(self::setMicrosecondRangeErrorMessage($className, $microsecond));
        }
    }

    /** php-src zim_DateTime_createFromImmutable — clone immutable snapshot to mutable (#6518). */
    public static function createDateTimeFromImmutable(Variable $immutableArg, Context $ctx): ObjectEntry
    {
        $immutable = self::requireDateTimeImmutable(
            $immutableArg,
            'DateTime::createFromImmutable()',
            1,
            'object',
            $ctx
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
            'object',
            $ctx
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

    /** Clone DateTime/DateTimeImmutable for DatePeriod iteration (#14228). */
    public static function cloneDateTimeLike(ObjectEntry $source): ObjectEntry
    {
        return self::cloneDateTimeObject($source);
    }

    public static function readTimestamp(ObjectEntry $dt): int
    {
        return self::requireIntProperty($dt, self::TS_PROPERTY, self::classLabel($dt))->toInt();
    }

    public static function readMicrosecond(ObjectEntry $dt): int
    {
        return self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, self::classLabel($dt))->toInt();
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

    /** php-src ext/date/php_date.c — TIMELIB_ZONETYPE_OFFSET (1) vs TIMELIB_ZONETYPE_ID (3). */
    public static function zendTimezoneWireType(string $tzName): int
    {
        return null !== VmDateTimeNative::parseNumericTimezoneOffset($tzName) ? 1 : 3;
    }

    /**
     * php-src ext/json/php_json.c — DateTime/DateTimeImmutable json encode wire (#14143).
     *
     * @return array{date: string, timezone_type: int, timezone: string}
     */
    public static function exportZendJsonWireDateTimeLike(ObjectEntry $dt): array
    {
        self::requireInitializedDateTimeLike($dt, self::classLabel($dt));
        $className = self::classLabel($dt);
        $timestamp = self::requireIntProperty($dt, self::TS_PROPERTY, $className)->toInt();
        $microsecond = self::requireIntProperty($dt, self::MICROSECOND_PROPERTY, $className)->toInt();
        $tzName = self::requireStringProperty($dt, self::TZ_PROPERTY, $className)->toString();

        return [
            'date' => VmDateTimeNative::formatZendDateWire($timestamp, $microsecond, $tzName),
            'timezone_type' => self::zendTimezoneWireType($tzName),
            'timezone' => $tzName,
        ];
    }

    /**
     * php-src ext/json/php_json.c — DateTimeZone json encode wire (#14143).
     *
     * @return array{timezone_type: int, timezone: string}
     */
    public static function exportZendJsonWireDateTimeZone(ObjectEntry $zone): array
    {
        $tzName = self::timezoneName($zone);

        return [
            'timezone_type' => self::zendTimezoneWireType($tzName),
            'timezone' => $tzName,
        ];
    }

    /** php-src php_date_serialize — Zend `date` / `timezone_type` / `timezone` wire (#10710). */
    public static function encodeZendSerializeWire(ObjectEntry $dt): string
    {
        return VmSerialize::encodeExportedPropertyBag(
            self::classLabel($dt),
            self::exportZendJsonWireDateTimeLike($dt)
        );
    }

    /**
     * php-src zend_get_properties_for(ZEND_PROP_PURPOSE_VAR_EXPORT) — DateTime wire (#22407).
     *
     * @return array<string, Variable>
     */
    public static function varExportPropertyMap(ObjectEntry $dt): array
    {
        $wire = self::exportZendJsonWireDateTimeLike($dt);
        $date = new Variable(Variable::TYPE_STRING);
        $date->string($wire['date']);
        $type = new Variable(Variable::TYPE_INTEGER);
        $type->int($wire['timezone_type']);
        $tz = new Variable(Variable::TYPE_STRING);
        $tz->string($wire['timezone']);

        return [
            'date' => $date,
            'timezone_type' => $type,
            'timezone' => $tz,
        ];
    }

    /**
     * php-src zend_get_properties_for(ZEND_PROP_PURPOSE_VAR_EXPORT) — DateTimeZone wire (#22407).
     *
     * @return array<string, Variable>
     */
    public static function varExportTimezonePropertyMap(ObjectEntry $zone): array
    {
        $wire = self::exportZendJsonWireDateTimeZone($zone);
        $type = new Variable(Variable::TYPE_INTEGER);
        $type->int($wire['timezone_type']);
        $tz = new Variable(Variable::TYPE_STRING);
        $tz->string($wire['timezone']);

        return [
            'timezone_type' => $type,
            'timezone' => $tz,
        ];
    }

    /**
     * php-src date_object_get_properties_for(ZEND_PROP_PURPOSE_DEBUG) — Zend date/timezone wire (#22462).
     *
     * Same hash as VAR_EXPORT / (array) cast. Subclasses of DateTime* / DateTimeZone included.
     * Caller merges user-declared properties in front; do not use for get_mangled_object_vars (#22445).
     *
     * @return array<string, Variable>|null
     */
    public static function tryDebugWirePropertyMap(ObjectEntry $obj, ?Context $ctx = null): ?array
    {
        $lc = strtolower($obj->class->name);
        if (self::CLASS_DATETIME === $lc || self::CLASS_DATETIMEIMMUTABLE === $lc) {
            return self::varExportPropertyMap($obj);
        }
        if (self::CLASS_DATETIMEZONE === $lc) {
            return self::varExportTimezonePropertyMap($obj);
        }
        if (null !== $ctx) {
            if (InterfaceCheck::entryIsInstanceOf($obj->class, self::CLASS_DATETIME, $ctx)
                || InterfaceCheck::entryIsInstanceOf($obj->class, self::CLASS_DATETIMEIMMUTABLE, $ctx)) {
                return self::varExportPropertyMap($obj);
            }
            if (InterfaceCheck::entryIsInstanceOf($obj->class, self::CLASS_DATETIMEZONE, $ctx)) {
                return self::varExportTimezonePropertyMap($obj);
            }

            return null;
        }
        $parent = $obj->class->parentLc;
        while (null !== $parent) {
            if (self::CLASS_DATETIME === $parent || self::CLASS_DATETIMEIMMUTABLE === $parent) {
                return self::varExportPropertyMap($obj);
            }
            if (self::CLASS_DATETIMEZONE === $parent) {
                return self::varExportTimezonePropertyMap($obj);
            }
            // Walk one level via known parentLc only (no Context class table).
            break;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data Zend DateTime unserialize payload
     */
    public static function restoreFromZendSerialize(
        Context $ctx,
        string $classKey,
        array $data,
        ?ObjectEntry $target = null
    ): ObjectEntry {
        $dateWire = $data['date'] ?? null;
        $timezoneType = $data['timezone_type'] ?? null;
        $timezone = $data['timezone'] ?? null;
        $label = self::CLASS_DATETIMEIMMUTABLE === $classKey ? 'DateTimeImmutable' : 'DateTime';
        if (!\is_string($dateWire)
            || !\is_int($timezoneType)
            || !\is_string($timezone)) {
            throw new \Error('Invalid serialization data for '.$label.' object');
        }
        try {
            VmDateTimeNative::validateTimezoneId($timezone);
            $parsed = VmDateTimeNative::parseDateTime($dateWire, $timezone);
        } catch (NativeDateInvalidTimeZoneException|NativeDateMalformedStringException) {
            throw new \Error('Invalid serialization data for '.$label.' object');
        }
        if (null !== $target) {
            self::applyParsedState($target, $parsed, $timezone);
            $target->constructed = true;
            self::markDateTimeLikeInitialized($target);

            return $target;
        }
        $class = $ctx->classes[$classKey] ?? null;
        if (null === $class) {
            throw new \Error('Invalid serialization data for '.$label.' object');
        }
        $entry = new ObjectEntry($class);
        self::applyParsedState($entry, $parsed, $timezone);
        $entry->constructed = true;
        self::markDateTimeLikeInitialized($entry);

        return $entry;
    }

    /**
     * php-src php_date_timezone_initialize_from_hash — DateTimeZone::__set_state (#22407).
     *
     * @param array<string, mixed> $data
     */
    public static function restoreTimezoneFromZendSerialize(
        Context $ctx,
        array $data,
        ?ObjectEntry $target = null,
        bool $wakeupMode = false
    ): ObjectEntry {
        $timezoneType = $data['timezone_type'] ?? null;
        $timezone = $data['timezone'] ?? null;
        if (!\is_int($timezoneType)
            || $timezoneType < 1
            || $timezoneType > 3
            || !\is_string($timezone)
            || str_contains($timezone, "\0")) {
            throw new \Error(
                $wakeupMode
                    ? 'Timezone initialization failed'
                    : 'Invalid serialization data for DateTimeZone object'
            );
        }
        try {
            if (null !== $target) {
                self::initDateTimeZone($target, $timezone);

                return $target;
            }

            return self::newDateTimeZoneVariable($ctx, $timezone)->toObject();
        } catch (NativeDateInvalidTimeZoneException) {
            throw new \Error(
                $wakeupMode
                    ? 'Timezone initialization failed'
                    : 'Invalid serialization data for DateTimeZone object'
            );
        }
    }

    /** php-src DATE_CHECK_INITIALIZED — DateTime*::__serialize (#22596). */
    public static function requireInitializedForSerialize(ObjectEntry $obj, string $classLabel): void
    {
        if (self::CLASS_DATETIMEZONE === strtolower($obj->class->name)) {
            if ($obj->constructed) {
                return;
            }
            throw new \Error(
                'The '.$classLabel.' object has not been correctly initialized by its constructor'
            );
        }
        if (isset(self::$dateTimeLikeInitialized[$obj->id])) {
            return;
        }
        throw new \Error(
            'The '.$classLabel.' object has not been correctly initialized by its constructor'
        );
    }
}
