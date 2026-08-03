<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomAppendChild;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DOMNode::appendChild() — user-script AOT (#18478, #18927, #27044).
 *
 * Prefer ParentNode::append lowering (live mutation + childNodes length). The
 * historic JitDomAppendChild stub only wrote parentNode; RuntimeIndirect on
 * Element class_ids aborts when Document/Node are the only candidates (#19208).
 */
final class DomNodeAppendChild implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_ac_invoke_cont');
        if (
            JitDomDocumentMethodKernel::shouldUse($context)
            && \count($args) >= 2
        ) {
            $append = new DomNodeAppend();
            $append->call($context, ...$args);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_ac_ret_cont');

            return self::boxChildResult($context, $args[1]);
        }

        return JitDomAppendChild::invoke($context, ...$args);
    }

    private static function boxChildResult(Context $context, Variable $child): Value
    {
        if (Variable::TYPE_OBJECT === $child->type) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $ptr,
                $context->helper->loadValue($child)
            );

            return JitValueBox::normalizeValuePtr($context, $ptr);
        }

        return JitValueBox::valuePtrFromVariable($context, $child);
    }
}
