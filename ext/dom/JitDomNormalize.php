<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomNormalizeRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMNode::normalize() / DOMDocument::normalizeDocument() (#20642). */
final class JitDomNormalize
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('DOMNode::normalize() called without $this');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_normalize_cont');
        DomNormalizeRuntime::ensureNormalizeLinked($context);

        $node = self::loadObjectArg($context, $args[0]);
        $context->builder->call(
            $context->lookupFunction(DomNormalizeRuntime::ABI_NORMALIZE),
            $node
        );
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_normalize_post');
        }

        return self::boxNull($context);
    }

    public static function invokeDocument(Context $context, JITVariable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('DOMDocument::normalizeDocument() called without $this');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_normalize_document_cont');
        DomNormalizeRuntime::ensureNormalizeDocumentLinked($context);

        $document = self::loadObjectArg($context, $args[0]);
        $context->builder->call(
            $context->lookupFunction(DomNormalizeRuntime::ABI_NORMALIZE_DOCUMENT),
            $document
        );
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_normalize_document_post');
        }

        return self::boxNull($context);
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

        throw new \LogicException('DOMNode::normalize() expects an object receiver');
    }

    private static function boxNull(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
