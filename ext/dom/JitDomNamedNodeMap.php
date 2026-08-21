<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * User-script AOT for DOMNamedNodeMap::$length, ::item(), ::getNamedItem(),
 * ::getNamedItemNS() (php-src namednodemap.c).
 *
 * Dummy attributes maps used to be allocated without {@code length} / Attr pins;
 * fetching length then SIGSEGVd (#32546, peer NodeList #28672).
 * {@code getNamedItem()} / {@code getNamedItemNS()} must scan the same pins —
 * NestedJIT DomRegistry lists are absent on thin-AOT maps and abort (#33107 / #33116).
 *
 * php-src: ext/dom/namednodemap.c php_dom_get_namednodemap_length /
 *          PHP_METHOD(DOMNamedNodeMap, item) /
 *          PHP_METHOD(DOMNamedNodeMap, getNamedItem) /
 *          PHP_METHOD(DOMNamedNodeMap, getNamedItemNS)
 */
final class JitDomNamedNodeMap
{
    public const MAX_PINNED_ATTRS = 16;

    private const CLASS_MAP = 'DOMNamedNodeMap';

    private const CLASS_ATTR = 'DOMAttr';

    /** Attr string props matched by getNamedItem (php-src xmlHasProp / nodeName). */
    private const ATTR_NAME_PROPS = ['name', 'localName', 'nodeName'];

    /** Attr local-name props for getNamedItemNS (php-src xmlHasNsProp). */
    private const ATTR_LOCAL_NAME_PROPS = ['localName', 'name'];

    public static function pinProp(int $index): string
    {
        return '__phpcItem'.$index;
    }

    public static function ensureLayout(Object_ $objectType, int $mapClassId): void
    {
        if (!$objectType->hasProperty($mapClassId, VmDom::PROP_LENGTH)) {
            $objectType->defineProperty($mapClassId, VmDom::PROP_LENGTH, JITVariable::TYPE_NATIVE_LONG);
        }
        for ($i = 0; $i < self::MAX_PINNED_ATTRS; ++$i) {
            $pin = self::pinProp($i);
            if (!$objectType->hasProperty($mapClassId, $pin)) {
                $objectType->defineProperty($mapClassId, $pin, JITVariable::TYPE_VALUE);
            }
        }
    }

    public static function isAttributesProperty(string $classLc, string $propLc): bool
    {
        $classLc = strtolower($classLc);

        return 'attributes' === $propLc
            && (
                'domelement' === $classLc
                || 'domnode' === $classLc
                || 'dom\\element' === $classLc
                || 'dom\\htmlelement' === $classLc
            );
    }

    public static function fetchAttributes(Object_ $objectType, Value $obj, string $class): JITVariable
    {
        $classId = $objectType->lookup($class);
        if (!$objectType->hasProperty($classId, VmDom::PROP_ATTRIBUTES)) {
            $objectType->defineProperty($classId, VmDom::PROP_ATTRIBUTES, JITVariable::TYPE_VALUE);
        }
        $var = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            $class,
            VmDom::PROP_ATTRIBUTES,
            $classId
        );
        $var->classUserType = self::CLASS_MAP;
        // So foreach($el->attributes) takes the NamedNodeMap snapshot path (#33099).
        JitDomNodeListForeachSnapshot::markAttributesFetch();

        return $var;
    }

    public static function isLength(string $classLc, string $propLc): bool
    {
        $classLc = strtolower($classLc);

        return VmDom::PROP_LENGTH === $propLc
            && (
                'domnamednodemap' === $classLc
                || 'dom\\namednodemap' === $classLc
                || 'dom\\dtdnamednodemap' === $classLc
            );
    }

    public static function fetchLength(Object_ $objectType, Value $obj): JITVariable
    {
        $mapClassId = $objectType->lookup(self::CLASS_MAP);
        self::ensureLayout($objectType, $mapClassId);

        return ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            self::CLASS_MAP,
            VmDom::PROP_LENGTH,
            $mapClassId
        );
    }

    public static function invokeItem(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_nnm_item_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMNamedNodeMap::item',
            1
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        $map = self::loadObject($context, $args[0]);
        $objectType = $context->type->object;
        self::ensureLayout($objectType, $objectType->lookup(self::CLASS_MAP));

        // Fold only LLVM i64 constants — loop `$i` keeps stale compileTimeLong (#32831).
        $indexLit = null;
        if (
            null !== $args[1]->value
            && \PHPLLVM\Value::KIND_CONSTANT_INT === $args[1]->value->getKind()
        ) {
            $indexLit = $args[1]->compileTimeLong;
        }
        if (null !== $indexLit) {
            return self::boxPinnedOrNull($context, $map, (int) $indexLit);
        }

        return self::emitRuntimeItem($context, $map, self::loadIntArg($context, $args[1]));
    }

    /**
     * DOMNamedNodeMap::getNamedItem() — pin scan by Attr local/node name (#33107).
     *
     * php-src namednodemap.c: Attr maps match local name ({@see xmlHasProp});
     * Entity/Notation maps match declaration nodeName.
     */
    public static function invokeGetNamedItem(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_nnm_getnameditem_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMNamedNodeMap::getNamedItem',
            1
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        $map = self::loadObject($context, $args[0]);
        $objectType = $context->type->object;
        self::ensureLayout($objectType, $objectType->lookup(self::CLASS_MAP));
        self::ensureAttrNameLayout($objectType);

        return self::emitRuntimeGetNamedItem(
            $context,
            $map,
            self::loadStringArg($context, $args[1])
        );
    }

    /**
     * DOMNamedNodeMap::getNamedItemNS() — pin scan by namespaceURI + localName (#33116).
     *
     * php-src namednodemap.c / xmlHasNsProp: null namespace ≡ no namespace (empty URI).
     */
    public static function invokeGetNamedItemNS(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_nnm_getnameditemns_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMNamedNodeMap::getNamedItemNS',
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        $map = self::loadObject($context, $args[0]);
        $objectType = $context->type->object;
        self::ensureLayout($objectType, $objectType->lookup(self::CLASS_MAP));
        self::ensureAttrNameLayout($objectType);
        self::ensureAttrNsLayout($objectType);

        return self::emitRuntimeGetNamedItemNS(
            $context,
            $map,
            self::loadStringArg($context, $args[1]),
            self::loadStringArg($context, $args[2])
        );
    }

    private static function emitRuntimeGetNamedItem(Context $context, Value $map, Value $nameStr): Value
    {
        $fn = $context->builder->getInsertBlock()->getParent();
        $done = $fn->appendBasicBlock('dom_nnm_gni_done');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $objectType = $context->type->object;

        for ($i = 0; $i < self::MAX_PINNED_ATTRS; ++$i) {
            $tryPin = $fn->appendBasicBlock('dom_nnm_gni_try_'.$i);
            $next = $fn->appendBasicBlock('dom_nnm_gni_next_'.$i);
            $context->builder->branch($tryPin);
            $context->builder->positionAtEnd($tryPin);

            $pinRaw = $context->builder->load(
                $objectType->propertySlotFor($map, self::CLASS_MAP, self::pinProp($i))
            );
            $slotNull = $context->builder->icmp(Builder::INT_EQ, $pinRaw, $voidPtr->constNull());
            $readPin = $fn->appendBasicBlock('dom_nnm_gni_read_'.$i);
            $context->builder->branchIf($slotNull, $next, $readPin);

            $context->builder->positionAtEnd($readPin);
            $pinObj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $context->builder->pointerCast($pinRaw, $valuePtrTy)
            );
            $objNull = $context->builder->icmp(Builder::INT_EQ, $pinObj, $objPtrTy->constNull());
            $matchProps = $fn->appendBasicBlock('dom_nnm_gni_props_'.$i);
            $context->builder->branchIf($objNull, $next, $matchProps);

            $context->builder->positionAtEnd($matchProps);
            $matched = self::emitAttrNameMatches($context, $pinObj, $nameStr);
            $hit = $fn->appendBasicBlock('dom_nnm_gni_hit_'.$i);
            $context->builder->branchIf($matched, $hit, $next);

            $context->builder->positionAtEnd($hit);
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $ptr,
                $pinObj
            );
            $context->builder->branch($done);

            $context->builder->positionAtEnd($next);
        }
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    /**
     * Pin scan: Attr.localName/name equals $localName and namespaceURI equals $wantNs
     * (empty string = no namespace; php-src null ≡ '').
     */
    private static function emitRuntimeGetNamedItemNS(
        Context $context,
        Value $map,
        Value $wantNs,
        Value $localName
    ): Value {
        $fn = $context->builder->getInsertBlock()->getParent();
        $done = $fn->appendBasicBlock('dom_nnm_gnins_done');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $objectType = $context->type->object;

        for ($i = 0; $i < self::MAX_PINNED_ATTRS; ++$i) {
            $tryPin = $fn->appendBasicBlock('dom_nnm_gnins_try_'.$i);
            $next = $fn->appendBasicBlock('dom_nnm_gnins_next_'.$i);
            $context->builder->branch($tryPin);
            $context->builder->positionAtEnd($tryPin);

            $pinRaw = $context->builder->load(
                $objectType->propertySlotFor($map, self::CLASS_MAP, self::pinProp($i))
            );
            $slotNull = $context->builder->icmp(Builder::INT_EQ, $pinRaw, $voidPtr->constNull());
            $readPin = $fn->appendBasicBlock('dom_nnm_gnins_read_'.$i);
            $context->builder->branchIf($slotNull, $next, $readPin);

            $context->builder->positionAtEnd($readPin);
            $pinObj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $context->builder->pointerCast($pinRaw, $valuePtrTy)
            );
            $objNull = $context->builder->icmp(Builder::INT_EQ, $pinObj, $objPtrTy->constNull());
            $matchProps = $fn->appendBasicBlock('dom_nnm_gnins_props_'.$i);
            $context->builder->branchIf($objNull, $next, $matchProps);

            $context->builder->positionAtEnd($matchProps);
            $matched = self::emitAttrNsMatches($context, $pinObj, $wantNs, $localName);
            $hit = $fn->appendBasicBlock('dom_nnm_gnins_hit_'.$i);
            $context->builder->branchIf($matched, $hit, $next);

            $context->builder->positionAtEnd($hit);
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $ptr,
                $pinObj
            );
            $context->builder->branch($done);

            $context->builder->positionAtEnd($next);
        }
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    /** True when Attr name / localName / nodeName equals the sought string. */
    private static function emitAttrNameMatches(Context $context, Value $attrObj, Value $nameStr): Value
    {
        $objectType = $context->type->object;
        $attrClassId = $objectType->lookup(self::CLASS_ATTR);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $fn = $context->builder->getInsertBlock()->getParent();
        $any = $fn->appendBasicBlock('dom_nnm_gni_any');
        $no = $fn->appendBasicBlock('dom_nnm_gni_no');
        $phiBlock = $fn->appendBasicBlock('dom_nnm_gni_match_phi');
        $i1 = $context->getTypeFromString('bool');

        foreach (self::ATTR_NAME_PROPS as $prop) {
            $propVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
                $objectType,
                $attrObj,
                self::CLASS_ATTR,
                $prop,
                $attrClassId
            );
            $propStr = $context->helper->loadValue($propVar);
            $cmp = JitStringCompare::strcmp($context, $propStr, $nameStr);
            $eq = $context->builder->icmp(Builder::INT_EQ, $cmp, $zero);
            $cont = $fn->appendBasicBlock('dom_nnm_gni_prop_cont_'.$prop);
            $context->builder->branchIf($eq, $any, $cont);
            $context->builder->positionAtEnd($cont);
        }
        $context->builder->branch($no);

        $context->builder->positionAtEnd($any);
        $context->builder->branch($phiBlock);
        $context->builder->positionAtEnd($no);
        $context->builder->branch($phiBlock);
        $context->builder->positionAtEnd($phiBlock);
        $phi = $context->builder->phi($i1);
        // Incoming order must match branch predecessors (any then no).
        $phi->addIncoming($i1->constInt(1, false), $any);
        $phi->addIncoming($i1->constInt(0, false), $no);

        return $phi;
    }

    /**
     * True when Attr localName/name equals $localName and namespaceURI equals $wantNs.
     */
    private static function emitAttrNsMatches(
        Context $context,
        Value $attrObj,
        Value $wantNs,
        Value $localName
    ): Value {
        $objectType = $context->type->object;
        $attrClassId = $objectType->lookup(self::CLASS_ATTR);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $fn = $context->builder->getInsertBlock()->getParent();
        $yes = $fn->appendBasicBlock('dom_nnm_gnins_yes');
        $no = $fn->appendBasicBlock('dom_nnm_gnins_no');
        $phiBlock = $fn->appendBasicBlock('dom_nnm_gnins_match_phi');
        $i1 = $context->getTypeFromString('bool');

        $nsVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $attrObj,
            self::CLASS_ATTR,
            VmDom::PROP_NAMESPACE_URI,
            $attrClassId
        );
        $nsStr = $context->helper->loadValue($nsVar);
        $nsCmp = JitStringCompare::strcmp($context, $nsStr, $wantNs);
        $nsEq = $context->builder->icmp(Builder::INT_EQ, $nsCmp, $zero);
        $checkLocal = $fn->appendBasicBlock('dom_nnm_gnins_local');
        $context->builder->branchIf($nsEq, $checkLocal, $no);

        $context->builder->positionAtEnd($checkLocal);
        foreach (self::ATTR_LOCAL_NAME_PROPS as $prop) {
            $propVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
                $objectType,
                $attrObj,
                self::CLASS_ATTR,
                $prop,
                $attrClassId
            );
            $propStr = $context->helper->loadValue($propVar);
            $cmp = JitStringCompare::strcmp($context, $propStr, $localName);
            $eq = $context->builder->icmp(Builder::INT_EQ, $cmp, $zero);
            $cont = $fn->appendBasicBlock('dom_nnm_gnins_prop_cont_'.$prop);
            $context->builder->branchIf($eq, $yes, $cont);
            $context->builder->positionAtEnd($cont);
        }
        $context->builder->branch($no);

        $context->builder->positionAtEnd($yes);
        $context->builder->branch($phiBlock);
        $context->builder->positionAtEnd($no);
        $context->builder->branch($phiBlock);
        $context->builder->positionAtEnd($phiBlock);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(1, false), $yes);
        $phi->addIncoming($i1->constInt(0, false), $no);

        return $phi;
    }

    private static function ensureAttrNameLayout(Object_ $objectType): void
    {
        $attrClassId = $objectType->lookup(self::CLASS_ATTR);
        foreach (self::ATTR_NAME_PROPS as $prop) {
            if (!$objectType->hasProperty($attrClassId, $prop)) {
                $objectType->defineProperty($attrClassId, $prop, JITVariable::TYPE_STRING);
            }
        }
    }

    private static function ensureAttrNsLayout(Object_ $objectType): void
    {
        self::ensureAttrNameLayout($objectType);
        $attrClassId = $objectType->lookup(self::CLASS_ATTR);
        if (!$objectType->hasProperty($attrClassId, VmDom::PROP_NAMESPACE_URI)) {
            $objectType->defineProperty($attrClassId, VmDom::PROP_NAMESPACE_URI, JITVariable::TYPE_STRING);
        }
    }

    private static function emitRuntimeItem(Context $context, Value $map, Value $index): Value
    {
        $fn = $context->builder->getInsertBlock()->getParent();
        $done = $fn->appendBasicBlock('dom_nnm_item_rt_done');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i64 = $context->getTypeFromString('int64');
        for ($i = 0; $i < self::MAX_PINNED_ATTRS; ++$i) {
            $hit = $fn->appendBasicBlock('dom_nnm_item_rt_'.$i);
            $miss = $fn->appendBasicBlock('dom_nnm_item_rt_n'.$i);
            $eq = $context->builder->icmp(Builder::INT_EQ, $index, $i64->constInt($i, false));
            $context->builder->branchIf($eq, $hit, $miss);
            $context->builder->positionAtEnd($hit);
            self::writePinnedOrNull($context, $map, $i, $ptr);
            $context->builder->branch($done);
            $context->builder->positionAtEnd($miss);
        }
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function boxPinnedOrNull(Context $context, Value $map, int $index): Value
    {
        $slot = JitValueBox::alloc($context);
        self::writePinnedOrNull($context, $map, $index, JitValueBox::pointer($context, $slot));

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function writePinnedOrNull(Context $context, Value $map, int $index, Value $resultPtr): void
    {
        if ($index < 0 || $index >= self::MAX_PINNED_ATTRS) {
            $context->builder->call($context->lookupFunction('__value__writeNull'), $resultPtr);

            return;
        }
        $objectType = $context->type->object;
        $pinRaw = $context->builder->load(
            $objectType->propertySlotFor($map, self::CLASS_MAP, self::pinProp($index))
        );
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $slotNull = $context->builder->icmp(Builder::INT_EQ, $pinRaw, $voidPtr->constNull());
        $writeNull = BasicBlockHelper::append($context, 'dom_nnm_pin_null');
        $readPin = BasicBlockHelper::append($context, 'dom_nnm_pin_read');
        $done = BasicBlockHelper::append($context, 'dom_nnm_pin_done');
        $context->builder->branchIf($slotNull, $writeNull, $readPin);

        $context->builder->positionAtEnd($writeNull);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $resultPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($readPin);
        $pinObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($pinRaw, $valuePtrTy)
        );
        $objNull = $context->builder->icmp(Builder::INT_EQ, $pinObj, $objPtrTy->constNull());
        $writeObj = BasicBlockHelper::append($context, 'dom_nnm_pin_obj');
        $context->builder->branchIf($objNull, $writeNull, $writeObj);

        $context->builder->positionAtEnd($writeObj);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $resultPtr,
            $pinObj
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function loadObject(Context $context, JITVariable $arg): Value
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

        throw new \LogicException('DOMNamedNodeMap::item() receiver must be an object');
    }

    private static function loadIntArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMNamedNodeMap::item() index must be an integer');
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }
        if (JITVariable::TYPE_NULL === $arg->type) {
            return $context->builder->load($context->constantStringFromString(''));
        }

        throw new \LogicException('DOMNamedNodeMap::getNamedItem() name must be a string');
    }
}
