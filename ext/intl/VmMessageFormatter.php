<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * MessageFormatter / msgfmt_* — ICU MessageFormat subset in PHP (#6366, #20718).
 *
 * php-src: ext/intl/msgformat/msgformat.c, msgformat_class.c, msgformat.stub.php
 *
 * Covers simple / named placeholders, `{n,number}` (locale grouping via
 * NumberFormatter — #21959), `{n,date}` / `{n,time}` (#25226), spellout/ordinal/
 * duration/selectordinal (#25227), and plural/select (#21099). Advertisement gates
 * on loaded ext/intl (#19670).
 */
final class VmMessageFormatter
{
    public const CLASS_LC = 'messageformatter';

    /**
     * @var array<int, array{
     *   locale: string,
     *   pattern: string,
     *   errorCode: int,
     *   errorMessage: string
     * }>
     */
    private static array $state = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('MessageFormatter');
        $entry->isInternal = true;
        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $construct = new MessageFormatterConstruct();
        $entry->constructor = $construct;
        $methods = [
            '__construct' => [$construct, $pub, '__construct'],
            'create' => [new MessageFormatterCreate(), $pubStatic, 'create'],
            'format' => [new MessageFormatterFormat(), $pub, 'format'],
            'setpattern' => [new MessageFormatterSetPattern(), $pub, 'setPattern'],
            'getpattern' => [new MessageFormatterGetPattern(), $pub, 'getPattern'],
            'formatmessage' => [new MessageFormatterFormatMessage(), $pubStatic, 'formatMessage'],
            'parse' => [new MessageFormatterParse(), $pub, 'parse'],
            'parsemessage' => [new MessageFormatterParseMessage(), $pubStatic, 'parseMessage'],
            'getlocale' => [new MessageFormatterGetLocale(), $pub, 'getLocale'],
            'geterrorcode' => [new MessageFormatterGetErrorCode(), $pub, 'getErrorCode'],
            'geterrormessage' => [new MessageFormatterGetErrorMessage(), $pub, 'getErrorMessage'],
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

    /**
     * Shared init for create() / __construct() (#20809, #22577).
     *
     * @return bool false + intl error when pattern is empty or ICU-invalid
     */
    public static function initObject(ObjectEntry $object, string $locale, string $pattern): bool
    {
        if ('' === $pattern) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'msgfmt_create: pattern is empty: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $patternError = self::validatePattern($pattern);
        if (null !== $patternError) {
            IntlError::set($patternError[0], $patternError[1]);

            return false;
        }
        $object->constructed = true;
        self::$state[$object->id] = [
            'locale' => '' !== $locale ? $locale : VmLocale::getDefault(),
            'pattern' => $pattern,
            'errorCode' => IntlError::U_ZERO_ERROR,
            'errorMessage' => 'U_ZERO_ERROR',
        ];
        IntlError::clear();

        return true;
    }

    /**
     * ICU MessageFormat create-time brace check (php-src msgformat_create.c / umsg_open; #22577).
     *
     * Unclosed `{` → U_UNMATCHED_BRACES. Lone `}` at depth 0 is accepted (Zend/ICU).
     *
     * @return array{0: int, 1: string}|null error code + message, or null when OK
     */
    public static function validatePattern(string $pattern): ?array
    {
        $len = \strlen($pattern);
        $depth = 0;
        $i = 0;
        while ($i < $len) {
            $ch = $pattern[$i];
            if ("'" === $ch) {
                ++$i;
                if ($i < $len && "'" === $pattern[$i]) {
                    ++$i;
                    continue;
                }
                while ($i < $len) {
                    if ("'" === $pattern[$i]) {
                        ++$i;
                        if ($i < $len && "'" === $pattern[$i]) {
                            ++$i;
                            continue;
                        }
                        break;
                    }
                    ++$i;
                }
                continue;
            }
            if ('{' === $ch) {
                ++$depth;
                ++$i;
                continue;
            }
            if ('}' === $ch) {
                if ($depth > 0) {
                    --$depth;
                }
                ++$i;
                continue;
            }
            ++$i;
        }
        if ($depth > 0) {
            return [
                IntlError::U_UNMATCHED_BRACES,
                'msgfmt_create: message formatter creation failed: U_UNMATCHED_BRACES',
            ];
        }

        return null;
    }

    /**
     * @return ObjectEntry|null null + intl error when pattern is empty (php-src returns false)
     */
    public static function create(Context $ctx, string $locale, string $pattern): ?ObjectEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "MessageFormatter" not found');
        }
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        if (!self::initObject($object, $locale, $pattern)) {
            return null;
        }

        return $object;
    }

    public static function setPattern(ObjectEntry $formatter, string $pattern): bool
    {
        if (!isset(self::$state[$formatter->id])) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'msgfmt_set_pattern: bad formatter: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        if ('' === $pattern) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'msgfmt_set_pattern: pattern is empty: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $patternError = self::validatePattern($pattern);
        if (null !== $patternError) {
            // php-src msgfmt_set_pattern uses a different ICU message prefix than create (#22577).
            IntlError::set(
                $patternError[0],
                'Error setting symbol value: U_UNMATCHED_BRACES'
            );

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
    public static function getPattern(ObjectEntry $formatter)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'msgfmt_get_pattern: bad formatter: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        self::clearObjectError($formatter);
        IntlError::clear();

        return $state['pattern'];
    }

    /**
     * @return string|false
     */
    public static function getLocale(ObjectEntry $formatter)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'msgfmt_get_locale: bad formatter: U_ILLEGAL_ARGUMENT_ERROR');

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

    /**
     * @return array<int|string, mixed>|false
     */
    public static function parse(ObjectEntry $formatter, string $source)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'msgfmt_parse: bad formatter: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        $result = self::parsePattern($state['locale'], $state['pattern'], $source);
        if (false === $result) {
            self::fail($formatter, 'msgfmt_parse: Parsing failed: U_ARGUMENT_TYPE_MISMATCH');

            return false;
        }
        self::clearObjectError($formatter);
        IntlError::clear();

        return $result;
    }

    /**
     * @return array<int|string, mixed>|false
     */
    public static function parseMessage(string $locale, string $pattern, string $source)
    {
        if ('' === $pattern) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'msgfmt_parse_message: pattern is empty: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $result = self::parsePattern(
            '' !== $locale ? $locale : VmLocale::getDefault(),
            $pattern,
            $source
        );
        if (false === $result) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'msgfmt_parse_message: Parsing failed: U_ARGUMENT_TYPE_MISMATCH'
            );

            return false;
        }
        IntlError::clear();

        return $result;
    }

    /**
     * @param array<int|string, mixed> $args
     *
     * @return string|false
     */
    public static function format(ObjectEntry $formatter, array $args)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'msgfmt_format: bad formatter: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        IntlError::clear();

        return self::applyPattern($state['locale'], $state['pattern'], $args);
    }

    /**
     * @param array<int|string, mixed> $args
     *
     * @return string|false
     */
    public static function formatMessage(string $locale, string $pattern, array $args)
    {
        if ('' === $pattern) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'msgfmt_format_message: pattern is empty: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        IntlError::clear();

        return self::applyPattern(
            '' !== $locale ? $locale : VmLocale::getDefault(),
            $pattern,
            $args
        );
    }

    /**
     * Z_PARAM_STR $locale — caller strict_types TypeError on null (#29921, msgformat.stub.php).
     *
     * Non-strict soft-coerces with E_DEPRECATED (php-src Z_PARAM_STR). Prefer this over bare
     * {@see VmString::coerceStringBuiltinArg} so declare(strict_types=1) matches Zend.
     */
    public static function coerceLocaleArgFromFrame(
        Frame $frame,
        int $frameArgIndex,
        string $function,
        int $userArgIndex
    ): string {
        return VmString::stringBuiltinArgForFrame(
            $frame,
            $frameArgIndex,
            $function,
            $userArgIndex,
            'locale'
        );
    }

    /**
     * Z_PARAM_STR $pattern — caller strict_types TypeError on null (#29921).
     */
    public static function coercePatternArgFromFrame(
        Frame $frame,
        int $frameArgIndex,
        string $function,
        int $userArgIndex
    ): string {
        return VmString::stringBuiltinArgForFrame(
            $frame,
            $frameArgIndex,
            $function,
            $userArgIndex,
            'pattern'
        );
    }

    /**
     * Z_PARAM_STR $string / source — caller strict_types TypeError on null (#29921 sibling).
     */
    public static function coerceSourceArgFromFrame(
        Frame $frame,
        int $frameArgIndex,
        string $function,
        int $userArgIndex
    ): string {
        return VmString::stringBuiltinArgForFrame(
            $frame,
            $frameArgIndex,
            $function,
            $userArgIndex,
            'string'
        );
    }

    /** @deprecated Use {@see coerceLocaleArgFromFrame} — kept for Variable-only call sites. */
    public static function coerceLocaleArg(Variable $var, string $function, int $position): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, $position, 'locale');
    }

    /** @deprecated Use {@see coercePatternArgFromFrame}. */
    public static function coercePatternArg(Variable $var, string $function, int $position): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, $position, 'pattern');
    }

    /** @deprecated Use {@see coerceSourceArgFromFrame}. */
    public static function coerceSourceArg(Variable $var, string $function, int $position): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, $position, 'string');
    }

    /**
     * @param array<int|string, mixed> $values
     */
    public static function valuesToHashTable(array $values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $key => $value) {
            $slot = new Variable();
            if (\is_int($value)) {
                $slot->int($value);
            } elseif (\is_float($value)) {
                $slot->float($value);
            } elseif (\is_bool($value)) {
                $slot->bool($value);
            } elseif (null === $value) {
                $slot->null();
            } else {
                $slot->string((string) $value);
            }
            if (\is_int($key)) {
                $ht->addIndex($key, $slot);
            } else {
                $ht->add((string) $key, $slot);
            }
        }

        return $ht;
    }

    /**
     * @return array<int|string, mixed>
     */
    public static function coerceArgsArray(Variable $var, string $function, int $position): array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($values) must be of type array, %s given',
                $function,
                $position + 1,
                ReflectionSupport::valueTypeLabelPublic($var)
            ));
        }

        return self::exportArgs($var->toArray());
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

    /**
     * @return array<int|string, mixed>|false
     */
    private static function parsePattern(string $locale, string $pattern, string $source)
    {
        unset($locale);
        $parts = [];
        $offset = 0;
        $len = \strlen($pattern);
        $re = '/\{([A-Za-z_][A-Za-z0-9_]*|[0-9]+)(?:,\s*([a-zA-Z]+)(?:,\s*([^}]+))?)?\}/';
        if (false === preg_match_all($re, $pattern, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return false;
        }
        foreach ($matches as $m) {
            $full = $m[0][0];
            $pos = $m[0][1];
            if ($pos > $offset) {
                $parts[] = ['lit', \substr($pattern, $offset, $pos - $offset)];
            }
            $parts[] = [
                'ph',
                $m[1][0],
                isset($m[2]) ? strtolower($m[2][0]) : null,
            ];
            $offset = $pos + \strlen($full);
        }
        if ($offset < $len) {
            $parts[] = ['lit', \substr($pattern, $offset)];
        }
        if ([] === $parts) {
            return '' === $source ? [] : false;
        }

        $out = [];
        $cursor = 0;
        $sourceLen = \strlen($source);
        $n = \count($parts);
        for ($i = 0; $i < $n; ++$i) {
            $part = $parts[$i];
            if ('lit' === $part[0]) {
                $lit = $part[1];
                $litLen = \strlen($lit);
                if (0 === $litLen) {
                    continue;
                }
                if (\substr($source, $cursor, $litLen) !== $lit) {
                    return false;
                }
                $cursor += $litLen;
                continue;
            }
            $name = $part[1];
            $type = $part[2];
            $nextLit = null;
            for ($j = $i + 1; $j < $n; ++$j) {
                if ('lit' === $parts[$j][0] && '' !== $parts[$j][1]) {
                    $nextLit = $parts[$j][1];
                    break;
                }
            }
            if (null === $nextLit) {
                $raw = \substr($source, $cursor);
                $cursor = $sourceLen;
            } else {
                $end = \strpos($source, $nextLit, $cursor);
                if (false === $end) {
                    return false;
                }
                $raw = \substr($source, $cursor, $end - $cursor);
                $cursor = $end;
            }
            $key = ctype_digit($name) ? (int) $name : $name;
            if ('number' === $type) {
                $normalized = \str_replace([',', ' '], '', $raw);
                if ('' === $normalized || !is_numeric($normalized)) {
                    return false;
                }
                if (false === \strpos($normalized, '.')) {
                    $out[$key] = (int) $normalized;
                } else {
                    $out[$key] = (float) $normalized;
                }
            } else {
                // php-src/ICU: untyped named args often U_ARGUMENT_TYPE_MISMATCH; numeric ids OK.
                if (!ctype_digit($name)) {
                    return false;
                }
                $out[$key] = $raw;
            }
        }
        if ($cursor !== $sourceLen) {
            return false;
        }

        return $out;
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function exportArgs(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $k = $key->resolveIndirect();
            $v = $value->resolveIndirect();
            $php = self::exportScalar($v);
            if (Variable::TYPE_STRING === $k->type) {
                $out[$k->toString()] = $php;
            } elseif (Variable::TYPE_INTEGER === $k->type) {
                $out[$k->toInt()] = $php;
            }
        }

        return $out;
    }

    /** @return mixed */
    private static function exportScalar(Variable $v)
    {
        return match ($v->type) {
            Variable::TYPE_INTEGER => $v->toInt(),
            Variable::TYPE_FLOAT => $v->toFloat(),
            Variable::TYPE_STRING => $v->toString(),
            Variable::TYPE_BOOLEAN => $v->toBool(),
            Variable::TYPE_NULL => null,
            default => ReflectionSupport::valueTypeLabelPublic($v),
        };
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private static function applyPattern(string $locale, string $pattern, array $args): string
    {
        return self::formatMessagePattern($locale, $pattern, $args);
    }

    /**
     * ICU MessageFormat subset: simple args, number, plural, select (#21099).
     *
     * @param array<int|string, mixed> $args
     */
    private static function formatMessagePattern(string $locale, string $pattern, array $args): string
    {
        $out = '';
        $i = 0;
        $len = \strlen($pattern);
        while ($i < $len) {
            $ch = $pattern[$i];
            if ("'" === $ch) {
                // ICU quoted literal: '…' ('' → single quote).
                ++$i;
                if ($i < $len && "'" === $pattern[$i]) {
                    $out .= "'";
                    ++$i;
                    continue;
                }
                while ($i < $len) {
                    if ("'" === $pattern[$i]) {
                        ++$i;
                        if ($i < $len && "'" === $pattern[$i]) {
                            $out .= "'";
                            ++$i;
                            continue;
                        }
                        break;
                    }
                    $out .= $pattern[$i];
                    ++$i;
                }
                continue;
            }
            if ('{' !== $ch) {
                $out .= $ch;
                ++$i;
                continue;
            }
            [$replacement, $end] = self::expandPlaceholder($locale, $pattern, $i, $args);
            $out .= $replacement;
            $i = $end;
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $args
     *
     * @return array{0: string, 1: int} replacement + index after closing brace
     */
    private static function expandPlaceholder(string $locale, string $pattern, int $start, array $args): array
    {
        $len = \strlen($pattern);
        if ($start >= $len || '{' !== $pattern[$start]) {
            return ['{', $start + 1];
        }
        $depth = 0;
        $end = $start;
        for ($i = $start; $i < $len; ++$i) {
            $ch = $pattern[$i];
            if ("'" === $ch) {
                ++$i;
                while ($i < $len) {
                    if ("'" === $pattern[$i]) {
                        if ($i + 1 < $len && "'" === $pattern[$i + 1]) {
                            $i += 2;
                            continue;
                        }
                        break;
                    }
                    ++$i;
                }
                continue;
            }
            if ('{' === $ch) {
                ++$depth;
            } elseif ('}' === $ch) {
                --$depth;
                if (0 === $depth) {
                    $end = $i;
                    break;
                }
            }
        }
        if (0 !== $depth) {
            // Unbalanced — leave literal remainder.
            return [\substr($pattern, $start), $len];
        }
        $inner = \substr($pattern, $start + 1, $end - $start - 1);
        $parts = self::splitPlaceholderParts($inner);
        $name = $parts[0];
        $type = isset($parts[1]) ? strtolower(trim($parts[1])) : null;
        $style = $parts[2] ?? null;
        $has = \array_key_exists($name, $args)
            || (ctype_digit($name) && \array_key_exists((int) $name, $args));
        if (!$has) {
            // php-src/ICU: missing args leave a stripped placeholder `{n}` / `{name}`,
            // not the full type/style skeleton (`{0,number}`, `{0,select,…}`) (#22946).
            return ['{'.$name.'}', $end + 1];
        }
        $val = self::lookupArg($args, $name);
        if (null === $type || 'none' === $type) {
            return [self::stringify($val), $end + 1];
        }
        if ('number' === $type) {
            return [self::formatNumberSimple($locale, $val, null !== $style ? trim($style) : null), $end + 1];
        }
        if ('date' === $type || 'time' === $type) {
            return [
                VmIntlDateFormatter::formatMessageDateTimeArg(
                    $locale,
                    $val,
                    $type,
                    null !== $style ? trim($style) : null
                ),
                $end + 1,
            ];
        }
        if ('spellout' === $type) {
            return [
                VmNumberFormatter::formatMessageRuleBasedArg($locale, $val, VmNumberFormatter::SPELLOUT),
                $end + 1,
            ];
        }
        if ('ordinal' === $type) {
            return [
                VmNumberFormatter::formatMessageRuleBasedArg($locale, $val, VmNumberFormatter::ORDINAL),
                $end + 1,
            ];
        }
        if ('duration' === $type) {
            return [
                VmNumberFormatter::formatMessageRuleBasedArg($locale, $val, VmNumberFormatter::DURATION),
                $end + 1,
            ];
        }
        if ('plural' === $type) {
            $sub = self::choosePluralSelect($style ?? '', $val, true, $locale);

            return [self::formatMessagePattern($locale, $sub, $args), $end + 1];
        }
        if ('selectordinal' === $type) {
            $sub = self::chooseSelectOrdinal($style ?? '', $val, $locale);

            return [self::formatMessagePattern($locale, $sub, $args), $end + 1];
        }
        if ('select' === $type) {
            $sub = self::choosePluralSelect($style ?? '', $val, false, $locale);

            return [self::formatMessagePattern($locale, $sub, $args), $end + 1];
        }

        return [self::stringify($val), $end + 1];
    }

    /** @return list<string> name[, type[, style…]] */
    private static function splitPlaceholderParts(string $inner): array
    {
        $parts = [];
        $buf = '';
        $depth = 0;
        $len = \strlen($inner);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $inner[$i];
            if ("'" === $ch) {
                $buf .= $ch;
                ++$i;
                while ($i < $len) {
                    $buf .= $inner[$i];
                    if ("'" === $inner[$i]) {
                        if ($i + 1 < $len && "'" === $inner[$i + 1]) {
                            $buf .= "'";
                            $i += 2;
                            continue;
                        }
                        break;
                    }
                    ++$i;
                }
                continue;
            }
            if ('{' === $ch) {
                ++$depth;
                $buf .= $ch;
                continue;
            }
            if ('}' === $ch) {
                if ($depth > 0) {
                    --$depth;
                }
                $buf .= $ch;
                continue;
            }
            if (',' === $ch && 0 === $depth && \count($parts) < 2) {
                $parts[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        $parts[] = $buf;

        return $parts;
    }

    /**
     * Parse plural/select style body and pick a sub-message.
     * Plural: exact =N first, then keyword (one/other/…); `#` → number.
     *
     * @param mixed $val
     */
    private static function choosePluralSelect(string $style, $val, bool $plural, string $locale): string
    {
        $cases = self::parsePluralSelectCases($style);
        if ([] === $cases) {
            return self::stringify($val);
        }
        $chosen = null;
        if ($plural) {
            $num = 0.0;
            if (\is_int($val) || \is_float($val) || (\is_string($val) && is_numeric($val))) {
                $num = (float) $val;
            }
            // Prefer =N / =n exact forms.
            foreach ($cases as [$key, $msg]) {
                if (str_starts_with($key, '=')) {
                    $rhs = substr($key, 1);
                    if (is_numeric($rhs) && (float) $rhs == $num) {
                        $chosen = $msg;
                        break;
                    }
                }
            }
            if (null === $chosen) {
                $keyword = self::pluralKeyword($locale, $num);
                foreach ([$keyword, 'other'] as $want) {
                    foreach ($cases as [$key, $msg]) {
                        if ($key === $want) {
                            $chosen = $msg;
                            break 2;
                        }
                    }
                }
            }
            if (null === $chosen) {
                $chosen = $cases[\count($cases) - 1][1];
            }
            $numberText = self::formatNumberSimple($locale, $val, null);

            return str_replace('#', $numberText, $chosen);
        }
        $sel = self::stringify($val);
        foreach ($cases as [$key, $msg]) {
            if ($key === $sel) {
                return $msg;
            }
        }
        foreach ($cases as [$key, $msg]) {
            if ('other' === $key) {
                return $msg;
            }
        }

        return $cases[\count($cases) - 1][1];
    }

    /**
     * selectordinal: CLDR ordinal keywords (en: one/two/few/other) + `#` → number (#25227).
     *
     * @param mixed $val
     */
    private static function chooseSelectOrdinal(string $style, $val, string $locale): string
    {
        $cases = self::parsePluralSelectCases($style);
        if ([] === $cases) {
            return self::stringify($val);
        }
        $num = 0.0;
        if (\is_int($val) || \is_float($val) || (\is_string($val) && is_numeric($val))) {
            $num = (float) $val;
        }
        $chosen = null;
        foreach ($cases as [$key, $msg]) {
            if (str_starts_with($key, '=')) {
                $rhs = substr($key, 1);
                if (is_numeric($rhs) && (float) $rhs == $num) {
                    $chosen = $msg;
                    break;
                }
            }
        }
        if (null === $chosen) {
            $keyword = self::ordinalKeyword($locale, $num);
            foreach ([$keyword, 'other'] as $want) {
                foreach ($cases as [$key, $msg]) {
                    if ($key === $want) {
                        $chosen = $msg;
                        break 2;
                    }
                }
            }
        }
        if (null === $chosen) {
            $chosen = $cases[\count($cases) - 1][1];
        }
        // ICU selectordinal `#` is the integer argument (not locale-grouped).
        $numberText = (string) (int) $num;

        return str_replace('#', $numberText, $chosen);
    }

    /** @return list<array{0: string, 1: string}> */
    private static function parsePluralSelectCases(string $style): array
    {
        $cases = [];
        $len = \strlen($style);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && ctype_space($style[$i])) {
                ++$i;
            }
            if ($i >= $len) {
                break;
            }
            $key = '';
            if ('=' === $style[$i]) {
                $key .= '=';
                ++$i;
                while ($i < $len && (ctype_digit($style[$i]) || '.' === $style[$i] || '-' === $style[$i])) {
                    $key .= $style[$i];
                    ++$i;
                }
            } else {
                while ($i < $len && (ctype_alnum($style[$i]) || '_' === $style[$i])) {
                    $key .= $style[$i];
                    ++$i;
                }
            }
            while ($i < $len && ctype_space($style[$i])) {
                ++$i;
            }
            if ($i >= $len || '{' !== $style[$i]) {
                break;
            }
            $depth = 0;
            $msgStart = $i + 1;
            for (; $i < $len; ++$i) {
                if ('{' === $style[$i]) {
                    ++$depth;
                } elseif ('}' === $style[$i]) {
                    --$depth;
                    if (0 === $depth) {
                        $cases[] = [$key, \substr($style, $msgStart, $i - $msgStart)];
                        ++$i;
                        break;
                    }
                }
            }
        }

        return $cases;
    }

    /** English-oriented CLDR plural keyword (enough for en_* php-src-strict). */
    private static function pluralKeyword(string $locale, float $n): string
    {
        unset($locale);
        if (1.0 === $n) {
            return 'one';
        }

        return 'other';
    }

    /**
     * English-oriented CLDR ordinal keyword (en: one/two/few/other) (#25227).
     *
     * Matches ICU en selectordinal: 1→one, 2→two, 3→few, 11–13→other, 21→one, …
     */
    private static function ordinalKeyword(string $locale, float $n): string
    {
        unset($locale);
        $i = (int) $n;
        $mod100 = $i % 100;
        $mod10 = $i % 10;
        if (1 === $mod10 && 11 !== $mod100) {
            return 'one';
        }
        if (2 === $mod10 && 12 !== $mod100) {
            return 'two';
        }
        if (3 === $mod10 && 13 !== $mod100) {
            return 'few';
        }

        return 'other';
    }

    /**
     * @param array<int|string, mixed> $args
     *
     * @return mixed
     */
    private static function lookupArg(array $args, string $name)
    {
        if (\array_key_exists($name, $args)) {
            return $args[$name];
        }
        if (ctype_digit($name) && \array_key_exists((int) $name, $args)) {
            return $args[(int) $name];
        }

        return null;
    }

    /** @param mixed $val */
    private static function stringify($val): string
    {
        if (null === $val) {
            return '';
        }
        if (\is_bool($val)) {
            return $val ? '1' : '';
        }

        return (string) $val;
    }

    /** @param mixed $val */
    private static function formatNumberSimple(string $locale, $val, ?string $style): string
    {
        if (!\is_int($val) && !\is_float($val) && !(\is_string($val) && is_numeric($val))) {
            return self::stringify($val);
        }
        // ICU MessageFormat `{n,number}` uses locale NumberFormat (grouping) — #21959.
        return VmNumberFormatter::formatMessageArg($locale, $val, $style);
    }
}

/** MessageFormatter::__construct() — same init as create() (#20809). */
final class MessageFormatterConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'MessageFormatter::__construct() expects exactly 2 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmMessageFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('MessageFormatter::__construct() called on incompatible object');
        }
        $locale = VmMessageFormatter::coerceLocaleArgFromFrame(
            $frame,
            1,
            'MessageFormatter::__construct',
            0
        );
        $pattern = VmMessageFormatter::coercePatternArgFromFrame(
            $frame,
            2,
            'MessageFormatter::__construct',
            1
        );
        if (!VmMessageFormatter::initObject($receiver->toObject(), $locale, $pattern)) {
            // php-src msgformat_create.c — construct failure throws IntlException (#22577).
            throw new \IntlException(IntlError::getMessage());
        }
    }

    public function call(\PHPCompiler\JIT\Context $context, \PHPCompiler\JIT\Variable ...$args): \PHPLLVM\Value
    {
        return JitMessageFormatterConstruct::invoke($context, ...$args);
    }
}

/** MessageFormatter::create() — php-src msgfmt_create (#6366). */
final class MessageFormatterCreate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'MessageFormatter::create() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $locale = VmMessageFormatter::coerceLocaleArgFromFrame(
            $frame,
            0,
            'MessageFormatter::create',
            0
        );
        $pattern = VmMessageFormatter::coercePatternArgFromFrame(
            $frame,
            1,
            'MessageFormatter::create',
            1
        );
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmMessageFormatter::create($frame->vmContext, $locale, $pattern);
        if (null === $object) {
            // php-src MessageFormatter::create → null (not false) on ICU failure (#22577).
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($object);
    }
}

/** MessageFormatter::format() — php-src msgfmt_format (#6366). AOT: #28655. */
final class MessageFormatterFormat extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('format');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'MessageFormatter::format() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmMessageFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('MessageFormatter::format() called on incompatible object');
        }
        $args = VmMessageFormatter::coerceArgsArray($frame->calledArgs[1], 'MessageFormatter::format', 1);
        $result = VmMessageFormatter::format($receiver->toObject(), $args);
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
        return JitMessageFormatterFormat::invokeMethod($context, ...$args);
    }
}

/** MessageFormatter::setPattern() — php-src msgfmt_set_pattern (#6366). */
final class MessageFormatterSetPattern extends VmClassMethod
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
                'MessageFormatter::setPattern() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmMessageFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('MessageFormatter::setPattern() called on incompatible object');
        }
        $pattern = VmMessageFormatter::coercePatternArgFromFrame(
            $frame,
            1,
            'MessageFormatter::setPattern',
            0
        );
        $ok = VmMessageFormatter::setPattern($receiver->toObject(), $pattern);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** MessageFormatter::getPattern() — php-src msgfmt_get_pattern (#6366). */
final class MessageFormatterGetPattern extends VmClassMethod
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
                'MessageFormatter::getPattern() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmMessageFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('MessageFormatter::getPattern() called on incompatible object');
        }
        $result = VmMessageFormatter::getPattern($receiver->toObject());
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

/** MessageFormatter::formatMessage() — php-src msgfmt_format_message (#6366). */
final class MessageFormatterFormatMessage extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('formatMessage');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'MessageFormatter::formatMessage() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $locale = VmMessageFormatter::coerceLocaleArgFromFrame(
            $frame,
            0,
            'MessageFormatter::formatMessage',
            0
        );
        $pattern = VmMessageFormatter::coercePatternArgFromFrame(
            $frame,
            1,
            'MessageFormatter::formatMessage',
            1
        );
        $args = VmMessageFormatter::coerceArgsArray($frame->calledArgs[2], 'MessageFormatter::formatMessage', 2);
        $result = VmMessageFormatter::formatMessage($locale, $pattern, $args);
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

/** MessageFormatter::parse() — php-src msgfmt_parse (#20718). */
final class MessageFormatterParse extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('parse');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'MessageFormatter::parse() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmMessageFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('MessageFormatter::parse() called on incompatible object');
        }
        $source = VmMessageFormatter::coerceSourceArgFromFrame(
            $frame,
            1,
            'MessageFormatter::parse',
            0
        );
        $result = VmMessageFormatter::parse($receiver->toObject(), $source);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmMessageFormatter::valuesToHashTable($result));
    }
}

/** MessageFormatter::parseMessage() — php-src msgfmt_parse_message (#20718). */
final class MessageFormatterParseMessage extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('parseMessage');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'MessageFormatter::parseMessage() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $locale = VmMessageFormatter::coerceLocaleArgFromFrame(
            $frame,
            0,
            'MessageFormatter::parseMessage',
            0
        );
        $pattern = VmMessageFormatter::coercePatternArgFromFrame(
            $frame,
            1,
            'MessageFormatter::parseMessage',
            1
        );
        $source = VmMessageFormatter::coerceSourceArgFromFrame(
            $frame,
            2,
            'MessageFormatter::parseMessage',
            2
        );
        $result = VmMessageFormatter::parseMessage($locale, $pattern, $source);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmMessageFormatter::valuesToHashTable($result));
    }
}

/** MessageFormatter::getLocale() — php-src msgfmt_get_locale (#20718). */
final class MessageFormatterGetLocale extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getLocale');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'MessageFormatter::getLocale() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmMessageFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('MessageFormatter::getLocale() called on incompatible object');
        }
        $result = VmMessageFormatter::getLocale($receiver->toObject());
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

/** MessageFormatter::getErrorCode() — php-src msgfmt_get_error_code (#20718). */
final class MessageFormatterGetErrorCode extends VmClassMethod
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
                'MessageFormatter::getErrorCode() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmMessageFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('MessageFormatter::getErrorCode() called on incompatible object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmMessageFormatter::getErrorCode($receiver->toObject()));
    }
}

/** MessageFormatter::getErrorMessage() — php-src msgfmt_get_error_message (#20718). */
final class MessageFormatterGetErrorMessage extends VmClassMethod
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
                'MessageFormatter::getErrorMessage() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmMessageFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('MessageFormatter::getErrorMessage() called on incompatible object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmMessageFormatter::getErrorMessage($receiver->toObject()));
    }
}
