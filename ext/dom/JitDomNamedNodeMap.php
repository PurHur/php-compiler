<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * User-script AOT for DOMNamedNodeMap::$length and ::item() (php-src namednodemap.c).
 *
 * Dummy attributes maps used to be allocated without {@code length} / Attr pins;
 * fetching length then SIGSEGVd (#32546, peer NodeList #28672).
 *
 * php-src: ext/dom/namednodemap.c php_dom_get_namednodemap_length /
 *          PHP_METHOD(DOMNamedNodeMap, item)
 */
final class JitDomNamedNodeMap
{
    public const MAX_PINNED_ATTRS = 16;

    private const CLASS_MAP = 'DOMNamedNodeMap';

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
}
