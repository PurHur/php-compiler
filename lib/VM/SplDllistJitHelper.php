<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT SplDoublyLinkedList / SplQueue / SplStack — object `__spl_ht` deque (#26790).
 *
 * php-src: ext/spl/spl_dllist.c — push/pop/shift/unshift/top/bottom; SplQueue enqueue/dequeue; SplStack push/pop/top.
 * Serialize bag: flags + dllist + members (#33966; peer #33625 / #33876).
 */
final class SplDllistJitHelper
{
    public const PROP_HT = '__spl_ht';

    public static function compileConstruct(Context $context, JITVariable $receiver, string $className): Value
    {
        $obj = self::loadObject($context, $receiver);
        $objectType = $context->type->object;
        $ht = HashTableHelper::alloc($context);
        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
        $slot = $objectType->propertySlotFor($obj, $className, self::PROP_HT);
        $objectType->propertyStore($slot, $htVar, JITVariable::TYPE_HASHTABLE);
        $objectType->markObjectConstructed($obj);

        return self::voidResult($context);
    }

    public static function compilePush(Context $context, JITVariable $receiver, JITVariable $value): Value
    {
        $obj = self::loadObject($context, $receiver);
        $htVar = self::htVar($context, $obj);
        $htVar->nextFreeElementFromRuntime = true;
        HashTableHelper::addElement($context, $htVar, $value, null);

        return self::voidResult($context);
    }

    /** SplQueue::enqueue ≡ push. */
    public static function compileEnqueue(Context $context, JITVariable $receiver, JITVariable $value): Value
    {
        return self::compilePush($context, $receiver, $value);
    }

    /**
     * Insert at front of packed `__spl_ht` (php-src spl_ptr_llist_unshift / #27311).
     *
     * Grow with push, then rotate slots right so index 0 holds $value.
     */
    public static function compileUnshift(Context $context, JITVariable $receiver, JITVariable $value): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $htVar = self::htVar($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $oldN = $context->builder->load($context->builder->structGep($ht, $map['numElements']));

        // Append a slot (becomes temporary last element), then slide [0..oldN) → [1..oldN].
        $htVar->nextFreeElementFromRuntime = true;
        HashTableHelper::addElement($context, $htVar, $value, null);

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($oldN, $iSlot);
        $condBb = BasicBlockHelper::append($context, 'spldllist_unshift_cond');
        $moveBb = BasicBlockHelper::append($context, 'spldllist_unshift_move');
        $headBb = BasicBlockHelper::append($context, 'spldllist_unshift_head');
        $context->builder->branch($condBb);

        $context->builder->positionAtEnd($condBb);
        $i = $context->builder->load($iSlot);
        $more = $context->builder->icmp(
            Builder::INT_UGT,
            $i,
            $sizeT->constInt(0, false)
        );
        $context->builder->branchIf($more, $moveBb, $headBb);

        $context->builder->positionAtEnd($moveBb);
        $prev = $context->builder->sub($i, $sizeT->constInt(1, false));
        $elem = HashTableHelper::readIndexedToValueBox($context, $ht, $prev);
        HashTableHelper::setAtIndex($context, $ht, $i, $elem);
        $context->builder->store($prev, $iSlot);
        $context->builder->branch($condBb);

        $context->builder->positionAtEnd($headBb);
        HashTableHelper::setAtIndex($context, $ht, $sizeT->constInt(0, false), $value);

        return self::voidResult($context);
    }

    public static function compilePop(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $out = JitValueBox::alloc($context);
        $emptyBb = BasicBlockHelper::append($context, 'spldllist_pop_empty');
        $bodyBb = BasicBlockHelper::append($context, 'spldllist_pop_body');
        $doneBb = BasicBlockHelper::append($context, 'spldllist_pop_done');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyBb, $bodyBb);

        $context->builder->positionAtEnd($emptyBb);
        // Match Zend RuntimeException message only when thrown — thin AOT returns null on empty
        // for the silent-wrong-output fix (#26790); empty throw path is VM-covered.
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $out)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($bodyBb);
        $lastIdx = $context->builder->sub($n, $sizeT->constInt(1, false));
        $fetched = HashTableHelper::readIndexedToValueBox($context, $ht, $lastIdx);
        JitValueBox::copyFromPointer(
            $context,
            $out,
            JitValueBox::valuePtrFromVariable($context, $fetched)
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetLongAt'),
            $ht,
            $lastIdx
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $out;
    }

    /**
     * SplDoublyLinkedList::top / SplStack::top — peek last packed slot without removal (#28704).
     * Empty: thin AOT returns null (Zend throws; VM covers throw).
     */
    public static function compileTop(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $out = JitValueBox::alloc($context);
        $emptyBb = BasicBlockHelper::append($context, 'spldllist_top_empty');
        $bodyBb = BasicBlockHelper::append($context, 'spldllist_top_body');
        $doneBb = BasicBlockHelper::append($context, 'spldllist_top_done');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyBb, $bodyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $out)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($bodyBb);
        $lastIdx = $context->builder->sub($n, $sizeT->constInt(1, false));
        $fetched = HashTableHelper::readIndexedToValueBox($context, $ht, $lastIdx);
        JitValueBox::copyFromPointer(
            $context,
            $out,
            JitValueBox::valuePtrFromVariable($context, $fetched)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $out;
    }

    /**
     * SplDoublyLinkedList::bottom — peek first packed slot without removal (#28704).
     * Empty: thin AOT returns null (Zend throws; VM covers throw).
     */
    public static function compileBottom(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $out = JitValueBox::alloc($context);
        $emptyBb = BasicBlockHelper::append($context, 'spldllist_bottom_empty');
        $bodyBb = BasicBlockHelper::append($context, 'spldllist_bottom_body');
        $doneBb = BasicBlockHelper::append($context, 'spldllist_bottom_done');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyBb, $bodyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $out)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($bodyBb);
        $fetched = HashTableHelper::readIndexedToValueBox(
            $context,
            $ht,
            $sizeT->constInt(0, false)
        );
        JitValueBox::copyFromPointer(
            $context,
            $out,
            JitValueBox::valuePtrFromVariable($context, $fetched)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $out;
    }

    public static function compileShift(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $out = JitValueBox::alloc($context);
        $emptyBb = BasicBlockHelper::append($context, 'spldllist_shift_empty');
        $bodyBb = BasicBlockHelper::append($context, 'spldllist_shift_body');
        $doneBb = BasicBlockHelper::append($context, 'spldllist_shift_done');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyBb, $bodyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $out)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($bodyBb);
        $zero = $sizeT->constInt(0, false);
        $fetched = HashTableHelper::readIndexedToValueBox($context, $ht, $zero);
        JitValueBox::copyFromPointer(
            $context,
            $out,
            JitValueBox::valuePtrFromVariable($context, $fetched)
        );

        // Move packed slots [1..n) → [0..n-1); then unset last.
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $iSlot);
        $condBb = BasicBlockHelper::append($context, 'spldllist_shift_cond');
        $moveBb = BasicBlockHelper::append($context, 'spldllist_shift_move');
        $shrinkBb = BasicBlockHelper::append($context, 'spldllist_shift_shrink');
        $context->builder->branch($condBb);

        $context->builder->positionAtEnd($condBb);
        $i = $context->builder->load($iSlot);
        $limit = $context->builder->sub($n, $sizeT->constInt(1, false));
        $more = $context->builder->icmp(Builder::INT_ULT, $i, $limit);
        $context->builder->branchIf($more, $moveBb, $shrinkBb);

        $context->builder->positionAtEnd($moveBb);
        $next = $context->builder->add($i, $sizeT->constInt(1, false));
        $elem = HashTableHelper::readIndexedToValueBox($context, $ht, $next);
        HashTableHelper::setAtIndex($context, $ht, $i, $elem);
        $context->builder->store($next, $iSlot);
        $context->builder->branch($condBb);

        $context->builder->positionAtEnd($shrinkBb);
        $lastIdx = $context->builder->sub($n, $sizeT->constInt(1, false));
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetLongAt'),
            $ht,
            $lastIdx
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $out;
    }

    /** SplQueue::dequeue ≡ shift. */
    public static function compileDequeue(Context $context, JITVariable $receiver): Value
    {
        return self::compileShift($context, $receiver);
    }

    /**
     * php-src zim_SplDoublyLinkedList_count — zend_hash_num_elements on storage (#32910).
     */
    public static function compileCount(Context $context, JITVariable $receiver): Value
    {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong(
            $context,
            $slot,
            $context->builder->truncOrBitCast($n, $context->getTypeFromString('int64'))
        );

        return $slot;
    }

    /**
     * php-src zim_SplDoublyLinkedList_isEmpty — zend_hash_num_elements == 0 (#33973).
     * Without a thin-AOT proxy, unbound isEmpty lowered to null (#579) so drained queues
     * always looked non-empty in boolean context.
     */
    public static function compileIsEmpty(Context $context, JITVariable $receiver): Value
    {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $empty = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $empty);

        return $slot;
    }

    /**
     * php-src SplDoublyLinkedList serialize — flags + dllist HT + empty members (#33966).
     *
     * Prefer helper-runtime (avoid PHP_COMPILER_HELPER_RUNTIME_O=0) — peer #32925 / #33876.
     *
     * @return Value {@see __string__*} full `O:len:"Class":3:{…}` wire
     */
    public static function compileSerialize(Context $context, JITVariable $receiver): Value
    {
        \PHPCompiler\JIT\Builtin\StringSerialize::ensureLinked($context);
        $obj = self::loadObject($context, $receiver);
        $objVar = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj);
        $classNameStr = ReflectionBuiltinHelper::getClassName($context, $objVar);
        $ht = self::htPtr($context, $obj);
        $logical = 'PHPCompiler\\ext\\standard\\SerializeSplDllistNestedJitHelper::encodeWire';
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled(
            $context,
            '/ext/standard/SerializeSplDllistNestedJitHelper.php',
            [$logical],
            '#33966'
        );
        BasicBlockHelper::restoreInsertBlock($context, $saved);
        $fn = \PHPCompiler\JIT\JitVmHelperLink::lookupCompiled($context, $logical, '#33966');
        $strMap = $context->structFieldMap['__string__'];
        $classLen = $context->builder->load(
            $context->builder->structGep($classNameStr, $strMap['length'])
        );
        $args = [
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $classNameStr,
                $fn->getParam(0)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $classLen,
                $fn->getParam(1)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $ht,
                $fn->getParam(2)->typeOf()
            ),
        ];
        $raw = $context->builder->call($fn, ...$args);
        $strPtr = $context->getTypeFromString('__string__*');

        return \PHPCompiler\JIT\JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $strPtr);
    }

    /** Expose object load for {@see \PHPCompiler\ext\standard\JitSerialize} (#33966). */
    public static function loadObjectPtr(Context $context, JITVariable $receiver): Value
    {
        return self::loadObject($context, $receiver);
    }

    /**
     * php-src SplDoublyLinkedList::__unserialize — restore dllist into `__spl_ht` (#33966).
     *
     * Bag shape matches ArrayObject `i:1;a:` storage (#33636). Do not write firstIntProp
     * into slot 0 (that replaces the HT pointer).
     * Prefer helper-runtime (avoid PHP_COMPILER_HELPER_RUNTIME_O=0) — peer #32925 / #33636.
     */
    public static function compileUnserializeRestore(
        Context $context,
        Value $obj,
        Value $payloadString
    ): void {
        \PHPCompiler\JIT\Builtin\StringUnserialize::ensureLinked($context);
        $internals = [
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_at(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_long_at(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_null_at(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_double_at(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_bool_at(),
        ];
        foreach ($internals as $internal) {
            $lc = strtolower($internal->getName());
            $existing = $context->functionProxies[$lc] ?? null;
            if (null === $existing || $existing instanceof \PHPCompiler\JIT\Call\ExternalMethod) {
                $context->functionProxies[$lc] = $internal;
            }
        }
        $ht = self::htPtr($context, $obj);
        $findLogical = 'PHPCompiler\\ext\\standard\\UnserializeSplArrayFindNestedJitHelper::findStorage';
        $fillIntLogical = 'PHPCompiler\\ext\\standard\\UnserializeSplArrayFillIntKeyNestedJitHelper::fillAt';
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled(
            $context,
            '/ext/standard/UnserializeSplArrayFindNestedJitHelper.php',
            [$findLogical],
            '#33966'
        );
        BasicBlockHelper::restoreInsertBlock($context, $saved);
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled(
            $context,
            '/ext/standard/UnserializeSplArrayFillIntKeyNestedJitHelper.php',
            [$fillIntLogical],
            '#33966'
        );
        BasicBlockHelper::restoreInsertBlock($context, $saved);

        $findFn = \PHPCompiler\JIT\JitVmHelperLink::lookupCompiled($context, $findLogical, '#33966');
        $fillIntFn = \PHPCompiler\JIT\JitVmHelperLink::lookupCompiled($context, $fillIntLogical, '#33966');
        $i64 = $context->getTypeFromString('int64');
        $payloadOwned = self::nestedJitOwnedString($context, $payloadString);

        $findOffRaw = $context->builder->call(
            $findFn,
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $findFn->getParam(0)->typeOf()
            )
        );
        $findOff = \PHPCompiler\JIT\JitNestedHelperCoerce::coerceBridgeResult($context, $findOffRaw, $i64);
        $parent = BasicBlockHelper::parentFunction($context);
        $bbFill = $parent->appendBasicBlock('dll_unser_fill');
        $bbDone = $parent->appendBasicBlock('dll_unser_done');
        $found = $context->builder->icmp(
            Builder::INT_SGE,
            $findOff,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($found, $bbFill, $bbDone);

        $context->builder->positionAtEnd($bbFill);
        $destI64 = \PHPCompiler\JIT\JitNestedHelperCoerce::ptrToI64($context, $ht);
        $context->builder->call(
            $fillIntFn,
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $destI64,
                $fillIntFn->getParam(0)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $fillIntFn->getParam(1)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $findOff,
                $fillIntFn->getParam(2)->typeOf()
            )
        );
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbDone);
    }

    /** Owned `__string__*` copy for NestedJIT PHP string params (#24137 / #33966). */
    private static function nestedJitOwnedString(Context $context, Value $payload): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $separated = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $payload
        );
        $slot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $context->builder->store($separated, $slot);
        $loaded = $context->builder->load($slot);
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->builder->call($context->lookupFunction('__string__strlen'), $loaded);
        $src = $context->builder->pointerCast(
            $context->builder->structGep($loaded, $map['value']),
            $i8p
        );
        $copy = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $src
        );
        $context->refcount->disableRefcount($copy);

        return $copy;
    }

    private static function htVar(Context $context, Value $obj): JITVariable
    {
        return $context->type->object->splBackingHashtable(
            new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj)
        );
    }

    private static function htPtr(Context $context, Value $obj): Value
    {
        return $context->helper->loadValue(self::htVar($context, $obj));
    }

    private static function loadObject(Context $context, JITVariable $receiver): Value
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

        throw new \LogicException('SplDllist method requires an object receiver');
    }

    private static function voidResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
