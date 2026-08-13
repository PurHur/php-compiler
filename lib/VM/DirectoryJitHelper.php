<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT Directory for dir() — entry snapshot `__dir_ht` + `__dir_pos` (#30757).
 *
 * Peer DirectoryIteratorJitHelper. Live DirHandle NestedJIT is unreliable on user-script AOT;
 * scandir snapshot matches Zend for non-mutating directories.
 *
 * php-src: ext/standard/dir.c — Directory::{read,rewind,close}
 */
final class DirectoryJitHelper
{
    public const CLASS_NAME = 'Directory';

    public const PROP_PATH = 'path';

    public const PROP_HT = '__dir_ht';

    public const PROP_POS = '__dir_pos';

    public const PROP_CLOSED = '__dir_closed';

    public static function allocateFromSnapshot(
        Context $context,
        Value $pathStr,
        Value $ht
    ): Value {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_NAME);
        $obj = $objectType->allocate($classId);

        $pathVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $pathStr);
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_PATH),
            $pathVar,
            JITVariable::TYPE_STRING
        );
        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_HT),
            $htVar,
            JITVariable::TYPE_HASHTABLE
        );
        $i64 = $context->getTypeFromString('int64');
        self::storeLong($context, $obj, self::PROP_POS, $i64->constInt(0, false));
        self::storeLong($context, $obj, self::PROP_CLOSED, $i64->constInt(0, false));
        $objectType->markObjectConstructed($obj);

        return $obj;
    }

    public static function compileConstruct(Context $context): Value
    {
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, 'Cannot directly construct Directory, use dir() instead');
        $context->builder->call($context->lookupFunction('abort'));

        return self::voidResult($context);
    }

    public static function compileRead(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        self::requireOpen($context, $obj, 'read');

        $ht = self::htPtr($context, $obj);
        $pos = self::loadLong($context, $obj, self::PROP_POS);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $i64 = $context->getTypeFromString('int64');
        $n64 = $context->builder->truncOrBitCast($n, $i64);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $pos, $n64);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $eofBb = BasicBlockHelper::append($context, 'directory_read_eof');
        $okBb = BasicBlockHelper::append($context, 'directory_read_ok');
        $doneBb = BasicBlockHelper::append($context, 'directory_read_done');
        $context->builder->branchIf($atEnd, $eofBb, $okBb);

        $context->builder->positionAtEnd($eofBb);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $eofTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $sizeT = $context->getTypeFromString('size_t');
        $idx = $context->builder->truncOrBitCast($pos, $sizeT);
        $fetched = HashTableHelper::readIndexedToValueBox($context, $ht, $idx);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $fetched)
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $str
        );
        self::storeLong($context, $obj, self::PROP_POS, $context->builder->addNoSignedWrap($pos, $i64->constInt(1, false)));
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($ptr, $eofTail);
        $result->addIncoming($ptr, $okTail);

        return $result;
    }

    public static function compileRewind(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        self::requireOpen($context, $obj, 'rewind');
        $i64 = $context->getTypeFromString('int64');
        self::storeLong($context, $obj, self::PROP_POS, $i64->constInt(0, false));

        return self::voidResult($context);
    }

    public static function compileClose(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        self::storeLong($context, $obj, self::PROP_CLOSED, $i64->constInt(1, false));
        // Zend sets handle to false; expose closed via empty HT.
        $empty = HashTableHelper::alloc($context);
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, self::CLASS_NAME, self::PROP_HT),
            new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $empty),
            JITVariable::TYPE_HASHTABLE
        );

        return self::voidResult($context);
    }

    private static function requireOpen(Context $context, Value $obj, string $method): void
    {
        $closed = self::loadLong($context, $obj, self::PROP_CLOSED);
        $i64 = $context->getTypeFromString('int64');
        $isClosed = $context->builder->icmp(Builder::INT_NE, $closed, $i64->constInt(0, false));
        $okBb = BasicBlockHelper::append($context, 'directory_open_ok_'.$method);
        $errBb = BasicBlockHelper::append($context, 'directory_open_err_'.$method);
        $context->builder->branchIf($isClosed, $errBb, $okBb);

        $context->builder->positionAtEnd($errBb);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            \sprintf('Directory::%s(): supplied resource is not a valid Directory resource', $method)
        );
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($okBb);
    }

    private static function loadObject(Context $context, JITVariable $receiver): Value
    {
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::valuePtrFromVariable($context, $receiver)
        );
    }

    private static function htPtr(Context $context, Value $obj): Value
    {
        $slot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, self::PROP_HT);
        if (JITVariable::TYPE_HASHTABLE === $slot->type) {
            return $context->helper->loadValue($slot);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $slot)
        );
    }

    private static function loadLong(Context $context, Value $obj, string $prop): Value
    {
        $slot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, $prop);
        if (JITVariable::TYPE_NATIVE_LONG === $slot->type) {
            return $context->helper->loadValue($slot);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            JitValueBox::valuePtrFromVariable($context, $slot)
        );
    }

    private static function storeLong(Context $context, Value $obj, string $prop, Value $value): void
    {
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, self::CLASS_NAME, $prop),
            new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $value),
            JITVariable::TYPE_NATIVE_LONG
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
}
