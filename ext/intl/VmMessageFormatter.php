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
 * MessageFormatter / msgfmt_* — ICU MessageFormat subset in PHP (#6366).
 *
 * php-src: ext/intl/msgformat/msgformat.c, msgformat_class.c, msgformat.stub.php
 *
 * v1 covers simple / named placeholders and `{n, number}` without full ICU umsg_*
 * plural/select. Advertisement gates on loaded ext/intl (#19670).
 */
final class VmMessageFormatter
{
    public const CLASS_LC = 'messageformatter';

    /** @var array<int, array{locale: string, pattern: string}> */
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
        $entry->methods['create'] = new MessageFormatterCreate();
        $entry->methodVisibility['create'] = $pubStatic;
        $entry->methodNames['create'] = 'create';
        $entry->methods['format'] = new MessageFormatterFormat();
        $entry->methodVisibility['format'] = $pub;
        $entry->methodNames['format'] = 'format';
        $entry->methods['setpattern'] = new MessageFormatterSetPattern();
        $entry->methodVisibility['setpattern'] = $pub;
        $entry->methodNames['setpattern'] = 'setPattern';
        $entry->methods['getpattern'] = new MessageFormatterGetPattern();
        $entry->methodVisibility['getpattern'] = $pub;
        $entry->methodNames['getpattern'] = 'getPattern';
        $entry->methods['formatmessage'] = new MessageFormatterFormatMessage();
        $entry->methodVisibility['formatmessage'] = $pubStatic;
        $entry->methodNames['formatmessage'] = 'formatMessage';
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
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'msgfmt_get_pattern: bad formatter: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        IntlError::clear();

        return $state['pattern'];
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
