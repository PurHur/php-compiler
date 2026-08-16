<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * SplObjectStorage instance methods for self-host spine (#816, #1998).
 */
final class SplObjectStorageMethod implements Call
{
    public function __construct(
        private readonly string $method,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        switch (strtolower($this->method)) {
            case 'attach':
                return $this->callAttach($context, ...$args);
            case 'contains':
            case 'offsetexists':
                return $this->callContains($context, ...$args);
            case 'count':
                return $this->callCount($context, ...$args);
            case 'offsetget':
                return $this->callOffsetGet($context, ...$args);
            case 'offsetset':
                return $this->callOffsetSet($context, ...$args);
            case 'rewind':
                // php-src: ZEND_PARSE_PARAMETERS_NONE — #30999
                if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SplObjectStorage::rewind', 0)) {
                    return VmClassMethod::jitArgcDummyReturn($context);
                }

                return \PHPCompiler\VM\SplObjectStorageJitHelper::compileRewind($context, $args[0]);
            case 'next':
                // php-src: ZEND_PARSE_PARAMETERS_NONE — #30999
                if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SplObjectStorage::next', 0)) {
                    return VmClassMethod::jitArgcDummyReturn($context);
                }

                return \PHPCompiler\VM\SplObjectStorageJitHelper::compileNext($context, $args[0]);
            case 'valid':
                // php-src: ZEND_PARSE_PARAMETERS_NONE — #30999
                if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SplObjectStorage::valid', 0)) {
                    return VmClassMethod::jitArgcDummyReturn($context);
                }

                return \PHPCompiler\VM\SplObjectStorageJitHelper::compileValid($context, $args[0]);
            case 'key':
                // php-src: ZEND_PARSE_PARAMETERS_NONE — #30999
                if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SplObjectStorage::key', 0)) {
                    return VmClassMethod::jitArgcDummyReturn($context);
                }

                return \PHPCompiler\VM\SplObjectStorageJitHelper::compileKey($context, $args[0]);
            case 'current':
                // php-src: ZEND_PARSE_PARAMETERS_NONE — #30999
                if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SplObjectStorage::current', 0)) {
                    return VmClassMethod::jitArgcDummyReturn($context);
                }

                return \PHPCompiler\VM\SplObjectStorageJitHelper::compileCurrent($context, $args[0]);
            case 'getinfo':
                return \PHPCompiler\VM\SplObjectStorageJitHelper::compileGetInfo($context, $args[0]);
            case 'setinfo':
                // php-src: ZEND_PARSE_PARAMETERS_START(1, 1) — #30954
                if (!VmClassMethod::requireExactJitUserArgCount(
                    $context,
                    $args,
                    'SplObjectStorage::setInfo',
                    1
                )) {
                    return VmClassMethod::jitArgcDummyReturn($context);
                }

                return \PHPCompiler\VM\SplObjectStorageJitHelper::compileSetInfo($context, $args[0], $args[1]);
            default:
                throw new \LogicException(
                    'SplObjectStorage JIT lowering is not implemented for '.$this->method.'()'
                );
        }
    }

    private function callAttach(Context $context, Variable ...$args): Value
    {
        // php-src: ZEND_PARSE_PARAMETERS_START(1, 2) — #30954
        if (!VmClassMethod::requireJitUserArgCountRange(
            $context,
            $args,
            'SplObjectStorage::attach',
            1,
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $ht = self::backingHashtable($context, $args[0]);
        $keyObj = self::loadKeyObject($context, $args[1], 'attach');
        if (null === $keyObj) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'spl_attach_after_typeerror');

            return self::voidResult($context);
        }
        if (count($args) >= 3) {
            HashTableHelper::setAtObjectKey($context, $ht, $keyObj, $args[2]);
        } else {
            $writable = HashTableHelper::writableObjectKeyValueBox($context, $ht, $keyObj);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $writable->value)
            );
        }

        return self::voidResult($context);
    }

    private function callOffsetSet(Context $context, Variable ...$args): Value
    {
        if (count($args) < 3) {
            throw new \LogicException('SplObjectStorage::offsetSet() requires object key and value');
        }
        $ht = self::backingHashtable($context, $args[0]);
        // php-src offsetSet / write_dimension — TypeError cites offsetSet (#31509).
        $keyObj = self::loadKeyObject($context, $args[1], 'offsetSet');
        if (null === $keyObj) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'spl_offsetset_after_typeerror');

            return self::voidResult($context);
        }
        HashTableHelper::setAtObjectKey($context, $ht, $keyObj, $args[2]);

        return self::voidResult($context);
    }

    private function callOffsetGet(Context $context, Variable ...$args): Value
    {
        // php-src: ZEND_PARSE_PARAMETERS_START(1, 1) — #30999
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SplObjectStorage::offsetGet', 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $ht = self::backingHashtable($context, $args[0]);
        $keyObj = self::loadKeyObject($context, $args[1], 'offsetGet');
        if (null === $keyObj) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'spl_offsetget_after_typeerror');

            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $fetched = HashTableHelper::readObjectKeyToValueBox($context, $ht, $keyObj);

        return $fetched->value;
    }

    private function callContains(Context $context, Variable ...$args): Value
    {
        // php-src: ZEND_PARSE_PARAMETERS_START(1, 1) — #30954
        // offsetExists shares the contains lowering; Zend exact arity 1.
        $display = 'offsetexists' === strtolower($this->method)
            ? 'SplObjectStorage::offsetExists'
            : 'SplObjectStorage::contains';
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, $display, 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $ht = self::backingHashtable($context, $args[0]);
        $keyMethod = 'offsetexists' === strtolower($this->method) ? 'offsetExists' : 'contains';
        $keyObj = self::loadKeyObject($context, $args[1], $keyMethod);
        if (null === $keyObj) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'spl_contains_after_typeerror');

            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetObjectKey'),
            $ht,
            $keyObj
        );
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $isSet);

        return $slot;
    }

    private function callCount(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('SplObjectStorage::count() requires the storage receiver');
        }
        $ht = self::backingHashtable($context, $args[0]);
        $map = $context->structFieldMap['__hashtable__'];
        $num = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong(
            $context,
            $slot,
            $context->builder->truncOrBitCast($num, $context->getTypeFromString('int64'))
        );

        return $slot;
    }

    /**
     * Object key for SplObjectStorage methods — Zend Z_PARAM_OBJECT TypeError (#31509).
     *
     * @param string $methodDisplay  attach / offsetSet / offsetGet / contains / offsetExists
     *
     * @return Value|null  null when a TypeError was emitted (caller must not continue in this BB)
     */
    private static function loadKeyObject(Context $context, Variable $key, string $methodDisplay): ?Value
    {
        if (Variable::TYPE_OBJECT === $key->type) {
            return self::materializeObjectPointer($context, $key);
        }

        $rejectCompileTime = Variable::TYPE_NULL === $key->type
            || !empty($key->isNullConstant)
            || Variable::TYPE_VALUE !== $key->type;
        if ($rejectCompileTime) {
            $given = Variable::TYPE_NULL === $key->type || !empty($key->isNullConstant)
                ? 'null'
                : JitOperandTypeLabel::givenLabel($context, $key);
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                \sprintf(
                    'SplObjectStorage::%s(): Argument #1 ($object) must be of type object, %s given',
                    $methodDisplay,
                    $given
                )
            );

            return null;
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::valuePtrFromVariable($context, $key)
        );
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

    private static function backingHashtable(Context $context, Variable $receiver): Value
    {
        if (Variable::TYPE_HASHTABLE === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (Variable::TYPE_OBJECT === $receiver->type) {
            $obj = self::materializeObjectPointer($context, $receiver);

            return $context->helper->loadValue(
                $context->type->object->splBackingHashtable(
                    new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj)
                )
            );
        }
        if (Variable::TYPE_VALUE === $receiver->type) {
            return self::backingHashtableFromValueBox($context, $receiver);
        }

        throw new \LogicException(
            'SplObjectStorage method receiver must be a hashtable or object, got '
            .Variable::getStringType($receiver->type)
        );
    }

    private static function backingHashtableFromValueBox(Context $context, Variable $receiver): Value
    {
        $valPtr = JitValueBox::valuePtrFromVariable($context, $receiver);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $isHashtable = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $fromObject = BasicBlockHelper::append($context, 'spl_ht_from_object');
        $fromHashtable = BasicBlockHelper::append($context, 'spl_ht_from_hashtable');
        $empty = BasicBlockHelper::append($context, 'spl_ht_empty');
        $merge = BasicBlockHelper::append($context, 'spl_ht_merge');
        $context->builder->branchIf($isObject, $fromObject, $empty);
        $context->builder->positionAtEnd($empty);
        $context->builder->branchIf($isHashtable, $fromHashtable, $merge);
        $context->builder->positionAtEnd($fromObject);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valPtr
        );
        $objHt = $context->helper->loadValue(
            $context->type->object->splBackingHashtable(
                new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj)
            )
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($fromHashtable);
        $boxedHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valPtr
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $htPhi = $context->builder->phi($objHt->typeOf());
        $htPhi->addIncoming($objHt, $fromObject);
        $htPhi->addIncoming($boxedHt, $fromHashtable);
        $htPhi->addIncoming($objHt->typeOf()->constNull(), $empty);

        return $htPhi;
    }

    /** Resolve storage receiver to __object__* without property-lvalue indirection (#8422). */
    private static function materializeObjectPointer(Context $context, Variable $receiver): Value
    {
        if (null !== $receiver->objectPropertySlot && Variable::TYPE_OBJECT === ($receiver->objectPropertyType ?? null)) {
            return $context->builder->pointerCast(
                $context->builder->load($receiver->objectPropertySlot),
                $context->getTypeFromString('__object__*')
            );
        }

        return $context->helper->loadValue($receiver);
    }
}
