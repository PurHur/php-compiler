<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomElementTextContentRuntime;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMElement::$textContent / $nodeValue (#17954, #23251).
 *
 * User-script AOT: detach held children via parentNode slots, then install a text child.
 * DomRegistry-backed nodes also call writeTextContent / writeNodeValue.
 */
final class JitDomElementTextContent
{
    private const CLASS_ELEMENT = 'DOMElement';

    private const PROP_TEXT_CONTENT = 'textContent';

    private const PROP_NODE_VALUE = 'nodeValue';

    /** Unique BB labels when Attr/Element textContent arms share one main (#33904). */
    private static int $tcSeq = 0;

    public static function fetch(Object_ $objectType, Value $obj, ?JITVariable $receiverVar = null): JITVariable
    {
        return self::fetchNamed($objectType, $obj, self::PROP_TEXT_CONTENT, $receiverVar);
    }

    public static function fetchNamed(
        Object_ $objectType,
        Value $obj,
        string $propName,
        ?JITVariable $receiverVar = null
    ): JITVariable
    {
        $context = $objectType->jitContext();
        $propLc = strtolower($propName);

        // User-script AOT: always read the seeded STRING slot. NestedJIT of
        // textContentArgv SIGSEGVs after c:main_before_php when there was no
        // loadXML (createElement($name, $value) — #32292 / php-src document.c).
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            $slotProp = 'nodevalue' === $propLc ? self::PROP_NODE_VALUE : self::PROP_TEXT_CONTENT;
            // getAttributeNode temps lose DOMAttr as CFG `object`, so the Element
            // bridge GEPs Attr objects — wrong reads + SIGSEGV writes (#33904 / re-#33864).
            return self::fetchUserScriptTextContentMaybeAttr(
                $objectType,
                $obj,
                $slotProp,
                $receiverVar
            );
        }

        DomElementTextContentRuntime::ensureLinked($context);
        $str = $context->builder->call(
            $context->lookupFunction(DomElementTextContentRuntime::ABI_NAME),
            $obj
        );

        $var = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $str
        );
        $var->objectPropertyReceiver = $obj;
        $var->objectPropertyName = $propName;
        $var->objectPropertyClassName = self::CLASS_ELEMENT;
        $var->objectPropertyType = JITVariable::TYPE_STRING;

        return $var;
    }

    public static function isDomElementTextContent(string $classLc, string $propLc): bool
    {
        $propLc = strtolower($propLc);
        $classLc = strtolower(str_replace('/', '\\', ltrim($classLc, '\\')));
        if (!\in_array($propLc, ['textcontent', 'nodevalue'], true)) {
            return false;
        }
        if ('domelement' === $classLc) {
            return true;
        }
        // User-script AOT often loses DOMElement type on temps after documentElement assign
        // (CFG userType → object); still route textContent writes through the DOM bridge (#23251).
        if (JitDomLoadXMLUserScript::lastLoadWasPureUserScript()
            && \in_array($classLc, ['object', 'stdclass', ''], true)
        ) {
            return true;
        }

        return false;
    }

    /** Dom\Attr / DOMAttr::$value|nodeValue|textContent — sync TYPE_STRING slots (#27108, #33864). */
    public static function isDomAttrValueProperty(string $classLc, string $propLc): bool
    {
        $propLc = strtolower($propLc);
        $classLc = strtolower(str_replace('/', '\\', ltrim($classLc, '\\')));
        if (\in_array($classLc, ['dom\\attr', 'domattr'], true)
            && \in_array($propLc, ['value', 'nodevalue', 'textcontent'], true)
        ) {
            return true;
        }
        // Temps often lose Dom\Attr as CFG `object`. Prefer `$value` only — `$nodeValue` on
        // `object` still belongs to the DOMElement textContent bridge (#23251).
        return 'value' === $propLc
            && JitDomLoadXMLUserScript::lastLoadWasPureUserScript()
            && null !== JitDomLoadXMLUserScript::lastDocumentClass()
            && str_starts_with((string) JitDomLoadXMLUserScript::lastDocumentClass(), 'Dom\\')
            && \in_array($classLc, ['object', 'stdclass', ''], true);
    }

    /**
     * @return bool true when the store was handled (caller must skip propertyStore)
     */
    public static function tryEmitStore(Context $context, JITVariable $lvalue, JITVariable $value): bool
    {
        $prop = $lvalue->objectPropertyName ?? '';
        $class = $lvalue->objectPropertyClassName ?? '';
        $propLc = strtolower($prop);
        $classLc = strtolower(str_replace('/', '\\', ltrim($class, '\\')));
        if (null === $lvalue->objectPropertyReceiver) {
            return false;
        }

        $receiver = $lvalue->objectPropertyReceiver;

        // Living / classic Attr value|nodeValue|textContent — direct TYPE_STRING slot write (#27108, #33864).
        // Must run before isDomElementTextContent so Attr::$nodeValue is not treated as Element.
        if (self::isDomAttrValueProperty($classLc, $propLc)) {
            $str = self::loadStringValue($context, $value);
            self::emitAttrValueSlotSync($context, $receiver, $str, $value);

            return true;
        }

        if (!self::isDomElementTextContent($classLc, $propLc)) {
            return false;
        }

        $str = self::loadStringValue($context, $value);
        $textLit = JitStringBuiltinArg::compileTimeLiteral($value) ?? $value->compileTimeString;

        if (JitDomDocumentMethodKernel::shouldUse($context)
            && JitDomLoadXMLUserScript::lastLoadWasPureUserScript()
        ) {
            // Runtime Attr vs Element — same getAttributeNode typing gap as fetch (#33904).
            $tag = (string) (++self::$tcSeq);
            $bbAttr = BasicBlockHelper::append($context, 'dom_tc_wr_attr_'.$tag);
            $bbElem = BasicBlockHelper::append($context, 'dom_tc_wr_elem_'.$tag);
            $bbDone = BasicBlockHelper::append($context, 'dom_tc_wr_done_'.$tag);
            $isAttr = JitDomAppendChildLiveSlots::isAttrNode($context, $receiver);
            $context->builder->branchIf($isAttr, $bbAttr, $bbElem);

            $context->builder->positionAtEnd($bbAttr);
            self::emitAttrValueSlotSync($context, $receiver, $str, $value);
            $context->builder->branch($bbDone);

            $context->builder->positionAtEnd($bbElem);
            self::emitUserScriptDetachAndReplace($context, $receiver, $str);
            // Child saveXML($node) already uses cleared INNER_XML + textContent (#23251).
            // Parent / document saveXML still read the parent's seeded INNER_XML — splice
            // the child's new outer markup in (peer replaceChild #28671; #33293 / re-#23892).
            if (null !== $textLit) {
                self::syncParentInnerXmlAfterTextContentWrite($context, $receiver, $textLit, $lvalue);
            }
            $hostToken = $lvalue->compileTimeDomImportHostSxeToken
                ?? JitDomImportSimpleXmlUserScript::lastHostImportToken();
            if (null !== $hostToken && null !== $textLit) {
                JitDomImportSimpleXmlUserScript::syncHostSimpleXmlText($context, $hostToken, $textLit);
            }
            // fetchUserScriptTextContentMaybeAttr returns KIND_VALUE without objectPropertySlot
            // (#33904) — always store onto the Element STRING slots so reads/saveXML see the
            // write (regression of #33293 / #33983).
            self::emitElementTextContentSlotSync($context, $receiver, $str);
            $context->builder->branch($bbDone);

            $context->builder->positionAtEnd($bbDone);

            return true;
        }

        DomElementTextContentRuntime::ensureWriteLinked($context);
        $abi = 'nodevalue' === $propLc
            ? DomElementTextContentRuntime::ABI_WRITE_NODE_VALUE
            : DomElementTextContentRuntime::ABI_WRITE_TEXT_CONTENT;
        $context->builder->call(
            $context->lookupFunction($abi),
            $receiver,
            $str
        );

        return true;
    }

    /**
     * User-script textContent/nodeValue fetch — Attr class_id vs Element slots (#33904).
     */
    private static function fetchUserScriptTextContentMaybeAttr(
        Object_ $objectType,
        Value $obj,
        string $slotProp,
        ?JITVariable $receiverVar
    ): JITVariable {
        $context = $objectType->jitContext();
        $tag = (string) (++self::$tcSeq);
        $bbAttr = BasicBlockHelper::append($context, 'dom_tc_rd_attr_'.$tag);
        $bbElem = BasicBlockHelper::append($context, 'dom_tc_rd_elem_'.$tag);
        $bbDone = BasicBlockHelper::append($context, 'dom_tc_rd_done_'.$tag);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $resultPtr = BasicBlockHelper::entryAlloca($context, $strPtrTy);

        $isAttr = JitDomAppendChildLiveSlots::isAttrNode($context, $obj);
        $context->builder->branchIf($isAttr, $bbAttr, $bbElem);

        $context->builder->positionAtEnd($bbAttr);
        $attrClass = JitDomAttributeNodeNS::attrClassForUserScriptCache();
        JitDomAttributeNodeNS::ensureLivingAttrMethods($context);
        $attrClassId = $objectType->lookup($attrClass);
        if (!$objectType->hasProperty($attrClassId, $slotProp)) {
            $objectType->defineProperty($attrClassId, $slotProp, JITVariable::TYPE_STRING);
        }
        $attrFetched = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            $attrClass,
            $slotProp,
            $attrClassId
        );
        $context->builder->store($context->helper->loadValue($attrFetched), $resultPtr);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbElem);
        $classId = $objectType->lookup(self::CLASS_ELEMENT);
        if (!$objectType->hasProperty($classId, $slotProp)) {
            $objectType->defineProperty($classId, $slotProp, JITVariable::TYPE_STRING);
        }
        $elemFetched = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            self::CLASS_ELEMENT,
            $slotProp,
            $classId
        );
        $context->builder->store($context->helper->loadValue($elemFetched), $resultPtr);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $var = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $context->builder->load($resultPtr)
        );
        // Class name stays DOMElement so tryEmitStore still claims the write; runtime
        // isAttrNode there selects Attr slot sync (#33904).
        $var->objectPropertyReceiver = $obj;
        $var->objectPropertyName = $slotProp;
        $var->objectPropertyClassName = self::CLASS_ELEMENT;
        $var->objectPropertyType = JITVariable::TYPE_STRING;
        if (null !== $receiverVar?->compileTimeDomChildIndex) {
            $var->compileTimeDomChildIndex = $receiverVar->compileTimeDomChildIndex;
        } elseif (null !== JitDomNodeChildProperty::$lastFetchedChildIndex) {
            // Assign temps often drop compileTimeDomChildIndex (#33983 / peer #32947).
            $var->compileTimeDomChildIndex = JitDomNodeChildProperty::$lastFetchedChildIndex;
        }
        if (null !== $receiverVar?->compileTimeDomTagName) {
            $var->compileTimeDomTagName = $receiverVar->compileTimeDomTagName;
        } elseif (null !== JitDomNodeChildProperty::$lastFetchedTagName) {
            $var->compileTimeDomTagName = JitDomNodeChildProperty::$lastFetchedTagName;
        }
        if (null !== $receiverVar?->compileTimeDomImportHostSxeToken) {
            $var->compileTimeDomImportHostSxeToken = $receiverVar->compileTimeDomImportHostSxeToken;
        }

        return $var;
    }

    /** Sync Dom\Attr / DOMAttr value|nodeValue|textContent TYPE_STRING slots (#33864 / #33904). */
    private static function emitAttrValueSlotSync(
        Context $context,
        Value $receiver,
        Value $str,
        JITVariable $value
    ): void {
        $attrClass = JitDomAttributeNodeNS::attrClassForUserScriptCache();
        JitDomAttributeNodeNS::ensureLivingAttrMethods($context);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        foreach (['value', 'nodeValue', 'textContent'] as $syncProp) {
            $context->type->object->propertyStore(
                $context->type->object->propertySlotFor($receiver, $attrClass, $syncProp),
                new JITVariable(
                    $context,
                    JITVariable::TYPE_STRING,
                    JITVariable::KIND_VALUE,
                    $owned
                ),
                JITVariable::TYPE_STRING
            );
        }
        $valueLit = JitStringBuiltinArg::compileTimeLiteral($value) ?? $value->compileTimeString;
        if (null === $valueLit) {
            return;
        }
        // getAttributeNode remembers the key via lastFetchedKey; createAttribute via
        // lastCreateLocalName. Prefer fetch key so loadXML Attr writes refresh saveXML (#34305).
        // createAttribute orphan must prefer lastCreate — a prior getAttributeNode leaves
        // lastFetchedKey pointing at a different Attr (#35118).
        if (JitDomAttrRename::lastAttrIsOrphan()) {
            $ns = DomUserScriptAttributeCacheLlvm::lastCreateNamespace() ?? '';
            $local = DomUserScriptAttributeCacheLlvm::lastCreateLocalName();
        } else {
            $fetched = JitDomAttrRename::lastFetchedKey();
            $ns = $fetched[0] ?? DomUserScriptAttributeCacheLlvm::lastCreateNamespace() ?? '';
            $local = $fetched[1] ?? DomUserScriptAttributeCacheLlvm::lastCreateLocalName();
        }
        if (null === $local || 'xmlns' === $local) {
            return;
        }
        // createAttributeNS open-tag keys are qName (+ xmlns:prefix); bare localName
        // duplicates the attr in saveXML (#34926 leftover of #34305 / #33578).
        // Only reuse lastCreateQualifiedName when it names this same Attr (ns+local).
        $qname = $local;
        $createdLocal = DomUserScriptAttributeCacheLlvm::lastCreateLocalName();
        $createdNs = DomUserScriptAttributeCacheLlvm::lastCreateNamespace() ?? '';
        $createdQ = DomUserScriptAttributeCacheLlvm::lastCreateQualifiedName();
        if (null !== $createdQ && $createdLocal === $local && $createdNs === $ns) {
            $qname = $createdQ;
        }
        DomUserScriptAttributeCacheLlvm::setLiteralValue($ns, $local, $valueLit);
        self::syncOwnerElementSaveXmlAfterAttrValueWrite(
            $context,
            $receiver,
            $ns,
            $local,
            $qname,
            $valueLit
        );
    }

    /**
     * Attr slot writes leave PROP_USER_SCRIPT_XMLNS_ATTR / INNER_XML stale (#34305).
     *
     * Mirror setAttribute / removeAttribute: refresh loadXML compile-time open-tag and
     * push onto Attr::$ownerElement (peer #32981 / #34257).
     *
     * Namespaced Attrs must refresh the qName bag key (php-src xmlSetNsProp), not the
     * localName alone (#34926).
     */
    private static function syncOwnerElementSaveXmlAfterAttrValueWrite(
        Context $context,
        Value $attr,
        string $ns,
        string $local,
        string $qname,
        string $valueLit
    ): void {
        $hadLoadXml = null !== JitDomLoadXMLUserScript::lastCompileTimeXml()
            && JitDomLoadXMLUserScript::lastLoadWasPureUserScript();
        $id = JitDomCreateElementAttrs::lastId();
        if (null !== $id) {
            if ('' !== $ns) {
                $updates = JitDomAttributeNodeNS::openTagAttrUpdates($ns, $qname, $valueLit);
                foreach ($updates as $name => $val) {
                    JitDomCreateElementAttrs::set($id, $name, $val);
                }
                // Drop a prior bare-local key if a previous wrong write left one (#34926).
                if ($qname !== $local) {
                    JitDomCreateElementAttrs::remove($id, $local);
                }
            } else {
                JitDomCreateElementAttrs::set($id, $local, $valueLit);
            }
        }
        if ($hadLoadXml) {
            // loadXML open-tag for NS attrs still uses the serialized qName when present.
            $openName = ('' !== $ns && $qname !== $local) ? $qname : $local;
            JitDomLoadXMLUserScript::refreshCompileTimeXmlRootAttributeSet($openName, $valueLit);
        }
        if (!$hadLoadXml && null === $id) {
            return;
        }

        // storeOwnerElement writes boxed TYPE_VALUE on DOMAttr (#33570 / #22709).
        $attrClass = 'DOMAttr';
        $objectType = $context->type->object;
        $attrClassId = $objectType->lookup($attrClass);
        if (!$objectType->hasProperty($attrClassId, VmDom::PROP_OWNER_ELEMENT)) {
            $objectType->defineProperty($attrClassId, VmDom::PROP_OWNER_ELEMENT, JITVariable::TYPE_VALUE);
        }
        $ownerElPtr = $context->builder->load(
            $objectType->propertySlotFor($attr, $attrClass, VmDom::PROP_OWNER_ELEMENT)
        );
        $ownerRaw = $context->builder->pointerCast(
            $ownerElPtr,
            $context->getTypeFromString('__value__*')
        );
        $ownerNull = \PHPCompiler\JIT\JitNestedHelperCoerce::isHelperResultNull($context, $ownerRaw);
        $tag = (string) (++self::$tcSeq);
        $bbNull = BasicBlockHelper::append($context, 'dom_attr_tc_owner_null_'.$tag);
        $bbHas = BasicBlockHelper::append($context, 'dom_attr_tc_owner_has_'.$tag);
        $bbDone = BasicBlockHelper::append($context, 'dom_attr_tc_owner_done_'.$tag);
        $context->builder->branchIf($ownerNull, $bbNull, $bbHas);

        $context->builder->positionAtEnd($bbHas);
        $ownerObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::normalizeValuePtr($context, $ownerRaw)
        );
        $ownerVar = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $ownerObj
        );
        if ($hadLoadXml) {
            JitDomLoadXMLUserScript::syncElementXmlnsAttrFromCompileTimeXml($context, $ownerVar);
        } elseif (null !== $id) {
            JitDomAttributeNodeNS::syncSaveXmlAttrSuffix(
                $context,
                $ownerVar,
                JitDomCreateElementAttrs::get($id)
            );
        }
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbNull);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    /**
     * Sync DOMElement textContent/nodeValue STRING slots after a user-script write (#33983).
     *
     * Thin-AOT fetch reads these seeded slots; #33904's Attr/Element fetch no longer
     * exposes objectPropertySlot, so stores must target propertySlotFor directly.
     */
    private static function emitElementTextContentSlotSync(
        Context $context,
        Value $receiver,
        Value $str
    ): void {
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $jitStr = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $owned
        );
        // Keep aliases in sync — Element nodeValue mirrors textContent under php-src.
        foreach ([self::PROP_TEXT_CONTENT, self::PROP_NODE_VALUE] as $syncProp) {
            if (!$objectType->hasProperty($elementClassId, $syncProp)) {
                $objectType->defineProperty($elementClassId, $syncProp, JITVariable::TYPE_STRING);
            }
            $objectType->propertyStore(
                $objectType->propertySlotFor($receiver, self::CLASS_ELEMENT, $syncProp),
                $jitStr,
                JITVariable::TYPE_STRING
            );
        }
    }

    /**
     * Detach children like php_libxml_node_free_list (#23251, #23892).
     *
     * First held child keeps a null parentNode; later siblings are marked freed so
     * property access raises dom_objects_not_found(). Then install a text stand-in.
     */
    private static function emitUserScriptDetachAndReplace(
        Context $context,
        Value $receiver,
        Value $textStr
    ): void {
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
        }

        $objPtrTy = $context->getTypeFromString('__object__*');
        $firstObj = self::loadChildObjectFromSlot(
            $context,
            $objectType,
            $receiver,
            VmDom::PROP_FIRST_CHILD
        );
        $lastObj = self::loadChildObjectFromSlot(
            $context,
            $objectType,
            $receiver,
            VmDom::PROP_LAST_CHILD
        );

        // Null parentNode on firstChild (retained user handle).
        $firstNull = $context->builder->icmp(Builder::INT_EQ, $firstObj, $objPtrTy->constNull());
        $detachFirst = BasicBlockHelper::append($context, 'dom_tc_detach_first');
        $afterFirst = BasicBlockHelper::append($context, 'dom_tc_after_first');
        $context->builder->branchIf($firstNull, $afterFirst, $detachFirst);
        $context->builder->positionAtEnd($detachFirst);
        $nullSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $nullSlot)
        );
        $nullVar = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $nullSlot
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($firstObj, self::CLASS_ELEMENT, VmDom::PROP_PARENT_NODE),
            $nullVar,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($afterFirst);
        $context->builder->positionAtEnd($afterFirst);

        // Mark lastChild freed when it is a distinct sibling (#23892).
        $lastNull = $context->builder->icmp(Builder::INT_EQ, $lastObj, $objPtrTy->constNull());
        $sameAsFirst = $context->builder->icmp(Builder::INT_EQ, $lastObj, $firstObj);
        $skipLast = $context->builder->or($lastNull, $sameAsFirst);
        $markLast = BasicBlockHelper::append($context, 'dom_tc_mark_last_freed');
        $afterLast = BasicBlockHelper::append($context, 'dom_tc_after_last');
        $context->builder->branchIf($skipLast, $afterLast, $markLast);
        $context->builder->positionAtEnd($markLast);
        JitDomParentNodeProperty::markFreed($context, $lastObj);
        $context->builder->branch($afterLast);
        $context->builder->positionAtEnd($afterLast);

        $textNode = JitDomCreateTextNode::materialize($context);
        if (!$objectType->hasProperty($elementClassId, self::PROP_TEXT_CONTENT)) {
            $objectType->defineProperty($elementClassId, self::PROP_TEXT_CONTENT, JITVariable::TYPE_STRING);
        }
        $textJitStr = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $textStr
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($textNode, self::CLASS_ELEMENT, self::PROP_TEXT_CONTENT),
            $textJitStr,
            JITVariable::TYPE_STRING
        );
        $textJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $textNode
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($receiver, 'DOMElement', VmDom::PROP_FIRST_CHILD),
            $textJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($receiver, 'DOMElement', VmDom::PROP_LAST_CHILD),
            $textJit,
            JITVariable::TYPE_VALUE
        );
        $parentJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $receiver
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($textNode, self::CLASS_ELEMENT, VmDom::PROP_PARENT_NODE),
            $parentJit,
            JITVariable::TYPE_VALUE
        );
        JitDomDocumentElement::storeChildNodesLength($context, $receiver, 1);
        // Prefer empty inner markup so saveXML falls back to textContent (#26757 / #23251).
        JitDomCreateElement::storeUserScriptInnerXml($context, $receiver, '');
    }

    /**
     * After a child textContent write, refresh the parent's PROP_USER_SCRIPT_INNER_XML
     * so saveXML($parent) / saveXML() match Zend (#33293 / re-#23892).
     *
     * documentElement textContent writes leave lastFetchedChildIndex null
     * ({@see JitDomGetNodePath}) — skip; the receiver's own INNER_XML was cleared above.
     */
    private static function syncParentInnerXmlAfterTextContentWrite(
        Context $context,
        Value $receiver,
        string $textLit,
        JITVariable $lvalue
    ): void {
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return;
        }
        $nodes = DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
        // Prefer the element Variable's stamped index (survives nextSibling) over
        // lastFetched* statics — documentElement writes leave index null (#33293).
        // Prefer the element Variable's stamped index (survives nextSibling) over
        // lastFetched* statics — documentElement writes leave lastFetched null
        // ({@see JitDomGetNodePath}). Fall back when assign/temps drop the stamp (#33983).
        $index = $lvalue->compileTimeDomChildIndex
            ?? JitDomNodeChildProperty::$lastFetchedChildIndex
            ?? null;
        $tag = $lvalue->compileTimeDomTagName
            ?? JitDomNodeChildProperty::$lastFetchedTagName
            ?? null;
        if (null === $index) {
            // No child-index on the receiver: this is documentElement (or unknown).
            // emitUserScriptDetachAndReplace already cleared its own INNER_XML.
            return;
        }
        if (null === $tag || '' === $tag) {
            if ($index < 0 || $index >= \count($nodes)
                || 'element' !== ($nodes[$index]['kind'] ?? '')
            ) {
                return;
            }
            $tag = $nodes[$index]['data'];
        }
        if ($index < 0 || $index >= \count($nodes)) {
            return;
        }
        $attrs = '';
        if (isset($nodes[$index]['open']) && \is_string($nodes[$index]['open'])) {
            $attrs = DomParseSimpleXmlJitHelper::attrSuffixFromOpenTagArgv($nodes[$index]['open']);
        }
        $escaped = htmlspecialchars($textLit, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $replacement = '' === $escaped
            ? '<'.$tag.$attrs.'/>'
            : '<'.$tag.$attrs.'>'.$escaped.'</'.$tag.'>';
        $newInner = DomParseSimpleXmlJitHelper::rootInnerXmlReplaceChildAt($xml, $index, $replacement);
        if (null === $newInner) {
            return;
        }

        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
        }
        // parentNode lives on DOMElement slots (peer JitDomParentNodeProperty / #23251) —
        // loading via DOMNode aliases the wrong field and leaves the real parent stale.
        $slotPtr = $context->builder->load(
            $objectType->propertySlotFor($receiver, self::CLASS_ELEMENT, VmDom::PROP_PARENT_NODE)
        );
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $isNullSlot = $context->builder->icmp(Builder::INT_EQ, $slotPtr, $voidPtr->constNull());
        $nullBlock = BasicBlockHelper::append($context, 'dom_tc_parent_slot_null');
        $readBlock = BasicBlockHelper::append($context, 'dom_tc_parent_slot_read');
        $merge = BasicBlockHelper::append($context, 'dom_tc_parent_slot_merge');
        $context->builder->branchIf($isNullSlot, $nullBlock, $readBlock);
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($readBlock);
        $valuePtr = $context->builder->pointerCast(
            $slotPtr,
            $context->getTypeFromString('__value__*')
        );
        $parentFromSlot = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $parentObj = $context->builder->phi($objPtrTy);
        $parentObj->addIncoming($objPtrTy->constNull(), $nullBlock);
        $parentObj->addIncoming($parentFromSlot, $readBlock);

        $parentNull = $context->builder->icmp(Builder::INT_EQ, $parentObj, $objPtrTy->constNull());
        $doStore = BasicBlockHelper::append($context, 'dom_tc_parent_inner_store');
        $afterStore = BasicBlockHelper::append($context, 'dom_tc_parent_inner_done');
        $context->builder->branchIf($parentNull, $afterStore, $doStore);
        $context->builder->positionAtEnd($doStore);
        JitDomCreateElement::storeUserScriptInnerXml($context, $parentObj, $newInner);
        JitDomLoadXMLUserScript::refreshCompileTimeXmlWithRootInner($newInner, null);
        $context->builder->branch($afterStore);
        $context->builder->positionAtEnd($afterStore);
    }

    /** Load __object__* from a DOMElement firstChild/lastChild TYPE_VALUE slot (or null). */
    private static function loadChildObjectFromSlot(
        Context $context,
        Object_ $objectType,
        Value $receiver,
        string $prop
    ): Value {
        // Peer JitDomDocumentElement::storeFirstLast / JitDomParentNodeProperty (#33807).
        $childSlot = $objectType->propertySlotFor($receiver, self::CLASS_ELEMENT, $prop);
        $slotPtr = $context->builder->load($childSlot);
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $isNullSlot = $context->builder->icmp(Builder::INT_EQ, $slotPtr, $voidPtr->constNull());
        $nullBlock = BasicBlockHelper::append($context, 'dom_tc_load_'.$prop.'_null');
        $readBlock = BasicBlockHelper::append($context, 'dom_tc_load_'.$prop.'_read');
        $merge = BasicBlockHelper::append($context, 'dom_tc_load_'.$prop.'_merge');
        $context->builder->branchIf($isNullSlot, $nullBlock, $readBlock);
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($readBlock);
        $valuePtr = $context->builder->pointerCast(
            $slotPtr,
            $context->getTypeFromString('__value__*')
        );
        $childObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($objPtrTy);
        $phi->addIncoming($objPtrTy->constNull(), $nullBlock);
        $phi->addIncoming($childObj, $readBlock);

        return $phi;
    }

    public static function loadObjectFromReceiver(Context $context, JITVariable $receiver): Value
    {
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (JITVariable::TYPE_VALUE === $receiver->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException('DOMElement::$textContent receiver must be an object');
    }

    private static function loadStringValue(Context $context, JITVariable $value): Value
    {
        if (JITVariable::TYPE_STRING === $value->type) {
            return $context->helper->loadValue($value);
        }
        if (JITVariable::TYPE_NULL === $value->type || $value->isNullConstant) {
            return $context->builder->load($context->constantStringFromString(''));
        }
        if (JITVariable::TYPE_VALUE === $value->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitValueBox::valuePtrFromVariable($context, $value)
            );
        }

        $lit = JitStringBuiltinArg::compileTimeLiteral($value) ?? $value->compileTimeString;
        if (null !== $lit) {
            return $context->builder->load($context->constantStringFromString($lit));
        }

        return $context->builder->load($context->constantStringFromString(''));
    }
}
