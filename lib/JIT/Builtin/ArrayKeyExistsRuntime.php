<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\array_key_exists;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for array_key_exists()/key_exists() via ArrayKeyExistsJitHelper PHP (#13735).
 *
 * Standalone AOT keeps LLVM key-dispatch in this Runtime; embed routes through PHP SSOT.
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_key_exists)
 */
final class ArrayKeyExistsRuntime
{
    private const ABI_KEY_EXISTS = '__array_key_exists__has_key';

    private const HELPER_PATH = '/ext/standard/ArrayKeyExistsJitHelper.php';

    private const KEY_EXISTS_HELPER = 'PHPCompiler\\ext\\standard\\ArrayKeyExistsJitHelper::keyExists';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::KEY_EXISTS_HELPER,
    ];

    public static function keyExists(
        Context $context,
        JITVariable $key,
        JITVariable $array,
        string $function
    ): Value {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || ($array->type & JITVariable::IS_NATIVE_ARRAY)) {
            if ($array->type & JITVariable::IS_NATIVE_ARRAY) {
                return self::nativeArrayKeyExists($context, $key, $array, $function);
            }
            $ht = JITVariable::TYPE_HASHTABLE === $array->type
                ? $context->helper->loadValue($array)
                : ArrayBuiltinHelper::loadHashTable($context, $array);

            return self::standaloneKeyExistsOnHashTable($context, $ht, $key, $function);
        }

        if (JITVariable::TYPE_OBJECT === $key->type) {
            HashTableHelper::emitIllegalOffsetType($context, 'Illegal offset type');

            return $context->constantFromInteger(0, 'int1');
        }

        self::ensureLinked($context);
        $keyPtr = JitValueBox::valuePtrFromVariable($context, $key);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_KEY_EXISTS),
            $keyPtr,
            $ht
        );
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_KEY_EXISTS);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_KEY_EXISTS,
            'array_key_exists_bridge_entry',
            [$valuePtr, $htPtr],
            $i1,
            self::KEY_EXISTS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13735'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function nativeArrayKeyExists(
        Context $context,
        JITVariable $key,
        JITVariable $array,
        string $function
    ): Value {
        if (JITVariable::TYPE_NULL === $key->type
            || JITVariable::TYPE_STRING === $key->type
            || JITVariable::TYPE_VALUE === $key->type) {
            return $context->constantFromInteger(0, 'int1');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $key->type) {
            throw new \LogicException(
                $function.'() on native arrays only supports integer keys in this compiler build'
            );
        }
        $index = JitLongArg::lower($context, $key, $function.'() key');
        $size = $context->constantFromInteger($array->nextFreeElement, 'int32');
        $i32 = $context->getTypeFromString('int32');
        $inRange = $context->builder->icmp(Builder::INT_SLT, $index, $size);
        $nonNeg = $context->builder->icmp(Builder::INT_SGE, $index, $i32->constInt(0, false));

        return $context->builder->and($inRange, $nonNeg);
    }

    /** php-src: null lookup key coerces to empty string (ext/standard/array.c). */
    private static function standaloneKeyExistsOnHashTable(
        Context $context,
        Value $ht,
        JITVariable $key,
        string $function
    ): Value {
        if (JITVariable::TYPE_NULL === $key->type) {
            return self::standaloneEmptyStringKeyExists($context, $ht);
        }
        if (JITVariable::TYPE_STRING === $key->type) {
            return $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
                $ht,
                array_key_exists::jitKeyString($context, $key, $function.'() key')
            );
        }
        if (JITVariable::TYPE_NATIVE_LONG === $key->type) {
            $index = $context->builder->truncOrBitCast(
                $context->helper->loadValue($key),
                $context->getTypeFromString('size_t')
            );

            return $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSet'),
                $ht,
                $index
            );
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $key->type) {
            $index = $context->builder->fptosi(
                $context->helper->loadValue($key),
                $context->getTypeFromString('size_t')
            );

            return $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSet'),
                $ht,
                $index
            );
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $key->type) {
            $index = $context->builder->zext(
                $context->helper->loadValue($key),
                $context->getTypeFromString('size_t')
            );

            return $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSet'),
                $ht,
                $index
            );
        }
        if (JITVariable::TYPE_OBJECT === $key->type) {
            HashTableHelper::emitIllegalOffsetType($context, 'Illegal offset type');

            return $context->constantFromInteger(0, 'int1');
        }
        if (JITVariable::TYPE_VALUE === $key->type) {
            return self::standaloneKeyExistsValueBoxKey($context, $ht, $key);
        }

        throw new \LogicException(
            $function.'() key must be an integer or string in this compiler build'
        );
    }

    private static function standaloneEmptyStringKeyExists(Context $context, Value $ht): Value
    {
        $emptyKey = $context->builder->load($context->constantStringFromString(''));

        return $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $ht,
            $emptyKey
        );
    }

    private static function standaloneKeyExistsValueBoxKey(
        Context $context,
        Value $ht,
        JITVariable $key
    ): Value {
        if (JITVariable::TYPE_VALUE !== $key->type) {
            throw new \LogicException('standaloneKeyExistsValueBoxKey requires TYPE_VALUE');
        }
        $valPtr = JITVariable::KIND_VARIABLE === $key->kind
            ? JitValueBox::pointer($context, $key->value)
            : $context->helper->loadValue($key);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $sizeT = $context->getTypeFromString('size_t');
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        \assert($fn instanceof LlvmFunction);
        $stringBlock = $fn->appendBasicBlock('ake_vk_str');
        $longBlock = $fn->appendBasicBlock('ake_vk_long');
        $nullBlock = $fn->appendBasicBlock('ake_vk_null');
        $boolBlock = $fn->appendBasicBlock('ake_vk_bool');
        $falseBlock = $fn->appendBasicBlock('ake_vk_false');
        $merge = $fn->appendBasicBlock('ake_vk_merge');
        $afterString = $fn->appendBasicBlock('ake_vk_after_str');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_STRING, false)
            ),
            $stringBlock,
            $afterString
        );
        $context->builder->positionAtEnd($stringBlock);
        $keyStr = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $strResult = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $ht,
            $keyStr
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterString);
        $afterLong = $fn->appendBasicBlock('ake_vk_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
            ),
            $longBlock,
            $afterLong
        );
        $context->builder->positionAtEnd($longBlock);
        $index = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr),
            $sizeT
        );
        $longResult = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $index
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterLong);
        $doubleBlock = $fn->appendBasicBlock('ake_vk_double');
        $afterDouble = $fn->appendBasicBlock('ake_vk_after_double');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)
            ),
            $doubleBlock,
            $afterDouble
        );
        $context->builder->positionAtEnd($doubleBlock);
        $indexFromDouble = $context->builder->fptosi(
            $context->builder->call($context->lookupFunction('__value__readDouble'), $valPtr),
            $sizeT
        );
        $doubleResult = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $indexFromDouble
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterDouble);
        $afterNull = $fn->appendBasicBlock('ake_vk_after_null');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NULL, false)
            ),
            $nullBlock,
            $afterNull
        );
        $context->builder->positionAtEnd($nullBlock);
        $nullResult = self::standaloneEmptyStringKeyExists($context, $ht);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterNull);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_BOOLEAN, false)
            ),
            $boolBlock,
            $falseBlock
        );
        $context->builder->positionAtEnd($boolBlock);
        $valueField = $context->builder->structGep($valPtr, $valueMap['value']);
        $boolByte = $context->builder->load(
            $context->builder->inBoundsGEP(
                $valueField,
                $context->getTypeFromString('int32')->constInt(0, false),
                $context->getTypeFromString('int64')->constInt(0, false)
            )
        );
        $boolIndex = $context->builder->zext($boolByte, $sizeT);
        $boolResult = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $boolIndex
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($strResult, $stringBlock);
        $phi->addIncoming($longResult, $longBlock);
        $phi->addIncoming($doubleResult, $doubleBlock);
        $phi->addIncoming($nullResult, $nullBlock);
        $phi->addIncoming($boolResult, $boolBlock);
        $phi->addIncoming($i1->constInt(0, false), $falseBlock);

        return $phi;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_KEY_EXISTS);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_KEY_EXISTS.' missing after ArrayKeyExistsRuntime bridge (#13735)');
        }
        $context->registerFunction(self::ABI_KEY_EXISTS, $fn);
    }
}
