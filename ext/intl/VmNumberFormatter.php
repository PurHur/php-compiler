<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\CompilerVersion;
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
 * NumberFormatter — locale decimal/percent/currency + ICU rule-based styles
 * (#5154, #20710, #20728, #21110).
 *
 * php-src: ext/intl/formatter/formatter_*.c, formatter.stub.php
 * Style/attr constants: unicode/unum.h UNumberFormatStyle / UNumberFormatAttribute.
 * SPELLOUT / ORDINAL / DURATION / PATTERN_RULEBASED format via thin libicui18n FFI
 * (unum_open / unum_formatDouble) — same ICU ABI php-src links; no new runtime C.
 */
final class VmNumberFormatter
{
    public const CLASS_LC = 'numberformatter';

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    private static string $symSuffix = '_70';

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
    /** ICU UNUM_ROUND_UNNECESSARY — internal only; never registered (#22704, absent from php-src stubs). */
    public const ROUND_UNNECESSARY = 7;
    /** PHP 8.4+ only when advertised — see {@see CompilerVersion::supportsNumberFormatterPhp84RoundConsts()}. */
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
            'ROUND_HALFEVEN' => self::ROUND_HALFEVEN,
            'ROUND_HALFDOWN' => self::ROUND_HALFDOWN,
            'ROUND_HALFUP' => self::ROUND_HALFUP,
            // PHP 8.4+ ROUND_HALFODD / TOWARD_ZERO / AWAY_FROM_ZERO — withhold on 8.2 (#22704).
            // ROUND_UNNECESSARY never advertised (absent from php-src formatter.stub.php).
            ...(CompilerVersion::supportsNumberFormatterPhp84RoundConsts() ? [
                'ROUND_TOWARD_ZERO' => self::ROUND_TOWARD_ZERO,
                'ROUND_AWAY_FROM_ZERO' => self::ROUND_AWAY_FROM_ZERO,
                'ROUND_HALFODD' => self::ROUND_HALFODD,
            ] : []),
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

    public static function create(Context $ctx, string $locale, int $style, ?string $pattern): ?ObjectEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "NumberFormatter" not found');
        }
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        if (!self::initObject($object, $locale, $style, $pattern)) {
            return null;
        }

        return $object;
    }

    /**
     * NumberFormatter::__construct / create shared init (#20754, #25204).
     *
     * @return bool false when ICU rejects the style (create → null; construct → IntlException)
     */
    public static function initObject(ObjectEntry $object, string $locale, int $style, ?string $pattern): bool
    {
        $resolvedLocale = '' !== $locale ? $locale : VmLocale::getDefault();
        $openStatus = self::probeStyleOpen($resolvedLocale, $style, $pattern);
        if ($openStatus > 0) {
            // php-src formatter_main.c numfmt_create — U_UNSUPPORTED_ERROR etc.
            IntlError::set(
                $openStatus,
                'numfmt_create: number formatter creation failed: '.IntlError::errorName($openStatus)
            );

            return false;
        }
        $object->constructed = true;
        // php-src/ICU: create without an explicit pattern still exposes a non-empty
        // default via unum_toPattern (#21113) — e.g. DECIMAL → #,##0.###.
        self::$state[$object->id] = [
            'locale' => $resolvedLocale,
            'style' => $style,
            'pattern' => null !== $pattern ? $pattern : self::defaultPatternForStyle($style),
            // php-src/ICU style defaults (#21894, #22900): PERCENT → 0 fraction
            // digits; CURRENCY → 2; DECIMAL → min 0 / max 3 (CLDR #,##0.###).
            'attributes' => self::defaultAttributesForStyle($style),
            'symbols' => self::defaultSymbolsForLocale($resolvedLocale),
            'textAttributes' => self::defaultTextAttributes(),
            'errorCode' => IntlError::U_ZERO_ERROR,
            'errorMessage' => 'U_ZERO_ERROR',
        ];
        // Explicit pattern (PATTERN_DECIMAL arg or style default with '.') drives
        // MIN/MAX_FRACTION_DIGITS like ICU DecimalFormat.applyPattern (#22579).
        if (null !== $pattern && '' !== $pattern) {
            self::applyPatternDigitAttributes($object->id, $pattern);
        }
        // php-src INTL_METHOD_CHECK_STATUS — preserve ICU warnings e.g. U_USING_DEFAULT_WARNING (#23547).
        if ($openStatus < 0) {
            IntlError::set($openStatus, IntlError::errorName($openStatus));
        } else {
            IntlError::clear();
        }

        return true;
    }

    /**
     * Probe unum_open for style validity — returns ICU UErrorCode (>0 = failure).
     * When ICU FFI is unavailable, reject styles outside the known php-src range (#25204).
     */
    private static function probeStyleOpen(string $locale, int $style, ?string $pattern): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            // Offline / no libicui18n: mirror ICU UNumberFormatStyle span (0..16).
            if ($style < 0 || $style > 16) {
                return IntlError::U_UNSUPPORTED_ERROR;
            }

            return IntlError::U_ZERO_ERROR;
        }
        $suffix = self::$symSuffix;
        $open = 'unum_open'.$suffix;
        $close = 'unum_close'.$suffix;
        try {
            $status = \FFI::new('int32_t');
            $status->cdata = 0;
            $patPtr = null;
            $patLen = -1;
            if (null !== $pattern && '' !== $pattern
                && (self::PATTERN_RULEBASED === $style || self::PATTERN_DECIMAL === $style)) {
                $u = self::utf8ToUChar($pattern);
                if (null !== $u) {
                    $patPtr = $u[0];
                    $patLen = $u[1];
                }
            }
            $fmt = $ffi->$open($style, $patPtr, $patLen, $locale, null, \FFI::addr($status));
            if (null !== $fmt) {
                try {
                    $ffi->$close($fmt);
                } catch (\Throwable) {
                }
            }
            if ($status->cdata > 0) {
                return (int) $status->cdata;
            }

            // Preserve ICU warnings (negative) — U_USING_DEFAULT_WARNING for en_US (#23547).
            return (int) $status->cdata;
        } catch (\Throwable) {
            if ($style < 0 || $style > 16) {
                return IntlError::U_UNSUPPORTED_ERROR;
            }

            return IntlError::U_ZERO_ERROR;
        }
    }

    /**
     * ICU default DecimalFormat patterns for common UNumberFormatStyle values
     * (php-src numfmt_get_pattern / unum_toPattern; #21113).
     *
     * Rule-based styles leave '' here; {@see getPattern()} resolves the live ICU
     * ruleset via unum_toPattern (#21110). DECIMAL/PERCENT/CURRENCY/SCIENTIFIC match
     * Zend/ICU CLDR defaults.
     */
    public static function defaultPatternForStyle(int $style): string
    {
        return match ($style) {
            self::PERCENT => '#,##0%',
            self::CURRENCY => '¤#,##0.00',
            self::CURRENCY_ACCOUNTING => '¤#,##0.00;(¤#,##0.00)',
            self::SCIENTIFIC => '#E0',
            self::PATTERN_DECIMAL, self::IGNORE => '#',
            self::SPELLOUT, self::ORDINAL, self::DURATION, self::PATTERN_RULEBASED => '',
            default => '#,##0.###',
        };
    }

    /**
     * ICU/php-src default UNumberFormatAttribute values per style (#21894, #22900, #22919).
     * PERCENT CLDR patterns use 0 fraction digits; CURRENCY uses 2;
     * DECIMAL uses min 0 / max 3 fraction (CLDR #,##0.###) and INTEGER 1/1/2e9;
     * SCIENTIFIC uses 0/0/0 fraction and INTEGER 1/1/1.
     *
     * @return array<int, int|float>
     */
    public static function defaultAttributesForStyle(int $style): array
    {
        $attrs = [
            self::GROUPING_USED => 1,
            self::ROUNDING_MODE => self::ROUND_HALFEVEN,
            // ICU DecimalFormat integer defaults (#22919).
            self::INTEGER_DIGITS => 1,
            self::MIN_INTEGER_DIGITS => 1,
            self::MAX_INTEGER_DIGITS => 2000000000,
            // ICU pad defaults (#22920): width 0, PAD_BEFORE_PREFIX.
            self::FORMAT_WIDTH => 0,
            self::PADDING_POSITION => self::PAD_BEFORE_PREFIX,
            // Significant digits unused until setAttribute (#22921).
            self::SIGNIFICANT_DIGITS_USED => 0,
        ];
        if (self::PERCENT === $style) {
            $attrs[self::FRACTION_DIGITS] = 0;
            $attrs[self::MIN_FRACTION_DIGITS] = 0;
            $attrs[self::MAX_FRACTION_DIGITS] = 0;

            return $attrs;
        }
        if (self::CURRENCY === $style || self::CURRENCY_ACCOUNTING === $style) {
            $attrs[self::FRACTION_DIGITS] = 2;
            $attrs[self::MIN_FRACTION_DIGITS] = 2;
            $attrs[self::MAX_FRACTION_DIGITS] = 2;

            return $attrs;
        }
        if (self::SCIENTIFIC === $style) {
            $attrs[self::FRACTION_DIGITS] = 0;
            $attrs[self::MIN_FRACTION_DIGITS] = 0;
            $attrs[self::MAX_FRACTION_DIGITS] = 0;
            $attrs[self::MAX_INTEGER_DIGITS] = 1;

            return $attrs;
        }
        // DECIMAL / PATTERN_DECIMAL / default — ICU unum_open DECIMAL (#22900).
        $attrs[self::FRACTION_DIGITS] = 0;
        $attrs[self::MIN_FRACTION_DIGITS] = 0;
        $attrs[self::MAX_FRACTION_DIGITS] = 3;

        return $attrs;
    }

    /**
     * MessageFormat `{n,number[,style]}` via ICU default NumberFormat (#21959).
     *
     * php-src: ext/intl/msgformat/msgformat_format.c → umsg_format → unum DECIMAL
     * (locale grouping / decimal separators). Style `integer` truncates toward zero
     * then formats with 0 fraction digits (not NumberFormatter ROUND_HALFEVEN).
     *
     * @param int|float $num
     */
    public static function formatMessageArg(string $locale, $num, ?string $style = null): string
    {
        $styleLc = null !== $style ? strtolower(trim($style)) : '';
        $nfStyle = match ($styleLc) {
            'percent' => self::PERCENT,
            'currency' => self::CURRENCY,
            default => self::DECIMAL,
        };
        $value = (float) $num;
        if ('integer' === $styleLc) {
            $value = (float) (int) $value;
        }
        $attrs = self::defaultAttributesForStyle($nfStyle);
        if ('integer' === $styleLc) {
            $attrs[self::FRACTION_DIGITS] = 0;
            $attrs[self::MIN_FRACTION_DIGITS] = 0;
            $attrs[self::MAX_FRACTION_DIGITS] = 0;
        }
        $resolvedLocale = '' !== $locale ? $locale : VmLocale::getDefault();
        $state = [
            'locale' => $resolvedLocale,
            'style' => $nfStyle,
            'pattern' => self::defaultPatternForStyle($nfStyle),
            'attributes' => $attrs,
            'symbols' => self::defaultSymbolsForLocale($resolvedLocale),
            'textAttributes' => self::defaultTextAttributes(),
            'errorCode' => IntlError::U_ZERO_ERROR,
            'errorMessage' => 'U_ZERO_ERROR',
        ];
        $result = self::formatFromState($state, $value);

        return false === $result ? (string) $value : $result;
    }

    /**
     * MessageFormat `{n,spellout}` / `{n,ordinal}` / `{n,duration}` (#25227).
     *
     * php-src: msgformat_helpers → ICU RuleBasedNumberFormat / duration NumberFormat.
     *
     * @param mixed $num
     */
    public static function formatMessageRuleBasedArg(string $locale, $num, int $style): string
    {
        if (!self::isRuleBasedStyle($style) || self::PATTERN_RULEBASED === $style) {
            return (string) $num;
        }
        if (!\is_int($num) && !\is_float($num) && !(\is_string($num) && is_numeric($num))) {
            return (string) $num;
        }
        $resolvedLocale = '' !== $locale ? $locale : VmLocale::getDefault();
        $state = [
            'locale' => $resolvedLocale,
            'style' => $style,
            'pattern' => self::defaultPatternForStyle($style),
            'attributes' => self::defaultAttributesForStyle($style),
            'symbols' => self::defaultSymbolsForLocale($resolvedLocale),
            'textAttributes' => self::defaultTextAttributes(),
            'errorCode' => IntlError::U_ZERO_ERROR,
            'errorMessage' => 'U_ZERO_ERROR',
        ];
        $result = self::formatFromState($state, (float) $num);

        return false === $result ? (string) $num : $result;
    }

    public static function isRuleBasedStyle(int $style): bool
    {
        return self::SPELLOUT === $style
            || self::ORDINAL === $style
            || self::DURATION === $style
            || self::PATTERN_RULEBASED === $style;
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
        $result = self::formatFromState($state, $num);
        if (false === $result) {
            self::fail($formatter, 'numfmt_format: number formatting failed: U_ILLEGAL_ARGUMENT_ERROR');
        }

        return $result;
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
        // php-src numfmt_format_currency / unum_formatDoubleCurrency: non-currency
        // construction styles format like format() (ignore ISO currency wire) (#25015).
        $style = (int) ($state['style'] ?? self::DECIMAL);
        if (self::CURRENCY !== $style && self::CURRENCY_ACCOUNTING !== $style) {
            return self::format($formatter, $amount);
        }
        $currency = strtoupper($currency);
        $symbol = self::currencySymbol($currency);
        $body = self::formatDecimalFromState($state, $amount, 2);
        self::clearObjectError($formatter);
        IntlError::clear();
        // CURRENCY_ACCOUNTING negatives use parentheses like format() / ICU
        // unum_formatDoubleCurrency (php-src formatter_format.c; #22699).
        $accountingNeg = self::CURRENCY_ACCOUNTING === $style
            && $amount < 0;
        if ('$' === $symbol || '£' === $symbol || '€' === $symbol || '¥' === $symbol) {
            if ($accountingNeg) {
                return '('.$symbol.$body.')';
            }

            return ($amount < 0 ? '-' : '').$symbol.$body;
        }
        if ($accountingNeg) {
            return '('.$body.' '.$currency.')';
        }

        return ($amount < 0 ? '-' : '').$body.' '.$currency;
    }

    /**
     * numfmt_parse / NumberFormatter::parse — php-src formatter_main.c (#20728, #21139).
     *
     * Optional by-ref $offset is both parse start (bytes) and end (bytes consumed) like ICU.
     *
     * @param-out int|null $offset
     *
     * @return int|float|false
     */
    public static function parse(
        ObjectEntry $formatter,
        string $value,
        int $type = self::TYPE_DOUBLE,
        ?int &$offset = null
    ) {
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
        $hasOffset = null !== $offset;
        $start = 0;
        if ($hasOffset) {
            $start = $offset ?? 0;
            if ($start < 0) {
                $start = 0;
            }
            if ($start > \strlen($value)) {
                self::failParse($formatter);

                return false;
            }
        }
        $slice = $hasOffset ? \substr($value, $start) : $value;
        $style = (int) ($state['style'] ?? self::DECIMAL);
        // CURRENCY / CURRENCY_ACCOUNTING: ICU unum_parseDouble requires a currency
        // affix — share the parseCurrencySlice path (#25159). Bare numerics fail
        // with U_PARSE_ERROR; Zend still advances $offset through a numeric prefix.
        if (self::CURRENCY === $style || self::CURRENCY_ACCOUNTING === $style) {
            $parsed = self::parseCurrencySlice($slice, $state['locale']);
            if (null === $parsed) {
                if ($hasOffset) {
                    $bare = self::matchNumberPrefix($slice, $state['locale']);
                    if (null !== $bare) {
                        $offset = $start + $bare[1];
                    }
                }
                self::failParse($formatter);

                return false;
            }
            [$num, /* $currency */, $consumed] = $parsed;
            if ($hasOffset) {
                $offset = $start + $consumed;
            }
        } elseif (self::PERCENT === $style) {
            // PERCENT: consume percent symbol and scale /100 (inverse of format; #25160).
            $parsed = self::parsePercentSlice($slice, $state);
            if (null === $parsed) {
                if ($hasOffset) {
                    $bare = self::matchNumberPrefix($slice, $state['locale']);
                    if (null !== $bare) {
                        $offset = $start + $bare[1];
                    }
                }
                self::failParse($formatter);

                return false;
            }
            [$num, $consumed] = $parsed;
            if ($hasOffset) {
                $offset = $start + $consumed;
            }
        } elseif (self::isRuleBasedStyle($style) || self::SCIENTIFIC === $style) {
            // SPELLOUT/ORDINAL/DURATION/PATTERN_RULEBASED (#25161) + SCIENTIFIC (#25162).
            $parsed = self::icuParseDouble($state, $slice);
            if (null === $parsed) {
                self::failParse($formatter);

                return false;
            }
            [$num, $consumed] = $parsed;
            if ($hasOffset) {
                $offset = $start + $consumed;
            }
        } elseif ($hasOffset) {
            $prefix = self::matchNumberPrefix($slice, $state['locale']);
            if (null === $prefix) {
                self::failParse($formatter);

                return false;
            }
            [$num, $consumed] = $prefix;
            $offset = $start + $consumed;
        } else {
            // Without $offset, ICU still parses a numeric prefix (stops before trailing junk).
            $prefix = self::matchNumberPrefix($value, $state['locale']);
            if (null === $prefix) {
                // Fallback: historic full-string sanitize for whitespace / odd separators.
                $num = self::parseNumberString($value, $state['locale']);
                if (null === $num) {
                    self::failParse($formatter);

                    return false;
                }
            } else {
                $num = $prefix[0];
            }
        }
        self::clearObjectError($formatter);
        IntlError::clear();
        if (self::TYPE_INT32 === $type || self::TYPE_INT64 === $type) {
            return (int) $num;
        }

        return $num;
    }

    /**
     * numfmt_parse_currency / NumberFormatter::parseCurrency — php-src formatter_main.c (#20728, #21127, #21145).
     *
     * Optional by-ref $offset is both parse start (bytes) and end (bytes consumed) like ICU.
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
        $hasOffset = null !== $offset;
        $start = 0;
        if ($hasOffset) {
            $start = $offset ?? 0;
            if ($start < 0) {
                $start = 0;
            }
            if ($start > \strlen($value)) {
                self::failParse($formatter);
                $currencyOut = null;

                return false;
            }
        }
        $slice = $hasOffset ? \substr($value, $start) : $value;
        $parsed = self::parseCurrencySlice($slice, $state['locale']);
        if (null === $parsed) {
            self::failParse($formatter);
            $currencyOut = null;

            return false;
        }
        [$num, $currency, $consumed] = $parsed;
        $currencyOut = $currency;
        if ($hasOffset) {
            $offset = $start + $consumed;
        }
        self::clearObjectError($formatter);
        IntlError::clear();

        return $num;
    }

    /**
     * Parse a PERCENT amount prefix; return [fractionalValue, bytesConsumed] or null.
     *
     * Requires an immediate percent symbol after the numeric prefix (ICU percent
     * style / php-src numfmt_parse; #25160). Scales by /100 to invert format().
     *
     * @param array{locale: string, symbols?: array<int, string>} $state
     *
     * @return array{0: float, 1: int}|null
     */
    private static function parsePercentSlice(string $slice, array $state): ?array
    {
        $prefix = self::matchNumberPrefix($slice, $state['locale']);
        if (null === $prefix) {
            return null;
        }
        [$num, $numBytes] = $prefix;
        $pct = $state['symbols'][self::PERCENT_SYMBOL] ?? '%';
        if ('' === $pct) {
            $pct = '%';
        }
        $after = \substr($slice, $numBytes);
        if (!str_starts_with($after, $pct)) {
            return null;
        }

        return [$num / 100.0, $numBytes + \strlen($pct)];
    }

    /**
     * Parse a currency amount prefix; return [amount, currency, bytesConsumed] or null.
     *
     * @return array{0: float, 1: string, 2: int}|null
     */
    private static function parseCurrencySlice(string $slice, string $locale): ?array
    {
        if ('' === $slice) {
            return null;
        }
        // Prefer leading currency symbols over trailing ISO codes so "$12abc" stays USD (#21145).
        $negative = false;
        $numericStart = 0;
        $currency = null;
        if (str_starts_with($slice, '-$')) {
            $currency = 'USD';
            $numericStart = 2;
            $negative = true;
        } elseif (str_starts_with($slice, '$')) {
            $currency = 'USD';
            $numericStart = 1;
        } elseif (str_starts_with($slice, '€')) {
            $currency = 'EUR';
            $numericStart = \strlen('€');
        } elseif (str_starts_with($slice, '£')) {
            $currency = 'GBP';
            $numericStart = \strlen('£');
        } elseif (str_starts_with($slice, '¥')) {
            $currency = 'JPY';
            $numericStart = \strlen('¥');
        } elseif (preg_match('/^([A-Z]{3})\s*/i', $slice, $m)) {
            $currency = strtoupper($m[1]);
            $numericStart = \strlen($m[0]);
        } elseif (preg_match('/^(.+?)\s*([A-Z]{3})$/i', $slice, $m)) {
            // Trailing ISO only when it is the entire remainder (end-anchored).
            $num = self::parseNumberString(trim($m[1]), $locale);
            if (null === $num) {
                return null;
            }

            return [$num, strtoupper($m[2]), \strlen($m[0])];
        } else {
            return null;
        }

        $numericSlice = \substr($slice, $numericStart);
        if ($negative) {
            $numericSlice = '-'.$numericSlice;
        }
        $prefix = self::matchNumberPrefix($numericSlice, $locale);
        if (null === $prefix) {
            return null;
        }
        [$num, $numBytes] = $prefix;
        $consumed = $numericStart + ($negative ? $numBytes - 1 : $numBytes);

        return [$num, $currency, $consumed];
    }

    /**
     * Longest locale-aware numeric prefix (stops before trailing junk — e.g. "$12abc" → "12").
     *
     * @return array{0: float, 1: int}|null
     */
    private static function matchNumberPrefix(string $value, string $locale): ?array
    {
        [$grouping, $decimal] = self::separatorsForLocale($locale);
        $g = preg_quote($grouping, '/');
        $d = preg_quote($decimal, '/');
        $pattern = '/^(-)?(?:\d{1,3}(?:'.$g.'\d{3})*|\d+)(?:'.$d.'\d+)?/';
        if (!preg_match($pattern, $value, $m)) {
            $pattern = '/^(-)?('.$d.'\d+)/';
            if (!preg_match($pattern, $value, $m)) {
                return null;
            }
        }
        $matched = $m[0];
        $num = self::parseNumberString($matched, $locale);
        if (null === $num) {
            return null;
        }

        return [$num, \strlen($matched)];
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

        $value = $state['attributes'][$attribute] ?? -1;
        // Zend/php-src returns boolean false for FORMAT_WIDTH when ICU width is 0 (#22920).
        if (self::FORMAT_WIDTH === $attribute && is_numeric($value) && 0 === (int) $value) {
            return false;
        }
        // Unused significant min/max are unset → false (#22921).
        if ((self::MIN_SIGNIFICANT_DIGITS === $attribute || self::MAX_SIGNIFICANT_DIGITS === $attribute)
            && !array_key_exists($attribute, $state['attributes'])) {
            return false;
        }

        return $value;
    }

    public static function setAttribute(ObjectEntry $formatter, int $attribute, int|float $value): bool
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'numfmt_set_attribute: bad formatter: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        // ICU UNUM_FRACTION_DIGITS / UNUM_INTEGER_DIGITS set both min and max
        // (php-src formatter_attr.c / #22900, #22919).
        if (self::FRACTION_DIGITS === $attribute) {
            $n = (int) $value;
            self::$state[$formatter->id]['attributes'][self::FRACTION_DIGITS] = $n;
            self::$state[$formatter->id]['attributes'][self::MIN_FRACTION_DIGITS] = $n;
            self::$state[$formatter->id]['attributes'][self::MAX_FRACTION_DIGITS] = $n;
        } elseif (self::MIN_FRACTION_DIGITS === $attribute) {
            $n = (int) $value;
            self::$state[$formatter->id]['attributes'][self::MIN_FRACTION_DIGITS] = $n;
            // getAttribute(FRACTION_DIGITS) mirrors min when min ≠ max (Zend/ICU).
            self::$state[$formatter->id]['attributes'][self::FRACTION_DIGITS] = $n;
        } elseif (self::INTEGER_DIGITS === $attribute) {
            $n = (int) $value;
            self::$state[$formatter->id]['attributes'][self::INTEGER_DIGITS] = $n;
            self::$state[$formatter->id]['attributes'][self::MIN_INTEGER_DIGITS] = $n;
            self::$state[$formatter->id]['attributes'][self::MAX_INTEGER_DIGITS] = $n;
        } elseif (self::MIN_INTEGER_DIGITS === $attribute) {
            $n = (int) $value;
            self::$state[$formatter->id]['attributes'][self::MIN_INTEGER_DIGITS] = $n;
            self::$state[$formatter->id]['attributes'][self::INTEGER_DIGITS] = $n;
        } elseif (self::SIGNIFICANT_DIGITS_USED === $attribute) {
            $n = (int) $value;
            self::$state[$formatter->id]['attributes'][self::SIGNIFICANT_DIGITS_USED] = $n;
            // ICU fills default min/max when significant mode is first enabled (#22921).
            if ($n !== 0) {
                if (!isset(self::$state[$formatter->id]['attributes'][self::MIN_SIGNIFICANT_DIGITS])) {
                    self::$state[$formatter->id]['attributes'][self::MIN_SIGNIFICANT_DIGITS] = 1;
                }
                if (!isset(self::$state[$formatter->id]['attributes'][self::MAX_SIGNIFICANT_DIGITS])) {
                    self::$state[$formatter->id]['attributes'][self::MAX_SIGNIFICANT_DIGITS] = 6;
                }
            }
        } else {
            self::$state[$formatter->id]['attributes'][$attribute] = $value;
        }
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
        // ICU UNUM_PADDING_CHARACTER is a single UChar (#22920).
        if (self::PADDING_CHARACTER === $attribute && '' !== $value) {
            $value = substr($value, 0, 1);
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
        $pattern = $state['pattern'] ?? null;
        $style = (int) ($state['style'] ?? self::DECIMAL);
        if (self::isRuleBasedStyle($style) && (null === $pattern || '' === $pattern)) {
            $icuPattern = self::icuToPattern($state);
            if (null !== $icuPattern) {
                self::$state[$formatter->id]['pattern'] = $icuPattern;

                return $icuPattern;
            }
        }
        if (null === $pattern) {
            return self::defaultPatternForStyle($style);
        }

        return $pattern;
    }

    public static function setPattern(ObjectEntry $formatter, string $pattern): bool
    {
        if (!isset(self::$state[$formatter->id])) {
            self::fail($formatter, 'numfmt_set_pattern: bad formatter: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        self::$state[$formatter->id]['pattern'] = $pattern;
        self::applyPatternDigitAttributes($formatter->id, $pattern);
        self::clearObjectError($formatter);
        IntlError::clear();

        return true;
    }

    /**
     * Mirror ICU DecimalFormat.applyPattern digit / grouping effects (#22919, #22579).
     * Positive subpattern only (before ';'). Min integer digits = count of '0' in
     * the integer section; absence of ',' disables grouping.
     * Fraction: '0' → required (min); '#' → optional; both count toward max.
     */
    private static function applyPatternDigitAttributes(int $id, string $pattern): void
    {
        $pos = explode(';', $pattern, 2)[0];
        self::$state[$id]['attributes'][self::GROUPING_USED] = str_contains($pos, ',') ? 1 : 0;

        $intPart = $pos;
        $fracPart = '';
        $dot = strpos($pos, '.');
        if (false !== $dot) {
            $intPart = substr($pos, 0, $dot);
            $fracPart = substr($pos, $dot + 1);
            $eInFrac = stripos($fracPart, 'E');
            if (false !== $eInFrac) {
                $fracPart = substr($fracPart, 0, $eInFrac);
            }
        } else {
            $ePos = stripos($pos, 'E');
            if (false !== $ePos) {
                $intPart = substr($pos, 0, $ePos);
            }
        }
        $minZeros = substr_count($intPart, '0');
        if ($minZeros > 0) {
            self::$state[$id]['attributes'][self::MIN_INTEGER_DIGITS] = $minZeros;
            self::$state[$id]['attributes'][self::INTEGER_DIGITS] = $minZeros;
        }

        // Fraction digit attrs from pattern (ICU DecimalFormat; #22579).
        if (false !== $dot) {
            $minFrac = substr_count($fracPart, '0');
            $maxFrac = $minFrac + substr_count($fracPart, '#');
            self::$state[$id]['attributes'][self::MIN_FRACTION_DIGITS] = $minFrac;
            self::$state[$id]['attributes'][self::MAX_FRACTION_DIGITS] = $maxFrac;
            self::$state[$id]['attributes'][self::FRACTION_DIGITS] = $minFrac;
        } elseif ('' !== $pos && !str_contains($pos, 'E') && !str_contains($pos, 'e')) {
            // No decimal section → 0 fraction digits (e.g. '#,##0').
            self::$state[$id]['attributes'][self::MIN_FRACTION_DIGITS] = 0;
            self::$state[$id]['attributes'][self::MAX_FRACTION_DIGITS] = 0;
            self::$state[$id]['attributes'][self::FRACTION_DIGITS] = 0;
        }
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

    private static function fail(ObjectEntry $formatter, string $message, int $code = IntlError::U_ILLEGAL_ARGUMENT_ERROR): void
    {
        IntlError::set($code, $message);
        if (isset(self::$state[$formatter->id])) {
            self::$state[$formatter->id]['errorCode'] = $code;
            self::$state[$formatter->id]['errorMessage'] = $message;
        }
    }

    /**
     * Parse/parseCurrency failure — object error only (php-src leaves intl_get_error_code at 0) (#22855).
     */
    private static function failParse(ObjectEntry $formatter, string $message = 'Number parsing failed: U_PARSE_ERROR'): void
    {
        if (isset(self::$state[$formatter->id])) {
            self::$state[$formatter->id]['errorCode'] = IntlError::U_PARSE_ERROR;
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

    /**
     * Honor attributes/symbols/textAttributes in format() (#21121).
     * Rule-based styles use ICU unum_formatDouble (#21110).
     *
     * @param array{
     *   locale: string,
     *   style: int,
     *   pattern: ?string,
     *   attributes: array<int, int|float>,
     *   symbols: array<int, string>,
     *   textAttributes: array<int, string>,
     *   errorCode: int,
     *   errorMessage: string
     * } $state
     *
     * @return string|false
     */
    private static function formatFromState(array $state, float $num)
    {
        $style = $state['style'];
        if (self::isRuleBasedStyle($style)) {
            $formatted = self::icuFormatDouble($state, $num);
            if (null === $formatted) {
                return false;
            }

            return $formatted;
        }
        if (self::SCIENTIFIC === $style) {
            // Prefer ICU unum_formatDouble so `#E0` matches Zend (`1.234E3`; #25162).
            $formatted = self::icuFormatDouble($state, $num);
            if (null !== $formatted) {
                return $formatted;
            }
            $body = self::formatScientificFromState($state, abs($num));

            return self::applyTextAffixes($state, $body, $num < 0);
        }
        if (self::PERCENT === $style) {
            // Pass signed value so ROUNDING_MODE (CEILING/FLOOR/UP/DOWN) is sign-aware (#22703).
            $body = self::formatDecimalFromState($state, $num * 100.0, null);
            $pct = $state['symbols'][self::PERCENT_SYMBOL] ?? '%';

            return self::applyTextAffixes($state, $body.$pct, $num < 0);
        }
        $forceFrac = null;
        if (self::CURRENCY === $style || self::CURRENCY_ACCOUNTING === $style) {
            $forceFrac = 2;
        }
        $body = self::formatDecimalFromState($state, $num, $forceFrac);
        if (self::CURRENCY === $style || self::CURRENCY_ACCOUNTING === $style) {
            $symbol = $state['symbols'][self::CURRENCY_SYMBOL] ?? '$';
            if (self::CURRENCY_ACCOUNTING === $style && $num < 0) {
                return '('.$symbol.$body.')';
            }

            return self::applyTextAffixes($state, $symbol.$body, $num < 0);
        }

        return self::applyTextAffixes($state, $body, $num < 0);
    }

    /**
     * @param array{
     *   attributes: array<int, int|float>,
     *   textAttributes: array<int, string>
     * } $state
     */
    private static function applyTextAffixes(array $state, string $body, bool $negative): string
    {
        $text = $state['textAttributes'];
        if ($negative) {
            $prefix = $text[self::NEGATIVE_PREFIX] ?? '-';
            $suffix = $text[self::NEGATIVE_SUFFIX] ?? '';
        } else {
            $prefix = $text[self::POSITIVE_PREFIX] ?? '';
            $suffix = $text[self::POSITIVE_SUFFIX] ?? '';
        }

        return self::applyFormatWidthPadding($state, $prefix, $body, $suffix);
    }

    /**
     * ICU UNUM_FORMAT_WIDTH / UNUM_PADDING_POSITION / PADDING_CHARACTER (#22920).
     *
     * @param array{
     *   attributes: array<int, int|float>,
     *   textAttributes: array<int, string>
     * } $state
     */
    private static function applyFormatWidthPadding(array $state, string $prefix, string $body, string $suffix): string
    {
        $attrs = $state['attributes'];
        $width = (int) ($attrs[self::FORMAT_WIDTH] ?? 0);
        $composed = $prefix.$body.$suffix;
        if ($width <= 0 || strlen($composed) >= $width) {
            return $composed;
        }
        $padChar = $state['textAttributes'][self::PADDING_CHARACTER] ?? ' ';
        if ('' === $padChar) {
            $padChar = ' ';
        }
        $padChar = substr($padChar, 0, 1);
        $pad = str_repeat($padChar, $width - strlen($composed));
        $pos = (int) ($attrs[self::PADDING_POSITION] ?? self::PAD_BEFORE_PREFIX);

        return match ($pos) {
            self::PAD_AFTER_PREFIX => $prefix.$pad.$body.$suffix,
            self::PAD_BEFORE_SUFFIX => $prefix.$body.$pad.$suffix,
            self::PAD_AFTER_SUFFIX => $prefix.$body.$suffix.$pad,
            default => $pad.$prefix.$body.$suffix, // PAD_BEFORE_PREFIX
        };
    }

    /**
     * @param array{
     *   locale: string,
     *   attributes: array<int, int|float>,
     *   symbols: array<int, string>
     * } $state
     *
     * $num is signed so ROUNDING_MODE CEILING/FLOOR/UP/DOWN match ICU (#22703).
     */
    private static function formatDecimalFromState(array $state, float $num, ?int $forceFrac): string
    {
        $attrs = $state['attributes'];
        $symbols = $state['symbols'];
        $grouping = $symbols[self::GROUPING_SEPARATOR_SYMBOL]
            ?? self::separatorsForLocale($state['locale'])[0];
        $decimal = $symbols[self::DECIMAL_SEPARATOR_SYMBOL]
            ?? self::separatorsForLocale($state['locale'])[1];
        $groupingUsed = (int) ($attrs[self::GROUPING_USED] ?? 1) !== 0;
        $groupSize = (int) ($attrs[self::GROUPING_SIZE] ?? 3);
        if ($groupSize < 1) {
            $groupSize = 3;
        }
        if (!$groupingUsed) {
            $grouping = '';
        }
        $roundingMode = (int) ($attrs[self::ROUNDING_MODE] ?? self::ROUND_HALFEVEN);
        $abs = abs($num);

        // Significant-digit mode overrides fraction attrs (#22921).
        if ((int) ($attrs[self::SIGNIFICANT_DIGITS_USED] ?? 0) !== 0) {
            $minSig = (int) ($attrs[self::MIN_SIGNIFICANT_DIGITS] ?? 1);
            $maxSig = (int) ($attrs[self::MAX_SIGNIFICANT_DIGITS] ?? 6);
            if ($minSig < 1) {
                $minSig = 1;
            }
            if ($maxSig < $minSig) {
                $maxSig = $minSig;
            }
            [$abs, $minFrac, $maxFrac] = self::roundToSignificantDigits($abs, $minSig, $maxSig);
            // Skip normal fraction attr resolution; use significant-derived fracs.
            $forceFrac = null;
            $intPart = (int) floor($abs + 1e-12);
            $frac = $abs - $intPart;
            $intStr = self::groupDigits(
                self::applyIntegerDigitAttrs((string) $intPart, $attrs),
                $grouping,
                $groupSize
            );
            if ($maxFrac <= 0) {
                return $intStr;
            }
            $fracInt = (int) round($frac * (10 ** $maxFrac) + 1e-12);
            $fracStr = str_pad((string) $fracInt, $maxFrac, '0', STR_PAD_LEFT);
            if ($minFrac < $maxFrac) {
                $fracStr = substr($fracStr, 0, max($minFrac, strlen(rtrim($fracStr, '0'))));
                if ($minFrac > 0) {
                    $fracStr = str_pad($fracStr, $minFrac, '0', STR_PAD_RIGHT);
                }
            }
            if ('' === $fracStr && 0 === $minFrac) {
                return $intStr;
            }

            return $intStr.$decimal.str_pad($fracStr, max($minFrac, strlen($fracStr)), '0', STR_PAD_RIGHT);
        }

        // Prefer MIN/MAX_FRACTION_DIGITS (ICU DecimalFormat). FRACTION_DIGITS alone is
        // only a fallback when min/max were never materialized (#22900 — DECIMAL
        // defaults are min=0 max=3 with getAttribute(FRACTION_DIGITS)=0).
        $minFrac = null;
        $maxFrac = null;
        if (null !== $forceFrac) {
            $minFrac = $forceFrac;
            $maxFrac = $forceFrac;
        } else {
            if (isset($attrs[self::MIN_FRACTION_DIGITS]) && (int) $attrs[self::MIN_FRACTION_DIGITS] >= 0) {
                $minFrac = (int) $attrs[self::MIN_FRACTION_DIGITS];
            }
            if (isset($attrs[self::MAX_FRACTION_DIGITS]) && (int) $attrs[self::MAX_FRACTION_DIGITS] >= 0) {
                $maxFrac = (int) $attrs[self::MAX_FRACTION_DIGITS];
            }
            if (null === $minFrac && null === $maxFrac) {
                $fracDigitsAttr = $attrs[self::FRACTION_DIGITS] ?? -1;
                if (is_numeric($fracDigitsAttr) && (int) $fracDigitsAttr >= 0) {
                    $minFrac = (int) $fracDigitsAttr;
                    $maxFrac = (int) $fracDigitsAttr;
                }
            }
        }

        if (null !== $minFrac && null !== $maxFrac) {
            $scaled = abs(self::roundWithMode($num, $maxFrac, $roundingMode));
            $intPart = (int) floor($scaled + 1e-12);
            $fracInt = (int) round(($scaled - $intPart) * (10 ** $maxFrac));
            if ($maxFrac > 0 && $fracInt >= (int) (10 ** $maxFrac)) {
                $fracInt = 0;
                ++$intPart;
            }
            $intStr = self::groupDigits(
                self::applyIntegerDigitAttrs((string) $intPart, $attrs),
                $grouping,
                $groupSize
            );
            if ($maxFrac <= 0) {
                return $intStr;
            }
            $fracStr = str_pad((string) $fracInt, $maxFrac, '0', STR_PAD_LEFT);
            if ($minFrac < $maxFrac) {
                $fracStr = substr($fracStr, 0, max($minFrac, strlen(rtrim($fracStr, '0'))));
                if ($minFrac > 0) {
                    $fracStr = str_pad($fracStr, $minFrac, '0', STR_PAD_RIGHT);
                }
            }
            if ('' === $fracStr && 0 === $minFrac) {
                return $intStr;
            }

            return $intStr.$decimal.str_pad($fracStr, max($minFrac, strlen($fracStr)), '0', STR_PAD_RIGHT);
        }
        if (null !== $maxFrac) {
            $scaled = abs(self::roundWithMode($num, $maxFrac, $roundingMode));
            $intPart = (int) floor($scaled + 1e-12);
            $frac = $scaled - $intPart;
            $intStr = self::groupDigits(
                self::applyIntegerDigitAttrs((string) $intPart, $attrs),
                $grouping,
                $groupSize
            );
            if ($maxFrac <= 0 || $frac <= 0.0) {
                return $intStr;
            }
            $fracStr = rtrim(rtrim(sprintf('%0.'.$maxFrac.'F', $frac), '0'), '.');
            if (str_starts_with($fracStr, '0.')) {
                $fracStr = substr($fracStr, 2);
            }

            return '' !== $fracStr ? $intStr.$decimal.$fracStr : $intStr;
        }
        if (null !== $minFrac) {
            $scaled = abs(self::roundWithMode($num, max($minFrac, 6), $roundingMode));
            $intPart = (int) floor($scaled + 1e-12);
            $frac = $scaled - $intPart;
            $intStr = self::groupDigits(
                self::applyIntegerDigitAttrs((string) $intPart, $attrs),
                $grouping,
                $groupSize
            );
            $raw = rtrim(rtrim(sprintf('%.6F', $frac), '0'), '.');
            $fracStr = str_starts_with($raw, '0.') ? substr($raw, 2) : '';
            $fracStr = str_pad($fracStr, $minFrac, '0', STR_PAD_RIGHT);

            return $intStr.$decimal.$fracStr;
        }

        // Default DECIMAL: trim trailing zeros (historic formatDecimal behavior).
        $intPart = (int) floor($abs);
        $frac = $abs - $intPart;
        $fracStr = '';
        if ($frac > 0.0 || (string) $abs !== (string) (int) $abs) {
            $raw = rtrim(rtrim(sprintf('%.6F', $frac), '0'), '.');
            if (str_starts_with($raw, '0.')) {
                $fracStr = substr($raw, 2);
            } elseif ('0' !== $raw && '' !== $raw) {
                $fracStr = $raw;
            }
        }
        $intStr = self::groupDigits(
            self::applyIntegerDigitAttrs((string) $intPart, $attrs),
            $grouping,
            $groupSize
        );

        return '' !== $fracStr ? $intStr.$decimal.$fracStr : $intStr;
    }

    /**
     * ICU UNumberFormatRoundingMode for fraction digits (#22703 / #20710).
     *
     * php-src: unum_setAttribute(UNUM_ROUNDING_MODE) → DecimalFormat rounding.
     */
    private static function roundWithMode(float $value, int $precision, int $mode): float
    {
        if (!is_finite($value)) {
            return $value;
        }
        $precision = max(0, $precision);

        return match ($mode) {
            self::ROUND_HALFDOWN => round($value, $precision, PHP_ROUND_HALF_DOWN),
            self::ROUND_HALFUP => round($value, $precision, PHP_ROUND_HALF_UP),
            self::ROUND_HALFODD => round($value, $precision, PHP_ROUND_HALF_ODD),
            self::ROUND_CEILING => self::scaledDirRound($value, $precision, true, null),
            self::ROUND_FLOOR => self::scaledDirRound($value, $precision, false, null),
            self::ROUND_DOWN => self::scaledDirRound($value, $precision, null, true),
            self::ROUND_UP => self::scaledDirRound($value, $precision, null, false),
            // HALFEVEN (default) and ROUND_UNNECESSARY → banker's rounding.
            default => round($value, $precision, PHP_ROUND_HALF_EVEN),
        };
    }

    /**
     * Scale → ceil/floor / toward-or-away-from-zero → unscale.
     *
     * @param bool|null $ceil true=CEILING, false=FLOOR, null=use $towardZero
     * @param bool|null $towardZero true=DOWN (toward 0), false=UP (away from 0)
     */
    private static function scaledDirRound(float $value, int $precision, ?bool $ceil, ?bool $towardZero): float
    {
        $factor = 10 ** $precision;
        $scaled = $value * $factor;
        $near = round($scaled);
        if (abs($scaled - $near) < 1e-9) {
            $scaled = (float) $near;
        }
        if (null !== $towardZero) {
            $rounded = $towardZero
                ? ($scaled >= 0.0 ? floor($scaled) : ceil($scaled))
                : ($scaled >= 0.0 ? ceil($scaled) : floor($scaled));
        } else {
            $rounded = $ceil ? ceil($scaled) : floor($scaled);
        }

        return $rounded / $factor;
    }

    /**
     * Apply MIN/MAX_INTEGER_DIGITS before grouping (ICU DecimalFormat; #22919).
     * Max truncates to the least-significant digits; min zero-pads on the left.
     *
     * @param array<int, int|float> $attrs
     */
    private static function applyIntegerDigitAttrs(string $intDigits, array $attrs): string
    {
        if ('' === $intDigits) {
            $intDigits = '0';
        }
        $maxInt = isset($attrs[self::MAX_INTEGER_DIGITS]) ? (int) $attrs[self::MAX_INTEGER_DIGITS] : -1;
        $minInt = isset($attrs[self::MIN_INTEGER_DIGITS]) ? (int) $attrs[self::MIN_INTEGER_DIGITS] : -1;
        if ($maxInt >= 0 && strlen($intDigits) > $maxInt) {
            if (0 === $maxInt) {
                $intDigits = '0';
            } else {
                $intDigits = substr($intDigits, -$maxInt);
            }
        }
        if ($minInt > 0) {
            $intDigits = str_pad($intDigits, $minInt, '0', STR_PAD_LEFT);
        }

        return $intDigits;
    }

    /**
     * Round abs value to max significant digits; return [value, minFrac, maxFrac] (#22921).
     *
     * @return array{0: float, 1: int, 2: int}
     */
    private static function roundToSignificantDigits(float $abs, int $minSig, int $maxSig): array
    {
        if ($abs <= 0.0 || !is_finite($abs)) {
            // Zend formats 0 with minSig as 0.0 when minSig=2.
            $frac = max(0, $minSig - 1);

            return [0.0, $frac, $frac];
        }
        $exp = (int) floor(log10($abs) + 1e-12);
        $magnitude = 10 ** ($exp - $maxSig + 1);
        if ($magnitude == 0.0) {
            $magnitude = 1e-300;
        }
        $rounded = round($abs / $magnitude) * $magnitude;
        if ($rounded <= 0.0) {
            $frac = max(0, $minSig - 1);

            return [0.0, $frac, $frac];
        }
        // Recompute exponent after rounding (999 → 1000 bumps exp).
        $exp2 = (int) floor(log10($rounded) + 1e-12);
        $maxFrac = max(0, $maxSig - $exp2 - 1);
        $minFrac = max(0, $minSig - $exp2 - 1);
        // For values like 12 (maxSig=2), minFrac/maxFrac are 0.
        // Keep trailing zeros when minSig requires more fraction digits than maxFrac.
        if ($minFrac > $maxFrac) {
            $maxFrac = $minFrac;
        }

        return [$rounded, $minFrac, $maxFrac];
    }

    /**
     * @param array{locale: string, symbols: array<int, string>} $state
     */
    private static function formatScientificFromState(array $state, float $num): string
    {
        $decimal = $state['symbols'][self::DECIMAL_SEPARATOR_SYMBOL]
            ?? self::separatorsForLocale($state['locale'])[1];
        // PHP fallback for `#E0` when ICU FFI is unavailable (#25162).
        if (0.0 === $num || -0.0 === $num) {
            return '0E0';
        }
        $abs = abs($num);
        $exp = (int) floor(log10($abs) + 1e-12);
        $mant = $abs / (10.0 ** $exp);
        if ($mant >= 10.0 - 1e-9) {
            $mant = 1.0;
            ++$exp;
        }
        $mantStr = rtrim(rtrim(sprintf('%.6F', $mant), '0'), '.');
        if ('' === $mantStr) {
            $mantStr = '0';
        }
        $mantStr = str_replace('.', $decimal, $mantStr);

        return $mantStr.'E'.$exp;
    }

    /** @deprecated kept for parse helpers that still take locale-only separators */
    private static function formatDecimal(float $num, string $locale, ?int $forceFrac): string
    {
        $state = [
            'locale' => $locale,
            'attributes' => [
                self::GROUPING_USED => 1,
                self::FRACTION_DIGITS => -1,
            ],
            'symbols' => self::defaultSymbolsForLocale($locale),
        ];
        $body = self::formatDecimalFromState($state, $num, $forceFrac);

        return $num < 0 ? '-'.$body : $body;
    }

    private static function formatScientific(float $num, string $locale): string
    {
        return self::formatScientificFromState([
            'locale' => $locale,
            'symbols' => self::defaultSymbolsForLocale($locale),
        ], $num);
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

    private static function groupDigits(string $digits, string $sep, int $groupSize = 3): string
    {
        if ('' === $sep || strlen($digits) <= $groupSize || $groupSize < 1) {
            return $digits;
        }
        $out = '';
        $len = strlen($digits);
        for ($i = 0; $i < $len; ++$i) {
            if (0 !== $i && 0 === ($len - $i) % $groupSize) {
                $out .= $sep;
            }
            $out .= $digits[$i];
        }

        return $out;
    }

    /**
     * Format via ICU unum_formatDouble for SPELLOUT/ORDINAL/DURATION/PATTERN_RULEBASED
     * (php-src formatter_format.c / unum.h; #21110).
     *
     * @param array{locale: string, style: int, pattern: ?string} $state
     */
    private static function icuFormatDouble(array $state, float $num): ?string
    {
        $fmt = self::icuOpen($state);
        if (null === $fmt) {
            return null;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $suffix = self::$symSuffix;
        $format = 'unum_formatDouble'.$suffix;
        $close = 'unum_close'.$suffix;
        $toUtf8 = 'u_strToUTF8'.$suffix;
        try {
            $cap = 64;
            for ($attempt = 0; $attempt < 4; ++$attempt) {
                $buf = \FFI::new('uint16_t['.$cap.']');
                $status = \FFI::new('int32_t');
                $status->cdata = 0;
                $n = (int) $ffi->$format($fmt[0], $num, $buf, $cap, null, \FFI::addr($status));
                // ICU U_BUFFER_OVERFLOW_ERROR (15) — grow and retry (php-src unum_format*).
                if (15 === $status->cdata) {
                    $cap = max($cap * 2, $n + 8);
                    continue;
                }
                // Warnings (<0) are acceptable; positive codes are failures.
                if ($status->cdata > 0 || $n < 0) {
                    $ffi->$close($fmt[0]);

                    return null;
                }
                $byteCap = max(16, $n * 3 + 8);
                $utf8len = \FFI::new('int32_t');
                $cbuf = \FFI::new('char['.$byteCap.']');
                $st2 = \FFI::new('int32_t');
                $st2->cdata = 0;
                $ffi->$toUtf8($cbuf, $byteCap, \FFI::addr($utf8len), $buf, $n, \FFI::addr($st2));
                $ffi->$close($fmt[0]);
                if ($st2->cdata > 0 || $utf8len->cdata < 0) {
                    return null;
                }

                return \FFI::string($cbuf, $utf8len->cdata);
            }
            $ffi->$close($fmt[0]);
        } catch (\Throwable) {
            try {
                $ffi->$close($fmt[0]);
            } catch (\Throwable) {
            }
        }

        return null;
    }

    /**
     * Parse via ICU unum_parseDouble for rule-based styles (#25161, #25169).
     *
     * @param array{locale: string, style: int, pattern: ?string} $state
     *
     * @return array{0: float, 1: int}|null value + UTF-8 bytes consumed
     */
    private static function icuParseDouble(array $state, string $text): ?array
    {
        if ('' === $text) {
            return null;
        }
        $fmt = self::icuOpen($state);
        if (null === $fmt) {
            return null;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $u = self::utf8ToUChar($text);
        if (null === $u) {
            try {
                $ffi->{'unum_close'.self::$symSuffix}($fmt[0]);
            } catch (\Throwable) {
            }

            return null;
        }
        $suffix = self::$symSuffix;
        $parse = 'unum_parseDouble'.$suffix;
        $close = 'unum_close'.$suffix;
        $toUtf8 = 'u_strToUTF8'.$suffix;
        try {
            $parsePos = \FFI::new('int32_t');
            $parsePos->cdata = 0;
            $status = \FFI::new('int32_t');
            $status->cdata = 0;
            $val = (float) $ffi->$parse($fmt[0], $u[0], $u[1], \FFI::addr($parsePos), \FFI::addr($status));
            $consumedU = (int) $parsePos->cdata;
            // Warnings (<0) ok; positive = failure. Require progress.
            if ($status->cdata > 0 || $consumedU <= 0) {
                $ffi->$close($fmt[0]);

                return null;
            }
            $byteCap = max(16, $consumedU * 3 + 8);
            $utf8len = \FFI::new('int32_t');
            $cbuf = \FFI::new('char['.$byteCap.']');
            $st2 = \FFI::new('int32_t');
            $st2->cdata = 0;
            $ffi->$toUtf8($cbuf, $byteCap, \FFI::addr($utf8len), $u[0], $consumedU, \FFI::addr($st2));
            $ffi->$close($fmt[0]);
            if ($st2->cdata > 0 || $utf8len->cdata < 0) {
                // ASCII-safe fallback: UChar index equals byte index for BMP ASCII.
                return [$val, min($consumedU, \strlen($text))];
            }

            return [$val, (int) $utf8len->cdata];
        } catch (\Throwable) {
            try {
                $ffi->$close($fmt[0]);
            } catch (\Throwable) {
            }

            return null;
        }
    }

    /**
     * unum_toPattern for rule-based formatters (#21110 / #21113).
     *
     * @param array{locale: string, style: int, pattern: ?string} $state
     */
    private static function icuToPattern(array $state): ?string
    {
        $fmt = self::icuOpen($state);
        if (null === $fmt) {
            return null;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $suffix = self::$symSuffix;
        $toPattern = 'unum_toPattern'.$suffix;
        $close = 'unum_close'.$suffix;
        $toUtf8 = 'u_strToUTF8'.$suffix;
        try {
            $cap = 4096;
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $buf = \FFI::new('uint16_t['.$cap.']');
                $status = \FFI::new('int32_t');
                $status->cdata = 0;
                $n = (int) $ffi->$toPattern($fmt[0], 0, $buf, $cap, \FFI::addr($status));
                if (15 === $status->cdata) {
                    $cap = max($cap * 2, $n + 8);
                    continue;
                }
                if ($status->cdata > 0 || $n < 0) {
                    $ffi->$close($fmt[0]);

                    return null;
                }
                $utf8len = \FFI::new('int32_t');
                $byteCap = max(64, $n * 3 + 8);
                $cbuf = \FFI::new('char['.$byteCap.']');
                $st2 = \FFI::new('int32_t');
                $st2->cdata = 0;
                $ffi->$toUtf8($cbuf, $byteCap, \FFI::addr($utf8len), $buf, $n, \FFI::addr($st2));
                $ffi->$close($fmt[0]);
                if ($st2->cdata > 0 || $utf8len->cdata < 0) {
                    return null;
                }

                return \FFI::string($cbuf, $utf8len->cdata);
            }
            $ffi->$close($fmt[0]);
        } catch (\Throwable) {
            try {
                $ffi->$close($fmt[0]);
            } catch (\Throwable) {
            }
        }

        return null;
    }

    /**
     * @param array{locale: string, style: int, pattern: ?string} $state
     *
     * @return array{0: object}|null FFI UNumberFormat*
     */
    private static function icuOpen(array $state): ?array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $style = (int) $state['style'];
        $locale = (string) $state['locale'];
        $pattern = $state['pattern'] ?? null;
        if (self::PATTERN_RULEBASED === $style && (null === $pattern || '' === $pattern)) {
            return null;
        }
        $suffix = self::$symSuffix;
        $open = 'unum_open'.$suffix;
        try {
            $status = \FFI::new('int32_t');
            $status->cdata = 0;
            $patPtr = null;
            $patLen = -1;
            if (null !== $pattern && '' !== $pattern
                && (self::PATTERN_RULEBASED === $style || self::PATTERN_DECIMAL === $style)) {
                $u = self::utf8ToUChar($pattern);
                if (null === $u) {
                    return null;
                }
                $patPtr = $u[0];
                $patLen = $u[1];
            }
            $fmt = $ffi->$open($style, $patPtr, $patLen, $locale, null, \FFI::addr($status));
            // U_ZERO_ERROR (0) or warning (<0) are ok; positive = failure.
            if (null === $fmt || $status->cdata > 0) {
                return null;
            }

            return [$fmt];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{0: object, 1: int}|null */
    private static function utf8ToUChar(string $utf8): ?array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $fromUtf8 = 'u_strFromUTF8'.self::$symSuffix;
        try {
            $len = \FFI::new('int32_t');
            $status = \FFI::new('int32_t');
            $status->cdata = 0;
            $ffi->$fromUtf8(null, 0, \FFI::addr($len), $utf8, \strlen($utf8), \FFI::addr($status));
            // Expected U_BUFFER_OVERFLOW_ERROR (-124) when dest is null.
            $status->cdata = 0;
            $cap = max(1, $len->cdata + 1);
            $buf = \FFI::new('uint16_t['.$cap.']');
            $ffi->$fromUtf8($buf, $cap, \FFI::addr($len), $utf8, \strlen($utf8), \FFI::addr($status));
            if ($status->cdata > 0) {
                return null;
            }

            return [$buf, (int) $len->cdata];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\class_exists(\FFI::class, false) && !\extension_loaded('FFI')) {
            self::$ffiUnavailable = true;

            return null;
        }
        $candidates = [
            ['libicui18n.so.74', '_74'],
            ['libicui18n.so.72', '_72'],
            ['libicui18n.so.71', '_71'],
            ['libicui18n.so.70', '_70'],
            ['/usr/lib/x86_64-linux-gnu/libicui18n.so.70', '_70'],
            ['/lib/x86_64-linux-gnu/libicui18n.so.70', '_70'],
            ['/usr/lib/x86_64-linux-gnu/libicui18n.so.74', '_74'],
            ['libicui18n.so', '_70'],
            ['libicui18n.dylib', ''],
        ];
        foreach ($candidates as [$lib, $suffix]) {
            try {
                self::$ffi = \FFI::cdef(self::cdefForSuffix($suffix), $lib);
                self::$symSuffix = $suffix;

                return self::$ffi;
            } catch (\Throwable) {
                self::$ffi = null;
            }
        }
        self::$ffiUnavailable = true;

        return null;
    }

    private static function cdefForSuffix(string $suffix): string
    {
        return <<<C
typedef int32_t UErrorCode;
typedef uint16_t UChar;
typedef struct UNumberFormat UNumberFormat;
typedef struct UParseError UParseError;
UNumberFormat *unum_open{$suffix}(int32_t style, const UChar *pattern, int32_t patternLength, const char *locale, UParseError *parseErr, UErrorCode *status);
void unum_close{$suffix}(UNumberFormat *fmt);
int32_t unum_formatDouble{$suffix}(const UNumberFormat *fmt, double number, UChar *result, int32_t resultLength, void *pos, UErrorCode *status);
double unum_parseDouble{$suffix}(const UNumberFormat *fmt, const UChar *text, int32_t textLength, int32_t *parsePos, UErrorCode *status);
int32_t unum_toPattern{$suffix}(const UNumberFormat *fmt, int8_t isPatternLocalized, UChar *result, int32_t resultLength, UErrorCode *status);
UChar *u_strFromUTF8{$suffix}(UChar *dest, int32_t destCapacity, int32_t *pDestLength, const char *src, int32_t srcLength, UErrorCode *pErrorCode);
char *u_strToUTF8{$suffix}(char *dest, int32_t destCapacity, int32_t *pDestLength, const UChar *src, int32_t srcLength, UErrorCode *pErrorCode);
C;
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
        if (!VmNumberFormatter::initObject($receiver->toObject(), $locale, $style, $pattern)) {
            // php-src formatter_main.c — EH_THROW → IntlException("Constructor failed") (#25204)
            throw new \IntlException('Constructor failed');
        }
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
        $object = VmNumberFormatter::create($frame->vmContext, $locale, $style, $pattern);
        if (null === $object) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($object);
    }
}

/** NumberFormatter::format() — php-src numfmt_format (#5154, AOT #28648). */
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

    public function call(\PHPCompiler\JIT\Context $context, \PHPCompiler\JIT\Variable ...$args): \PHPLLVM\Value
    {
        return JitNumberFormatterFormat::invokeMethod($context, ...$args);
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

/** NumberFormatter::parse() — php-src numfmt_parse (#20728, #21139). */
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
        $hasOffset = $argc >= 4;
        $offset = null;
        if ($hasOffset) {
            $offsetVar = $frame->calledArgs[3]->resolveIndirect();
            $offset = Variable::TYPE_NULL === $offsetVar->type
                ? 0
                : VmIntlDateFormatter::coerceIntArg(
                    $offsetVar,
                    'NumberFormatter::parse',
                    3,
                    'offset'
                );
        }
        if ($hasOffset) {
            $result = VmNumberFormatter::parse($receiver->toObject(), $value, $type, $offset);
            $frame->calledArgs[3]->byRefTarget()->int($offset);
        } else {
            $result = VmNumberFormatter::parse($receiver->toObject(), $value, $type);
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
