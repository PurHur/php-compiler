<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomAdoptNodeRuntime;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitValueCompare;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument::adoptNode() (#29853).
 *
 * Peer: {@see JitDomImportNode} (#19212). Thin-standalone AOT uses the document-method
 * kernel bridge for DomRegistry reparent, but returns the caller-side child
 * {@see __object__*} (not the NestedJIT return) — round-tripping the same node
 * through NestedJIT object returns leaves a pointer that segfaults on property
 * fetch / appendChild (createElement helper returns a *new* object and is fine).
 *
 * User-script AOT must detach via {@see JitDomRemoveChildLiveSlots} before DomRegistry
 * adopt — appendChild only updates LiveSlots, so VmDom::detachNodeIfAttached no-ops (#19654).
 *
 * Profile gate is evaluated in this user-script lowerer (not inside the helper TU):
 * helper-runtime objects are profile-agnostic and would otherwise bake 8.4 support
 * into default-profile binaries.
 */
final class JitDomAdoptNode
{
    private const CLASS_ELEMENT = 'DOMElement';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::adoptNode() expects receiver and node');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_adopt_node_cont');

        // php-src Z_PARAM_OBJ_OF_CLASS before NYI stub (#33737, document.c).
        if (JitDomRequireDomNodeArg::guardOrAbort(
            $context,
            $args[1],
            'DOMDocument::adoptNode',
            1,
            'node'
        )) {
            return JitDomRequireDomNodeArg::boxNullResult($context);
        }

        if (!CompilerVersion::supportsDomDocumentAdoptNode()) {
            return self::emitNotYetImplemented($context);
        }

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::invokeUserScriptAdopt($context, $args[0], $args[1]);
        }

        DomAdoptNodeRuntime::ensureLinked($context);
        $document = self::loadObjectArg($context, $args[0]);
        $node = self::loadObjectArg($context, $args[1]);
        // DomRegistry reparent (documentId / detach). Discard NestedJIT object return —
        // reuse the caller-side node pointer for the call ABI (#29853).
        $context->builder->call(
            $context->lookupFunction(DomAdoptNodeRuntime::ABI_NAME),
            $document,
            $node
        );

        return self::boxObjectResult($context, $node);
    }

    /**
     * Thin-AOT adopt: LiveSlots detach (peer removeChild) then DomRegistry reparent (#19654).
     */
    private static function invokeUserScriptAdopt(
        Context $context,
        JITVariable $documentVar,
        JITVariable $nodeVar
    ): Value {
        $document = self::loadObjectArg($context, $documentVar);
        $node = self::loadObjectArg($context, $nodeVar);

        $parentVar = JitDomParentNodeProperty::fetch($context->type->object, $node);
        $bbDetach = BasicBlockHelper::append($context, 'dom_adopt_detach');
        $bbSkipDetach = BasicBlockHelper::append($context, 'dom_adopt_skip_detach');
        $bbAfterDetach = BasicBlockHelper::append($context, 'dom_adopt_after_detach');
        $parentIsNull = JitValueCompare::valueBoxIsNull($context, $parentVar);
        $context->builder->branchIf($parentIsNull, $bbSkipDetach, $bbDetach);

        $context->builder->positionAtEnd($bbDetach);
        $parent = self::loadObjectArg($context, $parentVar);
        JitDomRemoveChildLiveSlots::sync($context, $parent, $node);
        self::syncUserScriptInnerXmlAfterDetach($context, $parentVar, $nodeVar);
        DomUserScriptElementCacheLlvm::invalidateIfElement($context, $node);
        DomUserScriptLiveTagListLlvm::decrementForChildArg($context, $nodeVar);
        $context->builder->branch($bbAfterDetach);

        $context->builder->positionAtEnd($bbSkipDetach);
        $context->builder->branch($bbAfterDetach);

        $context->builder->positionAtEnd($bbAfterDetach);
        self::storeOwnerDocument($context, $node, $document);

        DomAdoptNodeRuntime::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction(DomAdoptNodeRuntime::ABI_NAME),
            $document,
            $node
        );

        return self::boxObjectResult($context, $node);
    }

    /** Wire ownerDocument on the adopted node — Wrong Document on appendChild without this (#19654). */
    private static function storeOwnerDocument(Context $context, Value $node, Value $document): void
    {
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_OWNER_DOCUMENT)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_OWNER_DOCUMENT, JITVariable::TYPE_VALUE);
        }
        $docJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $document
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($node, self::CLASS_ELEMENT, VmDom::PROP_OWNER_DOCUMENT),
            $docJit,
            JITVariable::TYPE_VALUE
        );
    }

    /**
     * Drop detached child markup from parent's INNER_XML seed (peer {@see JitDomRemoveChild}).
     */
    private static function syncUserScriptInnerXmlAfterDetach(
        Context $context,
        JITVariable $parentVar,
        JITVariable $childVar
    ): void {
        $xml = $parentVar->compileTimeDomLoadXml
            ?? JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return;
        }
        $nodes = DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
        $index = $childVar->compileTimeDomChildIndex
            ?? JitDomNodeListItem::$lastFetchedChildIndex
            ?? JitDomNodeChildProperty::$lastFetchedChildIndex
            ?? JitDomNodeChildProperty::$stickyChildEdgeChildIndex
            ?? null;
        if (null === $index) {
            $oldTag = $childVar->compileTimeDomTagName
                ?? JitDomNodeListItem::$lastFetchedTagName
                ?? JitDomNodeChildProperty::$lastFetchedTagName
                ?? JitDomNodeChildProperty::$stickyChildEdgeTagName
                ?? null;
            if (null !== $oldTag) {
                foreach ($nodes as $i => $node) {
                    if ('element' === ($node['kind'] ?? '')
                        && strtolower($oldTag) === ($node['data'] ?? null)
                    ) {
                        $index = $i;
                        break;
                    }
                }
            } elseif (1 === \count($nodes)) {
                $index = 0;
            }
        }
        if (null === $index) {
            return;
        }
        $inner = DomParseSimpleXmlJitHelper::rootInnerXmlReplaceChildAt($xml, $index, '');
        if (null !== $inner) {
            $parent = self::loadObjectArg($context, $parentVar);
            JitDomCreateElement::storeUserScriptInnerXml($context, $parent, $inner);
            JitDomLoadXMLUserScript::refreshCompileTimeXmlWithRootInner($inner, $parentVar);
        }
    }

    private static function emitNotYetImplemented(Context $context): Value
    {
        $message = 'Not yet implemented';
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError($context, 'Error', $message);
        } else {
            ErrorRaise::emitRaise($context, $message);
            $abort = $context->module->getNamedFunction('phpc_jit_abort_if_pending_error');
            if (null !== $abort) {
                $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_error'));
            } else {
                $context->builder->call($context->lookupFunction('abort'));
            }
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }

        // Unreachable after throw — satisfy call ABI with a null object box.
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

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

        throw new \LogicException('DOMDocument::adoptNode() expects object nodes');
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
