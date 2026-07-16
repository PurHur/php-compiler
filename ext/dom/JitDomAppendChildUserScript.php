<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** User-script AOT DOMDocument::appendChild() — documentElement store (#18927). */
final class JitDomAppendChildUserScript
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    private const PROP_DOCUMENT_ELEMENT = 'documentElement';

    public static function invokeDocumentAppend(
        Context $context,
        JITVariable $documentVar,
        JITVariable $childVar
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_doc_ac_store_cont');

        $document = self::loadObjectArg($context, $documentVar);
        $child = self::loadObjectArg($context, $childVar);
        $objectType = $context->type->object;
        $childJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $child);
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, self::PROP_DOCUMENT_ELEMENT),
            $childJit,
            JITVariable::TYPE_OBJECT
        );

        return self::boxObjectResult($context, $child);
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

        throw new \LogicException('DOMDocument::appendChild() expects object nodes');
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
