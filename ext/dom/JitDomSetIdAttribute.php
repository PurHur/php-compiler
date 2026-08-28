<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomImportNodeRuntime;
use PHPCompiler\JIT\Builtin\DomSetIdAttributeRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMElement::setIdAttribute{,NS,Node}() (#29257, #29284, #33957).
 *
 * NestedJIT helper updates DomRegistry without PROP_ELEMENT_ID_MAP sync. Thin AOT
 * stores {@see DomUserScriptElementCacheLlvm} from a **runtime** getAttribute after
 * setId so getElementById sees the receiver's id value — not the first `id=` in the
 * loadXML literal (#33957).
 *
 * setAttribute reusing an id already in the compile-time loadXML literal skips/clears
 * the cache — xmlAddID first-wins after replaceChild (#29694 / re-#25274).
 */
final class JitDomSetIdAttribute
{
    /** @var list<string> Compile-time id attribute values from setAttribute('id', …). */
    private static array $setAttributeIdValues = [];

    /** @var array<string, true> Id values that successfully called setIdAttribute(true) this compile. */
    private static array $registeredIdLiterals = [];

    public static function rememberSetAttributeIdValue(string $value): void
    {
        self::$setAttributeIdValues[] = $value;
    }

    public static function resetCompileTimeState(): void
    {
        self::$setAttributeIdValues = [];
        self::$registeredIdLiterals = [];
    }

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('DOMElement::setIdAttribute() expects receiver, name, and isId');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_set_id_attribute_cont');
        self::seedReceiverCompileTimeAttrs($context, $args[0]);
        $nameLlvm = JitStringArg::lower($context, $args[1], 'DOMElement::setIdAttribute() name');
        $element = self::loadObjectArg($context, $args[0]);
        $isIdTrue = self::resolveIsIdTrue($context, $args[2]);
        if ($isIdTrue) {
            DomSetIdAttributeRuntime::ensureTrueLinked($context);
            $abi = DomSetIdAttributeRuntime::ABI_TRUE;
        } else {
            DomSetIdAttributeRuntime::ensureFalseLinked($context);
            $abi = DomSetIdAttributeRuntime::ABI_FALSE;
        }
        $nameLitPre = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (JitDomDocumentMethodKernel::shouldUse($context)
            && null !== $nameLitPre
            && 'id' === $nameLitPre
        ) {
            // Before NestedJIT ABI — helper may throw when DomRegistry lacks the attr (#29884).
            DomUserScriptAttributeCacheLlvm::storeIdBearingGlobal($context, $isIdTrue);
        }
        $context->builder->call(
            $context->lookupFunction($abi),
            $element,
            $nameLlvm
        );
        if (JitDomDocumentMethodKernel::shouldUse($context) && $isIdTrue) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_set_id_attribute_post');
            // Prefer per-element compile-time attrs (firstChild/nextSibling stamps) over the
            // global name→value Attr cache — that cache returns the last id= in the doc (#34050).
            $nameLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            $elemAttrVal = self::compileTimeReceiverAttrValue($args[0], $nameLit);
            if (null === $elemAttrVal || '' === $elemAttrVal) {
                $elemAttrVal = self::compileTimeAttrFromInnerParentStamp($args[0], $nameLit)
                    ?? self::compileTimeAttrValueFromNodePath($args[0], $nameLit)
                    ?? self::compileTimeAttrValueFromRootChildTag($args[0], $nameLit);
            }
            $idLitForSkip = $elemAttrVal;
            if (null === $idLitForSkip || '' === $idLitForSkip) {
                $idLitForSkip = self::$setAttributeIdValues !== []
                    ? self::$setAttributeIdValues[\count(self::$setAttributeIdValues) - 1]
                    : null;
            }
            // xmlAddID first-wins after replaceChild — do not seed LLVM cache when this id
            // was already registered via setIdAttribute(true) earlier in the compile (#29694).
            // Do not gate on treeMutatedSinceLoad: user-script replaceChild deliberately
            // avoids that flag for C14N (#32972) but still needs the skip (#29694).
            $skipCache = null !== $idLitForSkip
                && '' !== $idLitForSkip
                && isset(self::$registeredIdLiterals[$idLitForSkip]);
            if (!$skipCache) {
                if (null !== $elemAttrVal && '' !== $elemAttrVal) {
                    $idStr = $context->builder->load($context->constantStringFromString($elemAttrVal));
                    self::storeCacheFromRuntimeIdString($context, $element, $idStr, $elemAttrVal);
                    self::$registeredIdLiterals[$elemAttrVal] = true;
                } else {
                    self::storeCacheFromRuntimeGetAttribute(
                        $context,
                        $element,
                        $nameLlvm,
                        $nameLit,
                        $idLitForSkip
                    );
                    if (null !== $idLitForSkip && '' !== $idLitForSkip) {
                        self::$registeredIdLiterals[$idLitForSkip] = true;
                    }
                }
            }
            if (null !== $nameLit && '' !== $nameLit) {
                DomUserScriptAttributeCacheLlvm::markIdBearingLiteral('', $nameLit, true);
            }
        } elseif (JitDomDocumentMethodKernel::shouldUse($context) && !$isIdTrue) {
            DomUserScriptElementCacheLlvm::invalidateIfElement($context, $element);
            $nameLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null !== $nameLit && '' !== $nameLit) {
                DomUserScriptAttributeCacheLlvm::markIdBearingLiteral('', $nameLit, false);
            }
        }

        return self::boxNull($context);
    }

    public static function invokeNs(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 4) {
            throw new \LogicException('DOMElement::setIdAttributeNS() expects receiver, namespace, localName, isId');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_set_id_attribute_ns_cont');
        $nsLlvm = self::loadNamespaceArg($context, $args[1]);
        $localLlvm = JitStringArg::lower($context, $args[2], 'DOMElement::setIdAttributeNS() localName');
        $element = self::loadObjectArg($context, $args[0]);
        $isIdTrue = self::resolveIsIdTrue($context, $args[3]);
        if ($isIdTrue) {
            DomSetIdAttributeRuntime::ensureNsTrueLinked($context);
            $abi = DomSetIdAttributeRuntime::ABI_NS_TRUE;
        } else {
            DomSetIdAttributeRuntime::ensureNsFalseLinked($context);
            $abi = DomSetIdAttributeRuntime::ABI_NS_FALSE;
        }
        $context->builder->call(
            $context->lookupFunction($abi),
            $element,
            $nsLlvm,
            $localLlvm
        );
        if (JitDomDocumentMethodKernel::shouldUse($context) && $isIdTrue) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_set_id_attribute_ns_post');
            $localLit = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
            $nsLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString ?? '';
            // loadXML open-tag stamp first; createElement+setAttributeNS stores under ns\0local
            // (#35303). Bare getAttribute(localName) is empty for x:id and skipped the id-map.
            $elemAttrVal = self::compileTimeReceiverAttrValue($args[0], $localLit);
            if (null === $elemAttrVal || '' === $elemAttrVal) {
                $elemAttrVal = DomUserScriptAttributeCacheLlvm::literalValue(
                    (string) $nsLit,
                    (string) ($localLit ?? '')
                );
            }
            if (null === $elemAttrVal || '' === $elemAttrVal) {
                $elemAttrVal = self::$setAttributeIdValues !== []
                    ? self::$setAttributeIdValues[\count(self::$setAttributeIdValues) - 1]
                    : null;
            }
            if (null !== $elemAttrVal && '' !== $elemAttrVal) {
                $idStr = $context->builder->load($context->constantStringFromString($elemAttrVal));
                self::storeCacheFromRuntimeIdString($context, $element, $idStr, $elemAttrVal);
            } else {
                // NS Attr object cache — getAttribute(local) misses namespaced props (#35303).
                self::storeCacheFromRuntimeGetAttributeNS(
                    $context,
                    $element,
                    (string) $nsLit,
                    $localLit,
                    $localLlvm
                );
            }
            if (null !== $localLit && '' !== $localLit) {
                DomUserScriptAttributeCacheLlvm::markIdBearingLiteral((string) $nsLit, $localLit, true);
            }
        } elseif (JitDomDocumentMethodKernel::shouldUse($context) && !$isIdTrue) {
            DomUserScriptElementCacheLlvm::invalidateIfElement($context, $element);
            $nsLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString ?? '';
            $localLit = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
            if (null !== $localLit && '' !== $localLit) {
                DomUserScriptAttributeCacheLlvm::markIdBearingLiteral((string) $nsLit, $localLit, false);
            }
        }

        return self::boxNull($context);
    }

    public static function invokeNode(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('DOMElement::setIdAttributeNode() expects receiver, attr, and isId');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_set_id_attribute_node_cont');

        // php-src Z_PARAM_OBJ_OF_CLASS(DOMAttr) — null must TypeError, not silent no-op (#33758).
        if (JitDomRequireDomNodeArg::guardOrAbort(
            $context,
            $args[1],
            'DOMElement::setIdAttributeNode',
            1,
            'attr',
            'DOMAttr'
        )) {
            return self::boxNull($context);
        }

        // Thin-AOT getAttributeNode stamps lastFetchedKey — avoid Node ABI on boxed Attr temps (#29884).
        $key = JitDomAttrRename::lastFetchedKey();
        if (null !== $key && '' === $key[0] && '' !== $key[1]) {
            $lit = new \PHPCfg\Operand\Literal($key[1]);
            $lit->type = \PHPTypes\Type::string();
            $nameVar = JITVariable::fromLiteral($context, $lit);

            return self::invoke($context, $args[0], $nameVar, $args[2]);
        }

        $element = self::loadObjectArg($context, $args[0]);
        $attr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::valuePtrFromVariable($context, $args[1])
        );
        $isIdTrue = self::resolveIsIdTrue($context, $args[2]);
        if ($isIdTrue) {
            DomSetIdAttributeRuntime::ensureNodeTrueLinked($context);
            $abi = DomSetIdAttributeRuntime::ABI_NODE_TRUE;
        } else {
            DomSetIdAttributeRuntime::ensureNodeFalseLinked($context);
            $abi = DomSetIdAttributeRuntime::ABI_NODE_FALSE;
        }
        $context->builder->call(
            $context->lookupFunction($abi),
            $element,
            $attr
        );
        if (JitDomDocumentMethodKernel::shouldUse($context) && $isIdTrue) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_set_id_attribute_node_post');
            self::storeCacheFromRuntimeAttrValue($context, $element, $attr);
            $key = JitDomAttrRename::lastFetchedKey();
            if (null !== $key) {
                DomUserScriptAttributeCacheLlvm::markIdBearingLiteral($key[0], $key[1], true);
            }
        } elseif (JitDomDocumentMethodKernel::shouldUse($context) && !$isIdTrue) {
            DomUserScriptElementCacheLlvm::invalidateIfElement($context, $element);
        }

        return self::boxNull($context);
    }

    /**
     * Thin-AOT getElementById cache from the live Attr value (#33957).
     *
     * Prefer NestedJIT / live Attr object over {@see DomUserScriptAttributeCacheLlvm::literalValue}:
     * that map is keyed only by attribute name, so multiple id= values collapse to the last
     * seed and firstChild setIdAttribute registers the wrong id (#34050).
     */
    private static function storeCacheFromRuntimeGetAttribute(
        Context $context,
        Value $element,
        Value $nameLlvm,
        ?string $nameLit = null,
        ?string $idLitForMap = null
    ): void {
        if (null !== $nameLit && '' !== $nameLit) {
            $attr = DomUserScriptAttributeCacheLlvm::lookupLiteral($context, '', $nameLit);
            $objPtr = $context->getTypeFromString('__object__*');
            $isNull = $context->builder->icmp(Builder::INT_EQ, $attr, $objPtr->constNull());
            $miss = BasicBlockHelper::append($context, 'dom_setid_live_miss');
            $hit = BasicBlockHelper::append($context, 'dom_setid_live_hit');
            $done = BasicBlockHelper::append($context, 'dom_setid_live_done');
            $context->builder->branchIf($isNull, $miss, $hit);

            $context->builder->positionAtEnd($miss);
            self::storeCacheFromNestedGetAttribute($context, $element, $nameLlvm, $idLitForMap);
            $context->builder->branch($done);

            $context->builder->positionAtEnd($hit);
            JitDomDocumentMethodKernel::ensureGetAttributeBridge($context);
            $idNested = $context->builder->call(
                $context->lookupFunction(DomImportNodeRuntime::ABI_GET_ATTRIBUTE),
                $element,
                $nameLlvm
            );
            $map = $context->structFieldMap['__string__'];
            $i64 = $context->getTypeFromString('int64');
            $nestedLen = $context->builder->load($context->builder->structGep($idNested, $map['length']));
            $nestedOk = $context->builder->icmp(Builder::INT_NE, $nestedLen, $i64->constInt(0, false));
            $nestedStore = BasicBlockHelper::append($context, 'dom_setid_live_nested_store');
            $attrStore = BasicBlockHelper::append($context, 'dom_setid_live_attr_store');
            $context->builder->branchIf($nestedOk, $nestedStore, $attrStore);

            $context->builder->positionAtEnd($nestedStore);
            self::storeCacheFromRuntimeIdString($context, $element, $idNested, $idLitForMap);
            $context->builder->branch($done);

            $context->builder->positionAtEnd($attrStore);
            $attrClass = JitDomAttributeNodeNS::attrClassForUserScriptCache();
            JitDomAttributeNodeNS::ensureLivingAttrMethods($context);
            $valueVar = $context->type->object->propertyFetch($attr, $attrClass, 'value');
            self::storeCacheFromRuntimeIdString(
                $context,
                $element,
                $context->helper->loadValue($valueVar),
                $idLitForMap ?? DomUserScriptAttributeCacheLlvm::literalValue('', (string) $nameLit)
            );
            $context->builder->branch($done);

            $context->builder->positionAtEnd($done);

            return;
        }
        self::storeCacheFromNestedGetAttribute($context, $element, $nameLlvm, $idLitForMap);
    }

    /**
     * Thin-AOT getElementById cache from a namespaced Attr (#35303).
     *
     * {@see storeCacheFromRuntimeGetAttribute} uses getAttribute(localName), which is empty
     * for {@code x:id="y"} — seed from the NS Attr cache object instead.
     */
    private static function storeCacheFromRuntimeGetAttributeNS(
        Context $context,
        Value $element,
        string $namespace,
        ?string $localLit,
        Value $localLlvm
    ): void {
        if (null !== $localLit && '' !== $localLit) {
            $attr = DomUserScriptAttributeCacheLlvm::lookupLiteral($context, $namespace, $localLit);
            $objPtr = $context->getTypeFromString('__object__*');
            $isNull = $context->builder->icmp(Builder::INT_EQ, $attr, $objPtr->constNull());
            $miss = BasicBlockHelper::append($context, 'dom_setidns_live_miss');
            $hit = BasicBlockHelper::append($context, 'dom_setidns_live_hit');
            $done = BasicBlockHelper::append($context, 'dom_setidns_live_done');
            $context->builder->branchIf($isNull, $miss, $hit);

            $context->builder->positionAtEnd($miss);
            // Last resort: non-NS getAttribute (loadXML unprefixed id= edge).
            self::storeCacheFromNestedGetAttribute($context, $element, $localLlvm, null);
            $context->builder->branch($done);

            $context->builder->positionAtEnd($hit);
            $attrClass = JitDomAttributeNodeNS::attrClassForUserScriptCache();
            JitDomAttributeNodeNS::ensureLivingAttrMethods($context);
            $valueVar = $context->type->object->propertyFetch($attr, $attrClass, 'value');
            self::storeCacheFromRuntimeIdString(
                $context,
                $element,
                $context->helper->loadValue($valueVar),
                DomUserScriptAttributeCacheLlvm::literalValue($namespace, $localLit)
            );
            $context->builder->branch($done);

            $context->builder->positionAtEnd($done);

            return;
        }
        self::storeCacheFromNestedGetAttribute($context, $element, $localLlvm, null);
    }

    private static function storeCacheFromNestedGetAttribute(
        Context $context,
        Value $element,
        Value $nameLlvm,
        ?string $idLitForMap = null
    ): void {
        JitDomDocumentMethodKernel::ensureGetAttributeBridge($context);
        $idStr = $context->builder->call(
            $context->lookupFunction(DomImportNodeRuntime::ABI_GET_ATTRIBUTE),
            $element,
            $nameLlvm
        );
        self::storeCacheFromRuntimeIdString($context, $element, $idStr, $idLitForMap);
    }

    /** setIdAttributeNode — Attr::$value is the id string (#33957). */
    private static function storeCacheFromRuntimeAttrValue(
        Context $context,
        Value $element,
        Value $attr
    ): void {
        $objectType = $context->type->object;
        $attrClass = JitDomAttributeNodeNS::attrClassForUserScriptCache();
        JitDomAttributeNodeNS::ensureLivingAttrMethods($context);
        $attrClassId = $objectType->lookup($attrClass);
        if (!$objectType->hasProperty($attrClassId, 'value')) {
            $objectType->defineProperty($attrClassId, 'value', JITVariable::TYPE_STRING);
        }
        $valueVar = \PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $attr,
            $attrClass,
            'value',
            $attrClassId
        );
        self::storeCacheFromRuntimeIdString(
            $context,
            $element,
            $context->helper->loadValue($valueVar),
            DomUserScriptAttributeCacheLlvm::literalValue('', 'id')
        );
    }

    private static function storeCacheFromRuntimeIdString(
        Context $context,
        Value $element,
        Value $idStr,
        ?string $idLitForMap = null
    ): void {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($idStr, $map['length']));
        $i64 = $context->getTypeFromString('int64');
        $nonEmpty = $context->builder->icmp(Builder::INT_NE, $len, $i64->constInt(0, false));
        $storeBlock = BasicBlockHelper::append($context, 'dom_setid_rt_store');
        $cont = BasicBlockHelper::append($context, 'dom_setid_rt_cont');
        $context->builder->branchIf($nonEmpty, $storeBlock, $cont);

        $context->builder->positionAtEnd($storeBlock);
        $document = self::loadOwnerDocumentObject($context, $element);
        // Single-slot cache is first-wins (xmlAddID); every id also lands in PROP_ELEMENT_ID_MAP.
        DomUserScriptElementCacheLlvm::storeFirstWins($context, $document, $idStr, $element);
        // Multi-id documents: single-slot cache is first-wins; every setIdAttribute id
        // must also land in PROP_ELEMENT_ID_MAP (#34696 / maintainer_gap replaceChild idmap).
        if (null !== $idLitForMap && '' !== $idLitForMap) {
            JitDomLoadXMLUserScript::storeElementInIdMapFromValueFirstWins(
                $context,
                $document,
                $context->builder->load($context->constantStringFromString($idLitForMap)),
                $element
            );
        } else {
            JitDomLoadXMLUserScript::storeElementInIdMapFromValueFirstWins($context, $document, $idStr, $element);
        }
        $context->builder->branch($cont);
        $context->builder->positionAtEnd($cont);
    }

    private static function loadOwnerDocumentObject(Context $context, Value $element): Value
    {
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_OWNER_DOCUMENT)) {
            return $element;
        }
        $ownerVar = $objectType->propertyFetch(
            $element,
            'DOMElement',
            VmDom::PROP_OWNER_DOCUMENT
        );

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::valuePtrFromVariable($context, $ownerVar)
        );
    }

    /**
     * ARG_SEND drops child-edge stamps — recover before id-map seeding (#21644 / #34050).
     */
    private static function seedReceiverCompileTimeAttrs(Context $context, JITVariable $receiver): void
    {
        self::mergeNamedDomStamps($context, $receiver);
        if (null !== $receiver->compileTimeDomAttributes && [] !== $receiver->compileTimeDomAttributes) {
            $fetched = JitDomNodeChildProperty::$lastFetchedAttributes;
            if (null === $fetched || $receiver->compileTimeDomAttributes !== $fetched) {
                return;
            }
        }
        $fromInner = self::compileTimeAttrsFromParentInner($receiver);
        if (null !== $fromInner && [] !== $fromInner) {
            $receiver->compileTimeDomAttributes = $fromInner;

            return;
        }
        $fromRoot = self::compileTimeAttrsFromRootChildTag($receiver);
        if (null !== $fromRoot && [] !== $fromRoot) {
            $receiver->compileTimeDomAttributes = $fromRoot;

            return;
        }
        $fromPath = self::compileTimeAttrsFromNodePath($receiver);
        if (null !== $fromPath && [] !== $fromPath) {
            $receiver->compileTimeDomAttributes = $fromPath;

            return;
        }
        $fetched = JitDomNodeChildProperty::$lastFetchedAttributes;
        if (null === $fetched || [] === $fetched) {
            return;
        }
        $lastPath = JitDomGetNodePath::$lastPath;
        $recvPath = $receiver->compileTimeDomNodePath;
        if (null === $lastPath || null === $recvPath || $lastPath !== $recvPath) {
            return;
        }
        $recvTag = (string) ($receiver->compileTimeDomTagName ?? '');
        if ('' === $recvTag) {
            $path = $receiver->compileTimeDomNodePath;
            if (null !== $path && '' !== $path) {
                $segments = array_values(array_filter(
                    explode('/', $path),
                    static fn (string $s): bool => '' !== $s
                ));
                if ([] !== $segments) {
                    $recvTag = strtolower((string) end($segments));
                }
            }
        } else {
            $recvTag = strtolower($recvTag);
        }
        $fetchTag = strtolower((string) (JitDomNodeChildProperty::$lastFetchedTagName ?? ''));
        if ('' === $recvTag || '' === $fetchTag || $recvTag !== $fetchTag) {
            return;
        }
        $receiver->compileTimeDomAttributes = $fetched;
    }

    private static function mergeNamedDomStamps(Context $context, JITVariable $receiver): void
    {
        if (!isset($context->namedVariableBindings)) {
            return;
        }
        $recvVal = $receiver->value ?? null;
        foreach ($context->namedVariableBindings as $bound) {
            if (!$bound instanceof JITVariable) {
                continue;
            }
            $match = null !== $recvVal && $bound->value === $recvVal;
            if (!$match) {
                continue;
            }
            foreach ([
                'compileTimeDomTagName',
                'compileTimeDomInnerXml',
                'compileTimeDomInnerXmlParent',
                'compileTimeDomChildIndex',
                'compileTimeDomNodePath',
                'compileTimeDomLoadXml',
                'compileTimeDomAttributes',
            ] as $prop) {
                if (null !== $bound->$prop && null === $receiver->$prop) {
                    $receiver->$prop = $bound->$prop;
                }
            }

            return;
        }
        // ARG_SEND temps often diverge from the CV value pointer — recover by unique path.
        $recvPath = $receiver->compileTimeDomNodePath;
        if (null !== $recvPath && '' !== $recvPath) {
            foreach ($context->namedVariableBindings as $bound) {
                if (!$bound instanceof JITVariable || ($bound->compileTimeDomNodePath ?? null) !== $recvPath) {
                    continue;
                }
                self::copyNamedDomStamps($receiver, $bound);

                return;
            }
        }
    }

    private static function copyNamedDomStamps(JITVariable $receiver, JITVariable $bound): void
    {
        foreach ([
            'compileTimeDomTagName',
            'compileTimeDomInnerXml',
            'compileTimeDomInnerXmlParent',
            'compileTimeDomChildIndex',
            'compileTimeDomNodePath',
            'compileTimeDomLoadXml',
            'compileTimeDomAttributes',
        ] as $prop) {
            if (null !== $bound->$prop && null === $receiver->$prop) {
                $receiver->$prop = $bound->$prop;
            }
        }
    }

    /**
     * Walk compile-time loadXML along {@see JITVariable::$compileTimeDomNodePath} (#21644).
     *
     * @return array<string, string>|null
     */
    private static function compileTimeAttrsFromNodePath(JITVariable $receiver): ?array
    {
        $path = $receiver->compileTimeDomNodePath;
        if (null === $path || '' === $path) {
            $lastPath = JitDomGetNodePath::$lastPath;
            $lastTag = JitDomNodeChildProperty::$lastFetchedTagName;
            $recvTag = $receiver->compileTimeDomTagName;
            if (
                null !== $lastPath
                && null !== $lastTag
                && '' !== $lastTag
                && null !== $recvTag
                && $recvTag === $lastTag
            ) {
                $segments = array_values(array_filter(
                    explode('/', $lastPath),
                    static fn (string $s): bool => '' !== $s
                ));
                if ([] !== $segments && ($segments[\count($segments) - 1] ?? '') === $lastTag) {
                    $path = $lastPath;
                    if (null === $receiver->compileTimeDomChildIndex) {
                        $receiver->compileTimeDomChildIndex = JitDomNodeChildProperty::$lastFetchedChildIndex;
                    }
                }
            }
        }
        if (null === $path || '' === $path) {
            return null;
        }
        $segments = array_values(array_filter(
            explode('/', $path),
            static fn (string $s): bool => '' !== $s
        ));
        if (\count($segments) < 2) {
            return null;
        }
        $xml = $receiver->compileTimeDomLoadXml
            ?? JitDomLoadXMLUserScript::compileTimeXmlFor($receiver)
            ?? JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml) {
            return null;
        }
        $inner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml);
        $open = null;
        for ($depth = 1, $n = \count($segments); $depth < $n; ++$depth) {
            $tag = $segments[$depth];
            $nodes = DomParseSimpleXmlJitHelper::parseSiblingNodesArgv($inner);
            $index = ($depth === $n - 1 && null !== $receiver->compileTimeDomChildIndex)
                ? $receiver->compileTimeDomChildIndex
                : null;
            $found = null;
            if (null !== $index && isset($nodes[$index]) && 'element' === ($nodes[$index]['kind'] ?? '')) {
                $candidate = $nodes[$index]['data'] ?? '';
                if ($candidate === $tag) {
                    $found = $nodes[$index];
                }
            }
            if (null === $found) {
                foreach ($nodes as $node) {
                    if ('element' === ($node['kind'] ?? '') && ($node['data'] ?? '') === $tag) {
                        $found = $node;
                        break;
                    }
                }
            }
            if (null === $found) {
                return null;
            }
            if ($depth === $n - 1) {
                $open = $found['open'] ?? null;
                break;
            }
            $inner = $found['inner'] ?? '';
        }
        if (null === $open || '' === $open) {
            return null;
        }
        $attrs = [];
        foreach (DomParseSimpleXmlJitHelper::attributesFromOpenTagArgv($open) as $pair) {
            $attrs[$pair['qname']] = $pair['value'];
            $pos = strpos($pair['qname'], ':');
            if (false !== $pos) {
                $attrs[substr($pair['qname'], $pos + 1)] = $pair['value'];
            }
        }

        return [] !== $attrs ? $attrs : null;
    }

    /**
     * @return array<string, string>|null
     */
    private static function compileTimeAttrsFromParentInner(JITVariable $receiver): ?array
    {
        $parentInner = $receiver->compileTimeDomInnerXmlParent ?? null;
        if (null === $parentInner || '' === $parentInner) {
            $path = $receiver->compileTimeDomNodePath;
            $segments = null !== $path && '' !== $path
                ? array_values(array_filter(explode('/', $path), static fn (string $s): bool => '' !== $s))
                : [];
            if (2 === \count($segments)) {
                $bound = $receiver->compileTimeDomLoadXml
                    ?? JitDomLoadXMLUserScript::compileTimeXmlFor($receiver);
                if (null !== $bound) {
                    $parentInner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($bound);
                }
            } elseif (\count($segments) >= 3 && null === $receiver->compileTimeDomChildIndex) {
                return null;
            }
        }
        if (null === $parentInner || '' === $parentInner) {
            return null;
        }
        $index = $receiver->compileTimeDomChildIndex;
        if (null === $index) {
            return null;
        }
        $nodes = DomParseSimpleXmlJitHelper::parseSiblingNodesArgv($parentInner);
        if (!isset($nodes[$index]) || 'element' !== ($nodes[$index]['kind'] ?? '')) {
            return null;
        }
        $recvTag = $receiver->compileTimeDomTagName;
        if (null === $recvTag || '' === $recvTag) {
            $path = $receiver->compileTimeDomNodePath;
            if (null !== $path && '' !== $path) {
                $segments = array_values(array_filter(
                    explode('/', $path),
                    static fn (string $s): bool => '' !== $s
                ));
                if ([] !== $segments) {
                    $recvTag = end($segments);
                }
            }
        }
        $nodeTag = $nodes[$index]['data'] ?? '';
        if (null !== $recvTag && '' !== $recvTag && $nodeTag !== $recvTag) {
            return null;
        }
        if (null === $recvTag || '' === $recvTag) {
            return null;
        }
        $open = $nodes[$index]['open'] ?? null;
        if (null === $open || '' === $open) {
            return null;
        }
        $attrs = [];
        foreach (DomParseSimpleXmlJitHelper::attributesFromOpenTagArgv($open) as $pair) {
            $attrs[$pair['qname']] = $pair['value'];
            $pos = strpos($pair['qname'], ':');
            if (false !== $pos) {
                $attrs[substr($pair['qname'], $pos + 1)] = $pair['value'];
            }
        }

        return [] !== $attrs ? $attrs : null;
    }

    /**
     * documentElement->firstChild (/root/child) attrs when nested walks stale (#21644).
     *
     * @return array<string, string>|null
     */
    private static function compileTimeAttrsFromRootChildTag(JITVariable $receiver): ?array
    {
        $tag = $receiver->compileTimeDomTagName;
        if (null === $tag || '' === $tag) {
            $path = $receiver->compileTimeDomNodePath;
            if (null === $path || '' === $path) {
                return null;
            }
            $segments = array_values(array_filter(
                explode('/', $path),
                static fn (string $s): bool => '' !== $s
            ));
            if (2 !== \count($segments)) {
                return null;
            }
            $tag = $segments[1];
        }
        $xml = $receiver->compileTimeDomLoadXml
            ?? JitDomLoadXMLUserScript::compileTimeXmlFor($receiver)
            ?? JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml) {
            return null;
        }
        foreach (DomParseSimpleXmlJitHelper::directChildNodesArgv($xml) as $node) {
            if ('element' !== ($node['kind'] ?? '') || ($node['data'] ?? '') !== $tag) {
                continue;
            }
            $open = $node['open'] ?? null;
            if (null === $open || '' === $open) {
                return null;
            }
            $attrs = [];
            foreach (DomParseSimpleXmlJitHelper::attributesFromOpenTagArgv($open) as $pair) {
                $attrs[$pair['qname']] = $pair['value'];
                $pos = strpos($pair['qname'], ':');
                if (false !== $pos) {
                    $attrs[substr($pair['qname'], $pos + 1)] = $pair['value'];
                }
            }

            return [] !== $attrs ? $attrs : null;
        }

        return null;
    }

    /**
     * Attribute value from the receiver's loadXML open-tag stamp (#34050).
     *
     * ARG_SEND / method temps often drop {@see JITVariable::$compileTimeDomAttributes};
     * re-parse under stamped parent inner / lastFetched child walk (#21644 / #34050).
     */
    private static function compileTimeReceiverAttrValue(JITVariable $receiver, ?string $nameLit): ?string
    {
        if (null === $nameLit || '' === $nameLit) {
            return null;
        }
        $attrs = $receiver->compileTimeDomAttributes;
        if (null === $attrs || [] === $attrs) {
            return null;
        }
        if (isset($attrs[$nameLit]) && '' !== $attrs[$nameLit]) {
            return $attrs[$nameLit];
        }
        $pos = strpos($nameLit, ':');
        if (false !== $pos) {
            $local = substr($nameLit, $pos + 1);
            if (isset($attrs[$local]) && '' !== $attrs[$local]) {
                return $attrs[$local];
            }
        }

        return null;
    }

    private static function compileTimeAttrValueFromNodePath(JITVariable $receiver, ?string $nameLit): ?string
    {
        if (null === $nameLit || '' === $nameLit) {
            return null;
        }
        $attrs = self::compileTimeAttrsFromNodePath($receiver);
        if (null === $attrs || [] === $attrs) {
            return null;
        }
        if (isset($attrs[$nameLit]) && '' !== $attrs[$nameLit]) {
            return $attrs[$nameLit];
        }
        $pos = strpos($nameLit, ':');
        if (false !== $pos) {
            $local = substr($nameLit, $pos + 1);
            if (isset($attrs[$local]) && '' !== $attrs[$local]) {
                return $attrs[$local];
            }
        }

        return null;
    }

    private static function compileTimeAttrValueFromRootChildTag(JITVariable $receiver, ?string $nameLit): ?string
    {
        if (null === $nameLit || '' === $nameLit) {
            return null;
        }
        $attrs = self::compileTimeAttrsFromRootChildTag($receiver);
        if (null === $attrs || [] === $attrs) {
            return null;
        }
        if (isset($attrs[$nameLit]) && '' !== $attrs[$nameLit]) {
            return $attrs[$nameLit];
        }
        $pos = strpos($nameLit, ':');
        if (false !== $pos) {
            $local = substr($nameLit, $pos + 1);
            if (isset($attrs[$local]) && '' !== $attrs[$local]) {
                return $attrs[$local];
            }
        }

        return null;
    }

    private static function compileTimeAttrFromInnerParentStamp(JITVariable $receiver, ?string $nameLit): ?string
    {
        if (null === $nameLit || '' === $nameLit) {
            return null;
        }
        $parentInner = $receiver->compileTimeDomInnerXmlParent ?? null;
        $index = $receiver->compileTimeDomChildIndex;
        if (null === $parentInner || '' === $parentInner || null === $index) {
            return null;
        }
        $nodes = DomParseSimpleXmlJitHelper::parseSiblingNodesArgv($parentInner);
        if (!isset($nodes[$index]) || 'element' !== ($nodes[$index]['kind'] ?? '')) {
            return null;
        }
        $open = $nodes[$index]['open'] ?? null;
        if (null === $open || '' === $open) {
            return null;
        }
        $attrs = [];
        foreach (DomParseSimpleXmlJitHelper::attributesFromOpenTagArgv($open) as $pair) {
            $attrs[$pair['qname']] = $pair['value'];
            $pos = strpos($pair['qname'], ':');
            if (false !== $pos) {
                $attrs[substr($pair['qname'], $pos + 1)] = $pair['value'];
            }
        }
        if (isset($attrs[$nameLit]) && '' !== $attrs[$nameLit]) {
            return $attrs[$nameLit];
        }

        return null;
    }

    /** NestedJIT bool load is unsafe — only constant false selects the false ABI (#29257). */
    private static function resolveIsIdTrue(Context $context, JITVariable $arg): bool
    {
        // compileTimeLong is stamped on TYPE_NATIVE_BOOL and boxed bool literals (#26774).
        if (null !== $arg->compileTimeLong) {
            return 0 !== $arg->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_BOOL !== $arg->type) {
            return true;
        }
        $raw = $context->helper->loadValue($arg);
        if (
            method_exists($raw, 'isConstant')
            && $raw->isConstant()
            && method_exists($raw, 'getConstantValue')
            && (int) $raw->getConstantValue() === 0
        ) {
            return false;
        }

        return true;
    }

    private static function loadNamespaceArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return $context->builder->load($context->constantStringFromString(''));
        }

        return JitStringArg::lower($context, $arg, 'DOMElement::setIdAttributeNS() namespace');
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

        throw new \LogicException('DOMElement::setIdAttribute*() expects an object receiver/attr');
    }

    private static function boxNull(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
