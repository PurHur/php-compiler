<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\JitHash;
use PHPCompiler\JIT\Builtin\HashContextCopyLlvm;
use PHPCompiler\JIT\Builtin\HashContextEmbedBridge;
use PHPCompiler\JIT\Builtin\HashContextFinalLlvm;
use PHPCompiler\JIT\Builtin\HashContextRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for hash_init/update/final/copy (#7174, #3357). */
final class JitHashContext
{
    private const INIT_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::init';

    private const UPDATE_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::update';

    private const ALGO_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::algoName';

    private const DATA_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::dataString';

    private const MARK_FINAL_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::markFinalized';

    private const COPY_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::copy';

    public static function dispatch(Context $context, string $name, JITVariable ...$args): Value
    {
        HashContextRuntime::ensureLinked($context);

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
        HashContextEmbedBridge::ensureLinked($context);
        $algoStr = JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'hash_init', 0, 'algo');
        $handle = self::callHelper($context, self::INIT_HELPER, $algoStr);

        $objectType = $context->type->object;
        $className = HashContextJitSupport::CLASS_NAME;
        $classId = $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        self::storeHandle($context, $obj, $handle);

        return self::boxObject($context, $obj);
    }

    public static function update(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('hash_update() requires exactly two arguments in this compiler build');
        }
        HashContextEmbedBridge::ensureLinked($context);
        $obj = self::readContextObject($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $chunkStr = JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'hash_update', 1, 'data');
        self::callHelper($context, self::UPDATE_HELPER, $handle, $chunkStr);

        return self::returnTrue($context);
    }

    public static function final(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('hash_final() requires one or two arguments in this compiler build');
        }
        HashContextEmbedBridge::ensureLinked($context);

        $rawBool = $context->getTypeFromString('int1')->constInt(0, false);
        if (isset($args[1])) {
            $rawBool = JitBoolArg::lower($context, $args[1], 'hash_final(): Argument #2 ($binary)');
        }

        return $context->builder->call(
            $context->lookupFunction('__compiler_hash_context_final'),
            JitValueBox::valuePtrFromVariable($context, $args[0]),
            $rawBool
        );
    }

    /** Shared hash_final() body for call sites and {@see HashContextFinalLlvm} (#3357). */
    public static function finalLowering(Context $context, JITVariable $ctxArg, Value $rawBool): Value
    {
        HashContextEmbedBridge::ensureLinked($context);
        $obj = self::readContextObject($context, $ctxArg);
        $handle = self::loadHandle($context, $obj);

        $algoRaw = self::callHelper($context, self::ALGO_HELPER, $handle);
        $dataRaw = self::callHelper($context, self::DATA_HELPER, $handle);
        $algoPtr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $algoRaw);
        $dataPtr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $dataRaw);
        $digestPtr = JitHash::hash($context, $algoPtr, $dataPtr, $rawBool);
        self::callHelper($context, self::MARK_FINAL_HELPER, $handle);

        return $digestPtr;
    }

    public static function copy(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('hash_copy() requires exactly one argument in this compiler build');
        }
        HashContextEmbedBridge::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_hash_context_copy'),
            JitValueBox::valuePtrFromVariable($context, $args[0])
        );
    }

    /** Shared hash_copy() body for call sites and {@see HashContextCopyLlvm} (#3357). */
    public static function copyLowering(Context $context, JITVariable $ctxArg): Value
    {
        HashContextEmbedBridge::ensureLinked($context);
        $src = self::readContextObject($context, $ctxArg);
        $handle = self::loadHandle($context, $src);
        $newHandle = self::callHelper($context, self::COPY_HELPER, $handle);

        $objectType = $context->type->object;
        $className = HashContextJitSupport::CLASS_NAME;
        $classId = $objectType->lookup($className);
        $dst = $objectType->allocate($classId);
        $objectType->markObjectConstructed($dst);
        self::storeHandle($context, $dst, $newHandle);

        return self::boxObject($context, $dst);
    }

    private static function storeHandle(Context $context, Value $obj, Value $handleI64): void
    {
        $handleVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $handleI64
        );
        $context->type->object->storeInstanceProperty(
            $obj,
            HashContextJitSupport::CLASS_NAME,
            HashContextJitSupport::PROP_ID,
            $handleVar
        );
    }

    private static function loadHandle(Context $context, Value $obj): Value
    {
        $handleVar = $context->type->object->propertyFetch(
            $obj,
            HashContextJitSupport::CLASS_NAME,
            HashContextJitSupport::PROP_ID
        );

        return $context->helper->loadValue($handleVar);
    }

    private static function callHelper(Context $context, string $logical, Value ...$args): Value
    {
        return JitNestedHelperCoerce::callHelper(
            $context,
            HashContextEmbedBridge::helperFunction($context, $logical),
            $args
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
