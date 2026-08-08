<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;

/**
 * parse_url() component resolution — PHP_URL_* ints only (#28536, re-#7260).
 *
 * php-src: ext/standard/basic_functions.stub.php — parse_url(..., int $component = -1)
 * php-src: ext/standard/url.c — Z_PARAM_LONG soft-null → E_DEPRECATED + 0 (#24942)
 */
final class VmParseUrl
{
    public const PHP_URL_SCHEME = 0;
    public const PHP_URL_HOST = 1;
    public const PHP_URL_PORT = 2;
    public const PHP_URL_USER = 3;
    public const PHP_URL_PASS = 4;
    public const PHP_URL_PATH = 5;
    public const PHP_URL_QUERY = 6;
    public const PHP_URL_FRAGMENT = 7;

    /**
     * Frame-aware component resolve — soft-null DEP+0 unless caller strict_types (#24942).
     */
    public static function resolveComponentArgForFrame(Frame $frame, int $argIndex): int
    {
        $var = $frame->calledArgs[$argIndex]->resolveIndirect();
        $fromEnum = self::tryParseUrlComponentInt($var);
        if (null !== $fromEnum) {
            return $fromEnum;
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                'parse_url(): Argument #2 ($component) must be of type int, %s given',
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_NULL === $var->type) {
            if (InternalStrictArg::isCallerStrict($frame)) {
                throw new \TypeError(
                    'parse_url(): Argument #2 ($component) must be of type int, null given'
                );
            }
            // Z_PARAM_LONG: E_DEPRECATED then coerce to 0 → PHP_URL_SCHEME (php-src url.c; #24942).
            VmNullNumberParamDeprecation::emit($frame, 'parse_url', 2, 'component', 'int');

            return self::validateUserComponentInt(0);
        }

        return self::resolveComponentArg($var);
    }

    public static function resolveComponentArg(Variable $var): int
    {
        $var = $var->resolveIndirect();
        $fromEnum = self::tryParseUrlComponentInt($var);
        if (null !== $fromEnum) {
            return $fromEnum;
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                'parse_url(): Argument #2 ($component) must be of type int, %s given',
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_NULL === $var->type) {
            // No frame: soft-coerce like Z_PARAM_LONG without strict_types (#24942).
            VmNullNumberParamDeprecation::emit(null, 'parse_url', 2, 'component', 'int');

            return self::validateUserComponentInt(0);
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(sprintf(
                'parse_url(): Argument #2 ($component) must be of type int, %s given',
                self::typeLabel($var)
            ));
        }

        return self::validateUserComponentInt($var->toInt());
    }

    /** php-src url.c — negative component returns full assoc array; out-of-range positives ValueError (#10645). */
    public static function validateUserComponentInt(int $component): int
    {
        if ($component < 0) {
            return -1;
        }
        if ($component > self::PHP_URL_FRAGMENT) {
            throw new \ValueError(sprintf(
                'parse_url(): Argument #2 ($component) must be a valid URL component identifier, %d given',
                $component
            ));
        }

        return $component;
    }

    /** @deprecated use validateUserComponentInt(); kept for JIT helpers that already validated */
    public static function normalizeRawComponentInt(int $component): int
    {
        return self::validateUserComponentInt($component);
    }

    public static function tryParseUrlComponentInt(Variable $var): ?int
    {
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return null;
        }
        $enumClass = EnumCaseSupport::enumClassForCaseVariable($var);
        if (null === $enumClass || !self::isParseUrlEnum($enumClass->name)) {
            return null;
        }
        $entry = EnumCaseSupport::enumCaseEntryForVariable($var);
        if (null === $entry || null === $entry->backingValue) {
            throw new \LogicException('ParseUrl case missing backing value');
        }

        return self::componentFromBacking($entry->backingValue->resolveIndirect()->toInt());
    }

    public static function componentFromBacking(int $backing): int
    {
        return match ($backing) {
            self::PHP_URL_SCHEME,
            self::PHP_URL_HOST,
            self::PHP_URL_PORT,
            self::PHP_URL_USER,
            self::PHP_URL_PASS,
            self::PHP_URL_PATH,
            self::PHP_URL_QUERY,
            self::PHP_URL_FRAGMENT => $backing,
            default => throw new \ValueError('Invalid ParseUrl enum value '.$backing),
        };
    }

    private static function validateComponentInt(int $component): int
    {
        return self::validateUserComponentInt($component);
    }

    private static function isParseUrlEnum(string $className): bool
    {
        return 0 === strcasecmp(ltrim($className, '\\'), 'ParseUrl');
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }
}
