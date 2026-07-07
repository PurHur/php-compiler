<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\JitHash;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\HashContextRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for hash_init/update/final/copy (#7174, #3357). */
final class JitHashContext
{
    public static function dispatch(Context $context, string $name, JITVariable ...$args): Value
    {
        return match ($name) {
            'hash_init' => self::init($context, ...$args),
            'hash_update' => self::update($context, ...$args),
            'hash_final' => self::final($context, ...$args),
            'hash_copy' => self::copy($context, ...$args),
            default => throw new \LogicException($name.'() JIT dispatch missing (#3357)'),
        };
    }

    public static function init(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('hash_init() requires exactly one argument in this compiler build');
        }
        $algoStr = JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'hash_init', 0, 'algo');

        $objectType = $context->type->object;
        $className = HashContextJitSupport::CLASS_NAME;
        $classId = $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        self::storeStringPtrProperty($context, $obj, HashContextJitSupport::PROP_ALGO, $algoStr);
        self::storeStringPtrProperty(
            $context,
            $obj,
            HashContextJitSupport::PROP_DATA,
            $context->builder->load($context->constantStringFromString(''))
        );
        self::storeStringPtrProperty(
            $context,
            $obj,
            HashContextJitSupport::PROP_LIVE,
            $context->builder->load($context->constantStringFromString('1'))
        );

        return self::boxObject($context, $obj);
    }

    public static function update(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('hash_update() requires exactly two arguments in this compiler build');
        }
        $obj = self::readContextObject($context, $args[0]);
        $chunkStr = JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'hash_update', 1, 'data');

        $currentPtr = self::loadStringPtrProperty($context, $obj, HashContextJitSupport::PROP_DATA);
        $chunkVar = self::stringVarFromPtr($context, $chunkStr);
        $destSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__string__*'));
        $dest = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VARIABLE, $destSlot);
        $dest->initialize();
        $currentVar = self::stringVarFromPtr($context, $currentPtr);
        $context->type->string->concat($dest, $currentVar, $chunkVar);
        $concatStr = $context->helper->loadValue($dest);
        self::storeStringPtrProperty($context, $obj, HashContextJitSupport::PROP_DATA, $concatStr);

        return self::returnTrue($context);
    }

    public static function final(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('hash_final() requires one or two arguments in this compiler build');
        }
        HashContextRuntime::ensureLinked($context);
        $obj = self::readContextObject($context, $args[0]);

        $algoPtr = self::loadStringPtrProperty($context, $obj, HashContextJitSupport::PROP_ALGO);
        $dataPtr = self::loadStringPtrProperty($context, $obj, HashContextJitSupport::PROP_DATA);

        $raw = $context->getTypeFromString('int1')->constInt(0, false);
        if (isset($args[1])) {
            $raw = JitBoolArg::lower($context, $args[1], 'hash_final(): Argument #2 ($binary)');
        }
        $digestPtr = JitHash::hash($context, $algoPtr, $dataPtr, $raw);
        self::storeStringPtrProperty(
            $context,
            $obj,
            HashContextJitSupport::PROP_LIVE,
            $context->builder->load($context->constantStringFromString(''))
        );

        return $digestPtr;
    }

    public static function copy(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('hash_copy() requires exactly one argument in this compiler build');
        }
        $src = self::readContextObject($context, $args[0]);

        $objectType = $context->type->object;
        $className = HashContextJitSupport::CLASS_NAME;
        $algoPtr = self::loadStringPtrProperty($context, $src, HashContextJitSupport::PROP_ALGO);
        $dataPtr = self::loadStringPtrProperty($context, $src, HashContextJitSupport::PROP_DATA);

        $classId = $objectType->lookup($className);
        $dst = $objectType->allocate($classId);
        $objectType->markObjectConstructed($dst);

        self::storeStringPtrProperty($context, $dst, HashContextJitSupport::PROP_ALGO, $algoPtr);
        self::storeStringPtrProperty($context, $dst, HashContextJitSupport::PROP_DATA, $dataPtr);
        self::storeStringPtrProperty(
            $context,
            $dst,
            HashContextJitSupport::PROP_LIVE,
            $context->builder->load($context->constantStringFromString('1'))
        );

        return self::boxObject($context, $dst);
    }

    private static function loadStringPtrProperty(Context $context, Value $obj, string $prop): Value
    {
        $slot = $context->type->object->propertySlotFor(
            $obj,
            HashContextJitSupport::CLASS_NAME,
            $prop
        );
        $loaded = $context->builder->load($slot);

        return $context->builder->pointerCast(
            $loaded,
            $context->getTypeFromString('__string__*')
        );
    }

    private static function storeStringPtrProperty(Context $context, Value $obj, string $prop, Value $strPtr): void
    {
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $strPtr
        );
        $slot = $context->type->object->propertySlotFor(
            $obj,
            HashContextJitSupport::CLASS_NAME,
            $prop
        );
        $voidPtr = $context->getTypeFromString('void*');
        $context->builder->store(
            $context->builder->pointerCast($owned, $voidPtr),
            $slot
        );
    }

    private static function stringVarFromPtr(Context $context, Value $strPtr): JITVariable
    {
        return new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $strPtr
        );
    }

    private static function readContextObject(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
    }

    private static function boxObject(Context $context, Value $obj): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );

        return $ptr;
    }

    private static function returnTrue(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(1, false)
        );

        return $ptr;
    }
}
