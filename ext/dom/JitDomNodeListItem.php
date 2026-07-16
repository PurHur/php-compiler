<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\DomNodeListItemRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMNodeList::item() (#18493). */
final class JitDomNodeListItem
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMNodeList::item() expects receiver and index');
        }

        if (JitDomNodeListItemUserScript::shouldUse($context)) {
            $us = JitDomNodeListItemUserScript::tryInvoke($context, ...$args);
            if (null !== $us) {
                return $us;
            }
        }

        DomNodeListItemRuntime::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_nodelist_item_call_cont');

        $nodeList = self::loadObjectArg($context, $args[0]);
        $index = self::loadIntArg($context, $args[1]);
        $result = $context->builder->call(
            $context->lookupFunction(DomNodeListItemRuntime::ABI_NAME),
            $nodeList,
            $index
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_nodelist_item_post_call');

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return JitValueBox::normalizeValuePtr($context, $result);
        }

        return $result;
    }

    private static function loadObjectArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMNodeList::item() receiver must be an object');
    }

    private static function loadIntArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type || JITVariable::TYPE_INTEGER === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMNodeList::item() index must be an integer');
    }
}
