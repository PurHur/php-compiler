<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for MessageFormatter::__construct() — persist locale/pattern (#28655).
 *
 * Stores props for runtime + {@see $lastCompileTimePattern} for AOT CT fold of format().
 *
 * php-src: ext/intl/msgformat/msgformat_class.c — zim_MessageFormatter___construct
 */
final class JitMessageFormatterConstruct
{
    public static ?string $lastCompileTimePattern = null;

    public static function takeLastCompileTimePattern(): ?string
    {
        $p = self::$lastCompileTimePattern;
        self::$lastCompileTimePattern = null;

        return $p;
    }

    /**
     * @param list<JITVariable> $args $this, $locale, $pattern
     */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'MessageFormatter::__construct() expects exactly 2 arguments, %d given',
                \max(0, $argc - 1)
            ));
        }

        $receiver = self::objectReceiver($context, $args[0]);
        $localeStr = JitStringBuiltinArg::lowerZparamStr(
            $context,
            $args[1],
            'MessageFormatter::__construct',
            0,
            'locale'
        );
        $patternStr = JitStringBuiltinArg::lowerZparamStr(
            $context,
            $args[2],
            'MessageFormatter::__construct',
            1,
            'pattern'
        );
        $patternLit = JitStringArg::compileTimeLiteral($args[2]);
        self::$lastCompileTimePattern = $patternLit;

        $objPtr = $context->helper->loadValue($receiver);
        $objectType = $context->type->object;
        $classId = $objectType->lookup('MessageFormatter');
        $objectType->defineProperty(
            $classId,
            MessageFormatterFormatJitHelper::PROP_LOCALE,
            JITVariable::TYPE_STRING
        );
        $objectType->defineProperty(
            $classId,
            MessageFormatterFormatJitHelper::PROP_PATTERN,
            JITVariable::TYPE_STRING
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor(
                $objPtr,
                'MessageFormatter',
                MessageFormatterFormatJitHelper::PROP_LOCALE
            ),
            new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $localeStr),
            JITVariable::TYPE_STRING
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor(
                $objPtr,
                'MessageFormatter',
                MessageFormatterFormatJitHelper::PROP_PATTERN
            ),
            new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $patternStr),
            JITVariable::TYPE_STRING
        );

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }

    private static function objectReceiver(Context $context, JITVariable $receiver): JITVariable
    {
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            return $receiver;
        }
        if (JITVariable::TYPE_VALUE === $receiver->type) {
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );

            return new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj);
        }

        throw new \LogicException(
            'MessageFormatter::__construct() receiver must be an object, got '
            .JITVariable::getStringType($receiver->type)
        );
    }
}
