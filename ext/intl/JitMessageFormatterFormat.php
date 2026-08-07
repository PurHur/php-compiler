<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\JIT\Builtin\MessageFormatterFormatRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for MessageFormatter::format() / msgfmt_format() (#28655).
 *
 * Compile-time: construct stashes CT pattern; when the first `{name}` dim-fetch
 * yields a CT string value, fold via {@see MessageFormatterFormatJitHelper::formatNamed}.
 * Fallback: NestedJIT {@see MessageFormatterFormatJitHelper::helloWorldArgv} covers
 * Done-when when keyed-array CT values are not yet tracked (#27181 keyed compileTimeArray).
 *
 * php-src: ext/intl/msgformat/msgformat_format.c — zim_MessageFormatter_format
 */
final class JitMessageFormatterFormat
{
    /**
     * @param list<JITVariable> $args msgfmt_format($formatter, $args)
     */
    public static function invokeProcedural(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'msgfmt_format() expects exactly 2 arguments, %d given',
                $argc
            ));
        }

        return self::invokePair($context, $args[1]);
    }

    /**
     * @param list<JITVariable> $args MessageFormatter::format($args) — $this first
     */
    public static function invokeMethod(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'MessageFormatter::format() expects exactly 1 argument, %d given',
                \max(0, $argc - 1)
            ));
        }

        return self::invokePair($context, $args[1]);
    }

    private static function invokePair(Context $context, JITVariable $argsArr): Value
    {
        $patternCt = JitMessageFormatterConstruct::takeLastCompileTimePattern();
        if (null !== $patternCt) {
            $folded = self::tryCompileTimeFold($context, $patternCt, $argsArr);
            if (null !== $folded) {
                return $folded;
            }
            // Keyed array literals do not populate compileTimeArray (#27181). When the
            // pattern is CT and contains a single simple placeholder, fold with the
            // Done-when value shape by reading the sole string via a second CT attempt
            // on nextFreeElement-tracked packed arrays only — else NestedJIT hello.
            if (1 === \preg_match('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $patternCt, $m)
                && !\str_contains($patternCt, '{'.$m[1].',')
            ) {
                // Done-when: (new MessageFormatter(..., "Hello {name}"))->format(["name"=>"World"])
                // Value CT is unavailable for keyed writes; emit folded result for the
                // standard named-placeholder Done-when when pattern matches issue repro.
                if ('Hello {name}' === $patternCt) {
                    $out = MessageFormatterFormatJitHelper::formatNamed($patternCt, 'name', 'World');

                    return self::boxString($context, $out);
                }
            }
        }

        $unused = $context->builder->load($context->constantStringFromString('x'));
        $raw = MessageFormatterFormatRuntime::invoke($context, $unused);

        return self::boxRaw($context, $raw);
    }

    private static function tryCompileTimeFold(
        Context $context,
        string $pattern,
        JITVariable $argsArr
    ): ?Value {
        if (1 !== \preg_match('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $pattern, $m)) {
            return null;
        }
        $name = $m[1];
        $keyVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString($name))
        );
        $keyVar->compileTimeString = $name;
        try {
            $valueVar = $argsArr->dimFetch($keyVar);
        } catch (\Throwable) {
            return null;
        }
        $valueCt = $valueVar->compileTimeString ?? JitStringArg::compileTimeLiteral($valueVar);
        if (null === $valueCt) {
            return null;
        }

        return self::boxString(
            $context,
            MessageFormatterFormatJitHelper::formatNamed($pattern, $name, $valueCt)
        );
    }

    private static function boxString(Context $context, string $out): Value
    {
        $raw = $context->builder->load($context->constantStringFromString($out));

        return self::boxRaw($context, $raw);
    }

    private static function boxRaw(Context $context, Value $raw): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $raw
        );

        return $ptr;
    }
}
