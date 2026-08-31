<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\OpensslPkeyNewEmbedBridge;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for openssl_pkey_new() — NestedJIT (JIT) / runtime EVP leaf (thin AOT) (#34015 / #35866).
 *
 * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_new)
 */
final class JitOpensslPkeyNew
{
    private static int $blockSerial = 0;

    /**
     * @param int    $bits  RSA/DSA/DH bits
     * @param int    $type  OpensslConstants::OPENSSL_KEYTYPE_*
     * @param string $curve EC curve name ('' when unused)
     */
    public static function generate(Context $context, int $bits, int $type, string $curve = ''): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $bitsVal = $i64->constInt($bits, false);
        $typeVal = $i64->constInt($type, false);
        $curveVal = $context->builder->load($context->constantStringFromString($curve));

        return self::generateWithValues($context, $bitsVal, $typeVal, $curveVal);
    }

    /**
     * Runtime options hashtable — leftover of #34015 (#35866).
     */
    public static function generateFromRuntimeOptions(Context $context, JITVariable $options): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_pkey_new_rt_opts');

        // Thin standalone AOT cannot NestedJIT FFI libcrypto — extract scalars + EVP RSA leaf.
        if ($context->isThinStandaloneAotMain()) {
            $ht = HashTableReadLlvm::loadHashtablePointer($context, $options);
            $bits = self::optionIntOrDefault($context, $ht, 'private_key_bits', 2048);
            $type = self::optionIntOrDefault(
                $context,
                $ht,
                'private_key_type',
                OpensslConstants::OPENSSL_KEYTYPE_RSA
            );
            $curve = self::optionStringOrEmpty($context, $ht, 'curve_name');

            return self::generateThinAotRuntimeValues($context, $bits, $type, $curve);
        }

        OpensslPkeyNewEmbedBridge::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_pkey_new_rt_nested');
        $ht = HashTableReadLlvm::loadHashtablePointer($context, $options);
        $pemRaw = JitNestedHelperCoerce::callHelper(
            $context,
            OpensslPkeyNewEmbedBridge::generateFromOptionsHelper($context),
            [$ht]
        );
        $pemStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $pemRaw);

        return self::boxPemResult($context, $pemStr, 'rt');
    }

    /**
     * @param Value $bits     int64
     * @param Value $type     int64 OPENSSL_KEYTYPE_*
     * @param Value $curveStr __string__*
     */
    private static function generateWithValues(
        Context $context,
        Value $bits,
        Value $type,
        Value $curveStr
    ): Value {
        // Thin standalone AOT cannot NestedJIT FFI libcrypto. Runtime EVP keygen leaf
        // (not compile-time PEM bake) so keys differ across process runs (#34015 Done-when).
        if ($context->isThinStandaloneAotMain()) {
            return self::generateThinAotRuntimeValues($context, $bits, $type, $curveStr);
        }

        return self::generateViaNestedJitValues($context, $bits, $type, $curveStr);
    }

    /**
     * Thin AOT: call {@see JitOpensslPkeyKernel} at process runtime (RSA default).
     * Non-RSA types return false until dedicated leaves exist — never bake PEM constants.
     */
    private static function generateThinAotRuntimeValues(
        Context $context,
        Value $bits,
        Value $type,
        Value $curveStr
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_pkey_new_aot');
        JitOpensslPkeyKernel::ensureKeygenLeaf($context);

        $i64 = $context->getTypeFromString('int64');
        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failTypeBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_aot_fail_type_'.$id);
        $rsaBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_aot_rsa_'.$id);
        $failPemBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_aot_fail_pem_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_aot_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_aot_done_'.$id);

        $isRsa = $context->builder->icmp(
            Builder::INT_EQ,
            $type,
            $i64->constInt(OpensslConstants::OPENSSL_KEYTYPE_RSA, false)
        );
        $curveLen = $context->builder->call(
            $context->lookupFunction('__string__strlen'),
            $curveStr
        );
        $curveEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $curveLen,
            $i64->constInt(0, false)
        );
        $canRsa = $context->builder->and($isRsa, $curveEmpty);
        $context->builder->branchIf($canRsa, $rsaBlock, $failTypeBlock);

        $context->builder->positionAtEnd($failTypeBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($rsaBlock);
        $pemStr = $context->builder->call(
            $context->lookupFunction(JitOpensslPkeyKernel::EVP_RSA_KEYGEN),
            $bits
        );
        $strPtrTy = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $pemStr, $strPtrTy->constNull());
        $context->builder->branchIf($isNull, $failPemBlock, $okBlock);

        $context->builder->positionAtEnd($failPemBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        self::storePemObject($context, $slot, $ptr, $pemStr);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function generateViaNestedJitValues(
        Context $context,
        Value $bitsVal,
        Value $typeVal,
        Value $curveVal
    ): Value {
        OpensslPkeyNewEmbedBridge::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_pkey_new');

        $pemRaw = JitNestedHelperCoerce::callHelper(
            $context,
            OpensslPkeyNewEmbedBridge::generateHelper($context),
            [$bitsVal, $typeVal, $curveVal]
        );
        $pemStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $pemRaw);

        return self::boxPemResult($context, $pemStr, '');
    }

    private static function boxPemResult(Context $context, Value $pemStr, string $tag): Value
    {
        $id = (string) (++self::$blockSerial).($tag !== '' ? '_'.$tag : '');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $nullBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_null_'.$id);
        $lenBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_len_'.$id);
        $failBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_done_'.$id);

        $strPtrTy = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $pemStr, $strPtrTy->constNull());
        $context->builder->branchIf($isNull, $nullBlock, $lenBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($failBlock);

        $context->builder->positionAtEnd($lenBlock);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($pemStr, $map['length']));
        $empty = $context->builder->icmp(Builder::INT_SLE, $len, $i64->constInt(0, false));
        $context->builder->branchIf($empty, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        self::storePemObject($context, $slot, $ptr, $pemStr);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function storePemObject(
        Context $context,
        Value $slot,
        Value $ptr,
        Value $pemStr
    ): void {
        $objectType = $context->type->object;
        $className = OpensslPkeyNewJitSupport::CLASS_NAME;
        $classId = $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $pemVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $pemStr
        );
        $objectType->storeInstanceProperty(
            $obj,
            $className,
            OpensslPkeyNewJitSupport::PROP_PEM,
            $pemVar
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );
    }

    private static function optionIntOrDefault(
        Context $context,
        Value $ht,
        string $key,
        int $default
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $keyStr = $context->builder->load($context->constantStringFromString($key));
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $ht,
            $keyStr
        );
        $id = (string) (++self::$blockSerial);
        $defaultBlock = BasicBlockHelper::append($context, 'ossl_opt_int_def_'.$id);
        $readBlock = BasicBlockHelper::append($context, 'ossl_opt_int_read_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_opt_int_done_'.$id);
        $context->builder->branchIf($isSet, $readBlock, $defaultBlock);

        $context->builder->positionAtEnd($defaultBlock);
        $defaultEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($readBlock);
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $ht,
            $keyStr
        );
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $context->structFieldMap['__value__']['type'])
        );
        $isInt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_INTEGER & 0xff, false)
        );
        $read = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valPtr
        );
        $fallback = $i64->constInt($default, false);
        $readVal = $context->builder->select($isInt, $read, $fallback);
        $readEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64, 'ossl_opt_int_phi_'.$id);
        $phi->addIncoming($i64->constInt($default, false), $defaultEnd);
        $phi->addIncoming($readVal, $readEnd);

        return $phi;
    }

    private static function optionStringOrEmpty(Context $context, Value $ht, string $key): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $keyStr = $context->builder->load($context->constantStringFromString($key));
        $empty = $context->builder->load($context->constantStringFromString(''));
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $ht,
            $keyStr
        );
        $id = (string) (++self::$blockSerial);
        $emptyBlock = BasicBlockHelper::append($context, 'ossl_opt_str_empty_'.$id);
        $readBlock = BasicBlockHelper::append($context, 'ossl_opt_str_read_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_opt_str_done_'.$id);
        $context->builder->branchIf($isSet, $readBlock, $emptyBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($readBlock);
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $ht,
            $keyStr
        );
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $context->structFieldMap['__value__']['type'])
        );
        $isStr = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_STRING & 0xff, false)
        );
        $read = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valPtr
        );
        $readVal = $context->builder->select($isStr, $read, $empty);
        $readEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $strTy = $empty->typeOf();
        $phi = $context->builder->phi($strTy, 'ossl_opt_str_phi_'.$id);
        $phi->addIncoming($empty, $emptyEnd);
        $phi->addIncoming($readVal, $readEnd);

        return $phi;
    }

    /**
     * Resolve compile-time options array into bits/type/curve (#34015).
     *
     * @return array{0: int, 1: int, 2: string}|null null when options cannot be folded
     */
    public static function foldCompileTimeOptions(?JITVariable $options): ?array
    {
        $bits = 2048;
        $type = OpensslConstants::OPENSSL_KEYTYPE_RSA;
        $curve = '';

        if (null === $options) {
            return [$bits, $type, $curve];
        }
        if (JITVariable::TYPE_NULL === $options->type || ($options->isNullConstant ?? false)) {
            return [$bits, $type, $curve];
        }

        $assoc = $options->compileTimeAssoc ?? null;
        if (null === $assoc) {
            $arr = $options->compileTimeArray ?? null;
            if (\is_array($arr)) {
                $assoc = $arr;
            }
        }
        if (!\is_array($assoc)) {
            return null;
        }

        if (isset($assoc['private_key_bits']) && \is_int($assoc['private_key_bits'])) {
            $bits = $assoc['private_key_bits'];
        }
        if (isset($assoc['private_key_type']) && \is_int($assoc['private_key_type'])) {
            $type = $assoc['private_key_type'];
        }
        if (isset($assoc['curve_name']) && \is_string($assoc['curve_name'])) {
            $curve = $assoc['curve_name'];
        }
        if (isset($assoc['dh']) || isset($assoc['ec']) || isset($assoc['dsa']) || isset($assoc['rsa'])) {
            return null;
        }

        return [$bits, $type, $curve];
    }
}
