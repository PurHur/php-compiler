<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * NumberFormatter — locale decimal/percent/currency subset (#5154, #20710, #20728).
 *
 * php-src: ext/intl/formatter/formatter_*.c, formatter.stub.php
 * Style/attr constants: unicode/unum.h UNumberFormatStyle / UNumberFormatAttribute.
 */
final class VmNumberFormatter
{
    public const CLASS_LC = 'numberformatter';

    public const PATTERN_DECIMAL = 0;
    public const DECIMAL = 1;
    public const CURRENCY = 2;
    public const PERCENT = 3;
    public const SCIENTIFIC = 4;
    public const SPELLOUT = 5;
    public const ORDINAL = 6;
    public const DURATION = 7;
    /** ICU UNUM_PATTERN_RULEBASED — was wrongly 8 (UNUM_NUMBERING_SYSTEM); #20993. */
    public const PATTERN_RULEBASED = 9;
    /** ICU UNUM_IGNORE alias of UNUM_PATTERN_DECIMAL — was wrongly 9; #20993. */
    public const IGNORE = 0;
    public const CURRENCY_ACCOUNTING = 12;
    public const DEFAULT_STYLE = 1;

    public const PARSE_INT_ONLY = 0;
    public const GROUPING_USED = 1;
    public const DECIMAL_ALWAYS_SHOWN = 2;
    public const MAX_INTEGER_DIGITS = 3;
    public const MIN_INTEGER_DIGITS = 4;
    public const INTEGER_DIGITS = 5;
    public const MAX_FRACTION_DIGITS = 6;
    public const MIN_FRACTION_DIGITS = 7;
    public const FRACTION_DIGITS = 8;
    public const MULTIPLIER = 9;
    public const GROUPING_SIZE = 10;
    public const ROUNDING_MODE = 11;
    public const ROUNDING_INCREMENT = 12;
    public const FORMAT_WIDTH = 13;
    public const PADDING_POSITION = 14;
    public const SECONDARY_GROUPING_SIZE = 15;
    public const SIGNIFICANT_DIGITS_USED = 16;
    public const MIN_SIGNIFICANT_DIGITS = 17;
    public const MAX_SIGNIFICANT_DIGITS = 18;
    public const LENIENT_PARSE = 19;

    /** UNumberFormatPadPosition (unicode/unum.h; #20993). */
    public const PAD_BEFORE_PREFIX = 0;
    public const PAD_AFTER_PREFIX = 1;
    public const PAD_BEFORE_SUFFIX = 2;
    public const PAD_AFTER_SUFFIX = 3;

    /** FORMAT_TYPE_* for format()/parse() (#20993). */
    public const TYPE_DEFAULT = 0;
    public const TYPE_INT32 = 1;
    public const TYPE_INT64 = 2;
    public const TYPE_DOUBLE = 3;
    /** @deprecated since PHP 8.3 */
    public const TYPE_CURRENCY = 4;

    /** ICU UNumberFormatSymbol (unicode/unum.h; #20789). */
    public const DECIMAL_SEPARATOR_SYMBOL = 0;
    public const GROUPING_SEPARATOR_SYMBOL = 1;
    public const PATTERN_SEPARATOR_SYMBOL = 2;
    public const PERCENT_SYMBOL = 3;
    public const ZERO_DIGIT_SYMBOL = 4;
    public const DIGIT_SYMBOL = 5;
    public const MINUS_SIGN_SYMBOL = 6;
    public const PLUS_SIGN_SYMBOL = 7;
    public const CURRENCY_SYMBOL = 8;
    public const INTL_CURRENCY_SYMBOL = 9;
    public const MONETARY_SEPARATOR_SYMBOL = 10;
    public const EXPONENTIAL_SYMBOL = 11;
    public const PERMILL_SYMBOL = 12;
    public const PAD_ESCAPE_SYMBOL = 13;
    public const INFINITY_SYMBOL = 14;
    public const NAN_SYMBOL = 15;
    public const SIGNIFICANT_DIGIT_SYMBOL = 16;
    public const MONETARY_GROUPING_SEPARATOR_SYMBOL = 17;

    /** ICU UNumberFormatTextAttribute (unicode/unum.h; #20789). */
    public const POSITIVE_PREFIX = 0;
    public const POSITIVE_SUFFIX = 1;
    public const NEGATIVE_PREFIX = 2;
    public const NEGATIVE_SUFFIX = 3;
    public const PADDING_CHARACTER = 4;
    public const CURRENCY_CODE = 5;
    public const DEFAULT_RULESET = 6;
    public const PUBLIC_RULESETS = 7;

    /** ICU UNumberFormatRoundingMode (unicode/unum.h; #20710). */
    public const ROUND_CEILING = 0;
    public const ROUND_FLOOR = 1;
    public const ROUND_DOWN = 2;
    public const ROUND_UP = 3;
    public const ROUND_HALFEVEN = 4;
    public const ROUND_HALFDOWN = 5;
    public const ROUND_HALFUP = 6;
    public const ROUND_UNNECESSARY = 7;
    public const ROUND_HALFODD = 8;
    public const ROUND_TOWARD_ZERO = 2;
    public const ROUND_AWAY_FROM_ZERO = 3;

    /**
     * @var array<int, array{
     *   locale: string,
     *   style: int,
     *   pattern: ?string,
     *   attributes: array<int, int|float>,
     *   symbols: array<int, string>,
     *   textAttributes: array<int, string>,
     *   errorCode: int,
     *   errorMessage: string
     * }>
     */
    private static array $state = [];

    /** @return array<string, int> */
    public static function classConstants(): array
    {
        return [
            'PATTERN_DECIMAL' => self::PATTERN_DECIMAL,
            'DECIMAL' => self::DECIMAL,
            'CURRENCY' => self::CURRENCY,
            'PERCENT' => self::PERCENT,
            'SCIENTIFIC' => self::SCIENTIFIC,
            'SPELLOUT' => self::SPELLOUT,
            'ORDINAL' => self::ORDINAL,
            'DURATION' => self::DURATION,
            'PATTERN_RULEBASED' => self::PATTERN_RULEBASED,
            'IGNORE' => self::IGNORE,
            'CURRENCY_ACCOUNTING' => self::CURRENCY_ACCOUNTING,
            'DEFAULT_STYLE' => self::DEFAULT_STYLE,
            'PARSE_INT_ONLY' => self::PARSE_INT_ONLY,
            'GROUPING_USED' => self::GROUPING_USED,
            'DECIMAL_ALWAYS_SHOWN' => self::DECIMAL_ALWAYS_SHOWN,
            'MAX_INTEGER_DIGITS' => self::MAX_INTEGER_DIGITS,
            'MIN_INTEGER_DIGITS' => self::MIN_INTEGER_DIGITS,
            'INTEGER_DIGITS' => self::INTEGER_DIGITS,
            'MAX_FRACTION_DIGITS' => self::MAX_FRACTION_DIGITS,
            'MIN_FRACTION_DIGITS' => self::MIN_FRACTION_DIGITS,
            'FRACTION_DIGITS' => self::FRACTION_DIGITS,
            'MULTIPLIER' => self::MULTIPLIER,
            'GROUPING_SIZE' => self::GROUPING_SIZE,
            'ROUNDING_MODE' => self::ROUNDING_MODE,
            'ROUNDING_INCREMENT' => self::ROUNDING_INCREMENT,
            'FORMAT_WIDTH' => self::FORMAT_WIDTH,
            'PADDING_POSITION' => self::PADDING_POSITION,
            'SECONDARY_GROUPING_SIZE' => self::SECONDARY_GROUPING_SIZE,
            'SIGNIFICANT_DIGITS_USED' => self::SIGNIFICANT_DIGITS_USED,
            'MIN_SIGNIFICANT_DIGITS' => self::MIN_SIGNIFICANT_DIGITS,
            'MAX_SIGNIFICANT_DIGITS' => self::MAX_SIGNIFICANT_DIGITS,
            'LENIENT_PARSE' => self::LENIENT_PARSE,
            'PAD_BEFORE_PREFIX' => self::PAD_BEFORE_PREFIX,
            'PAD_AFTER_PREFIX' => self::PAD_AFTER_PREFIX,
            'PAD_BEFORE_SUFFIX' => self::PAD_BEFORE_SUFFIX,
            'PAD_AFTER_SUFFIX' => self::PAD_AFTER_SUFFIX,
            'TYPE_DEFAULT' => self::TYPE_DEFAULT,
            'TYPE_INT32' => self::TYPE_INT32,
            'TYPE_INT64' => self::TYPE_INT64,
            'TYPE_DOUBLE' => self::TYPE_DOUBLE,
            'TYPE_CURRENCY' => self::TYPE_CURRENCY,
            'DECIMAL_SEPARATOR_SYMBOL' => self::DECIMAL_SEPARATOR_SYMBOL,
            'GROUPING_SEPARATOR_SYMBOL' => self::GROUPING_SEPARATOR_SYMBOL,
            'PATTERN_SEPARATOR_SYMBOL' => self::PATTERN_SEPARATOR_SYMBOL,
            'PERCENT_SYMBOL' => self::PERCENT_SYMBOL,
            'ZERO_DIGIT_SYMBOL' => self::ZERO_DIGIT_SYMBOL,
            'DIGIT_SYMBOL' => self::DIGIT_SYMBOL,
            'MINUS_SIGN_SYMBOL' => self::MINUS_SIGN_SYMBOL,
            'PLUS_SIGN_SYMBOL' => self::PLUS_SIGN_SYMBOL,
            'CURRENCY_SYMBOL' => self::CURRENCY_SYMBOL,
            'INTL_CURRENCY_SYMBOL' => self::INTL_CURRENCY_SYMBOL,
            'MONETARY_SEPARATOR_SYMBOL' => self::MONETARY_SEPARATOR_SYMBOL,
            'EXPONENTIAL_SYMBOL' => self::EXPONENTIAL_SYMBOL,
            'PERMILL_SYMBOL' => self::PERMILL_SYMBOL,
            'PAD_ESCAPE_SYMBOL' => self::PAD_ESCAPE_SYMBOL,
            'INFINITY_SYMBOL' => self::INFINITY_SYMBOL,
            'NAN_SYMBOL' => self::NAN_SYMBOL,
            'SIGNIFICANT_DIGIT_SYMBOL' => self::SIGNIFICANT_DIGIT_SYMBOL,
            'MONETARY_GROUPING_SEPARATOR_SYMBOL' => self::MONETARY_GROUPING_SEPARATOR_SYMBOL,
            'POSITIVE_PREFIX' => self::POSITIVE_PREFIX,
            'POSITIVE_SUFFIX' => self::POSITIVE_SUFFIX,
            'NEGATIVE_PREFIX' => self::NEGATIVE_PREFIX,
            'NEGATIVE_SUFFIX' => self::NEGATIVE_SUFFIX,
            'PADDING_CHARACTER' => self::PADDING_CHARACTER,
            'CURRENCY_CODE' => self::CURRENCY_CODE,
            'DEFAULT_RULESET' => self::DEFAULT_RULESET,
            'PUBLIC_RULESETS' => self::PUBLIC_RULESETS,
            'ROUND_CEILING' => self::ROUND_CEILING,
            'ROUND_FLOOR' => self::ROUND_FLOOR,
            'ROUND_DOWN' => self::ROUND_DOWN,
            'ROUND_UP' => self::ROUND_UP,
            'ROUND_TOWARD_ZERO' => self::ROUND_TOWARD_ZERO,
            'ROUND_AWAY_FROM_ZERO' => self::ROUND_AWAY_FROM_ZERO,
            'ROUND_HALFEVEN' => self::ROUND_HALFEVEN,
            'ROUND_HALFODD' => self::ROUND_HALFODD,
            'ROUND_HALFDOWN' => self::ROUND_HALFDOWN,
            'ROUND_HALFUP' => self::ROUND_HALFUP,
            'ROUND_UNNECESSARY' => self::ROUND_UNNECESSARY,
        ];
    }

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('NumberFormatter');
        $entry->isInternal = true;
        foreach (self::classConstants() as $name => $value) {
            $lc = strtolower($name);
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$lc] = $const;
            $entry->constNames[$lc] = $name;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $construct = new NumberFormatterConstruct();
        $entry->constructor = $construct;
        $methods = [
            '__construct' => [$construct, $pub, '__construct'],
            'create' => [new NumberFormatterCreate(), $pubStatic, 'create'],
            'format' => [new NumberFormatterFormat(), $pub, 'format'],
            'formatcurrency' => [new NumberFormatterFormatCurrency(), $pub, 'formatCurrency'],
            'parse' => [new NumberFormatterParse(), $pub, 'parse'],
            'parsecurrency' => [new NumberFormatterParseCurrency(), $pub, 'parseCurrency'],
            'getattribute' => [new NumberFormatterGetAttribute(), $pub, 'getAttribute'],
            'setattribute' => [new NumberFormatterSetAttribute(), $pub, 'setAttribute'],
            'getsymbol' => [new NumberFormatterGetSymbol(), $pub, 'getSymbol'],
            'setsymbol' => [new NumberFormatterSetSymbol(), $pub, 'setSymbol'],
            'gettextattribute' => [new NumberFormatterGetTextAttribute(), $pub, 'getTextAttribute'],
            'settextattribute' => [new NumberFormatterSetTextAttribute(), $pub, 'setTextAttribute'],
            'getpattern' => [new NumberFormatterGetPattern(), $pub, 'getPattern'],
            'setpattern' => [new NumberFormatterSetPattern(), $pub, 'setPattern'],
            'getlocale' => [new NumberFormatterGetLocale(), $pub, 'getLocale'],
            'geterrorcode' => [new NumberFormatterGetErrorCode(), $pub, 'getErrorCode'],
            'geterrormessage' => [new NumberFormatterGetErrorMessage(), $pub, 'getErrorMessage'],
        ];
        foreach ($methods as $lc => [$handler, $vis, $name]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $vis;
            $entry->methodNames[$lc] = $name;
        }
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function isFormatterObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
    }

    public static function create(Context $ctx, string $locale, int $style, ?string $pattern): ObjectEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "NumberFormatter" not found');
        }
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        self::initObject($object, $locale, $style, $pattern);

        return $object;
    }

    /**
     * NumberFormatter::__construct / create shared init (#20754).
     */
    public static function initObject(ObjectEntry $object, string $locale, int $style, ?string $pattern): void
    {
        $object->constructed = true;
        $resolvedLocale = '' !== $locale ? $locale : VmLocale::getDefault();
        self::$state[$object->id] = [
            'locale' => $resolvedLocale,
            'style' => $style,
            'pattern' => $pattern,
            'attributes' => [
                self::GROUPING_USED => 1,
                self::FRACTION_DIGITS => self::CURRENCY === $style || self::CURRENCY_ACCOUNTING === $style ? 2 : -1,
                self::ROUNDING_MODE => self::ROUND_HALFEVEN,
            ],
            'symbols' => self::defaultSymbolsForLocale($resolvedLocale),
            'textAttributes' => self::defaultTextAttributes(),
            'errorCode' => IntlError::U_ZERO_ERROR,
            'errorMessage' => 'U_ZERO_ERROR',
        ];
        IntlError::clear();
    }

    /**
     * @return string|false
     */
    public static function format(ObjectEntry $formatter, float $num, int $type = self::TYPE_DEFAULT)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'numfmt_format: bad formatter: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        self::clearObjectError($formatter);
        IntlError::clear();
        if (self::TYPE_INT32 === $type || self::TYPE_INT64 === $type) {
            $num = (float) (int) $num;
        } elseif (self::TYPE_CURRENCY === $type) {
            throw new \ValueError(
                'NumberFormatter::format(): Argument #2 ($type) cannot be NumberFormatter::TYPE_CURRENCY constant, use NumberFormatter::formatCurrency() method instead'
            );
        } elseif (self::TYPE_DEFAULT !== $type && self::TYPE_DOUBLE !== $type) {
            throw new \ValueError(
                'NumberFormatter::format(): Argument #2 ($type) must be a NumberFormatter::TYPE_* constant'
            );
        }
        $style = $state['style'];
        $locale = $state['locale'];
        if (self::PERCENT === $style) {
            return self::formatDecimal($num * 100.0, $locale, null).'%';
        }
        if (self::SCIENTIFIC === $style) {
            return self::formatScientific($num, $locale);
        }
        if (self::CURRENCY === $style || self::CURRENCY_ACCOUNTING === $style) {
            return self::formatDecimal($num, $locale, 2);
        }
        if (self::SPELLOUT === $style || self::ORDINAL === $style || self::DURATION === $style
            || self::PATTERN_RULEBASED === $style) {
            throw new \Error(
                'NumberFormatter::format() style requires full ext/intl ICU (issue #5154)'
            );
        }

        return self::formatDecimal($num, $locale, null);
    }

    /**
     * @return string|false
     */
    public static function formatCurrency(ObjectEntry $formatter, float $amount, string $currency)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'numfmt_format_currency: bad formatter: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        $currency = strtoupper($currency);
        $symbol = self::currencySymbol($currency);
        $body = self::formatDecimal($amount, $state['locale'], 2);
        self::clearObjectError($formatter);
        IntlError::clear();
        if ('$' === $symbol || '£' === $symbol || '€' === $symbol || '¥' === $symbol) {
            $negative = str_starts_with($body, '-');
            $abs = $negative ? substr($body, 1) : $body;

            return ($negative ? '-' : '').$symbol.$abs;
        }

        return $body.' '.$currency;
    }

    /**
     * @return int|float|false
     */
    public static function parse(ObjectEntry $formatter, string $value, int $type = self::TYPE_DOUBLE)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'numfmt_parse: bad formatter: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        if (self::TYPE_CURRENCY === $type) {
            throw new \ValueError(
                'NumberFormatter::parse(): Argument #2 ($type) cannot be NumberFormatter::TYPE_CURRENCY constant, use NumberFormatter::parseCurrency() method instead'
            );
        }
        if (self::TYPE_INT32 !== $type && self::TYPE_INT64 !== $type && self::TYPE_DOUBLE !== $type) {
            throw new \ValueError(
                'NumberFormatter::parse(): Argument #2 ($type) must be a NumberFormatter::TYPE_* constant'
            );
        }
        $num = self::parseNumberString($value, $state['locale']);
        if (null === $num) {
            self::fail($formatter, 'numfmt_parse: Number parsing failed: U_PARSE_ERROR');

            return false;
        }
        self::clearObjectError($formatter);
        IntlError::clear();
        if (self::TYPE_INT32 === $type || self::TYPE_INT64 === $type) {
            return (int) $num;
        }

        return $num;
    }

    /**
     * Parse currency amount (php-src unum_parseDoubleCurrency; #20728, #21127).
     *
     * Optional &$offset is both start position (bytes) and end position out-param,
     * matching formatter.stub.php `parseCurrency(..., &$currency, &$offset = null)`.
     *
     * @param-out string|null $currencyOut
     * @param-out int|null    $offset
     *
     * @return float|false
     */
    public static function parseCurrency(
        ObjectEntry $formatter,
        string $value,
        ?string &$currencyOut,
        ?int &$offset = null
    ) {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'numfmt_parse_currency: bad formatter: U_ILLEGAL_ARGUMENT_ERROR');
            $currencyOut = null;

            return false;
        }
        $start = 0;
        if (null !== $offset) {
            $start = $offset;
            if ($start < 0) {
                $start = 0;
            }
            if ($start > \strlen($value)) {
                $start = \strlen($value);
            }
        }
        $slice = $start > 0 ? substr($value, $start) : $value;
        $trimmed = trim($slice);
        $currency = null;
        $numeric = $trimmed;
        if (preg_match('/^([A-Z]{3})\s*(.+)$/i', $trimmed, $m)) {
            $currency = strtoupper($m[1]);
            $numeric = $m[2];
        } elseif (preg_match('/^(.+?)\s*([A-Z]{3})$/i', $trimmed, $m)) {
            $numeric = $m[1];
            $currency = strtoupper($m[2]);
        } elseif (str_starts_with($trimmed, '$')) {
            $currency = 'USD';
            $numeric = substr($trimmed, 1);
        } elseif (str_starts_with($trimmed, '€')) {
            $currency = 'EUR';
            $numeric = substr($trimmed, strlen('€'));
        } elseif (str_starts_with($trimmed, '£')) {
            $currency = 'GBP';
            $numeric = substr($trimmed, strlen('£'));
        } elseif (str_starts_with($trimmed, '¥')) {
            $currency = 'JPY';
            $numeric = substr($trimmed, strlen('¥'));
        } elseif (str_starts_with($trimmed, '-$')) {
            $currency = 'USD';
            $numeric = '-'.substr($trimmed, 2);
        }
        $num = self::parseNumberString(trim($numeric), $state['locale']);
        if (null === $num || null === $currency) {
            self::fail($formatter, 'numfmt_parse_currency: Currency parsing failed: U_PARSE_ERROR');
            $currencyOut = null;
            // Leave &$offset unchanged on failure (matches NumberFormatter::parse subset #20993).

            return false;
        }
        $currencyOut = $currency;
        if (null !== $offset) {
            // Subset: advance to end of input on success (ICU updates parse end index).
            $offset = \strlen($value);
        }
        self::clearObjectError($formatter);
        IntlError::clear();

        return $num;
    }

    /**
     * @return int|float|false
     */
    public static function getAttribute(ObjectEntry $formatter, int $attribute)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'numfmt_get_attribute: bad formatter: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        self::clearObjectError($formatter);
        IntlError::clear();

        return $state['attributes'][$attribute] ?? -1;
    }

    public static function setAttribute(ObjectEntry $formatter, int $attribute, int|float $value): bool
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'numfmt_set_attribute: bad formatter: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        self::$state[$formatter->id]['attributes'][$attribute] = $value;
        self::clearObjectError($formatter);
        IntlError::clear();

        return true;
    }

    /**
     * NumberFormatter::getSymbol() — php-src numfmt_get_symbol (#20789).
     *
     * @return string|false
     */
    public static function getSymbol(ObjectEntry $formatter, int $symbol)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'numfmt_get_symbol: bad formatter: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        if ($symbol < self::DECIMAL_SEPARATOR_SYMBOL || $symbol > self::MONETARY_GROUPING_SEPARATOR_SYMBOL) {
            self::fail($formatter, 'numfmt_get_symbol: invalid symbol value: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        self::clearObjectError($formatter);
        IntlError::clear();

        return $state['symbols'][$symbol] ?? '';
    }

    /**
     * NumberFormatter::setSymbol() — php-src numfmt_set_symbol (#20789).
     */
    public static function setSymbol(ObjectEntry $formatter, int $symbol, string $value): bool
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'numfmt_set_symbol: bad formatter: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        if ($symbol < self::DECIMAL_SEPARATOR_SYMBOL || $symbol > self::MONETARY_GROUPING_SEPARATOR_SYMBOL) {
            self::fail($formatter, 'numfmt_set_symbol: invalid symbol value: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        self::$state[$formatter->id]['symbols'][$symbol] = $value;
        self::clearObjectError($formatter);
        IntlError::clear();

        return true;
    }

    /**
     * NumberFormatter::getTextAttribute() — php-src numfmt_get_text_attribute (#20789).
     *
     * @return string|false
     */
    public static function getTextAttribute(ObjectEntry $formatter, int $attribute)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'numfmt_get_text_attribute: bad formatter: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        if ($attribute < self::POSITIVE_PREFIX || $attribute > self::PUBLIC_RULESETS) {
            self::fail($formatter, 'Error getting attribute value: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        if ($attribute === self::DEFAULT_RULESET || $attribute === self::PUBLIC_RULESETS) {
            self::fail($formatter, 'Error getting attribute value: U_UNSUPPORTED_ERROR');

            return false;
        }
        self::clearObjectError($formatter);
        IntlError::clear();

        return $state['textAttributes'][$attribute] ?? '';
    }

    /**
     * NumberFormatter::setTextAttribute() — php-src numfmt_set_text_attribute (#20789).
     */
    public static function setTextAttribute(ObjectEntry $formatter, int $attribute, string $value): bool
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'numfmt_set_text_attribute: bad formatter: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        if ($attribute < self::POSITIVE_PREFIX || $attribute > self::PUBLIC_RULESETS) {
            self::fail($formatter, 'Error setting text attribute: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        if ($attribute === self::DEFAULT_RULESET || $attribute === self::PUBLIC_RULESETS) {
            self::fail($formatter, 'Error setting text attribute: U_UNSUPPORTED_ERROR');

            return false;
        }
        self::$state[$formatter->id]['textAttributes'][$attribute] = $value;
        self::clearObjectError($formatter);
        IntlError::clear();

        return true;
    }

    /**
     * @return string|false
     */
    public static function getPattern(ObjectEntry $formatter)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'numfmt_get_pattern: bad formatter: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        self::clearObjectError($formatter);
        IntlError::clear();

        return $state['pattern'] ?? '';
    }

    public static function setPattern(ObjectEntry $formatter, string $pattern): bool
    {
        if (!isset(self::$state[$formatter->id])) {
            self::fail($formatter, 'numfmt_set_pattern: bad formatter: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        self::$state[$formatter->id]['pattern'] = $pattern;
        self::clearObjectError($formatter);
        IntlError::clear();

        return true;
    }

    /**
     * @return string|false
     */
    public static function getLocale(ObjectEntry $formatter)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'numfmt_get_locale: bad formatter: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        self::clearObjectError($formatter);
        IntlError::clear();

        return $state['locale'];
    }

    public static function getErrorCode(ObjectEntry $formatter): int
    {
        $state = self::$state[$formatter->id] ?? null;

        return null === $state ? IntlError::U_ZERO_ERROR : $state['errorCode'];
    }

    public static function getErrorMessage(ObjectEntry $formatter): string
    {
        $state = self::$state[$formatter->id] ?? null;

        return null === $state ? 'U_ZERO_ERROR' : $state['errorMessage'];
    }

    public static function coerceFloatArg(Variable $var, string $function, int $position, string $name): float
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int|float, %s given',
                $function,
                $position + 1,
                $name,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return (float) $var->toInt();
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return $var->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool() ? 1.0 : 0.0;
        }
        if (Variable::TYPE_STRING === $var->type && is_numeric($var->toString())) {
            return (float) $var->toString();
        }
        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type int|float, %s given',
            $function,
            $position + 1,
            $name,
            ReflectionSupport::valueTypeLabelPublic($var)
        ));
    }

    public static function coerceStringArg(Variable $var, string $function, int $position, string $name): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, $position, $name);
    }

    private static function fail(ObjectEntry $formatter, string $message): void
    {
        IntlError::set(IntlError::U_ILLEGAL_ARGUMENT_ERROR, $message);
        if (isset(self::$state[$formatter->id])) {
            self::$state[$formatter->id]['errorCode'] = IntlError::U_ILLEGAL_ARGUMENT_ERROR;
            self::$state[$formatter->id]['errorMessage'] = $message;
        }
    }

    private static function clearObjectError(ObjectEntry $formatter): void
    {
        if (!isset(self::$state[$formatter->id])) {
            return;
        }
        self::$state[$formatter->id]['errorCode'] = IntlError::U_ZERO_ERROR;
        self::$state[$formatter->id]['errorMessage'] = 'U_ZERO_ERROR';
    }

    private static function currencySymbol(string $currency): string
    {
        return match ($currency) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            default => $currency,
        };
    }

    private static function formatDecimal(float $num, string $locale, ?int $forceFrac): string
    {
        [$grouping, $decimal] = self::separatorsForLocale($locale);
        $negative = $num < 0;
        $abs = abs($num);
        if (null !== $forceFrac) {
            $scaled = round($abs, $forceFrac);
            $intPart = (int) floor($scaled + 1e-12);
            $fracInt = (int) round(($scaled - $intPart) * (10 ** $forceFrac));
            $intStr = self::groupDigits((string) $intPart, $grouping);
            $fracStr = str_pad((string) $fracInt, $forceFrac, '0', STR_PAD_LEFT);
            $out = $intStr.$decimal.$fracStr;

            return $negative ? '-'.$out : $out;
        }
        $intPart = (int) floor($abs);
        $frac = $abs - $intPart;
        $fracStr = '';
        if ($frac > 0.0 || (string) $num !== (string) (int) $num) {
            $raw = rtrim(rtrim(sprintf('%.6F', $frac), '0'), '.');
            if (str_starts_with($raw, '0.')) {
                $fracStr = substr($raw, 2);
            } elseif ('0' !== $raw && '' !== $raw) {
                $fracStr = $raw;
            }
        }
        $intStr = self::groupDigits((string) $intPart, $grouping);
        $out = '' !== $fracStr ? $intStr.$decimal.$fracStr : $intStr;

        return $negative ? '-'.$out : $out;
    }

    private static function formatScientific(float $num, string $locale): string
    {
        [$grouping, $decimal] = self::separatorsForLocale($locale);
        unset($grouping);
        $s = sprintf('%.6E', $num);

        return str_replace('.', $decimal, $s);
    }

    /** @return float|null */
    private static function parseNumberString(string $value, string $locale): ?float
    {
        [$grouping, $decimal] = self::separatorsForLocale($locale);
        $s = trim($value);
        if ('' === $s) {
            return null;
        }
        if ('' !== $grouping) {
            $s = str_replace($grouping, '', $s);
        }
        if ('.' !== $decimal) {
            $s = str_replace($decimal, '.', $s);
        }
        // Strip common currency leftovers that may remain.
        $s = preg_replace('/[^0-9.\\-]/', '', $s) ?? $s;
        if ('' === $s || '-' === $s || '.' === $s || !is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }

    /** @return array{0: string, 1: string} grouping, decimal */
    private static function separatorsForLocale(string $locale): array
    {
        $lc = strtolower(str_replace('-', '_', $locale));
        $lang = explode('_', $lc)[0] ?? 'en';

        return match ($lang) {
            'de', 'es', 'it', 'nl', 'da', 'fi', 'sv', 'pl', 'cs', 'hu', 'tr', 'ru', 'uk' => ['.', ','],
            'fr', 'pt', 'vi' => [' ', ','],
            default => [',', '.'],
        };
    }

    /** @return array<int, string> */
    private static function defaultSymbolsForLocale(string $locale): array
    {
        [$grouping, $decimal] = self::separatorsForLocale($locale);

        return [
            self::DECIMAL_SEPARATOR_SYMBOL => $decimal,
            self::GROUPING_SEPARATOR_SYMBOL => $grouping,
            self::PATTERN_SEPARATOR_SYMBOL => ';',
            self::PERCENT_SYMBOL => '%',
            self::ZERO_DIGIT_SYMBOL => '0',
            self::DIGIT_SYMBOL => '#',
            self::MINUS_SIGN_SYMBOL => '-',
            self::PLUS_SIGN_SYMBOL => '+',
            self::CURRENCY_SYMBOL => '$',
            self::INTL_CURRENCY_SYMBOL => 'USD',
            self::MONETARY_SEPARATOR_SYMBOL => $decimal,
            self::EXPONENTIAL_SYMBOL => 'E',
            self::PERMILL_SYMBOL => "\u{2030}",
            self::PAD_ESCAPE_SYMBOL => '*',
            self::INFINITY_SYMBOL => "\u{221E}",
            self::NAN_SYMBOL => 'NaN',
            self::SIGNIFICANT_DIGIT_SYMBOL => '@',
            self::MONETARY_GROUPING_SEPARATOR_SYMBOL => $grouping,
        ];
    }

    /** @return array<int, string> */
    private static function defaultTextAttributes(): array
    {
        return [
            self::POSITIVE_PREFIX => '',
            self::POSITIVE_SUFFIX => '',
            self::NEGATIVE_PREFIX => '-',
            self::NEGATIVE_SUFFIX => '',
            self::PADDING_CHARACTER => ' ',
            self::CURRENCY_CODE => 'USD',
        ];
    }

    private static function groupDigits(string $digits, string $sep): string
    {
        if ('' === $sep || strlen($digits) <= 3) {
            return $digits;
        }
        $out = '';
        $len = strlen($digits);
        for ($i = 0; $i < $len; ++$i) {
            if (0 !== $i && 0 === ($len - $i) % 3) {
                $out .= $sep;
            }
            $out .= $digits[$i];
        }

        return $out;
    }
}

/** NumberFormatter::__construct() — php-src numfmt_create / new NumberFormatter (#20754). */
final class NumberFormatterConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'NumberFormatter::__construct() expects between 2 and 3 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('NumberFormatter::__construct() called on incompatible object');
        }
        $locale = VmIntlDateFormatter::coerceLocaleArg($frame->calledArgs[1], 'NumberFormatter::__construct', 1);
        $style = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'NumberFormatter::__construct', 2, 'style');
        $pattern = null;
        if ($argc >= 4) {
            $pattern = VmIntlDateFormatter::coerceOptionalPattern($frame->calledArgs[3], 'NumberFormatter::__construct', 3);
        }
        VmNumberFormatter::initObject($receiver->toObject(), $locale, $style, $pattern);
    }
}

/** NumberFormatter::create() — php-src numfmt_create (#5154). */
final class NumberFormatterCreate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'NumberFormatter::create() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        $locale = VmIntlDateFormatter::coerceLocaleArg($frame->calledArgs[0], 'NumberFormatter::create', 0);
        $style = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'NumberFormatter::create', 1, 'style');
        $pattern = null;
        if ($argc >= 3) {
            $pattern = VmIntlDateFormatter::coerceOptionalPattern($frame->calledArgs[2], 'NumberFormatter::create', 2);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmNumberFormatter::create($frame->vmContext, $locale, $style, $pattern));
    }
}

/** NumberFormatter::format() — php-src numfmt_format (#5154). */
final class NumberFormatterFormat extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('format');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'NumberFormatter::format() expects between 1 and 2 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('NumberFormatter::format() called on incompatible object');
        }
        $num = VmNumberFormatter::coerceFloatArg($frame->calledArgs[1], 'NumberFormatter::format', 1, 'num');
        $type = VmNumberFormatter::TYPE_DEFAULT;
        if ($argc >= 3) {
            $type = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'NumberFormatter::format', 2, 'type');
        }
        $result = VmNumberFormatter::format($receiver->toObject(), $num, $type);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

/** NumberFormatter::formatCurrency() — php-src numfmt_format_currency (#20728). */
final class NumberFormatterFormatCurrency extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('formatCurrency');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'NumberFormatter::formatCurrency() expects exactly 2 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('NumberFormatter::formatCurrency() called on incompatible object');
        }
        $amount = VmNumberFormatter::coerceFloatArg($frame->calledArgs[1], 'NumberFormatter::formatCurrency', 1, 'amount');
        $currency = VmNumberFormatter::coerceStringArg($frame->calledArgs[2], 'NumberFormatter::formatCurrency', 2, 'currency');
        $result = VmNumberFormatter::formatCurrency($receiver->toObject(), $amount, $currency);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

/** NumberFormatter::parse() — php-src numfmt_parse (#20728). */
final class NumberFormatterParse extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('parse');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'NumberFormatter::parse() expects between 1 and 3 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('NumberFormatter::parse() called on incompatible object');
        }
        $value = VmNumberFormatter::coerceStringArg($frame->calledArgs[1], 'NumberFormatter::parse', 1, 'string');
        $type = VmNumberFormatter::TYPE_DOUBLE;
        if ($argc >= 3) {
            $type = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'NumberFormatter::parse', 2, 'type');
        }
        $result = VmNumberFormatter::parse($receiver->toObject(), $value, $type);
        if ($argc >= 4) {
            // php-src updates &$offset to bytes consumed; subset advances to strlen on success (#20993).
            $offsetVar = $frame->calledArgs[3]->resolveIndirect();
            if (false === $result) {
                // leave offset unchanged on failure (Zend keeps prior value)
            } else {
                $offsetVar->int(\strlen($value));
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        if (\is_int($result)) {
            $frame->returnVar->int($result);

            return;
        }
        $frame->returnVar->float($result);
    }
}

/** NumberFormatter::parseCurrency() — php-src numfmt_parse_currency (#20728, #21127). */
final class NumberFormatterParseCurrency extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('parseCurrency');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'NumberFormatter::parseCurrency() expects between 2 and 3 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('NumberFormatter::parseCurrency() called on incompatible object');
        }
        $value = VmNumberFormatter::coerceStringArg($frame->calledArgs[1], 'NumberFormatter::parseCurrency', 1, 'string');
        $hasOffset = $argc >= 4;
        $offset = null;
        if ($hasOffset) {
            $offsetVar = $frame->calledArgs[3]->resolveIndirect();
            $offset = Variable::TYPE_NULL === $offsetVar->type
                ? 0
                : VmIntlDateFormatter::coerceIntArg(
                    $offsetVar,
                    'NumberFormatter::parseCurrency',
                    3,
                    'offset'
                );
        }
        $currencyOut = null;
        if ($hasOffset) {
            $result = VmNumberFormatter::parseCurrency($receiver->toObject(), $value, $currencyOut, $offset);
            $frame->calledArgs[3]->byRefTarget()->int($offset);
        } else {
            $result = VmNumberFormatter::parseCurrency($receiver->toObject(), $value, $currencyOut);
        }
        $currencyVar = $frame->calledArgs[2]->resolveIndirect();
        if (null === $currencyOut) {
            $currencyVar->null();
        } else {
            $currencyVar->string($currencyOut);
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->float($result);
    }
}

/** NumberFormatter::getAttribute() — php-src numfmt_get_attribute (#20728). */
final class NumberFormatterGetAttribute extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttribute');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'NumberFormatter::getAttribute() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('NumberFormatter::getAttribute() called on incompatible object');
        }
        $attr = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'NumberFormatter::getAttribute', 1, 'attribute');
        $result = VmNumberFormatter::getAttribute($receiver->toObject(), $attr);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        if (\is_float($result)) {
            $frame->returnVar->float($result);
        } else {
            $frame->returnVar->int((int) $result);
        }
    }
}

/** NumberFormatter::setAttribute() — php-src numfmt_set_attribute (#20728). */
final class NumberFormatterSetAttribute extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setAttribute');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'NumberFormatter::setAttribute() expects exactly 2 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('NumberFormatter::setAttribute() called on incompatible object');
        }
        $attr = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'NumberFormatter::setAttribute', 1, 'attribute');
        $value = VmNumberFormatter::coerceFloatArg($frame->calledArgs[2], 'NumberFormatter::setAttribute', 2, 'value');
        $ok = VmNumberFormatter::setAttribute($receiver->toObject(), $attr, $value);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** NumberFormatter::getSymbol() — php-src numfmt_get_symbol (#20789). */
final class NumberFormatterGetSymbol extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getSymbol');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'NumberFormatter::getSymbol() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('NumberFormatter::getSymbol() called on incompatible object');
        }
        $symbol = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'NumberFormatter::getSymbol', 1, 'symbol');
        $result = VmNumberFormatter::getSymbol($receiver->toObject(), $symbol);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

/** NumberFormatter::setSymbol() — php-src numfmt_set_symbol (#20789). */
final class NumberFormatterSetSymbol extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setSymbol');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'NumberFormatter::setSymbol() expects exactly 2 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('NumberFormatter::setSymbol() called on incompatible object');
        }
        $symbol = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'NumberFormatter::setSymbol', 1, 'symbol');
        $value = VmNumberFormatter::coerceStringArg($frame->calledArgs[2], 'NumberFormatter::setSymbol', 2, 'value');
        $ok = VmNumberFormatter::setSymbol($receiver->toObject(), $symbol, $value);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** NumberFormatter::getTextAttribute() — php-src numfmt_get_text_attribute (#20789). */
final class NumberFormatterGetTextAttribute extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTextAttribute');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'NumberFormatter::getTextAttribute() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('NumberFormatter::getTextAttribute() called on incompatible object');
        }
        $attr = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'NumberFormatter::getTextAttribute', 1, 'attribute');
        $result = VmNumberFormatter::getTextAttribute($receiver->toObject(), $attr);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

/** NumberFormatter::setTextAttribute() — php-src numfmt_set_text_attribute (#20789). */
final class NumberFormatterSetTextAttribute extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setTextAttribute');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'NumberFormatter::setTextAttribute() expects exactly 2 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('NumberFormatter::setTextAttribute() called on incompatible object');
        }
        $attr = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'NumberFormatter::setTextAttribute', 1, 'attribute');
        $value = VmNumberFormatter::coerceStringArg($frame->calledArgs[2], 'NumberFormatter::setTextAttribute', 2, 'value');
        $ok = VmNumberFormatter::setTextAttribute($receiver->toObject(), $attr, $value);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** NumberFormatter::getPattern() — php-src numfmt_get_pattern (#20728). */
final class NumberFormatterGetPattern extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPattern');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'NumberFormatter::getPattern() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('NumberFormatter::getPattern() called on incompatible object');
        }
        $result = VmNumberFormatter::getPattern($receiver->toObject());
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

/** NumberFormatter::setPattern() — php-src numfmt_set_pattern (#20728). */
final class NumberFormatterSetPattern extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setPattern');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'NumberFormatter::setPattern() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('NumberFormatter::setPattern() called on incompatible object');
        }
        $pattern = VmNumberFormatter::coerceStringArg($frame->calledArgs[1], 'NumberFormatter::setPattern', 1, 'pattern');
        $ok = VmNumberFormatter::setPattern($receiver->toObject(), $pattern);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** NumberFormatter::getLocale() — php-src numfmt_get_locale (#20728). */
final class NumberFormatterGetLocale extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getLocale');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'NumberFormatter::getLocale() expects between 0 and 1 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('NumberFormatter::getLocale() called on incompatible object');
        }
        $result = VmNumberFormatter::getLocale($receiver->toObject());
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

/** NumberFormatter::getErrorCode() — php-src numfmt_get_error_code (#20728). */
final class NumberFormatterGetErrorCode extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getErrorCode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'NumberFormatter::getErrorCode() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('NumberFormatter::getErrorCode() called on incompatible object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmNumberFormatter::getErrorCode($receiver->toObject()));
    }
}

/** NumberFormatter::getErrorMessage() — php-src numfmt_get_error_message (#20728). */
final class NumberFormatterGetErrorMessage extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getErrorMessage');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'NumberFormatter::getErrorMessage() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('NumberFormatter::getErrorMessage() called on incompatible object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmNumberFormatter::getErrorMessage($receiver->toObject()));
    }
}
