<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\DomUserScriptLiveTagListLlvm;
use PHPCompiler\ext\dom\VmDom;
use PHPCompiler\ext\dom\DomExceptionConstants;
use PHPCompiler\ext\dom\DomParseSimpleXmlJitHelper;
use PHPCompiler\ext\dom\JitDomAppendChildLiveSlots;
use PHPCompiler\ext\dom\JitDomAppendChildUserScript;
use PHPCompiler\ext\dom\JitDomCreateElement;
use PHPCompiler\ext\dom\JitDomCreateElementAttrs;
use PHPCompiler\ext\dom\JitDomCreateCDATASection;
use PHPCompiler\ext\dom\JitDomCreateComment;
use PHPCompiler\ext\dom\JitDomCreateDocumentFragment;
use PHPCompiler\ext\dom\JitDomCreateProcessingInstruction;
use PHPCompiler\ext\dom\JitDomCreateTextNode;
use PHPCompiler\ext\dom\JitDomCloneNode;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\ext\dom\JitDomImportNode;
use PHPCompiler\ext\dom\JitDomInsertBeforeLiveSlots;
use PHPCompiler\ext\dom\JitDomLoadXMLUserScript;
use PHPCompiler\ext\dom\JitDomNodeChildProperty;
use PHPCompiler\ext\dom\JitDomNodeListItem;
use PHPCompiler\ext\dom\JitDomParentChildLinkLayout;
use PHPCompiler\ext\dom\JitDomReplaceChildLiveSlots;
use PHPCompiler\ext\dom\JitDomRequireDomNodeArg;
use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for DOMNode::{append,prepend,replaceChildren} via PHP helpers (#18951).
 */
final class DomNodeLiveMutationRuntime
{
    public const MAX_EXTRA_ARGS = 4;

    /**
     * Epoch for compile-time InnerXml Variable stamps. Both bbDoc* and bbEl* IR arms
     * execute this PHP during emission; without a once-guard the same delta is concat'd
     * twice (#35997 leftover of #35881).
     */
    private static int $compileTimeInnerXmlEpoch = 0;

    private static int $compileTimeInnerXmlDoneEpoch = -1;

    /** True while emitting dual doc/el IR arms that share one InnerXml epoch (#35997). */
    private static bool $shareCompileTimeInnerXmlEpoch = false;

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
        // php-src ParentNode nodes: DOMNode|string — null must TypeError before LiveSlots (#33741).
        $method = match ($kind) {
            'append' => 'DOMElement::append',
            'prepend' => 'DOMElement::prepend',
            'replacechildren' => 'DOMElement::replaceChildren',
            default => 'DOMElement::append',
        };
        foreach ($extraArgs as $i => $arg) {
            if (JitDomRequireDomNodeArg::guardDomNodeOrStringOrAbort(
                $context,
                $arg,
                $method,
                $i + 1
            )) {
                return self::nullValuePtr($context);
            }
        }
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            if ('replacechildren' === $kind) {
                // Arity 0: skip NestedJIT — empty replaceChildren aborted in thin AOT (#29409).
                // Non-empty: NestedJIT + INNER_XML overwrite (saveXML reads INNER_XML).
                // #32846: never syncChildNodesLengthSlot here — that allocates a fresh
                // DOMNodeList and leaves held `$list = childNodes` with stale pins / SIGSEGV.
                if (0 === $extraArgCount) {
                    self::clearChildLinkSlots($context, $receiver);
                    self::refreshHeldChildNodesAfterReplace($context, $receiver, 0, null, null);
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
                    $context->builder->call($context->lookupFunction($abi), ...$llvmArgs);
                    $childObjs = [];
                    foreach ($extraArgs as $arg) {
                        $childObjs[] = self::mutationArgObject($context, $arg);
                    }
                    self::syncReplaceChildrenChildChain($context, $receiver, $childObjs);
                    self::syncTextContentSlotFromLiteralArgs($context, $receiver, $extraArgs);
                    self::refreshHeldChildNodesAfterReplace(
                        $context,
                        $receiver,
                        $extraArgCount,
                        $childObjs[0],
                        $childObjs[1] ?? null
                    );
                    self::syncUserScriptInnerXmlReplaceFromArgs($context, $receiver, $extraArgs);

                    return self::nullValuePtr($context);
                }
                // String/mixed args: replaceChildrenString/Argv bridges abort in thin AOT;
                // clear via object bridge then reuse working append string/object bridges (#29409).
                JitDomDocumentMethodKernel::ensureReplaceChildrenObjectBridge($context, 0);
                $context->builder->call(
                    $context->lookupFunction(self::replaceChildrenObjectAbi(0)),
                    self::receiverObject($context, $receiver)
                );
                self::clearChildLinkSlots($context, $receiver);
                self::refreshHeldChildNodesAfterReplace($context, $receiver, 0, null, null);
                self::syncUserScriptInnerXmlReplaceFromArgs($context, $receiver, []);

                $firstArg = $extraArgs[0];
                $lastArg = $extraArgs[\count($extraArgs) - 1];
                $firstChildObj = null;
                $lastChildObj = null;
                $secondChildObj = null;
                $childIdx = 0;
                foreach ($extraArgs as $arg) {
                    $appended = self::invokeUserScriptMutationArg($context, 'append', $receiver, $arg);
                    if (Variable::TYPE_STRING === $arg->type) {
                        $materialized = self::childObjectForSlotSync($context, $arg);
                        if ($arg === $firstArg) {
                            $firstChildObj = $materialized;
                        }
                        if ($arg === $lastArg) {
                            $lastChildObj = $materialized;
                        }
                        if (1 === $childIdx) {
                            $secondChildObj = $materialized;
                        }
                    } else {
                        if ($arg === $firstArg) {
                            $firstChildObj = $appended;
                        }
                        if ($arg === $lastArg) {
                            $lastChildObj = $appended;
                        }
                        if (1 === $childIdx) {
                            $secondChildObj = $appended;
                        }
                    }
                    ++$childIdx;
                }
                if (null !== $firstChildObj && null !== $lastChildObj) {
                    self::syncChildLinkSlots($context, $receiver, $firstChildObj, $lastChildObj);
                }
                self::syncTextContentSlotFromLiteralArgs($context, $receiver, $extraArgs);
                self::refreshHeldChildNodesAfterReplace(
                    $context,
                    $receiver,
                    $extraArgCount,
                    $firstChildObj,
                    $secondChildObj ?? ($extraArgCount >= 2 ? $lastChildObj : null)
                );
                self::syncUserScriptInnerXmlReplaceFromArgs($context, $receiver, $extraArgs);

                return self::nullValuePtr($context);
            }
            $orderedArgs = 'prepend' === $kind ? array_reverse($extraArgs) : $extraArgs;
            // #27476 / #32838: Element object append — LLVM live-slot sync only (peer
            // insertBefore #27449). NestedJIT+syncChildLinkSlots+bump resets length to 1
            // on same-parent moves and leaves multi-arg held lists stale (#32838).
            // Document ParentNode::append uses invokeDocumentAppend; Element uses LiveSlots.
            if ('append' === $kind && self::canUseObjectMutationBridge($extraArgs)) {
                $parentObj = self::receiverObject($context, $receiver);
                $isDoc = JitDomParentChildLinkLayout::isDocumentObject($context, $parentObj, 'dom_append_recv');
                $bbDocAppend = BasicBlockHelper::append($context, 'dom_append_doc');
                $bbElAppend = BasicBlockHelper::append($context, 'dom_append_el');
                $bbAppendDone = BasicBlockHelper::append($context, 'dom_append_done');
                // Both IR arms below emit PHP compile-time side effects — share one epoch
                // so syncUserScriptInnerXmlFromArgs stamps Variable metadata once (#35997).
                self::$shareCompileTimeInnerXmlEpoch = true;
                ++self::$compileTimeInnerXmlEpoch;
                self::$compileTimeInnerXmlDoneEpoch = -1;
                $context->builder->branchIf($isDoc, $bbDocAppend, $bbElAppend);

                $context->builder->positionAtEnd($bbDocAppend);
                self::invokeDocumentObjectAppend($context, $receiver, $parentObj, $extraArgs);
                $context->builder->branch($bbAppendDone);

                $context->builder->positionAtEnd($bbElAppend);
                // LiveSlots increments the existing childNodes list in place so held
                // `$list = $node->childNodes` observes +N (#29048 / #32838). Do not bump
                // here — a pre-sync bumpHeld + LiveSlots +1 would double-count.
                // php-src Wrong Document / Hierarchy Request before LiveSlots (#30274).
                foreach ($extraArgs as $arg) {
                    $childObj = self::mutationArgObject($context, $arg);
                    self::assertTreeMutationChildBeforeLiveSlots($context, $parentObj, $childObj);
                    JitDomAppendChildLiveSlots::sync(
                        $context,
                        $parentObj,
                        $childObj
                    );
                    DomUserScriptLiveTagListLlvm::incrementForChildArg($context, $arg);
                }
                self::syncTextContentSlotFromLiteralArgs($context, $receiver, $extraArgs);
                $fragmentRecvPre = JitDomCreateDocumentFragment::TAG_KIND
                    === ($receiver->compileTimeDomTagName ?? null);
                if (!$fragmentRecvPre) {
                    JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlFromElementChildren(
                        $context,
                        $parentObj
                    );
                    JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlUpward($context, $parentObj);
                }
                $moved = 1 === \count($extraArgs)
                    && self::trySyncUserScriptInnerXmlMoveToEnd($context, $receiver, $extraArgs[0]);
                // Only the fragment Variable itself — not "$lastMaterialized" — owns
                // lastChildren. Nested createElement→appendChild(text) / cloneNode while a
                // fragment is open must not pollute the list (#35997 leftover of #35881).
                $fragmentRecv = JitDomCreateDocumentFragment::TAG_KIND
                    === ($receiver->compileTimeDomTagName ?? null);
                // Record only direct appendChild onto the fragment stand-in (#35881).
                // $lastMaterialized must not widen this — element/clone inner appends
                // while a fragment is open polluted lastChildren (#35997).
                if ($fragmentRecv) {
                    foreach ($extraArgs as $arg) {
                        JitDomCreateDocumentFragment::rememberAppendedChild($arg);
                    }
                    $fragInner = self::fragmentInnerXmlFromLastChildren();
                    $receiver->compileTimeDomInnerXml = $fragInner;
                    // saveXML($fragment) reads INNER_XML, not live child walk (#35997).
                    JitDomCreateElement::storeUserScriptInnerXml($context, $parentObj, $fragInner);
                } elseif (!$moved) {
                    // Rebuild already wrote the INNER_XML slot from live children. A follow-up
                    // syncUserScriptInnerXmlFromArgs still concatenates onto compile-time
                    // Variable metadata and doubles fragment markup (#35881 leftover of #35871).
                    self::syncUserScriptInnerXmlFromArgs($context, $receiver, $extraArgs, $kind, true);
                }
                if (
                    !$fragmentRecv
                    && JitDomCreateDocumentFragment::$lastMaterialized
                    && JitDomCreateDocumentFragment::TAG_KIND
                        !== ($receiver->compileTimeDomTagName ?? null)
                ) {
                    // Element already listed in the open fragment gained inner markup (#35997).
                    JitDomCreateDocumentFragment::refreshRecordedElementInner(
                        $receiver,
                        $receiver->compileTimeDomInnerXml ?? ''
                    );
                }
                $context->builder->branch($bbAppendDone);

                $context->builder->positionAtEnd($bbAppendDone);
                self::$shareCompileTimeInnerXmlEpoch = false;

                return self::nullValuePtr($context);
            }
            // #32828 / #32838: Element object prepend — insertBefore LiveSlots (peer
            // append #29048 / insertBefore #32801). NestedJIT + syncChildLinkSlots
            // set first=last=newChild and collapsed refetch_len; multi-arg left held
            // lists stale (#32838).
            if ('prepend' === $kind && self::canUseObjectMutationBridge($extraArgs)) {
                $parentObj = self::receiverObject($context, $receiver);
                $isDoc = JitDomParentChildLinkLayout::isDocumentObject($context, $parentObj, 'dom_prepend_recv');
                $bbDocPrepend = BasicBlockHelper::append($context, 'dom_prepend_doc');
                $bbElPrepend = BasicBlockHelper::append($context, 'dom_prepend_el');
                $bbPrependDone = BasicBlockHelper::append($context, 'dom_prepend_done');
                self::$shareCompileTimeInnerXmlEpoch = true;
                ++self::$compileTimeInnerXmlEpoch;
                self::$compileTimeInnerXmlDoneEpoch = -1;
                $context->builder->branchIf($isDoc, $bbDocPrepend, $bbElPrepend);

                $context->builder->positionAtEnd($bbDocPrepend);
                self::invokeDocumentObjectPrepend($context, $receiver, $parentObj, $extraArgs, $orderedArgs);
                $context->builder->branch($bbPrependDone);

                $context->builder->positionAtEnd($bbElPrepend);
                JitDomParentChildLinkLayout::ensureChildEdgeProperties($context);
                $objPtrTy = $context->getTypeFromString('__object__*');
                $prependIdx = 0;
                foreach ($orderedArgs as $arg) {
                    $childObj = self::mutationArgObject($context, $arg);
                    self::assertTreeMutationChildBeforeLiveSlots($context, $parentObj, $childObj);
                    $first = JitDomParentChildLinkLayout::loadFirstChild(
                        $context,
                        $parentObj,
                        'dom_prepend'
                    );
                    $firstNull = $context->builder->icmp(Builder::INT_EQ, $first, $objPtrTy->constNull());
                    $bbAppend = BasicBlockHelper::append($context, 'dom_prepend_empty_append_'.$prependIdx);
                    $bbInsert = BasicBlockHelper::append($context, 'dom_prepend_ib_'.$prependIdx);
                    $bbDone = BasicBlockHelper::append($context, 'dom_prepend_done_'.$prependIdx);
                    $context->builder->branchIf($firstNull, $bbAppend, $bbInsert);

                    $context->builder->positionAtEnd($bbInsert);
                    $bbAlreadyFirst = BasicBlockHelper::append(
                        $context,
                        'dom_prepend_already_first_'.$prependIdx
                    );
                    $bbDoInsert = BasicBlockHelper::append(
                        $context,
                        'dom_prepend_do_ib_'.$prependIdx
                    );
                    $alreadyFirst = $context->builder->icmp(
                        Builder::INT_EQ,
                        $childObj,
                        $first
                    );
                    $context->builder->branchIf($alreadyFirst, $bbAlreadyFirst, $bbDoInsert);
                    $context->builder->positionAtEnd($bbAlreadyFirst);
                    $context->builder->branch($bbDone);
                    $context->builder->positionAtEnd($bbDoInsert);
                    JitDomInsertBeforeLiveSlots::sync($context, $parentObj, $childObj, $first);
                    $context->builder->branch($bbDone);

                    $context->builder->positionAtEnd($bbAppend);
                    JitDomAppendChildLiveSlots::sync($context, $parentObj, $childObj);
                    $context->builder->branch($bbDone);

                    $context->builder->positionAtEnd($bbDone);
                    DomUserScriptLiveTagListLlvm::incrementForChildArg($context, $arg);
                    ++$prependIdx;
                }
                self::syncTextContentSlotFromLiteralArgs($context, $receiver, $extraArgs);
                JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlFromElementChildren(
                    $context,
                    $parentObj
                );
                JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlUpward($context, $parentObj);
                self::syncUserScriptInnerXmlFromArgs($context, $receiver, $extraArgs, $kind, true);
                $context->builder->branch($bbPrependDone);

                $context->builder->positionAtEnd($bbPrependDone);
                self::$shareCompileTimeInnerXmlEpoch = false;

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
        $elementClassId = $objectType->lookup('DOMElement');
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, Variable::TYPE_VALUE);
            }
        }

        $receiverObj = self::receiverObject($context, $receiver);
        $firstJit = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $firstChildObj);
        $lastJit = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $lastChildObj);
        // DOMElement layout — DOMNode first/last aliases tagName on Element (#32361).
        $objectType->propertyStore(
            $objectType->propertySlotFor($receiverObj, 'DOMElement', VmDom::PROP_FIRST_CHILD),
            $firstJit,
            Variable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($receiverObj, 'DOMElement', VmDom::PROP_LAST_CHILD),
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
        $elementClassId = $objectType->lookup('DOMElement');
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, Variable::TYPE_VALUE);
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
                $objectType->propertySlotFor($receiverObj, 'DOMElement', $prop),
                $nullVar,
                Variable::TYPE_VALUE
            );
        }
    }

    /**
     * Absolute length + pins on the held childNodes list after replaceChildren (#32846).
     *
     * Peer {@see JitDomReplaceChildLiveSlots::refreshHeldChildNodes}: never allocate a
     * fresh list via {@see syncChildNodesLengthSlot}.
     */
    private static function refreshHeldChildNodesAfterReplace(
        Context $context,
        Variable $receiver,
        int $childCount,
        ?Value $firstChildObj,
        ?Value $secondChildObj
    ): void {
        $objPtrTy = $context->getTypeFromString('__object__*');
        $parentObj = self::receiverObject($context, $receiver);
        $first = $firstChildObj ?? $objPtrTy->constNull();
        $second = $secondChildObj ?? $objPtrTy->constNull();
        JitDomReplaceChildLiveSlots::refreshHeldChildNodes(
            $context,
            $parentObj,
            $childCount,
            $first,
            $second
        );
    }

    /**
     * Wire first/last + sibling + parentNode for replaceChildren object args (#32846).
     *
     * NestedJIT mutates the PHP tree; thin-AOT item()/held lists read LLVM slots.
     *
     * @param list<Value> $childObjs
     */
    private static function syncReplaceChildrenChildChain(
        Context $context,
        Variable $receiver,
        array $childObjs
    ): void {
        if ([] === $childObjs) {
            self::clearChildLinkSlots($context, $receiver);

            return;
        }
        $n = \count($childObjs);
        self::syncChildLinkSlots($context, $receiver, $childObjs[0], $childObjs[$n - 1]);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        foreach ([VmDom::PROP_NEXT_SIBLING, VmDom::PROP_PREVIOUS_SIBLING] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, Variable::TYPE_VALUE);
            }
        }
        $nullSlot = JitValueBox::alloc($context);
        $nullPtr = JitValueBox::pointer($context, $nullSlot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);
        $nullVar = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $nullPtr)
        );
        for ($i = 0; $i < $n; ++$i) {
            $prev = 0 === $i ? $nullVar : new Variable(
                $context,
                Variable::TYPE_OBJECT,
                Variable::KIND_VALUE,
                $childObjs[$i - 1]
            );
            $next = ($i === $n - 1) ? $nullVar : new Variable(
                $context,
                Variable::TYPE_OBJECT,
                Variable::KIND_VALUE,
                $childObjs[$i + 1]
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($childObjs[$i], 'DOMElement', VmDom::PROP_PREVIOUS_SIBLING),
                $prev,
                Variable::TYPE_VALUE
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($childObjs[$i], 'DOMElement', VmDom::PROP_NEXT_SIBLING),
                $next,
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
            $markup = self::compileTimeChildElementMarkup($arg);
            if (null === $markup) {
                return;
            }
            $pieces[] = $markup;
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
            $objectType->propertySlotFor($receiverObj, 'DOMElement', VmDom::PROP_CHILD_NODES),
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
     * Same-parent appendChild move: reorder PROP_USER_SCRIPT_INNER_XML (#31684 / re-#28672).
     *
     * LiveSlots already rewrites first/last/childNodes; concat would append a fresh
     * {@code <tag/>} and leave saveXML wrong while childNodes stay correct.
     *
     * item($N) ARG_SEND temps often drop compileTimeDomChildIndex (#32903 / #32947) —
     * fall back to {@see JitDomNodeListItem} / {@see JitDomNodeChildProperty} stamps
     * like removeChild/insertBefore/replaceChild.
     */
    private static function trySyncUserScriptInnerXmlMoveToEnd(
        Context $context,
        Variable $receiver,
        Variable $childArg
    ): bool {
        if (!JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return false;
        }
        // createTextNode / character-data stand-ins are never same-parent moves (#33000).
        // Sticky lastFetchedChildIndex from firstChild would steal the index and rewrite
        // INNER_XML to the root child markup (saveXML → <a><a>1</a></a>).
        if (null !== self::compileTimeChildTextData($childArg)) {
            return false;
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || '' === trim($xml)) {
            return false;
        }
        $nodes = DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
        $index = $childArg->compileTimeDomChildIndex
            ?? JitDomNodeListItem::$lastFetchedChildIndex
            ?? JitDomNodeChildProperty::$lastFetchedChildIndex
            ?? null;
        if (null === $index) {
            $tag = $childArg->compileTimeDomTagName
                ?? JitDomNodeListItem::$lastFetchedTagName
                ?? JitDomNodeChildProperty::$lastFetchedTagName
                ?? null;
            if (null === $tag || '' === $tag) {
                return false;
            }
            foreach ($nodes as $i => $node) {
                if ('element' === $node['kind'] && strtolower($tag) === strtolower($node['data'])) {
                    $index = $i;
                    break;
                }
            }
        }
        if (null === $index) {
            return false;
        }
        $inner = DomParseSimpleXmlJitHelper::rootInnerXmlMoveChildToEnd($xml, $index);
        if (null === $inner) {
            return false;
        }
        $receiverObj = self::receiverObject($context, $receiver);
        JitDomCreateElement::storeUserScriptInnerXml($context, $receiverObj, $inner);
        JitDomLoadXMLUserScript::refreshCompileTimeXmlWithRootInner($inner, $receiver);

        return true;
    }

    /**
     * xmlNodeDump of a createElement child: empty → {@code <tag/>}, else paired
     * with escaped text / inner markup (#32361 / php-src document.c).
     *
     * createTextNode stand-ins (#33000): escaped character data when no element tag.
     */
    private static function compileTimeChildElementMarkup(Variable $arg): ?string
    {
        $tag = $arg->compileTimeDomTagName
            ?? JitDomImportNode::$lastMaterializedTagName
            ?? JitDomCloneNode::$lastResultTagName
            ?? null;
        if ('#text' === $tag || '#cdata-section' === $tag) {
            $text = self::compileTimeChildTextData($arg);
            if (null === $text) {
                return null;
            }

            return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }
        if ('#comment' === $tag) {
            $text = self::compileTimeChildTextData($arg);
            if (null === $text) {
                return null;
            }

            return '<!--'.$text.'-->';
        }
        if (null !== $tag && '' !== $tag) {
            $inner = $arg->compileTimeDomInnerXml ?? null;
            if (null === $inner || '' === $inner) {
                $inner = JitDomImportNode::$lastMaterializedInnerXml ?? '';
            }
            $attrSuffix = '';
            $id = $arg->compileTimeDomElementId ?? null;
            $attrMap = null !== $id ? JitDomCreateElementAttrs::get($id) : [];
            // Never read compileTimeDomAttributes when ElementId is set — ARG_SEND temps
            // inherit the parent's bag after setAttribute on replaceChild return (#35386).
            if (null === $id
                && null !== $arg->compileTimeDomAttributes
                && [] !== $arg->compileTimeDomAttributes
            ) {
                $attrMap = $arg->compileTimeDomAttributes;
            }
            if (null !== $attrMap && [] !== $attrMap) {
                $parts = [];
                foreach ($attrMap as $name => $value) {
                    $parts[] = $name.'="'.htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'"';
                }
                if ([] !== $parts) {
                    $attrSuffix = ' '.implode(' ', $parts);
                }
            }
            if ('' === $inner) {
                return '<'.$tag.$attrSuffix.'/>';
            }

            return '<'.$tag.$attrSuffix.'>'.$inner.'</'.$tag.'>';
        }
        $text = self::compileTimeChildTextData($arg);
        if (null === $text) {
            return null;
        }

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Raw createTextNode / splitText payload for INNER_XML + C14N fold (#33000).
     */
    private static function compileTimeChildTextData(Variable $arg): ?string
    {
        $tag = $arg->compileTimeDomTagName ?? null;
        if (null !== $tag && '' !== $tag && '#text' !== $tag && '#cdata-section' !== $tag) {
            return null;
        }

        return $arg->compileTimeDomTextData
            ?? JitDomCreateTextNode::$lastMaterializedData
            ?? JitDomCreateCDATASection::$lastMaterializedData
            ?? null;
    }


    /**
     * Rebuild fragment compile-time InnerXml from rememberAppendedChild (#35881).
     */
    private static function fragmentInnerXmlFromLastChildren(): string
    {
        $rebuilt = '';
        foreach (JitDomCreateDocumentFragment::$lastChildren as $node) {
            $kind = $node['kind'] ?? '';
            if ('text' === $kind) {
                $rebuilt .= $node['data'] ?? '';
            } elseif ('comment' === $kind) {
                $rebuilt .= '<!--'.($node['data'] ?? '').'-->';
            } elseif ('cdata' === $kind) {
                $rebuilt .= '<![CDATA['.($node['data'] ?? '').']]>';
            } elseif ('pi' === $kind) {
                $rebuilt .= '<?'.($node['data'] ?? '')
                    .(isset($node['content']) && '' !== $node['content'] ? ' '.$node['content'] : '')
                    .'?'.'>';
            } elseif ('element' === $kind) {
                $tag = $node['data'] ?? '';
                $childInner = $node['inner'] ?? '';
                $rebuilt .= '' === $childInner
                    ? '<'.$tag.'/>'
                    : '<'.$tag.'>'.$childInner.'</'.$tag.'>';
            }
        }

        return $rebuilt;
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
        string $kind = 'append',
        bool $skipInnerXmlSlotMerge = false
    ): void {
        if ([] === $extraArgs) {
            return;
        }
        $pieces = [];
        $rawTexts = [];
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
            $markup = self::compileTimeChildElementMarkup($arg);
            if (null === $markup) {
                if ($skipInnerXmlSlotMerge) {
                    DomUserScriptLiveTagListLlvm::clearPending($context);
                }

                return;
            }
            $pieces[] = $markup;
            $text = self::compileTimeChildTextData($arg);
            if (null !== $text) {
                $rawTexts[] = $text;
            }
        }
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($classId, VmDom::PROP_USER_SCRIPT_INNER_XML)) {
            $objectType->defineProperty($classId, VmDom::PROP_USER_SCRIPT_INNER_XML, Variable::TYPE_STRING);
        }
        $receiverObj = self::receiverObject($context, $receiver);
        // Refresh C14N fold from the *receiver* document's loadXML (#32972 / #32987 / #33000).
        $xml = $receiver->compileTimeDomLoadXml
            ?? JitDomLoadXMLUserScript::compileTimeXmlFor($receiver)
            ?? JitDomLoadXMLUserScript::lastCompileTimeXml();
        $childIndex = $receiver->compileTimeDomChildIndex
            ?? JitDomNodeChildProperty::$lastFetchedChildIndex
            ?? null;
        $delta = implode('', $pieces);
        // Keep Variable metadata so createElement trees can cloneNode without loadXML (#35361).
        $priorMeta = $receiver->compileTimeDomInnerXml ?? '';
        // Dual doc/el IR arms share one epoch; other callers bump so each sync stamps (#35997).
        if (!self::$shareCompileTimeInnerXmlEpoch) {
            ++self::$compileTimeInnerXmlEpoch;
        }
        if (self::$compileTimeInnerXmlDoneEpoch !== self::$compileTimeInnerXmlEpoch) {
            self::$compileTimeInnerXmlDoneEpoch = self::$compileTimeInnerXmlEpoch;
            if ('prepend' === $kind) {
                $receiver->compileTimeDomInnerXml = str_starts_with($priorMeta, $delta)
                    ? $priorMeta
                    : $delta.$priorMeta;
            } elseif ('' !== $delta && '' !== $priorMeta && str_ends_with($priorMeta, $delta)) {
                $receiver->compileTimeDomInnerXml = $priorMeta;
            } else {
                $receiver->compileTimeDomInnerXml = $priorMeta.$delta;
            }
        }
        // Nested firstChild/lastChild + createTextNode: slot often lacks loadXML-seeded
        // inner ("1"), so runtime load+concat yields only the delta (#33000).
        if (
            null !== $xml
            && JitDomLoadXMLUserScript::lastLoadWasPureUserScript()
            && null !== $childIndex
            && [] !== $rawTexts
            && \count($rawTexts) === \count($pieces)
        ) {
            $nodes = DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
            $seedInner = '';
            $node = $nodes[$childIndex] ?? null;
            if (null !== $node && 'element' === ($node['kind'] ?? '')) {
                $seedInner = $node['inner'] ?? '';
            }
            $newInner = 'prepend' === $kind ? $delta.$seedInner : $seedInner.$delta;
            $receiver->compileTimeDomInnerXml = $newInner;
            JitDomCreateElement::storeUserScriptInnerXml($context, $receiverObj, $newInner);
            foreach ($rawTexts as $rawText) {
                JitDomLoadXMLUserScript::refreshCompileTimeXmlAppendTextToChild(
                    $childIndex,
                    $rawText,
                    'prepend' === $kind
                );
            }

            return;
        }
        if (!$skipInnerXmlSlotMerge) {
            $deltaStr = $context->builder->load(
                $context->constantStringFromString($delta)
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
        if (null === $xml || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return;
        }
        $oldInner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml);
        $newInner = 'prepend' === $kind ? $delta.$oldInner : $oldInner.$delta;
        JitDomLoadXMLUserScript::refreshCompileTimeXmlWithRootInner($newInner, $receiver);
        if ($skipInnerXmlSlotMerge) {
            // LiveSlots rebuild + C14N refresh already baked $delta into compile-time XML.
            // incrementForChildArg may have queued the same subtree on GLOBAL_PENDING before
            // the first getElementsByTagName — initCount would add base+pending twice (#33918).
            DomUserScriptLiveTagListLlvm::clearPending($context);
        }
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

    private static int $treeMutAssertSeq = 0;

    /**
     * Thin-AOT LiveSlots preflight (#30274 / #33937 / #34063): reject Document-class children,
     * foreign-document Element/Text/… children, and ancestor/self children before slot sync.
     *
     * LiveSlots objects use thin {@see __object__} layout (class_id only) — NestedJIT
     * {@see ObjectEntry::$class} access segfaults. Compare class_id / ownerDocument /
     * parentNode walks in LLVM and throw catchable {@see \DOMException}.
     *
     * Attr children are excluded: VmDom installs them via the attribute map before
     * {@see VmDom::assertCanReceiveTreeMutationChild}, and Attr objects lack Element
     * ownerDocument/parentNode layout — walking those slots SIGSEGVs (#35185 / re-#33570
     * after #34089). {@see JitDomAppendChildLiveSlots::sync} still handles Attr.
     *
     * Peer: {@see VmDom::assertCanReceiveTreeMutationChild} / {@see VmDom::assertSameDocument}
     * / {@see VmDom::assertNotAncestorOfParent}.
     */
    public static function assertTreeMutationChildBeforeLiveSlots(
        Context $context,
        Value $parent,
        Value $child
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_assert_tree_mut');
        $seq = (string) (self::$treeMutAssertSeq++);
        $objectType = $context->type->object;
        $map = $context->structFieldMap['__object__'];
        $bbOk = BasicBlockHelper::append($context, 'dom_assert_tree_mut_ok_'.$seq);

        // Attr → attribute map (php-src / VmDom) — do not load Element props on Attr (#35185).
        $bbAttr = BasicBlockHelper::append($context, 'dom_assert_tree_mut_attr_'.$seq);
        $bbNotAttr = BasicBlockHelper::append($context, 'dom_assert_tree_mut_not_attr_'.$seq);
        $isAttr = JitDomAppendChildLiveSlots::isAttrNode($context, $child);
        $context->builder->branchIf($isAttr, $bbAttr, $bbNotAttr);
        $context->builder->positionAtEnd($bbAttr);
        $context->builder->branch($bbOk);

        $context->builder->positionAtEnd($bbNotAttr);
        $classId = $context->builder->load($context->builder->structGep($child, $map['class_id']));
        $isDoc = self::icmpIsDocumentClass($context, $classId);

        $bbDoc = BasicBlockHelper::append($context, 'dom_assert_tree_mut_doc_'.$seq);
        $bbNode = BasicBlockHelper::append($context, 'dom_assert_tree_mut_node_'.$seq);
        $context->builder->branchIf($isDoc, $bbDoc, $bbNode);

        $context->builder->positionAtEnd($bbDoc);
        // Same-document: element.parentNode === document (#30274 / loadXML parentNode wire).
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, Variable::TYPE_VALUE);
        }
        $parentNodeSlot = $objectType->propertySlotFor($parent, 'DOMElement', VmDom::PROP_PARENT_NODE);
        $parentNodePtr = $context->builder->load($parentNodeSlot);
        $voidPtr = $context->getTypeFromString('void*');
        $slotNull = $context->builder->icmp(Builder::INT_EQ, $parentNodePtr, $voidPtr->constNull());
        $bbHierarchy = BasicBlockHelper::append($context, 'dom_assert_tree_mut_hier_'.$seq);
        $bbWrong = BasicBlockHelper::append($context, 'dom_assert_tree_mut_wrong_'.$seq);
        $bbCompare = BasicBlockHelper::append($context, 'dom_assert_tree_mut_cmp_'.$seq);
        $context->builder->branchIf($slotNull, $bbWrong, $bbCompare);

        $context->builder->positionAtEnd($bbCompare);
        $ownerDoc = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($parentNodePtr, $context->getTypeFromString('__value__*'))
        );
        $sameDoc = $context->builder->icmp(Builder::INT_EQ, $ownerDoc, $child);
        $context->builder->branchIf($sameDoc, $bbHierarchy, $bbWrong);

        $context->builder->positionAtEnd($bbHierarchy);
        TryCatchHelper::emitCatchableClassError(
            $context,
            'DOMException',
            'Hierarchy Request Error',
            null,
            '',
            0,
            DomExceptionConstants::HIERARCHY_REQUEST_ERR
        );
        // emitCatchableClassError terminates the block when a catch handler exists.

        $context->builder->positionAtEnd($bbWrong);
        TryCatchHelper::emitCatchableClassError(
            $context,
            'DOMException',
            'Wrong Document Error',
            null,
            '',
            0,
            DomExceptionConstants::WRONG_DOCUMENT_ERR
        );

        // Non-Document child: Wrong Document, then ancestor/self (#33937 / #34063).
        $context->builder->positionAtEnd($bbNode);
        $bbAfterDoc = BasicBlockHelper::append($context, 'dom_assert_tree_mut_after_doc_'.$seq);
        self::assertSameOwnerDocumentBeforeLiveSlots($context, $parent, $child, $bbAfterDoc);
        $context->builder->positionAtEnd($bbAfterDoc);
        self::assertChildNotAncestorOfParentBeforeLiveSlots($context, $parent, $child, $bbOk);

        $context->builder->positionAtEnd($bbOk);
    }

    /**
     * php-src hierarchy: newChild must not be parent or an ancestor of parent (#34063 / re-#19753).
     *
     * Peer {@see VmDom::assertNotAncestorOfParent} / {@see VmDom::contains} — walk parentNode
     * from {@code $parent} looking for {@code $child}. Without this, LiveSlots links a cycle
     * and thin AOT SIGSEGVs.
     *
     * Runtime alloca loop (not PHP-unrolled hops): foreach + three mutation lowerings used to
     * inline ~32 hops × loadNullableObjectProp BBs per call site and intermittently SIGSEGV
     * under thin AOT (#34089 / re-#33937; peer CFG pressure #33335).
     */
    private static function assertChildNotAncestorOfParentBeforeLiveSlots(
        Context $context,
        Value $parent,
        Value $child,
        \PHPLLVM\BasicBlock $bbOk
    ): void {
        $tag = (string) (self::$treeMutAssertSeq++);
        $objPtr = $context->getTypeFromString('__object__*');
        $bbWalk = BasicBlockHelper::append($context, 'dom_assert_anc_walk_'.$tag);
        $bbHier = BasicBlockHelper::append($context, 'dom_assert_anc_hier_'.$tag);
        $same = $context->builder->icmp(Builder::INT_EQ, $parent, $child);
        $context->builder->branchIf($same, $bbHier, $bbWalk);

        $context->builder->positionAtEnd($bbWalk);
        // Appending under Document: no Element parentNode chain to walk (#34063).
        $map = $context->structFieldMap['__object__'];
        $parentClassId0 = $context->builder->load(
            $context->builder->structGep($parent, $map['class_id'])
        );
        $bbParentDoc0 = BasicBlockHelper::append($context, 'dom_assert_anc_pdoc0_'.$tag);
        $bbWalkBody = BasicBlockHelper::append($context, 'dom_assert_anc_body_'.$tag);
        $context->builder->branchIf(
            self::icmpIsDocumentClass($context, $parentClassId0),
            $bbParentDoc0,
            $bbWalkBody
        );
        $context->builder->positionAtEnd($bbParentDoc0);
        $context->builder->branch($bbOk);

        $context->builder->positionAtEnd($bbWalkBody);
        // Peer JitDomAppendChildUserScript curAlloca sibling walk — one BB set, not N unrolls.
        $curAlloca = BasicBlockHelper::entryAlloca($context, $objPtr);
        $context->builder->store($parent, $curAlloca);
        $bbLoop = BasicBlockHelper::append($context, 'dom_assert_anc_loop_'.$tag);
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbLoop);
        $cur = $context->builder->load($curAlloca);
        $parentObj = self::loadNullableObjectProp(
            $context,
            $cur,
            'DOMElement',
            VmDom::PROP_PARENT_NODE
        );
        $bbHasParent = BasicBlockHelper::append($context, 'dom_assert_anc_pn_'.$tag);
        $bbNoParent = BasicBlockHelper::append($context, 'dom_assert_anc_nopn_'.$tag);
        $parentNull = $context->builder->icmp(Builder::INT_EQ, $parentObj, $objPtr->constNull());
        $context->builder->branchIf($parentNull, $bbNoParent, $bbHasParent);

        $context->builder->positionAtEnd($bbNoParent);
        $context->builder->branch($bbOk);

        $context->builder->positionAtEnd($bbHasParent);
        $hit = $context->builder->icmp(Builder::INT_EQ, $parentObj, $child);
        $bbCont = BasicBlockHelper::append($context, 'dom_assert_anc_cont_'.$tag);
        $context->builder->branchIf($hit, $bbHier, $bbCont);

        $context->builder->positionAtEnd($bbCont);
        // Document has no Element parentNode layout — stop (php-src root) (#34063).
        $parentClassId = $context->builder->load(
            $context->builder->structGep($parentObj, $map['class_id'])
        );
        $bbParentIsDoc = BasicBlockHelper::append($context, 'dom_assert_anc_doc_'.$tag);
        $bbParentCont = BasicBlockHelper::append($context, 'dom_assert_anc_pcont_'.$tag);
        $context->builder->branchIf(
            self::icmpIsDocumentClass($context, $parentClassId),
            $bbParentIsDoc,
            $bbParentCont
        );

        $context->builder->positionAtEnd($bbParentIsDoc);
        $context->builder->branch($bbOk);

        $context->builder->positionAtEnd($bbParentCont);
        $context->builder->store($parentObj, $curAlloca);
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbHier);
        TryCatchHelper::emitCatchableClassError(
            $context,
            'DOMException',
            'Hierarchy Request Error',
            null,
            '',
            0,
            DomExceptionConstants::HIERARCHY_REQUEST_ERR
        );
    }

    /**
     * php-src same-document check for Element/Text/Comment/… (#33937 / re-#22710).
     *
     * Resolves owner documents via {@see VmDom::PROP_OWNER_DOCUMENT} (createElement) or a
     * parentNode walk to Document (loadXML). Null on either side → allow (VmDom::assertSameDocument).
     */
    private static function assertSameOwnerDocumentBeforeLiveSlots(
        Context $context,
        Value $parent,
        Value $child,
        \PHPLLVM\BasicBlock $bbOk
    ): void {
        $tag = (string) (self::$treeMutAssertSeq++);
        $parentDoc = self::resolveOwnerDocumentObject($context, $parent);
        $childDoc = self::resolveOwnerDocumentObject($context, $child);
        $objPtr = $context->getTypeFromString('__object__*');
        $parentNull = $context->builder->icmp(Builder::INT_EQ, $parentDoc, $objPtr->constNull());
        $childNull = $context->builder->icmp(Builder::INT_EQ, $childDoc, $objPtr->constNull());
        $skip = $context->builder->or($parentNull, $childNull);
        $bbCheck = BasicBlockHelper::append($context, 'dom_assert_same_doc_check_'.$tag);
        $bbWrong = BasicBlockHelper::append($context, 'dom_assert_same_doc_wrong_'.$tag);
        $context->builder->branchIf($skip, $bbOk, $bbCheck);

        $context->builder->positionAtEnd($bbCheck);
        $same = $context->builder->icmp(Builder::INT_EQ, $parentDoc, $childDoc);
        $context->builder->branchIf($same, $bbOk, $bbWrong);

        $context->builder->positionAtEnd($bbWrong);
        TryCatchHelper::emitCatchableClassError(
            $context,
            'DOMException',
            'Wrong Document Error',
            null,
            '',
            0,
            DomExceptionConstants::WRONG_DOCUMENT_ERR
        );
    }

    /**
     * Thin-AOT owner document: Document self, else ownerDocument slot, else parentNode walk.
     *
     * Runtime alloca loop (not PHP-unrolled hops) — peer ancestor walk (#34089 / #33335).
     *
     * @return Value __object__* (nullable)
     */
    private static function resolveOwnerDocumentObject(Context $context, Value $node): Value
    {
        $tag = (string) (self::$treeMutAssertSeq++);
        $objPtr = $context->getTypeFromString('__object__*');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $objPtr);
        $map = $context->structFieldMap['__object__'];

        $bbDoc = BasicBlockHelper::append($context, 'dom_resolve_doc_isdoc_'.$tag);
        $bbWalk = BasicBlockHelper::append($context, 'dom_resolve_doc_walk_'.$tag);
        $bbDone = BasicBlockHelper::append($context, 'dom_resolve_doc_done_'.$tag);

        $classId = $context->builder->load($context->builder->structGep($node, $map['class_id']));
        $context->builder->branchIf(self::icmpIsDocumentClass($context, $classId), $bbDoc, $bbWalk);

        $context->builder->positionAtEnd($bbDoc);
        $context->builder->store($node, $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbWalk);
        $curAlloca = BasicBlockHelper::entryAlloca($context, $objPtr);
        $context->builder->store($node, $curAlloca);
        $bbLoop = BasicBlockHelper::append($context, 'dom_resolve_doc_loop_'.$tag);
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbLoop);
        $cur = $context->builder->load($curAlloca);
        $ownerSlot = self::loadNullableObjectProp(
            $context,
            $cur,
            'DOMElement',
            VmDom::PROP_OWNER_DOCUMENT
        );
        $bbHasOwner = BasicBlockHelper::append($context, 'dom_resolve_doc_owner_'.$tag);
        $bbNoOwner = BasicBlockHelper::append($context, 'dom_resolve_doc_no_owner_'.$tag);
        $ownerNull = $context->builder->icmp(Builder::INT_EQ, $ownerSlot, $objPtr->constNull());
        $context->builder->branchIf($ownerNull, $bbNoOwner, $bbHasOwner);

        $context->builder->positionAtEnd($bbHasOwner);
        $context->builder->store($ownerSlot, $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbNoOwner);
        $parentObj = self::loadNullableObjectProp(
            $context,
            $cur,
            'DOMElement',
            VmDom::PROP_PARENT_NODE
        );
        $bbHasParent = BasicBlockHelper::append($context, 'dom_resolve_doc_pn_'.$tag);
        $bbNoParent = BasicBlockHelper::append($context, 'dom_resolve_doc_no_pn_'.$tag);
        $parentNull = $context->builder->icmp(Builder::INT_EQ, $parentObj, $objPtr->constNull());
        $context->builder->branchIf($parentNull, $bbNoParent, $bbHasParent);

        $context->builder->positionAtEnd($bbNoParent);
        $context->builder->store($objPtr->constNull(), $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbHasParent);
        $parentClassId = $context->builder->load(
            $context->builder->structGep($parentObj, $map['class_id'])
        );
        $bbParentIsDoc = BasicBlockHelper::append($context, 'dom_resolve_doc_pn_doc_'.$tag);
        $bbParentCont = BasicBlockHelper::append($context, 'dom_resolve_doc_pn_cont_'.$tag);
        $context->builder->branchIf(
            self::icmpIsDocumentClass($context, $parentClassId),
            $bbParentIsDoc,
            $bbParentCont
        );

        $context->builder->positionAtEnd($bbParentIsDoc);
        $context->builder->store($parentObj, $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbParentCont);
        $context->builder->store($parentObj, $curAlloca);
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbDone);

        return $context->builder->load($resultSlot);
    }

    /** @param Value $classId int64 class_id */
    private static function icmpIsDocumentClass(Context $context, Value $classId): Value
    {
        $objectType = $context->type->object;
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $isDoc = $i1->constInt(0, false);
        foreach (['DOMDocument', 'Dom\\Document', 'Dom\\XMLDocument', 'Dom\\HTMLDocument'] as $className) {
            try {
                $expected = $objectType->lookup($className);
            } catch (\Throwable $e) {
                continue;
            }
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt($expected, false)
            );
            $isDoc = $context->builder->or($isDoc, $match);
        }

        return $isDoc;
    }

    /**
     * Load a TYPE_VALUE object property as nullable {@see __object__*} (null box → null).
     *
     * Must not call {@see JitNestedHelperCoerce::isHelperResultNull} on a null slot pointer —
     * loadXML nodes often leave ownerDocument unset (#33937).
     */
    private static function loadNullableObjectProp(
        Context $context,
        Value $obj,
        string $className,
        string $prop
    ): Value {
        $objectType = $context->type->object;
        $classId = $objectType->lookup($className);
        if (!$objectType->hasProperty($classId, $prop)) {
            $objectType->defineProperty($classId, $prop, Variable::TYPE_VALUE);
        }
        $rawPtr = $context->builder->load(
            $objectType->propertySlotFor($obj, $className, $prop)
        );
        $objPtr = $context->getTypeFromString('__object__*');
        $tag = (string) (self::$treeMutAssertSeq++);
        $bbSlotNull = BasicBlockHelper::append($context, 'dom_load_prop_slot_null_'.$tag);
        $bbSlotHas = BasicBlockHelper::append($context, 'dom_load_prop_slot_has_'.$tag);
        $bbBoxNull = BasicBlockHelper::append($context, 'dom_load_prop_box_null_'.$tag);
        $bbHas = BasicBlockHelper::append($context, 'dom_load_prop_has_'.$tag);
        $bbDone = BasicBlockHelper::append($context, 'dom_load_prop_done_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $objPtr);
        $voidPtr = $context->getTypeFromString('void*');
        $slotNull = $context->builder->icmp(Builder::INT_EQ, $rawPtr, $voidPtr->constNull());
        $context->builder->branchIf($slotNull, $bbSlotNull, $bbSlotHas);

        $context->builder->positionAtEnd($bbSlotNull);
        $context->builder->store($objPtr->constNull(), $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbSlotHas);
        $valuePtr = $context->builder->pointerCast(
            $rawPtr,
            $context->getTypeFromString('__value__*')
        );
        $boxNull = JitNestedHelperCoerce::isHelperResultNull($context, $valuePtr);
        $context->builder->branchIf($boxNull, $bbBoxNull, $bbHas);

        $context->builder->positionAtEnd($bbBoxNull);
        $context->builder->store($objPtr->constNull(), $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbHas);
        $loaded = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::normalizeValuePtr($context, $valuePtr)
        );
        $context->builder->store($loaded, $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return $context->builder->load($resultSlot);
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
            return JitDomCreateTextNode::materialize($context, $arg->compileTimeString ?? '');
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

    /**
     * DOMDocument::append() — invokeDocumentAppend per arg (peer appendChild #18927).
     *
     * @param list<Variable> $extraArgs
     */
    private static function invokeDocumentObjectAppend(
        Context $context,
        Variable $receiver,
        Value $parentObj,
        array $extraArgs
    ): void {
        foreach ($extraArgs as $arg) {
            $childObj = self::mutationArgObject($context, $arg);
            self::assertTreeMutationChildBeforeLiveSlots($context, $parentObj, $childObj);
            JitDomAppendChildUserScript::invokeDocumentAppend($context, $receiver, $arg);
            DomUserScriptLiveTagListLlvm::incrementForChildArg($context, $arg);
        }
        self::syncTextContentSlotFromLiteralArgs($context, $receiver, $extraArgs);
        JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlFromElementChildren($context, $parentObj);
        JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlUpward($context, $parentObj);
        $moved = 1 === \count($extraArgs)
            && self::trySyncUserScriptInnerXmlMoveToEnd($context, $receiver, $extraArgs[0]);
        if (!$moved) {
            self::syncUserScriptInnerXmlFromArgs($context, $receiver, $extraArgs, 'append', true);
        }
    }

    /**
     * DOMDocument::prepend() — empty ≡ invokeDocumentAppend; else insertBefore (#34813).
     *
     * @param list<Variable> $extraArgs document order
     * @param list<Variable> $orderedArgs reverse link order
     */
    private static function invokeDocumentObjectPrepend(
        Context $context,
        Variable $receiver,
        Value $parentObj,
        array $extraArgs,
        array $orderedArgs
    ): void {
        JitDomParentChildLinkLayout::ensureChildEdgeProperties($context);
        $objPtrTy = $context->getTypeFromString('__object__*');
        $prependIdx = 0;
        foreach ($orderedArgs as $arg) {
            $childObj = self::mutationArgObject($context, $arg);
            self::assertTreeMutationChildBeforeLiveSlots($context, $parentObj, $childObj);
            $first = JitDomParentChildLinkLayout::loadFirstChild($context, $parentObj, 'dom_doc_prepend');
            $firstNull = $context->builder->icmp(Builder::INT_EQ, $first, $objPtrTy->constNull());
            $bbAppend = BasicBlockHelper::append($context, 'dom_doc_prepend_empty_'.$prependIdx);
            $bbInsert = BasicBlockHelper::append($context, 'dom_doc_prepend_ib_'.$prependIdx);
            $bbDone = BasicBlockHelper::append($context, 'dom_doc_prepend_done_'.$prependIdx);
            $context->builder->branchIf($firstNull, $bbAppend, $bbInsert);

            $context->builder->positionAtEnd($bbInsert);
            $bbAlreadyFirst = BasicBlockHelper::append($context, 'dom_doc_prepend_af_'.$prependIdx);
            $bbDoInsert = BasicBlockHelper::append($context, 'dom_doc_prepend_do_'.$prependIdx);
            $alreadyFirst = $context->builder->icmp(Builder::INT_EQ, $childObj, $first);
            $context->builder->branchIf($alreadyFirst, $bbAlreadyFirst, $bbDoInsert);
            $context->builder->positionAtEnd($bbAlreadyFirst);
            $context->builder->branch($bbDone);
            $context->builder->positionAtEnd($bbDoInsert);
            JitDomInsertBeforeLiveSlots::sync($context, $parentObj, $childObj, $first);
            JitDomAppendChildUserScript::maybeUpdateDocumentElementOnPrepend(
                $context,
                $parentObj,
                $childObj,
                $first
            );
            $context->builder->branch($bbDone);

            $context->builder->positionAtEnd($bbAppend);
            JitDomAppendChildUserScript::invokeDocumentAppend($context, $receiver, $arg);
            $context->builder->branch($bbDone);

            $context->builder->positionAtEnd($bbDone);
            DomUserScriptLiveTagListLlvm::incrementForChildArg($context, $arg);
            ++$prependIdx;
        }
        self::syncTextContentSlotFromLiteralArgs($context, $receiver, $extraArgs);
        JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlFromElementChildren($context, $parentObj);
        JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlUpward($context, $parentObj);
        self::syncUserScriptInnerXmlFromArgs($context, $receiver, $extraArgs, 'prepend', true);
    }
}
