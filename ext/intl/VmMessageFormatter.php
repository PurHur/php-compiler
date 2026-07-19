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
 * v1 covers simple / named placeholders and `{n, number}` format + parse without
 * full ICU umsg_* plural/select. Advertisement gates on loaded ext/intl (#19670).
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
        $methods = [
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
     * @return ObjectEntry|null null + intl error when pattern is empty (php-src returns false)
     */
    public static function create(Context $ctx, string $locale, string $pattern): ?ObjectEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "MessageFormatter" not found');
        }
        if ('' === $pattern) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'msgfmt_create: pattern is empty: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return null;
        }
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'locale' => '' !== $locale ? $locale : VmLocale::getDefault(),
            'pattern' => $pattern,
            'errorCode' => IntlError::U_ZERO_ERROR,
            'errorMessage' => 'U_ZERO_ERROR',
        ];
        IntlError::clear();

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

    public static function coerceLocaleArg(Variable $var, string $function, int $position): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, $position, 'locale');
    }

    public static function coercePatternArg(Variable $var, string $function, int $position): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, $position, 'pattern');
    }

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
                '%s(): Argument #%d ($args) must be of type array, %s given',
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
        // Match {arg}, {arg, type}, {arg, type, style} — nested braces deferred (plural/select).
        return (string) preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*|[0-9]+)(?:,\s*([a-zA-Z]+)(?:,\s*([^}]+))?)?\}/',
            static function (array $m) use ($locale, $args): string {
                $name = $m[1];
                $type = isset($m[2]) ? strtolower($m[2]) : null;
                $style = isset($m[3]) ? trim($m[3]) : null;
                $has = \array_key_exists($name, $args)
                    || (ctype_digit($name) && \array_key_exists((int) $name, $args));
                if (!$has) {
                    return $m[0];
                }
                $val = self::lookupArg($args, $name);
                if (null === $type || 'none' === $type) {
                    return self::stringify($val);
                }
                if ('number' === $type) {
                    return self::formatNumberSimple($locale, $val, $style);
                }

                return self::stringify($val);
            },
            $pattern
        );
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
        unset($locale, $style);
        if (!\is_int($val) && !\is_float($val) && !(\is_string($val) && is_numeric($val))) {
            return self::stringify($val);
        }
        $num = (float) $val;
        // ICU MessageFormat default number style for integers omits fraction digits.
        if (\is_int($val) || floor($num) == $num) {
            return (string) (int) $num;
        }

        return (string) $num;
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
        $locale = VmMessageFormatter::coerceLocaleArg($frame->calledArgs[0], 'MessageFormatter::create', 0);
        $pattern = VmMessageFormatter::coercePatternArg($frame->calledArgs[1], 'MessageFormatter::create', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmMessageFormatter::create($frame->vmContext, $locale, $pattern);
        if (null === $object) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($object);
    }
}

/** MessageFormatter::format() — php-src msgfmt_format (#6366). */
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
        $pattern = VmMessageFormatter::coercePatternArg($frame->calledArgs[1], 'MessageFormatter::setPattern', 1);
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
        $locale = VmMessageFormatter::coerceLocaleArg($frame->calledArgs[0], 'MessageFormatter::formatMessage', 0);
        $pattern = VmMessageFormatter::coercePatternArg($frame->calledArgs[1], 'MessageFormatter::formatMessage', 1);
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
        $source = VmMessageFormatter::coerceSourceArg($frame->calledArgs[1], 'MessageFormatter::parse', 1);
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
        $locale = VmMessageFormatter::coerceLocaleArg($frame->calledArgs[0], 'MessageFormatter::parseMessage', 0);
        $pattern = VmMessageFormatter::coercePatternArg($frame->calledArgs[1], 'MessageFormatter::parseMessage', 1);
        $source = VmMessageFormatter::coerceSourceArg($frame->calledArgs[2], 'MessageFormatter::parseMessage', 2);
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
