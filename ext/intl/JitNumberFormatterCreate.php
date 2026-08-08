<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for NumberFormatter::create() / numfmt_create() (#27385 / re-#20754).
 *
 * Allocates a NumberFormatter object and stores locale/style/pattern props for
 * later format/attr paths. Peer {@see JitTransliteratorCreate} / {@see JitMessageFormatterConstruct}.
 *
 * php-src: ext/intl/formatter/formatter_main.c — zim_NumberFormatter_create / PHP_FUNCTION(numfmt_create)
 */
final class JitNumberFormatterCreate
{
    public const PROP_LOCALE = 'locale';

    public const PROP_STYLE = 'style';

    public const PROP_PATTERN = 'pattern';

    /**
     * @param list<JITVariable> $args static create($locale, $style, $pattern = null) — no $this
     */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'numfmt_create() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }

        $localeStr = JitStringBuiltinArg::lowerZparamStr(
            $context,
            $args[0],
            'numfmt_create',
            0,
            'locale'
        );
        $styleLong = self::lowerStyleLong($context, $args[1]);

        $objectType = $context->type->object;
        $classId = $objectType->lookup('NumberFormatter');
        $obj = $objectType->allocate($classId);

        $objectType->defineProperty($classId, self::PROP_LOCALE, JITVariable::TYPE_STRING);
        $objectType->defineProperty($classId, self::PROP_STYLE, JITVariable::TYPE_NATIVE_LONG);
        $objectType->defineProperty($classId, self::PROP_PATTERN, JITVariable::TYPE_STRING);

        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'NumberFormatter', self::PROP_LOCALE),
            new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $localeStr),
            JITVariable::TYPE_STRING
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'NumberFormatter', self::PROP_STYLE),
            new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $styleLong),
            JITVariable::TYPE_NATIVE_LONG
        );

        if ($argc >= 3) {
            $patternStr = JitStringBuiltinArg::lowerZparamStr(
                $context,
                $args[2],
                'numfmt_create',
                2,
                'pattern'
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($obj, 'NumberFormatter', self::PROP_PATTERN),
                new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $patternStr),
                JITVariable::TYPE_STRING
            );
        }

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );

        return $slot;
    }

    private static function lowerStyleLong(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \TypeError(
            'numfmt_create(): Argument #2 ($style) must be of type int, '
            .JITVariable::getStringType($arg->type).' given'
        );
    }
}
