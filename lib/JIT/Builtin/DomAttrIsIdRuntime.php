<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DOMAttr::isId() thin-AOT link (#29884).
 *
 * Returns int1 (peer DomLivingApiRuntime::invokeIsEqualNode / contains).
 */
final class DomAttrIsIdRuntime
{
    public const ABI_NAME = '__phpc_dom_attr_is_id';

    public static function invoke(Context $context, Variable $receiver): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_attr_isid_slots');
        $receiverLlvm = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::valuePtrFromVariable($context, $receiver)
        );
        JitDomDocumentMethodKernel::ensureAttrIsIdBridge($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_NAME),
            $receiverLlvm
        );
    }
}
