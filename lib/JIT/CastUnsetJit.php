<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * (unset) cast lowering — extracted from CastHelper (#10244).
 */
final class CastUnsetJit
{
    public static function emit(Context $context, Variable $src): Variable
    {
        if (null !== $src->valueBoxAliasPtr) {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::normalizeValuePtr($context, $src->valueBoxAliasPtr)
            );
        }
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }
}
