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
 * NumberFormatter — locale decimal/percent subset without full ICU (#5154).
 *
 * php-src: ext/intl/formatter/formatter_main.c, formatter_class.c, formatter.stub.php
 * Style constants: unicode/unum.h UNumberFormatStyle.
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
    public const PATTERN_RULEBASED = 8;
    public const IGNORE = 9;
    public const CURRENCY_ACCOUNTING = 12;
    public const DEFAULT_STYLE = 1;

    /** ICU UNumberFormatRoundingMode (unicode/unum.h; #20710). */
    public const ROUND_CEILING = 0;
    public const ROUND_FLOOR = 1;
    public const ROUND_DOWN = 2;
    public const ROUND_UP = 3;
    public const ROUND_HALFEVEN = 4;
    public const ROUND_HALFDOWN = 5;
    public const ROUND_HALFUP = 6;
    public const ROUND_UNNECESSARY = 7;
    /** ICU UNUM_ROUND_HALF_ODD (PHP 8.4+). */
    public const ROUND_HALFODD = 8;
    /** Alias of ROUND_DOWN (php-src formatter.stub.php). */
    public const ROUND_TOWARD_ZERO = 2;
    /** Alias of ROUND_UP (php-src formatter.stub.php). */
    public const ROUND_AWAY_FROM_ZERO = 3;

    /** @var array<int, array{locale: string, style: int, pattern: ?string}> */
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
        $entry->methods['create'] = new NumberFormatterCreate();
        $entry->methodVisibility['create'] = $pubStatic;
        $entry->methodNames['create'] = 'create';
        $entry->methods['format'] = new NumberFormatterFormat();
        $entry->methodVisibility['format'] = $pub;
        $entry->methodNames['format'] = 'format';
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
        $object->constructed = true;
        self::$state[$object->id] = [
            'locale' => '' !== $locale ? $locale : VmLocale::getDefault(),
            'style' => $style,
            'pattern' => $pattern,
        ];
        IntlError::clear();

        return $object;
    }

    /**
     * @return string|false
     */
    public static function format(ObjectEntry $formatter, float $num)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'numfmt_format: bad formatter: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        IntlError::clear();
        $style = $state['style'];
        $locale = $state['locale'];
        if (self::PERCENT === $style) {
            return self::formatDecimal($num * 100.0, $locale).'%';
        }
        if (self::SCIENTIFIC === $style) {
            return self::formatScientific($num, $locale);
        }
        if (self::CURRENCY === $style || self::CURRENCY_ACCOUNTING === $style) {
            // v1: decimal + ISO-ish currency suffix deferred; match DECIMAL digits.
            return self::formatDecimal($num, $locale);
        }
        if (self::SPELLOUT === $style || self::ORDINAL === $style || self::DURATION === $style
            || self::PATTERN_RULEBASED === $style) {
            throw new \Error(
                'NumberFormatter::format() style requires full ext/intl ICU (issue #5154)'
            );
        }

        return self::formatDecimal($num, $locale);
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

    private static function formatDecimal(float $num, string $locale): string
    {
        [$grouping, $decimal] = self::separatorsForLocale($locale);
        $negative = $num < 0;
        $abs = abs($num);
        $intPart = (int) floor($abs);
        $frac = $abs - $intPart;
        // Trim trailing zeros but keep at least one fractional digit when input had fraction.
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

    /** @return array{0: string, 1: string} grouping, decimal */
    private static function separatorsForLocale(string $locale): array
    {
        $lc = strtolower(str_replace('-', '_', $locale));
        $lang = explode('_', $lc)[0] ?? 'en';
        // Common ICU defaults without full CLDR.
        return match ($lang) {
            'de', 'es', 'it', 'nl', 'da', 'fi', 'sv', 'pl', 'cs', 'hu', 'tr', 'ru', 'uk' => ['.', ','],
            'fr', 'pt', 'vi' => [' ', ','],
            default => [',', '.'], // en_*, ja_*, zh_*, …
        };
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
        $result = VmNumberFormatter::format($receiver->toObject(), $num);
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
