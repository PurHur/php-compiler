<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ExceptionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Throwable/Exception::__toString() — JIT/AOT (#26796).
 *
 * Returns PROP_STRING when emitThrow seeded a redacted body; otherwise a safe fallback.
 * Avoids NestedJIT and message property reads that abort under thin user-script AOT.
 *
 * php-src: Zend/zend_exceptions.c — Exception::__toString
 */
final class ExceptionToString implements Call
{
    private static int $seq = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('__toString() requires an object receiver');
        }
        $tag = 'ets'.(string) (++self::$seq);
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $decl = self::declaringClass($context);
        $fallback = $context->builder->load($context->constantStringFromString(
            "Exception:\nStack trace:\n#0 {main}"
        ));
        $slot = JitValueBox::alloc($context);

        try {
            $cid = $context->type->object->lookup($decl);
        } catch (\Throwable) {
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                JitValueBox::pointer($context, $slot),
                $fallback
            );

            return $slot;
        }
        if (!$context->type->object->hasProperty($cid, ExceptionSupport::PROP_STRING)) {
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                JitValueBox::pointer($context, $slot),
                $fallback
            );

            return $slot;
        }

        $prop = $context->type->object->propertyFetch($obj, $decl, ExceptionSupport::PROP_STRING);
        $cached = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $prop)
        );
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($cached, $map['length']));
        $i64 = $context->getTypeFromString('int64');
        $nonEmpty = $context->builder->icmp(Builder::INT_NE, $len, $i64->constInt(0, false));
        $useBb = BasicBlockHelper::append($context, 'exc_ts_use_'.$tag);
        $defBb = BasicBlockHelper::append($context, 'exc_ts_def_'.$tag);
        $doneBb = BasicBlockHelper::append($context, 'exc_ts_done_'.$tag);
        $context->builder->branchIf($nonEmpty, $useBb, $defBb);

        $context->builder->positionAtEnd($useBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $cached
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($defBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $fallback
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $slot;
    }

    private static function declaringClass(Context $context): string
    {
        foreach (['Exception', 'Error'] as $candidate) {
            try {
                $cid = $context->type->object->lookup($candidate);
            } catch (\Throwable) {
                continue;
            }
            if ($context->type->object->hasProperty($cid, ExceptionSupport::PROP_STRING)) {
                return $candidate;
            }
        }

        return 'Exception';
    }
}
