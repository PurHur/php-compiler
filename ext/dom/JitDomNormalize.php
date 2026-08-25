<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomNormalizeRuntime;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMNode::normalize() / DOMDocument::normalizeDocument() (#20642).
 *
 * User-script AOT (#34749 / re-#33438): NestedJIT DomNormalizeJitHelper SIGSEGVs in
 * __object__load_value_slot on LiveSlots objects — skip NestedJIT and merge via
 * {@see JitDomNormalizeLiveSlots} only.
 */
final class JitDomNormalize
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('DOMNode::normalize() called without $this');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_normalize_cont');
        $node = self::loadObjectArg($context, $args[0]);

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            // NestedJIT DomRegistry path crashes on unregistered stand-ins (#34749).
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_normalize_live_slots');
            JitDomNormalizeLiveSlots::sync($context, $node);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_normalize_post');

            return self::boxNull($context);
        }

        DomNormalizeRuntime::ensureNormalizeLinked($context);
        $context->builder->call(
            $context->lookupFunction(DomNormalizeRuntime::ABI_NORMALIZE),
            $node
        );

        return self::boxNull($context);
    }

    public static function invokeDocument(Context $context, JITVariable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('DOMDocument::normalizeDocument() called without $this');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_normalize_document_cont');
        $document = self::loadObjectArg($context, $args[0]);

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            // Same NestedJIT crash as normalize() (#34749 / re-#27260).
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_normalize_document_live');
            self::syncDocumentElementLiveSlots($context, $document);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_normalize_document_post');

            return self::boxNull($context);
        }

        DomNormalizeRuntime::ensureNormalizeDocumentLinked($context);
        $context->builder->call(
            $context->lookupFunction(DomNormalizeRuntime::ABI_NORMALIZE_DOCUMENT),
            $document
        );

        return self::boxNull($context);
    }

    /**
     * normalizeDocument ≡ normalize(documentElement) for LiveSlots trees (#27260).
     */
    private static function syncDocumentElementLiveSlots(Context $context, Value $document): void
    {
        $objectType = $context->type->object;
        $docClassId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_DOCUMENT_ELEMENT)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_DOCUMENT_ELEMENT, JITVariable::TYPE_OBJECT);
        }
        $rootVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $document,
            'DOMDocument',
            VmDom::PROP_DOCUMENT_ELEMENT,
            $docClassId
        );
        $root = $context->helper->loadValue($rootVar);
        $objPtrTy = $context->getTypeFromString('__object__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $root, $objPtrTy->constNull());
        $bbSync = BasicBlockHelper::append($context, 'dom_norm_doc_sync');
        $bbDone = BasicBlockHelper::append($context, 'dom_norm_doc_done');
        $context->builder->branchIf($isNull, $bbDone, $bbSync);
        $context->builder->positionAtEnd($bbSync);
        JitDomNormalizeLiveSlots::sync($context, $root);
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbDone);
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
