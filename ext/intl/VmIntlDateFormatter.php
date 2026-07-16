<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\ext\standard\VmDateTimeNative;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/**
 * IntlDateFormatter create/format — ICU pattern subset without full ext/intl (#19549, #5201).
 *
 * php-src: ext/intl/dateformat/dateformat_create.c, dateformat_format.c, dateformat.stub.php
 */
final class VmIntlDateFormatter
{
    public const CLASS_LC = 'intldateformatter';

    public const NONE = -1;
    public const FULL = 0;
    public const LONG = 1;
    public const MEDIUM = 2;
    public const SHORT = 3;
    public const RELATIVE_FULL = 128;
    public const RELATIVE_LONG = 129;
    public const RELATIVE_MEDIUM = 130;
    public const RELATIVE_SHORT = 131;
    public const GREGORIAN = 1;
    public const TRADITIONAL = 0;

    /** @var array<int, array{locale: string, dateType: int, timeType: int, timezone: string, calendar: int, pattern: ?string}> */
    private static array $state = [];

    /** @return array<string, int> */
    public static function classConstants(): array
    {
        return [
            'NONE' => self::NONE,
            'FULL' => self::FULL,
            'LONG' => self::LONG,
            'MEDIUM' => self::MEDIUM,
            'SHORT' => self::SHORT,
            'RELATIVE_FULL' => self::RELATIVE_FULL,
            'RELATIVE_LONG' => self::RELATIVE_LONG,
            'RELATIVE_MEDIUM' => self::RELATIVE_MEDIUM,
            'RELATIVE_SHORT' => self::RELATIVE_SHORT,
            'GREGORIAN' => self::GREGORIAN,
            'TRADITIONAL' => self::TRADITIONAL,
        ];
    }

    public static function create(
        Context $ctx,
        string $locale,
        int $dateType,
        int $timeType,
        ?string $timezone,
        int $calendar,
        ?string $pattern
    ): ObjectEntry {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "IntlDateFormatter" not found');
        }
        $tz = null !== $timezone && '' !== $timezone
            ? VmDateTimeNative::validateTimezoneId($timezone)
            : VmDate::defaultTimezoneGet();
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'locale' => $locale,
            'dateType' => $dateType,
            'timeType' => $timeType,
            'timezone' => $tz,
            'calendar' => $calendar,
            'pattern' => $pattern,
        ];

        return $object;
    }

    /**
     * @return string|false
     */
    public static function format(ObjectEntry $formatter, Variable $datetimeArg, Frame $frame)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'datefmt_format: invalid object type for date/time (only IntlCalendar and DateTimeInterface permitted): U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $pattern = $state['pattern'];
        if (null === $pattern || '' === $pattern) {
            throw new \Error(
                'IntlDateFormatter::format() without an ICU pattern requires full ext/intl (issue #5201)'
            );
        }
        $resolved = self::resolveFormatInstant($datetimeArg, $frame->vmContext);
        if (null === $resolved) {
            return false;
        }
        [$timestamp, $microsecond] = $resolved;
        IntlError::clear();
        $phpFormat = self::icuPatternToPhpFormat($pattern);

        return VmDateTimeNative::format($timestamp, $microsecond, $state['timezone'], $phpFormat);
    }

    public static function coerceLocaleArg(Variable $var, string $function, int $position): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return '';
        }

        return VmString::coerceStringBuiltinArg($var, $function, $position, 'locale');
    }

    public static function coerceIntArg(Variable $var, string $function, int $position, string $name): int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $function,
                $position + 1,
                $name,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return (int) $var->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_STRING === $var->type && is_numeric($var->toString())) {
            return (int) $var->toString();
        }
        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $function,
            $position + 1,
            $name,
            ReflectionSupport::valueTypeLabelPublic($var)
        ));
    }

    public static function coerceOptionalTimezone(Variable $var, string $function, int $position): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            $obj = $var->toObject();
            if ('datetimezone' === strtolower($obj->class->name)) {
                return DateTimeSupport::timezoneName($obj);
            }
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($timezone) must be of type DateTimeZone|string|null, %s given',
                $function,
                $position + 1,
                $obj->class->name
            ));
        }

        return VmString::coerceStringBuiltinArg($var, $function, $position, 'timezone');
    }

    public static function coerceOptionalCalendar(Variable $var, string $function, int $position): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return self::GREGORIAN;
        }

        return self::coerceIntArg($var, $function, $position, 'calendar');
    }

    public static function coerceOptionalPattern(Variable $var, string $function, int $position): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $function, $position, 'pattern');
    }

    public static function isFormatterObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function resolveFormatInstant(Variable $var, ?Context $ctx): ?array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'datefmt_format: invalid PHP type for date: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return null;
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return [$var->toInt(), 0];
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return [(int) $var->toFloat(), 0];
        }
        if (Variable::TYPE_OBJECT === $var->type && null !== $ctx) {
            try {
                $dt = DateTimeSupport::requireDateTimeInterface(
                    $var,
                    'IntlDateFormatter::format()',
                    $ctx
                );

                return [DateTimeSupport::getTimestamp($dt), DateTimeSupport::getMicrosecond($dt)];
            } catch (\TypeError) {
                IntlError::set(
                    IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                    'datefmt_format: invalid object type for date/time (only IntlCalendar and DateTimeInterface permitted): U_ILLEGAL_ARGUMENT_ERROR'
                );

                return null;
            }
        }
        IntlError::set(
            IntlError::U_ILLEGAL_ARGUMENT_ERROR,
            'datefmt_format: invalid PHP type for date: U_ILLEGAL_ARGUMENT_ERROR'
        );

        return null;
    }

    /**
     * Map a small ICU SimpleDateFormat subset to PHP date() tokens (#19549).
     *
     * Supported: y/yy/yyyy, M/MM, d/dd, H/HH, h/hh, m/mm, s/ss, a, and literal punctuation.
     */
    public static function icuPatternToPhpFormat(string $pattern): string
    {
        $out = '';
        $len = \strlen($pattern);
        $i = 0;
        while ($i < $len) {
            $ch = $pattern[$i];
            if ("'" === $ch) {
                $i++;
                $literal = '';
                while ($i < $len) {
                    if ("'" === $pattern[$i]) {
                        if ($i + 1 < $len && "'" === $pattern[$i + 1]) {
                            $literal .= "'";
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $literal .= $pattern[$i];
                    $i++;
                }
                $out .= self::escapePhpDateLiteral($literal);
                continue;
            }
            if (ctype_alpha($ch)) {
                $run = $ch;
                $i++;
                while ($i < $len && $pattern[$i] === $ch) {
                    $run .= $pattern[$i];
                    $i++;
                }
                $out .= self::mapIcuRun($run);
                continue;
            }
            $out .= self::escapePhpDateLiteral($ch);
            $i++;
        }

        return $out;
    }

    private static function mapIcuRun(string $run): string
    {
        $ch = $run[0];
        $n = \strlen($run);

        return match ($ch) {
            'y' => match (true) {
                $n >= 4 => 'Y',
                2 === $n => 'y',
                default => 'Y',
            },
            'M' => match (true) {
                $n >= 4 => 'F',
                3 === $n => 'M',
                2 === $n => 'm',
                default => 'n',
            },
            'd' => $n >= 2 ? 'd' : 'j',
            'H' => $n >= 2 ? 'H' : 'G',
            'h' => $n >= 2 ? 'h' : 'g',
            'm' => 'i',
            's' => 's',
            'a' => 'A',
            default => self::escapePhpDateLiteral($run),
        };
    }

    private static function escapePhpDateLiteral(string $literal): string
    {
        if ('' === $literal) {
            return '';
        }
        // PHP date() treats backslash-escaped ASCII as literals.
        $out = '';
        $len = \strlen($literal);
        for ($i = 0; $i < $len; $i++) {
            $c = $literal[$i];
            if (ctype_alpha($c)) {
                $out .= '\\'.$c;
            } else {
                $out .= $c;
            }
        }

        return $out;
    }
}
