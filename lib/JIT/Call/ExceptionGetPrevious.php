<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ExceptionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Throwable/Exception::getPrevious() — read previous property (#30895).
 *
 * php-src: Zend/zend_exceptions.c — zim_Exception_getPrevious / zim_Error_getPrevious
 * VM SSOT: {@see \PHPCompiler\VM\Builtin\ExceptionGetPrevious}
 */
final class ExceptionGetPrevious implements Call
{
    private static int $seq = 0;

    public function __construct(
        private readonly string $declaringRoot = 'Exception',
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('getPrevious() requires an object receiver');
        }
        // php-src: ZEND_PARSE_PARAMETERS (0 args); $args[0] is $this (#30895)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    '%s::getPrevious() expects exactly 0 arguments, %d given',
                    $this->declaringRoot,
                    $userArgCount
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'exc_getprevious_argc_cont');
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $slot)
            );

            return $slot;
        }
        $tag = 'egp'.(string) (++self::$seq);
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $prevVar = $context->type->object->propertyFetch(
            $obj,
            $this->declaringRoot,
            ExceptionSupport::PROP_PREVIOUS
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $prevPtr = JitValueBox::valuePtrFromVariable($context, $prevVar);
        $typeByte = $context->builder->load(
            $context->builder->structGep($prevPtr, $context->structFieldMap['__value__']['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\JIT\Variable::TYPE_NULL, false)
        );
        $nullBb = BasicBlockHelper::append($context, 'exc_gprev_null_'.$tag);
        $copyBb = BasicBlockHelper::append($context, 'exc_gprev_copy_'.$tag);
        $doneBb = BasicBlockHelper::append($context, 'exc_gprev_done_'.$tag);
        $context->builder->branchIf($isNull, $nullBb, $copyBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($copyBb);
        JitValueBox::copyFromPointer($context, $slot, $prevPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ptr;
    }
}
