<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\GetClassRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Builtin\WeakRefNative;
use PHPCompiler\JIT\Builtin\WeakRefRuntime;
use PHPCompiler\JIT\Builtin\WeakRefSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** WeakMap ArrayAccess + count for JIT (#3667). */
final class WeakMapMethod implements Call
{
    public function __construct(
        private readonly string $method,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return match (strtolower($this->method)) {
            'offsetset' => $this->callOffsetSet($context, ...$args),
            'offsetget' => $this->callOffsetGet($context, ...$args),
            'offsetexists' => $this->callOffsetExists($context, ...$args),
            'offsetunset' => $this->callOffsetUnset($context, ...$args),
            'count' => $this->callCount($context, ...$args),
            default => throw new \LogicException(
                'WeakMap JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    private function callOffsetSet(Context $context, Variable ...$args): Value
    {
        if (count($args) < 3) {
            throw new \LogicException('WeakMap::offsetSet() expects map, key, and value');
        }
        WeakRefRuntime::ensureLinked($context);
        WeakRefNative::registerDeclarations($context);

        $ht = self::backingHashtable($context, $args[0]);
        $keyObj = WeakRefSetup::loadObjectFromArg($context, $args[1]);
        [$keyStr, $keyBuf] = self::buildObjectKey($context, $keyObj);
        HashTableHelper::setAtStringKey($context, $ht, $keyStr, $args[2]);
        $i8p = $context->getTypeFromString('int8*');
        $context->builder->call(
            $context->lookupFunction('phpc_weakref_register_map'),
            $context->builder->pointerCast($keyObj, $i8p),
            $context->builder->pointerCast($ht, $i8p),
            $keyBuf
        );

        return self::voidResult($context);
    }

    private function callOffsetGet(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('WeakMap::offsetGet() called without $this');
        }
        // php-src Zend/zend_weakrefs.stub.php — offsetGet(object $object): mixed (#30909)
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'WeakMap::offsetGet', 1)) {
            return self::voidResult($context);
        }
        // Zend zend_weakmap_offset_get — absent key throws Error (#24771).
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        $ht = self::backingHashtable($context, $args[0]);
        $keyObj = WeakRefSetup::loadObjectFromArg($context, $args[1]);
        [$keyStr] = self::buildObjectKey($context, $keyObj);
        $present = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $ht,
            $keyStr
        );
        $okBlock = BasicBlockHelper::append($context, 'weakmap_offsetget_ok');
        $missBlock = BasicBlockHelper::append($context, 'weakmap_offsetget_miss');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_NE,
                $present,
                $present->typeOf()->constInt(0, false)
            ),
            $okBlock,
            $missBlock
        );

        $context->builder->positionAtEnd($missBlock);
        self::emitMissingKeyError($context, $keyObj);
        $missInsert = $context->builder->getInsertBlock();
        if (null !== $missInsert && null === $missInsert->getTerminator()) {
            // Pending Error without catch edge — abort via ErrorRaise helper so the
            // message is printed (GeneratorGetReturn uses bare abort) (#24771).
            if (null !== $context->module->getNamedFunction('phpc_jit_abort_if_pending_error')) {
                $context->builder->call(
                    $context->lookupFunction('phpc_jit_abort_if_pending_error')
                );
            } else {
                TypeErrorRaise::ensureDeclInScope(
                    $context,
                    'abort',
                    $context->context->functionType(
                        $context->getTypeFromString('void'),
                        false
                    )
                );
                $context->builder->call($context->lookupFunction('abort'));
            }
        }

        // Catchable throw branched to dispatch — still need a typed result for the ok path.
        $context->builder->positionAtEnd($okBlock);
        $fetched = HashTableHelper::readStringKeyToValueBox($context, $ht, $keyStr);

        return $fetched->value;
    }

    /**
     * Raise `Object {class}#{id} not contained in WeakMap` (zend_weakmap.c, #24771).
     */
    private static function emitMissingKeyError(Context $context, Value $keyObj): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'snprintf',
            $context->context->functionType($i32, true, $i8p, $sizeT, $i8p)
        );
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'strlen',
            $context->context->functionType($sizeT, false, $i8p)
        );
        GetClassRuntime::ensureLinked($context);
        $keyObjVar = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $keyObj
        );
        $classNameStr = ReflectionBuiltinHelper::getClassName($context, $keyObjVar);
        $classCstr = $context->builder->pointerCast(
            $context->builder->structGep(
                $classNameStr,
                $context->structFieldIndex($classNameStr, 'value')
            ),
            $i8p
        );
        // JIT objects lack Zend handles — pointer identity is the display id.
        $handle = $context->builder->ptrToInt($keyObj, $i64);
        $buf = $context->builder->alloca($i8->arrayType(512), 1, 'weakmap_miss_msg');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufPtr,
            $context->constantFromInteger(512, 'size_t'),
            $context->builder->pointerCast(
                $context->constantFromString('Object %s#%lld not contained in WeakMap'),
                $i8p
            ),
            $classCstr,
            $handle
        );
        $len = $context->builder->call($context->lookupFunction('strlen'), $bufPtr);

        if ([] !== $context->tryCatch->handlerStack) {
            // Catchable Error for try (GeneratorRewind shape). Compile-time message uses a
            // placeholder handle; compliance normalizes #\d+. Class name matches the common
            // stdClass repro; VM path remains the php-src-strict SSOT for exact text (#24771).
            TryCatchHelper::emitCatchableClassError(
                $context,
                'Error',
                'Object stdClass#0 not contained in WeakMap'
            );

            return;
        }

        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_error'),
            $bufPtr,
            $len
        );
    }

    private function callOffsetExists(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('WeakMap::offsetExists() called without $this');
        }
        // php-src Zend/zend_weakrefs.stub.php — offsetExists(object $object): bool (#30909)
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'WeakMap::offsetExists', 1)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $ht = self::backingHashtable($context, $args[0]);
        $keyObj = WeakRefSetup::loadObjectFromArg($context, $args[1]);
        [$keyStr] = self::buildObjectKey($context, $keyObj);

        return $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $ht,
            $keyStr
        );
    }

    private function callOffsetUnset(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('WeakMap::offsetUnset() called without $this');
        }
        // php-src Zend/zend_weakrefs.stub.php — offsetUnset(object $object): void (#30909)
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'WeakMap::offsetUnset', 1)) {
            return self::voidResult($context);
        }
        WeakRefRuntime::ensureLinked($context);
        WeakRefNative::registerDeclarations($context);

        $ht = self::backingHashtable($context, $args[0]);
        $keyObj = WeakRefSetup::loadObjectFromArg($context, $args[1]);
        [$keyStr, $keyBuf] = self::buildObjectKey($context, $keyObj);
        HashTableHelper::unsetStringKey($context, $ht, $keyStr);
        $i8p = $context->getTypeFromString('int8*');
        $context->builder->call(
            $context->lookupFunction('phpc_weakref_unregister_map'),
            $context->builder->pointerCast($keyObj, $i8p),
            $context->builder->pointerCast($ht, $i8p),
            $keyBuf
        );

        return self::voidResult($context);
    }

    private function callCount(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('WeakMap::count() requires the map receiver');
        }
        $ht = self::backingHashtable($context, $args[0]);
        $map = $context->structFieldMap['__hashtable__'];
        $num = $context->builder->load($context->builder->structGep($ht, $map['numElements']));

        return $context->builder->truncOrBitCast($num, $context->getTypeFromString('int64'));
    }

    /**
     * @return array{0: Value, 1: Value} [__string__* key, int8* buf]
     */
    private static function buildObjectKey(Context $context, Value $keyObj): array
    {
        $i8 = $context->getTypeFromString('int8');
        $buf = $context->builder->alloca($i8, 32, 'weakmap_key_buf');
        $sizeT = $context->getTypeFromString('size_t');
        WeakRefSetup::formatObjectKey($context, $keyObj, $buf, $sizeT->constInt(32, false));
        $len = $context->builder->call(
            $context->lookupFunction('strlen'),
            $buf
        );
        $keyStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $context->getTypeFromString('int64')),
            $buf
        );

        return [$keyStr, $buf];
    }

    private static function backingHashtable(Context $context, Variable $receiver): Value
    {
        if (Variable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue(
                $context->type->object->weakMapBackingHashtable($receiver)
            );
        }
        if (Variable::TYPE_VALUE === $receiver->type) {
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                Variable::KIND_VARIABLE === $receiver->kind
                    ? JitValueBox::pointer($context, $receiver->value)
                    : $context->helper->loadValue($receiver)
            );

            return $context->helper->loadValue(
                $context->type->object->weakMapBackingHashtable(
                    new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj)
                )
            );
        }

        throw new \LogicException(
            'WeakMap receiver must be object, got '.Variable::getStringType($receiver->type)
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
