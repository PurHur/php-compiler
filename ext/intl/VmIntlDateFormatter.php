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
 * IntlDateFormatter create/format — ICU pattern subset (#19549, #5201, #3336).
 *
 * Style-only create (no explicit pattern) resolves CLDR-like date/time patterns for a
 * documented locale set, then formats via {@see icuPatternToPhpFormat()} — php-src
 * dateformat_create.c / dateformat_format.c / udat_open(UDAT_SHORT, …) semantics.
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

    /** Narrow no-break space (U+202F) — ICU en_US time patterns before `a`. */
    private const NNBSP = "\u{202F}";

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
        $pattern = self::effectivePattern($state);
        if (null === $pattern || '' === $pattern) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'datefmt_format: no date/time pattern available for locale/styles: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
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

    /**
     * IntlDateFormatter::getPattern() — php-src datefmt_get_pattern (#3336).
     *
     * @return string|false
     */
    public static function getPattern(ObjectEntry $formatter)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'datefmt_get_pattern: bad formatter: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $pattern = self::effectivePattern($state);
        if (null === $pattern) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'datefmt_get_pattern: no date/time pattern available for locale/styles: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        IntlError::clear();

        return $pattern;
    }

    /**
     * @param array{locale: string, dateType: int, timeType: int, timezone: string, calendar: int, pattern: ?string} $state
     */
    public static function effectivePattern(array $state): ?string
    {
        $explicit = $state['pattern'];
        if (null !== $explicit && '' !== $explicit) {
            return $explicit;
        }

        return self::patternFromStyles($state['locale'], $state['dateType'], $state['timeType']);
    }

    /**
     * Resolve ICU SimpleDateFormat pattern from locale + date/time styles (#3336).
     *
     * Tables match ICU/CLDR output observed on Zend PHP 8.2 (en_US / en_GB / de_DE / fr_FR).
     * Unknown locales fall back to en_US patterns (documented subset — not full udat_open).
     */
    public static function patternFromStyles(string $locale, int $dateType, int $timeType): ?string
    {
        $dateType = self::normalizeStyle($dateType);
        $timeType = self::normalizeStyle($timeType);
        $loc = self::normalizeLocaleKey($locale);
        $datePat = self::dateStylePattern($loc, $dateType);
        $timePat = self::timeStylePattern($loc, $timeType);
        if (null === $datePat && null === $timePat) {
            return null;
        }
        if (null === $datePat || '' === $datePat) {
            return $timePat ?? '';
        }
        if (null === $timePat || '' === $timePat) {
            return $datePat;
        }

        return $datePat.self::dateTimeConnector($loc, $dateType).$timePat;
    }

    private static function normalizeStyle(int $style): int
    {
        if ($style >= self::RELATIVE_FULL) {
            return $style - self::RELATIVE_FULL;
        }

        return $style;
    }

    private static function normalizeLocaleKey(string $locale): string
    {
        $locale = str_replace('-', '_', trim($locale));
        if ('' === $locale) {
            return 'en_US';
        }
        $parts = explode('_', $locale);
        $lang = strtolower($parts[0] ?? 'en');
        $region = isset($parts[1]) ? strtoupper($parts[1]) : '';
        $key = '' !== $region ? $lang.'_'.$region : $lang;
        $known = ['en_US', 'en_GB', 'de_DE', 'fr_FR'];
        if (\in_array($key, $known, true)) {
            return $key;
        }
        // Language-only fallbacks used by common short locales.
        return match ($lang) {
            'en' => 'en_US',
            'de' => 'de_DE',
            'fr' => 'fr_FR',
            default => 'en_US',
        };
    }

    private static function dateStylePattern(string $loc, int $dateType): ?string
    {
        if (self::NONE === $dateType) {
            return '';
        }
        /** @var array<string, array<int, string>> $table */
        $table = [
            'en_US' => [
                self::FULL => 'EEEE, MMMM d, y',
                self::LONG => 'MMMM d, y',
                self::MEDIUM => 'MMM d, y',
                self::SHORT => 'M/d/yy',
            ],
            'en_GB' => [
                self::FULL => 'EEEE, d MMMM y',
                self::LONG => 'd MMMM y',
                self::MEDIUM => 'd MMM y',
                self::SHORT => 'dd/MM/y',
            ],
            'de_DE' => [
                self::FULL => 'EEEE, d. MMMM y',
                self::LONG => 'd. MMMM y',
                self::MEDIUM => 'dd.MM.y',
                self::SHORT => 'dd.MM.yy',
            ],
            'fr_FR' => [
                self::FULL => 'EEEE d MMMM y',
                self::LONG => 'd MMMM y',
                self::MEDIUM => 'd MMM y',
                self::SHORT => 'dd/MM/y',
            ],
        ];

        return $table[$loc][$dateType] ?? $table['en_US'][$dateType] ?? null;
    }

    private static function timeStylePattern(string $loc, int $timeType): ?string
    {
        if (self::NONE === $timeType) {
            return '';
        }
        $nn = self::NNBSP;
        /** @var array<string, array<int, string>> $table */
        $table = [
            'en_US' => [
                self::FULL => 'h:mm:ss'.$nn.'a zzzz',
                self::LONG => 'h:mm:ss'.$nn.'a z',
                self::MEDIUM => 'h:mm:ss'.$nn.'a',
                self::SHORT => 'h:mm'.$nn.'a',
            ],
            'en_GB' => [
                self::FULL => 'HH:mm:ss zzzz',
                self::LONG => 'HH:mm:ss z',
                self::MEDIUM => 'HH:mm:ss',
                self::SHORT => 'HH:mm',
            ],
            'de_DE' => [
                self::FULL => 'HH:mm:ss zzzz',
                self::LONG => 'HH:mm:ss z',
                self::MEDIUM => 'HH:mm:ss',
                self::SHORT => 'HH:mm',
            ],
            'fr_FR' => [
                self::FULL => 'HH:mm:ss zzzz',
                self::LONG => 'HH:mm:ss z',
                self::MEDIUM => 'HH:mm:ss',
                self::SHORT => 'HH:mm',
            ],
        ];

        return $table[$loc][$timeType] ?? $table['en_US'][$timeType] ?? null;
    }

    /**
     * ICU date+time glue for style pairs (Zend 8.2 / ICU observations).
     */
    private static function dateTimeConnector(string $loc, int $dateType): string
    {
        if ('fr_FR' === $loc && $dateType >= self::MEDIUM) {
            return ' ';
        }
        if ($dateType <= self::LONG) {
            return " 'at' ";
        }

        return ', ';
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
     * Supported: y/yy/yyyy, M/MM/MMM/MMMM, d/dd, E/EEE/EEEE, H/HH, h/hh, m/mm, s/ss, a, z/zzzz,
     * and literal punctuation (including U+202F).
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
            'E' => $n >= 4 ? 'l' : 'D',
            'H' => $n >= 2 ? 'H' : 'G',
            'h' => $n >= 2 ? 'h' : 'g',
            'm' => 'i',
            's' => 's',
            'a' => 'A',
            // ICU zone: z..zzz ≈ short; zzzz ≈ long — PHP date() only has T / e (#3336 subset).
            'z' => $n >= 4 ? 'e' : 'T',
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
