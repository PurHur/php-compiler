<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\DomGetElementsByTagNameRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMDocument::getElementsByTagName() (#18461). */
final class JitDomGetElementsByTagName
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::getElementsByTagName() expects receiver and tag name');
        }

        if (JitDomGetElementsByTagNameUserScript::shouldUse($context)) {
            $us = JitDomGetElementsByTagNameUserScript::tryInvoke($context, ...$args);
            if (null !== $us) {
                return $us;
            }
        }

        DomGetElementsByTagNameRuntime::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_gebt_call_cont');

        $document = self::loadObjectArg($context, $args[0]);
        $tagStr = self::loadStringArg($context, $args[1]);
        $listObj = $context->builder->call(
            $context->lookupFunction(DomGetElementsByTagNameRuntime::ABI_NAME),
            $document,
            $tagStr
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_gebt_post_call');

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::boxObjectResult($context, $listObj);
        }

        return $listObj;
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

        throw new \LogicException('DOMDocument::getElementsByTagName() receiver must be an object');
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $arg)
        );
    }
}
