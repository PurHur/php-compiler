<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\VmDom;
use PHPCompiler\ext\dom\JitDomAppendChildLiveSlots;
use PHPCompiler\ext\dom\JitDomCreateElement;
use PHPCompiler\ext\dom\JitDomCreateTextNode;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\Variable;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Value;

/**
 * JIT/AOT link for DOMNode::{append,prepend,replaceChildren} via PHP helpers (#18951).
 */
final class DomNodeLiveMutationRuntime
{
    public const MAX_EXTRA_ARGS = 4;

    private const HELPER_PATH = '/ext/dom/DomCreateElementJitHelper.php';

    public const ABI_CREATE_FRAGMENT = '__phpc_dom_create_document_fragment';

    public const ABI_CREATE_FRAGMENT_OBJECT = '__phpc_dom_create_document_fragment_object';

    public const ABI_APPEND_STRING = '__phpc_dom_node_append_string';

    public const ABI_PREPEND_STRING = '__phpc_dom_node_prepend_string';

    private const HELPER_CREATE_FRAGMENT = 'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::createDocumentFragmentArgv';

    private const HELPER_CREATE_FRAGMENT_OBJECT = 'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::createDocumentFragmentObjectArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_CREATE_FRAGMENT,
        self::HELPER_CREATE_FRAGMENT_OBJECT,
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendObjectArgv1',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendObjectArgv2',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendObjectArgv3',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::prependObjectArgv1',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::prependObjectArgv2',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendArgv1',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendArgv2',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendArgv3',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendStringArgv1',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::prependArgv1',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::prependArgv2',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::prependStringArgv1',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::replaceChildrenArgv0',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::replaceChildrenArgv1',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::replaceChildrenArgv2',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::replaceChildrenArgv3',
        'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::replaceChildrenArgv4',
    ];

    public static function invokeAppend(Context $context, int $extraArgCount, Variable $receiver, Variable ...$extraArgs): Value
    {
        return self::invokeMutation($context, 'append', $extraArgCount, $receiver, ...$extraArgs);
    }

    public static function invokePrepend(Context $context, int $extraArgCount, Variable $receiver, Variable ...$extraArgs): Value
    {
        return self::invokeMutation($context, 'prepend', $extraArgCount, $receiver, ...$extraArgs);
    }

    public static function invokeReplaceChildren(Context $context, int $extraArgCount, Variable $receiver, Variable ...$extraArgs): Value
    {
        return self::invokeMutation($context, 'replacechildren', $extraArgCount, $receiver, ...$extraArgs);
    }

    public static function invokeCreateDocumentFragment(Context $context, Variable $receiver): Value
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureCreateDocumentFragmentObjectBridge($context);
            $abi = self::ABI_CREATE_FRAGMENT_OBJECT;
        } else {
            self::ensureCreateFragmentBridge($context);
            $abi = self::ABI_CREATE_FRAGMENT;
        }
        $parentObj = self::receiverObject($context, $receiver);
        $result = $context->builder->call(
            $context->lookupFunction($abi),
            $parentObj
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $result
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    public static function appendObjectAbi(int $extraArgCount): string
    {
        return '__phpc_dom_node_append_object_'.$extraArgCount;
    }

    public static function prependObjectAbi(int $extraArgCount): string
    {
        return '__phpc_dom_node_prepend_object_'.$extraArgCount;
    }

    public static function appendAbi(int $extraArgCount): string
    {
        return '__phpc_dom_node_append_'.$extraArgCount;
    }

    public static function prependAbi(int $extraArgCount): string
    {
        return '__phpc_dom_node_prepend_'.$extraArgCount;
    }

    public static function appendStringAbi(): string
    {
        return self::ABI_APPEND_STRING;
    }

    public static function prependStringAbi(): string
    {
        return self::ABI_PREPEND_STRING;
    }

    public static function replaceChildrenAbi(int $extraArgCount): string
    {
        return '__phpc_dom_node_replace_children_'.$extraArgCount;
    }

    public static function replaceChildrenObjectAbi(int $extraArgCount): string
    {
        return '__phpc_dom_node_replace_children_object_'.$extraArgCount;
    }

    public static function replaceChildrenStringAbi(): string
    {
        return '__phpc_dom_node_replace_children_string';
    }

    private static function invokeMutation(
        Context $context,
        string $kind,
        int $extraArgCount,
        Variable $receiver,
        Variable ...$extraArgs
    ): Value {
        if ($extraArgCount !== \count($extraArgs)) {
            throw new \LogicException('DomNodeLiveMutationRuntime arity mismatch');
        }
        if ($extraArgCount < 0 || $extraArgCount > self::MAX_EXTRA_ARGS) {
            throw new \LogicException('DomNodeLiveMutationRuntime unsupported arity');
        }
        $minArity = 'replacechildren' === $kind ? 0 : 1;
        if ($extraArgCount < $minArity) {
            throw new \LogicException('DomNodeLiveMutationRuntime unsupported arity');
        }
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            if ('replacechildren' === $kind) {
                // Arity 0: skip NestedJIT — empty replaceChildren aborted in thin AOT (#29409).
                // Non-empty: NestedJIT + INNER_XML overwrite (saveXML reads INNER_XML).
                if (0 === $extraArgCount) {
                    self::clearChildLinkSlots($context, $receiver);
                    self::syncChildNodesLengthSlot($context, $receiver, 0);
                    self::syncUserScriptInnerXmlReplaceFromArgs($context, $receiver, []);

                    return self::nullValuePtr($context);
                }
                if (self::canUseObjectMutationBridge($extraArgs)) {
                    JitDomDocumentMethodKernel::ensureReplaceChildrenObjectBridge($context, $extraArgCount);
                    $abi = self::replaceChildrenObjectAbi($extraArgCount);
                    $llvmArgs = [self::receiverObject($context, $receiver)];
                    foreach ($extraArgs as $arg) {
                        $llvmArgs[] = self::mutationArgObject($context, $arg);
                    }
                } elseif (1 === $extraArgCount && Variable::TYPE_STRING === $extraArgs[0]->type) {
                    JitDomDocumentMethodKernel::ensureReplaceChildrenStringBridge($context);
                    $abi = self::replaceChildrenStringAbi();
                    $llvmArgs = [
                        self::receiverObject($context, $receiver),
                        JitStringArg::lower($context, $extraArgs[0], 'DOMNode::replaceChildren() string argument'),
                    ];
                } else {
                    self::ensureMutationBridge($context, $kind, $extraArgCount);
                    $abi = self::abiFor($kind, $extraArgCount);
                    $llvmArgs = [self::receiverObject($context, $receiver)];
                    foreach ($extraArgs as $arg) {
                        $llvmArgs[] = JitValueBox::valuePtrFromVariable($context, $arg);
                    }
                }
                $context->builder->call($context->lookupFunction($abi), ...$llvmArgs);
                $firstArg = $extraArgs[0];
                $lastArg = $extraArgs[\count($extraArgs) - 1];
                $firstChildObj = self::childObjectForSlotSync($context, $firstArg);
                $lastChildObj = self::childObjectForSlotSync($context, $lastArg);
                if (null !== $firstChildObj && null !== $lastChildObj) {
                    self::syncChildLinkSlots($context, $receiver, $firstChildObj, $lastChildObj);
                }
                self::syncTextContentSlotFromLiteralArgs($context, $receiver, $extraArgs);
                self::syncChildNodesLengthSlot($context, $receiver, $extraArgCount);
                self::syncUserScriptInnerXmlReplaceFromArgs($context, $receiver, $extraArgs);

                return self::nullValuePtr($context);
            }
            $orderedArgs = 'prepend' === $kind ? array_reverse($extraArgs) : $extraArgs;
            // #27476: Element single-object append — LLVM live-slot sync only (peer
            // insertBefore #27449). NestedJIT+syncChildLinkSlots+bump resets length to 1
            // on same-parent moves. Document appendChild uses DomDocumentAppendChild.
            if (
                'append' === $kind
                && 1 === $extraArgCount
                && \in_array($extraArgs[0]->type, [Variable::TYPE_OBJECT, Variable::TYPE_VALUE], true)
            ) {
                // LiveSlots increments the existing childNodes list in place so held
                // `$list = $node->childNodes` observes +1 (#29048). Do not bump here —
                // a pre-sync bumpHeld + LiveSlots +1 would double-count.
                // Note (#30271): thin-AOT LiveSlots still skips NestedJIT Wrong Document /
                // Hierarchy Request — VmDom path covers VM/JIT; AOT follow-up separately.
                JitDomAppendChildLiveSlots::sync(
                    $context,
                    self::receiverObject($context, $receiver),
                    self::mutationArgObject($context, $extraArgs[0])
                );
                self::syncTextContentSlotFromLiteralArgs($context, $receiver, $extraArgs);
                self::syncUserScriptInnerXmlFromArgs($context, $receiver, $extraArgs, $kind);

                return self::nullValuePtr($context);
            }
            $firstArg = $extraArgs[0];
            $lastArg = $extraArgs[\count($extraArgs) - 1];
            $firstChildObj = null;
            $lastChildObj = null;
            $firstElementObj = null;
            $lastElementObj = null;
            $elementCount = 0;
            foreach ($orderedArgs as $arg) {
                $appended = self::invokeUserScriptMutationArg($context, $kind, $receiver, $arg);
                if (Variable::TYPE_STRING === $arg->type) {
                    if ($arg === $firstArg) {
                        $firstChildObj = JitDomCreateTextNode::materialize($context);
                    }
                    if ($arg === $lastArg) {
                        $lastChildObj = JitDomCreateTextNode::materialize($context);
                    }
                } else {
                    if ($arg === $firstArg) {
                        $firstChildObj = $appended;
                    }
                    if ($arg === $lastArg) {
                        $lastChildObj = $appended;
                    }
                    if (null === $firstElementObj) {
                        $firstElementObj = $appended;
                    }
                    $lastElementObj = $appended;
                    ++$elementCount;
                }
            }
            if (null !== $firstChildObj && null !== $lastChildObj) {
                self::syncChildLinkSlots($context, $receiver, $firstChildObj, $lastChildObj);
            }
            if (null !== $firstElementObj && null !== $lastElementObj && $elementCount > 0) {
                self::syncElementNavSlots(
                    $context,
                    $receiver,
                    $firstElementObj,
                    $lastElementObj,
                    $elementCount
                );
            }
            self::syncTextContentSlotFromLiteralArgs($context, $receiver, $extraArgs);
            // saveXML($node) must emit element children, not only textContent (#26765).
            // Concat onto loadXML-seeded markup so appendChild keeps prior children (#26757).
            self::syncUserScriptInnerXmlFromArgs($context, $receiver, $extraArgs, $kind);
            // Held `$node->childNodes` must observe length after append/prepend (#27044).
            self::bumpChildNodesLengthSlot($context, $receiver, $extraArgCount, $kind);

            return self::nullValuePtr($context);
        }
        self::ensureMutationBridge($context, $kind, $extraArgCount);
        $abi = self::abiFor($kind, $extraArgCount);
        $llvmArgs = [self::receiverObject($context, $receiver)];
        foreach ($extraArgs as $arg) {
            $llvmArgs[] = JitValueBox::valuePtrFromVariable($context, $arg);
        }
        $context->builder->call($context->lookupFunction($abi), ...$llvmArgs);

        return self::nullValuePtr($context);
    }

    private static function invokeUserScriptMutationArg(
        Context $context,
        string $kind,
        Variable $receiver,
        Variable $arg
    ): Value {
        if (Variable::TYPE_STRING === $arg->type) {
            self::ensureStringMutationBridge($context, $kind);
            $abi = self::stringAbiFor($kind);
            $receiverObj = self::receiverObject($context, $receiver);
            $context->builder->call(
                $context->lookupFunction($abi),
                $receiverObj,
                JitStringArg::lower($context, $arg, 'DOMNode::'.$kind.'() string argument')
            );

            return $receiverObj;
        }
        if (\in_array($arg->type, [Variable::TYPE_OBJECT, Variable::TYPE_VALUE], true)) {
            self::ensureObjectMutationBridge($context, $kind, 1);
            $abi = self::objectAbiFor($kind, 1);
            $context->builder->call(
                $context->lookupFunction($abi),
                self::receiverObject($context, $receiver),
                self::mutationArgObject($context, $arg)
            );

            return self::mutationArgObject($context, $arg);
        }
        self::ensureMutationBridge($context, $kind, 1);
        $abi = self::abiFor($kind, 1);
        $context->builder->call(
            $context->lookupFunction($abi),
            self::receiverObject($context, $receiver),
            JitValueBox::valuePtrFromVariable($context, $arg)
        );

        return self::receiverObject($context, $receiver);
    }

    /** @param list<Variable> $extraArgs */
    private static function canUseObjectMutationBridge(array $extraArgs): bool
    {
        if ([] === $extraArgs) {
            return false;
        }
        foreach ($extraArgs as $arg) {
            if (!\in_array($arg->type, [Variable::TYPE_OBJECT, Variable::TYPE_VALUE], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mirror live child links into LLVM property slots for user-script AOT reads (#18951, #19431).
     */
    private static function syncChildLinkSlots(
        Context $context,
        Variable $receiver,
        Value $firstChildObj,
        Value $lastChildObj
    ): void {
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, Variable::TYPE_VALUE);
            }
        }

        $receiverObj = self::receiverObject($context, $receiver);
        $firstJit = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $firstChildObj);
        $lastJit = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $lastChildObj);
        $objectType->propertyStore(
            $objectType->propertySlotFor($receiverObj, 'DOMNode', VmDom::PROP_FIRST_CHILD),
            $firstJit,
            Variable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($receiverObj, 'DOMNode', VmDom::PROP_LAST_CHILD),
            $lastJit,
            Variable::TYPE_VALUE
        );

        // #21687: parentNode on DOMElement layout (elements are allocated as DOMElement).
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, Variable::TYPE_VALUE);
        }
        $parentJit = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $receiverObj);
        foreach ([$firstChildObj, $lastChildObj] as $childObj) {
            $objectType->propertyStore(
                $objectType->propertySlotFor($childObj, 'DOMElement', VmDom::PROP_PARENT_NODE),
                $parentJit,
                Variable::TYPE_VALUE
            );
        }
    }

    /** Null firstChild/lastChild after replaceChildren() with no args (#29409). */
    private static function clearChildLinkSlots(Context $context, Variable $receiver): void
    {
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, Variable::TYPE_VALUE);
            }
        }
        $receiverObj = self::receiverObject($context, $receiver);
        $nullSlot = JitValueBox::alloc($context);
        $nullPtr = JitValueBox::pointer($context, $nullSlot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);
        $nullVar = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $nullPtr)
        );
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            $objectType->propertyStore(
                $objectType->propertySlotFor($receiverObj, 'DOMNode', $prop),
                $nullVar,
                Variable::TYPE_VALUE
            );
        }
    }

    /**
     * Overwrite {@see VmDom::PROP_USER_SCRIPT_INNER_XML} for replaceChildren (#29409).
     *
     * Append/prepend concat onto the loadXML-seeded slot (#26765); replaceChildren must
     * replace that markup so pure-LLVM saveXML($node) matches Zend.
     *
     * @param list<Variable> $extraArgs
     */
    private static function syncUserScriptInnerXmlReplaceFromArgs(
        Context $context,
        Variable $receiver,
        array $extraArgs
    ): void {
        $receiverObj = self::receiverObject($context, $receiver);
        if ([] === $extraArgs) {
            JitDomCreateElement::storeUserScriptInnerXml($context, $receiverObj, '');

            return;
        }
        $pieces = [];
        foreach ($extraArgs as $arg) {
            if (Variable::TYPE_STRING === $arg->type) {
                $lit = $arg->compileTimeString ?? null;
                if (null === $lit) {
                    return;
                }
                // Text nodes are serialized as escaped text content (simple literals only).
                $pieces[] = htmlspecialchars($lit, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                continue;
            }
            if (!\in_array($arg->type, [Variable::TYPE_OBJECT, Variable::TYPE_VALUE], true)) {
                return;
            }
            $tag = $arg->compileTimeDomTagName ?? null;
            if (null === $tag || '' === $tag) {
                return;
            }
            $pieces[] = '<'.$tag.'/>';
        }
        JitDomCreateElement::storeUserScriptInnerXml($context, $receiverObj, implode('', $pieces));
    }

    /**
     * Mirror ParentNode / NonDocumentTypeChildNode element-nav slots (#19431).
     */
    private static function syncElementNavSlots(
        Context $context,
        Variable $receiver,
        Value $firstElementObj,
        Value $lastElementObj,
        int $elementCount
    ): void {
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        foreach ([
            VmDom::PROP_FIRST_ELEMENT_CHILD,
            VmDom::PROP_LAST_ELEMENT_CHILD,
            VmDom::PROP_CHILD_ELEMENT_COUNT,
            VmDom::PROP_NEXT_ELEMENT_SIBLING,
            VmDom::PROP_PREVIOUS_ELEMENT_SIBLING,
        ] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty(
                    $elementClassId,
                    $prop,
                    VmDom::PROP_CHILD_ELEMENT_COUNT === $prop
                        ? Variable::TYPE_NATIVE_LONG
                        : Variable::TYPE_VALUE
                );
            }
        }

        $receiverObj = self::receiverObject($context, $receiver);
        $firstJit = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $firstElementObj);
        $lastJit = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $lastElementObj);
        $objectType->propertyStore(
            $objectType->propertySlotFor($receiverObj, 'DOMElement', VmDom::PROP_FIRST_ELEMENT_CHILD),
            $firstJit,
            Variable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($receiverObj, 'DOMElement', VmDom::PROP_LAST_ELEMENT_CHILD),
            $lastJit,
            Variable::TYPE_VALUE
        );
        $countJit = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt($elementCount, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($receiverObj, 'DOMElement', VmDom::PROP_CHILD_ELEMENT_COUNT),
            $countJit,
            Variable::TYPE_NATIVE_LONG
        );
        if ($firstElementObj === $lastElementObj || $elementCount < 2) {
            return;
        }
        $objectType->propertyStore(
            $objectType->propertySlotFor($firstElementObj, 'DOMElement', VmDom::PROP_NEXT_ELEMENT_SIBLING),
            $lastJit,
            Variable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($lastElementObj, 'DOMElement', VmDom::PROP_PREVIOUS_ELEMENT_SIBLING),
            $firstJit,
            Variable::TYPE_VALUE
        );
    }

    private static function syncChildNodesLengthSlot(Context $context, Variable $receiver, int $length): void
    {
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        if (!$objectType->hasProperty($nodeClassId, VmDom::PROP_CHILD_NODES)) {
            $objectType->defineProperty($nodeClassId, VmDom::PROP_CHILD_NODES, Variable::TYPE_VALUE);
        }
        $listClassId = $objectType->lookup('DOMNodeList');
        if (!$objectType->hasProperty($listClassId, 'length')) {
            $objectType->defineProperty($listClassId, 'length', Variable::TYPE_NATIVE_LONG);
        }

        $receiverObj = self::receiverObject($context, $receiver);
        $listObj = $objectType->allocate($listClassId);
        $objectType->markObjectConstructed($listObj);
        $lengthVar = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt($length, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($listObj, 'DOMNodeList', 'length'),
            $lengthVar,
            Variable::TYPE_NATIVE_LONG
        );
        $listJit = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $listObj);
        $objectType->propertyStore(
            $objectType->propertySlotFor($receiverObj, 'DOMNode', VmDom::PROP_CHILD_NODES),
            $listJit,
            Variable::TYPE_VALUE
        );
    }

    /**
     * Increment in-place length on a held childNodes DOMNodeList object (#28509, #27044, #29048).
     *
     * Unlike {@see bumpChildNodesLengthSlot}, never allocates / absolute-sets via
     * syncChildNodesLengthSlot — that path clobbers move-path writeChildNodesList (#27476).
     *
     * loadXML / LiveSlots store childNodes as TYPE_VALUE; unwrap the object so the
     * bump reaches the same list a user held via `$list = $node->childNodes`.
     */
    private static function bumpHeldObjectChildNodesLength(
        Context $context,
        Variable $receiver,
        int $delta
    ): void {
        if ($delta <= 0) {
            return;
        }
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        $listClassId = $objectType->lookup('DOMNodeList');
        if (!$objectType->hasProperty($nodeClassId, VmDom::PROP_CHILD_NODES)) {
            return;
        }
        if (!$objectType->hasProperty($listClassId, 'length')) {
            return;
        }
        $receiverObj = self::receiverObject($context, $receiver);
        $listVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $receiverObj,
            'DOMNode',
            VmDom::PROP_CHILD_NODES,
            $nodeClassId
        );
        $listObj = null;
        if (Variable::TYPE_OBJECT === $listVar->type) {
            $listObj = $context->helper->loadValue($listVar);
        } elseif (Variable::TYPE_VALUE === $listVar->type) {
            // TYPE_VALUE box (loadXML / #27216) — same live object as `$list = childNodes`.
            $listObj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $listVar)
            );
        } else {
            return;
        }
        self::bumpExistingChildNodesLength(
            $context,
            $objectType,
            $listClassId,
            $listObj,
            $delta
        );
    }

    /**
     * Increment in-place length on the existing childNodes DOMNodeList (#27044).
     *
     * Replacing the list object would leave held `$list = $node->childNodes` references
     * at the pre-mutation length; php-src nodelist.c updates the live collection.
     */
    private static function bumpChildNodesLengthSlot(
        Context $context,
        Variable $receiver,
        int $delta,
        string $kind
    ): void {
        if ($delta <= 0 || 'replacechildren' === $kind) {
            return;
        }
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        $listClassId = $objectType->lookup('DOMNodeList');
        $hadChildNodesProp = $objectType->hasProperty($nodeClassId, VmDom::PROP_CHILD_NODES);
        if (!$hadChildNodesProp) {
            // Seed as VALUE so unset slots stay NULL-tagged (#27216). TYPE_OBJECT
            // made loadValue return a garbage pointer and length stores segfaulted.
            $objectType->defineProperty($nodeClassId, VmDom::PROP_CHILD_NODES, Variable::TYPE_VALUE);
        }
        if (!$objectType->hasProperty($listClassId, 'length')) {
            $objectType->defineProperty($listClassId, 'length', Variable::TYPE_NATIVE_LONG);
        }

        // First touch: no live list yet — allocate length=delta (createElement path).
        if (!$hadChildNodesProp) {
            self::syncChildNodesLengthSlot($context, $receiver, $delta);

            return;
        }

        $receiverObj = self::receiverObject($context, $receiver);
        $listVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $receiverObj,
            'DOMNode',
            VmDom::PROP_CHILD_NODES,
            $nodeClassId
        );
        // Only bump in-place for a concrete object list (#27044 held $list refs).
        if (Variable::TYPE_OBJECT === $listVar->type) {
            self::bumpExistingChildNodesLength(
                $context,
                $objectType,
                $listClassId,
                $context->helper->loadValue($listVar),
                $delta
            );

            return;
        }

        self::syncChildNodesLengthSlot($context, $receiver, $delta);
    }

    /**
     * @param \PHPCompiler\JIT\Builtin\Type\Object_ $objectType
     */
    private static function bumpExistingChildNodesLength(
        Context $context,
        $objectType,
        int $listClassId,
        Value $listObj,
        int $delta
    ): void {
        $lengthVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $listObj,
            'DOMNodeList',
            'length',
            $listClassId
        );
        $i64 = $context->getTypeFromString('int64');
        $current = $context->helper->loadValue($lengthVar);
        $next = $context->builder->add($current, $i64->constInt($delta, false));
        $nextJit = new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $next);
        $objectType->propertyStore(
            $objectType->propertySlotFor($listObj, 'DOMNodeList', 'length'),
            $nextJit,
            Variable::TYPE_NATIVE_LONG
        );
    }

    /** @param list<Variable> $extraArgs */
    private static function syncTextContentSlotFromLiteralArgs(
        Context $context,
        Variable $receiver,
        array $extraArgs
    ): void {
        $parts = [];
        foreach ($extraArgs as $arg) {
            if (Variable::TYPE_STRING !== $arg->type) {
                continue;
            }
            $lit = $arg->compileTimeString ?? null;
            if (null === $lit) {
                return;
            }
            $parts[] = $lit;
        }
        if ([] === $parts) {
            return;
        }
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($classId, 'textContent')) {
            $objectType->defineProperty($classId, 'textContent', Variable::TYPE_STRING);
        }
        $receiverObj = self::receiverObject($context, $receiver);
        $textStr = $context->builder->load($context->constantStringFromString(implode('', $parts)));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $textStr
        );
        $propVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $owned);
        $objectType->propertyStore(
            $objectType->propertySlotFor($receiverObj, 'DOMElement', 'textContent'),
            $propVar,
            Variable::TYPE_STRING
        );
    }

    /**
     * Write ParentNode append/prepend args into __phpcUserScriptInnerXml for AOT saveXML (#26765).
     *
     * Compile-time pieces (string literals + createElement tags) are concatenated onto the
     * existing slot at runtime so loadXML-seeded children survive appendChild (#26757).
     * Empty prior slot keeps #26765 empty-root append behaviour.
     *
     * @param list<Variable> $extraArgs document order (caller passes original append args)
     */
    private static function syncUserScriptInnerXmlFromArgs(
        Context $context,
        Variable $receiver,
        array $extraArgs,
        string $kind = 'append'
    ): void {
        if ([] === $extraArgs) {
            return;
        }
        $pieces = [];
        foreach ($extraArgs as $arg) {
            if (Variable::TYPE_STRING === $arg->type) {
                $lit = $arg->compileTimeString ?? null;
                if (null === $lit) {
                    return;
                }
                $pieces[] = $lit;
                continue;
            }
            if (!\in_array($arg->type, [Variable::TYPE_OBJECT, Variable::TYPE_VALUE], true)) {
                return;
            }
            $tag = $arg->compileTimeDomTagName ?? null;
            if (null === $tag || '' === $tag) {
                return;
            }
            $pieces[] = '<'.$tag.'/>';
        }
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($classId, VmDom::PROP_USER_SCRIPT_INNER_XML)) {
            $objectType->defineProperty($classId, VmDom::PROP_USER_SCRIPT_INNER_XML, Variable::TYPE_STRING);
        }
        $receiverObj = self::receiverObject($context, $receiver);
        $deltaStr = $context->builder->load(
            $context->constantStringFromString(implode('', $pieces))
        );
        $existingVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $receiverObj,
            'DOMElement',
            VmDom::PROP_USER_SCRIPT_INNER_XML,
            $classId
        );
        $existingStr = $context->helper->loadValue($existingVar);
        $merged = 'prepend' === $kind
            ? JitStringConcat::concat($context, $deltaStr, $existingStr)
            : JitStringConcat::concat($context, $existingStr, $deltaStr);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $merged
        );
        $propVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $owned);
        $objectType->propertyStore(
            $objectType->propertySlotFor($receiverObj, 'DOMElement', VmDom::PROP_USER_SCRIPT_INNER_XML),
            $propVar,
            Variable::TYPE_STRING
        );
    }

    private static function ensureStringMutationBridge(Context $context, string $kind): void
    {
        match ($kind) {
            'append' => JitDomDocumentMethodKernel::ensureAppendStringBridge($context),
            'prepend' => JitDomDocumentMethodKernel::ensurePrependStringBridge($context),
            default => throw new \LogicException('DOM string live-mutation bridge unsupported for '.$kind),
        };
    }

    private static function stringAbiFor(string $kind): string
    {
        return match ($kind) {
            'append' => self::appendStringAbi(),
            'prepend' => self::prependStringAbi(),
            default => throw new \LogicException('Unknown DOM string live-mutation kind'),
        };
    }

    private static function ensureObjectMutationBridge(Context $context, string $kind, int $extraArgCount): void
    {
        match ($kind) {
            'append' => JitDomDocumentMethodKernel::ensureAppendObjectBridge($context, $extraArgCount),
            'prepend' => JitDomDocumentMethodKernel::ensurePrependObjectBridge($context, $extraArgCount),
            default => throw new \LogicException('DOM object live-mutation bridge unsupported for '.$kind),
        };
    }

    private static function mutationArgObject(Context $context, Variable $arg): Value
    {
        if (Variable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOM object live-mutation arg must be object or value box');
    }

    private static function objectAbiFor(string $kind, int $extraArgCount): string
    {
        return match ($kind) {
            'append' => self::appendObjectAbi($extraArgCount),
            'prepend' => self::prependObjectAbi($extraArgCount),
            default => throw new \LogicException('Unknown DOM object live-mutation kind'),
        };
    }

    private static function ensureMutationBridge(Context $context, string $kind, int $extraArgCount): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            match ($kind) {
                'append' => JitDomDocumentMethodKernel::ensureAppendBridge($context, $extraArgCount),
                'prepend' => JitDomDocumentMethodKernel::ensurePrependBridge($context, $extraArgCount),
                'replacechildren' => JitDomDocumentMethodKernel::ensureReplaceChildrenBridge($context, $extraArgCount),
                default => throw new \LogicException('Unknown DOM live-mutation kind'),
            };

            return;
        }

        $abi = self::abiFor($kind, $extraArgCount);
        $entryBlock = 'dom_'.$kind.'_bridge_'.$extraArgCount;
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryBlock)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);
        $helperPath = self::HELPER_PATH;
        JitVmHelperLink::ensureBridge(
            $context,
            $abi,
            $entryBlock,
            self::bridgeParamTypes($context, $extraArgCount),
            $context->context->voidType(),
            self::helperLogicalFor($kind, $extraArgCount),
            $helperPath,
            self::COMPILED_HELPERS,
            '#18951'
        );
    }

    private static function ensureCreateFragmentBridge(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureCreateDocumentFragmentBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_CREATE_FRAGMENT,
            'dom_create_document_fragment_bridge',
            [$objPtr],
            $objPtr,
            self::HELPER_CREATE_FRAGMENT,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18951'
        );
    }

    /** @return list<\PHPLLVM\Type> */
    private static function bridgeParamTypes(Context $context, int $extraArgCount): array
    {
        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $paramTypes = [$objPtr];
        for ($i = 0; $i < $extraArgCount; ++$i) {
            $paramTypes[] = $valuePtr;
        }

        return $paramTypes;
    }

    private static function receiverObject(Context $context, Variable $receiver): Value
    {
        if (Variable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (Variable::TYPE_VALUE === $receiver->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException('DOM live-mutation receiver must be object or value box');
    }

    private static function nullValuePtr(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    private static function abiFor(string $kind, int $extraArgCount): string
    {
        return match ($kind) {
            'append' => self::appendAbi($extraArgCount),
            'prepend' => self::prependAbi($extraArgCount),
            'replacechildren' => self::replaceChildrenAbi($extraArgCount),
            default => throw new \LogicException('Unknown DOM live-mutation kind'),
        };
    }

    private static function childObjectForSlotSync(Context $context, Variable $arg): ?Value
    {
        if (Variable::TYPE_STRING === $arg->type) {
            return JitDomCreateTextNode::materialize($context);
        }
        if (\in_array($arg->type, [Variable::TYPE_OBJECT, Variable::TYPE_VALUE], true)) {
            return self::mutationArgObject($context, $arg);
        }

        return null;
    }

    private static function helperLogicalFor(string $kind, int $extraArgCount): string
    {
        $suffix = 'Argv'.$extraArgCount;

        return match ($kind) {
            'append' => 'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::append'.$suffix,
            'prepend' => 'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::prepend'.$suffix,
            'replacechildren' => 'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::replaceChildren'.$suffix,
            default => throw new \LogicException('Unknown DOM live-mutation kind'),
        };
    }
}
