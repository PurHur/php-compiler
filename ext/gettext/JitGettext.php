<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

use PHPCompiler\JIT\Builtin\StringGettext;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for ext/gettext builtins (#3449, #6608, #8625). */
final class JitGettext
{
    public static function gettext(Context $context, JITVariable ...$args): Value
    {
        return self::gettextNamed($context, 'gettext', ...$args);
    }

    /** _() — gettext() alias (#14966, #20209). */
    public static function underscore(Context $context, JITVariable ...$args): Value
    {
        return self::gettextNamed($context, '_', ...$args);
    }

    private static function gettextNamed(Context $context, string $function, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                $function.'() expects exactly 1 argument, '.\max(0, \count($args) - 1).' given'
            );
        }

        // Soft-null msgid on 8.4 — Zend deprecate+coerce (#21581, reverts #20209 TypeError).
        return self::writeStringResult(
            $context,
            self::callStringFn(
                $context,
                '__compiler_gettext',
                JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], $function, 0, 'msgid')
            )
        );
    }

    public static function dgettext(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(
                'dgettext() expects exactly 2 arguments, '.\max(0, \count($args) - 1).' given'
            );
        }

        return self::writeStringResult(
            $context,
            self::callStringFn(
                $context,
                '__compiler_dgettext',
                JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'dgettext', 0, 'domain'),
                JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'dgettext', 1, 'message')
            )
        );
    }

    public static function dcgettext(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'dcgettext() expects at most 3 arguments, %d given',
                $argc
            ));
        }

        $category = 3 === $argc
            ? JitLongArg::lower($context, $args[2], 'dcgettext() category')
            : $context->getTypeFromString('int64')->constInt(VmGettextNative::defaultCategory(), false);

        return self::writeStringResult(
            $context,
            self::callStringFn(
                $context,
                '__compiler_dcgettext',
                JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'dcgettext', 0, 'domain'),
                JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'dcgettext', 1, 'message'),
                $category
            )
        );
    }

    public static function ngettext(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \ArgumentCountError(
                'ngettext() expects exactly 3 arguments, '.\max(0, \count($args) - 1).' given'
            );
        }

        return self::writeStringResult(
            $context,
            self::callStringFn(
                $context,
                '__compiler_ngettext',
                JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'ngettext', 0, 'msgid1'),
                JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'ngettext', 1, 'msgid2'),
                JitLongArg::lower($context, $args[2], 'ngettext() count')
            )
        );
    }

    public static function dngettext(Context $context, JITVariable ...$args): Value
    {
        if (4 !== \count($args)) {
            throw new \ArgumentCountError(
                'dngettext() expects exactly 4 arguments, '.\max(0, \count($args) - 1).' given'
            );
        }

        return self::writeStringResult(
            $context,
            self::callStringFn(
                $context,
                '__compiler_dngettext',
                JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'dngettext', 0, 'domain'),
                JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'dngettext', 1, 'msgid1'),
                JitStringBuiltinArg::lowerTrimFamilyString($context, $args[2], 'dngettext', 2, 'msgid2'),
                JitLongArg::lower($context, $args[3], 'dngettext() count')
            )
        );
    }

    public static function dcngettext(Context $context, JITVariable ...$args): Value
    {
        if (5 !== \count($args)) {
            throw new \ArgumentCountError(
                'dcngettext() expects exactly 5 arguments, '.\max(0, \count($args) - 1).' given'
            );
        }

        return self::writeStringResult(
            $context,
            self::callStringFn(
                $context,
                '__compiler_dcngettext',
                JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'dcngettext', 0, 'domain'),
                JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'dcngettext', 1, 'msgid1'),
                JitStringBuiltinArg::lowerTrimFamilyString($context, $args[2], 'dcngettext', 2, 'msgid2'),
                JitLongArg::lower($context, $args[3], 'dcngettext() count'),
                JitLongArg::lower($context, $args[4], 'dcngettext() category')
            )
        );
    }

    public static function bindtextdomain(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(sprintf(
                'bindtextdomain() expects at most 2 arguments, %d given',
                $argc
            ));
        }

        StringGettext::ensureLinked($context);
        // Soft-null domain → '' then empty-domain ValueError in Native (#21581).
        $domain = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'bindtextdomain', 0, 'domain');
        $dir = 2 === $argc
            ? JitStringBuiltinArg::lowerNullableString($context, $args[1], 'bindtextdomain', 1, 'directory')
            : $context->getTypeFromString('__string__*')->constNull();

        return self::callValueOut($context, '__compiler_bindtextdomain', $domain, $dir);
    }

    public static function textdomain(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'textdomain() expects at most 1 argument, %d given',
                $argc
            ));
        }

        StringGettext::ensureLinked($context);
        $domain = 1 === $argc
            ? JitStringBuiltinArg::lower($context, $args[0], 'textdomain', 0, 'domain')
            : $context->getTypeFromString('__string__*')->constNull();

        return self::callValueOut($context, '__compiler_textdomain', $domain);
    }

    public static function bindTextdomainCodeset(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(sprintf(
                'bind_textdomain_codeset() expects at most 2 arguments, %d given',
                $argc
            ));
        }

        StringGettext::ensureLinked($context);
        $domain = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'bind_textdomain_codeset', 0, 'domain');
        $codeset = 2 === $argc
            ? JitStringBuiltinArg::lowerNullableString($context, $args[1], 'bind_textdomain_codeset', 1, 'codeset')
            : $context->getTypeFromString('__string__*')->constNull();

        return self::callValueOut($context, '__compiler_bind_textdomain_codeset', $domain, $codeset);
    }

    private static function callStringFn(Context $context, string $name, Value ...$params): Value
    {
        StringGettext::ensureLinked($context);

        return $context->builder->call($context->lookupFunction($name), ...$params);
    }

    private static function writeStringResult(Context $context, Value $str): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $str
        );

        return $ptr;
    }

    private static function callValueOut(Context $context, string $name, Value ...$params): Value
    {
        StringGettext::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $params[] = $ptr;
        $context->builder->call($context->lookupFunction($name), ...$params);

        return $ptr;
    }
}
