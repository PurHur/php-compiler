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
 * DOMNode::appendChild() — user-script AOT (#18478, #18927, #27044, #27480).
 *
 * Prefer ParentNode::append lowering (live mutation + childNodes length). The
 * historic JitDomAppendChild stub only wrote parentNode; RuntimeIndirect on
 * Element class_ids aborts when Document/Node are the only candidates (#19208).
 *
 * Capture the child {@see __object__*} before live-slot sync and re-box it for
 * the return value (peer insertBefore). Re-reading `$args[1]` after sync via
 * {@see JitValueBox::valuePtrFromVariable} observes a null box — appendChild
 * then returns NULL and `$a->parentNode` after replaceChild segfaults (#27480).
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
            // Pin object identity before ParentNode::append mutates slots (#27480).
            $childObj = self::loadChildObject($context, $args[1]);
            $append = new DomNodeAppend();
            $append->call($context, ...$args);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_ac_ret_cont');

            return self::boxObjectResult($context, $childObj);
        }

        return JitDomAppendChild::invoke($context, ...$args);
    }

    private static function loadChildObject(Context $context, Variable $child): Value
    {
        if (Variable::TYPE_OBJECT === $child->type) {
            return $context->helper->loadValue($child);
        }
        if (Variable::TYPE_VALUE === $child->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $child)
            );
        }

        throw new \LogicException('DOMNode::appendChild() child must be object or value box');
    }

    private static function boxObjectResult(Context $context, Value $object): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $object
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
