<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\OpensslPkeyNewEmbedBridge;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for openssl_pkey_new() — NestedJIT (JIT) / runtime EVP leaf (thin AOT) (#34015).
 *
 * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_new)
 *
 * Runtime options arrays (#35866 leftover of #34015): when compile-time fold fails, read
 * private_key_bits / private_key_type / curve_name via __hashtable__readStringKeyValue
 * (peer {@see \PHPCompiler\ext\standard\JitPasswordBcryptCost}).
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

        return self::generateFromValues(
            $context,
            $i64->constInt($bits, false),
            $i64->constInt($type, false),
            $context->builder->load($context->constantStringFromString($curve))
        );
    }

    /**
     * Lower a runtime/?array options variable then generate (#35866).
     */
    public static function generateFromRuntimeOptions(Context $context, JITVariable $options): Value
    {
        [$bits, $type, $curve] = self::lowerRuntimeOptions($context, $options);

        return self::generateFromValues($context, $bits, $type, $curve);
    }

    /**
     * @param Value $bits  i64
     * @param Value $type  i64 OPENSSL_KEYTYPE_*
     * @param Value $curve __string__*
     */
    public static function generateFromValues(
        Context $context,
        Value $bits,
        Value $type,
        Value $curve
    ): Value {
        // Thin standalone AOT cannot NestedJIT FFI libcrypto. Runtime EVP keygen leaf
        // (not compile-time PEM bake) so keys differ across process runs (#34015 Done-when).
        if ($context->isThinStandaloneAotMain()) {
            return self::generateThinAotRuntime($context, $bits, $type, $curve);
        }

        return self::generateViaNestedJit($context, $bits, $type, $curve);
    }

    /**
     * Thin AOT: call {@see JitOpensslPkeyKernel} at process runtime (RSA default).
     * Non-RSA types / non-empty curve return false until dedicated leaves exist.
     */
    private static function generateThinAotRuntime(
        Context $context,
        Value $bits,
        Value $type,
        Value $curve
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_pkey_new_aot');
        JitOpensslPkeyKernel::ensureKeygenLeaf($context);

        $i64 = $context->getTypeFromString('int64');
        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $unsupportedBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_aot_unsup_'.$id);
        $callBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_aot_call_'.$id);
        $failBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_aot_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_aot_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_aot_done_'.$id);

        $isRsa = $context->builder->icmp(
            Builder::INT_EQ,
            $type,
            $i64->constInt(OpensslConstants::OPENSSL_KEYTYPE_RSA, false)
        );
        $strPtrTy = $context->getTypeFromString('__string__*');
        $curveNull = $context->builder->icmp(Builder::INT_EQ, $curve, $strPtrTy->constNull());
        $map = $context->structFieldMap['__string__'];
        $curveEmptyBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_aot_curve_empty_'.$id);
        $curveLenBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_aot_curve_len_'.$id);
        $curveOkBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_aot_curve_ok_'.$id);
        $context->builder->branchIf($curveNull, $curveEmptyBlock, $curveLenBlock);

        $i1 = $context->getTypeFromString('int1');
        $context->builder->positionAtEnd($curveEmptyBlock);
        $emptyEnd = $context->builder->getInsertBlock();
        $context->builder->branch($curveOkBlock);

        $context->builder->positionAtEnd($curveLenBlock);
        $len = $context->builder->load($context->builder->structGep($curve, $map['length']));
        $empty = $context->builder->icmp(Builder::INT_SLE, $len, $i64->constInt(0, false));
        $lenEnd = $context->builder->getInsertBlock();
        $context->builder->branch($curveOkBlock);

        $context->builder->positionAtEnd($curveOkBlock);
        $curveEmptyPhi = $context->builder->phi($i1);
        $curveEmptyPhi->addIncoming($i1->constInt(1, false), $emptyEnd);
        $curveEmptyPhi->addIncoming($empty, $lenEnd);
        $supported = $context->builder->and($isRsa, $curveEmptyPhi);
        $context->builder->branchIf($supported, $callBlock, $unsupportedBlock);

        $context->builder->positionAtEnd($unsupportedBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($callBlock);
        $pemStr = $context->builder->call(
            $context->lookupFunction(JitOpensslPkeyKernel::EVP_RSA_KEYGEN),
            $bits
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $pemStr, $strPtrTy->constNull());
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
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
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function generateViaNestedJit(
        Context $context,
        Value $bits,
        Value $type,
        Value $curve
    ): Value {
        OpensslPkeyNewEmbedBridge::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_pkey_new');

        $i64 = $context->getTypeFromString('int64');
        $pemRaw = JitNestedHelperCoerce::callHelper(
            $context,
            OpensslPkeyNewEmbedBridge::generateHelper($context),
            [$bits, $type, $curve]
        );
        $pemStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $pemRaw);

        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $nullBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_null_'.$id);
        $lenBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_len_'.$id);
        $failBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_done_'.$id);

        $strPtrTy = $context->getTypeFromString('__string__*');
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
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
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
        // Empty assoc is not a reliable fold: assigned temps often keep compileTimeAssoc=[]
        // while the runtime HT holds the real keys (peer session_start #33945 / #34574).
        // Fall through to hashtable string-key reads (#35866).
        if (!\is_array($assoc) || [] === $assoc) {
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

    /**
     * Read private_key_bits / private_key_type / curve_name from a runtime options array (#35866).
     *
     * @return array{0: Value, 1: Value, 2: Value} bits i64, type i64, curve __string__*
     */
    public static function lowerRuntimeOptions(Context $context, JITVariable $options): array
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_pkey_new_opts');
        $i64 = $context->getTypeFromString('int64');
        $defaultBits = $i64->constInt(2048, false);
        $defaultType = $i64->constInt(OpensslConstants::OPENSSL_KEYTYPE_RSA, false);
        $defaultCurve = $context->builder->load($context->constantStringFromString(''));

        $ht = self::loadOptionsHashtable($context, $options);
        $bits = self::readLongOption($context, $ht, 'private_key_bits', $defaultBits);
        $type = self::readLongOption($context, $ht, 'private_key_type', $defaultType);
        $curve = self::readStringOption($context, $ht, 'curve_name', $defaultCurve);

        return [$bits, $type, $curve];
    }

    private static function loadOptionsHashtable(Context $context, JITVariable $options): Value
    {
        // Same loader as dimFetch / session_start options (#33945) — covers property slots,
        // value-boxed arrays, and direct HASHTABLE kinds.
        return \PHPCompiler\JIT\HashTableReadLlvm::loadHashtablePointer($context, $options);
    }

    private static function readLongOption(
        Context $context,
        Value $ht,
        string $keyName,
        Value $default
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $key = $context->builder->load($context->constantStringFromString($keyName));
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $ht,
            $key
        );
        $has = $context->builder->icmp(
            Builder::INT_NE,
            $valPtr,
            $valPtr->typeOf()->constNull()
        );
        $id = (string) (++self::$blockSerial);
        $hasBlock = BasicBlockHelper::append($context, 'ossl_pkey_opt_long_has_'.$id);
        $missBlock = BasicBlockHelper::append($context, 'ossl_pkey_opt_long_miss_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkey_opt_long_done_'.$id);
        $context->builder->branchIf($has, $hasBlock, $missBlock);

        $context->builder->positionAtEnd($missBlock);
        $missEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($hasBlock);
        $longVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valPtr
        );
        $hasEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($default, $missEnd);
        $phi->addIncoming($longVal, $hasEnd);

        return $phi;
    }

    private static function readStringOption(
        Context $context,
        Value $ht,
        string $keyName,
        Value $default
    ): Value {
        $strPtrTy = $context->getTypeFromString('__string__*');
        $key = $context->builder->load($context->constantStringFromString($keyName));
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $ht,
            $key
        );
        $has = $context->builder->icmp(
            Builder::INT_NE,
            $valPtr,
            $valPtr->typeOf()->constNull()
        );
        $id = (string) (++self::$blockSerial);
        $hasBlock = BasicBlockHelper::append($context, 'ossl_pkey_opt_str_has_'.$id);
        $missBlock = BasicBlockHelper::append($context, 'ossl_pkey_opt_str_miss_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkey_opt_str_done_'.$id);
        $context->builder->branchIf($has, $hasBlock, $missBlock);

        $context->builder->positionAtEnd($missBlock);
        $missEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($hasBlock);
        $strVal = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valPtr
        );
        $hasEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($strPtrTy);
        $phi->addIncoming($default, $missEnd);
        $phi->addIncoming($strVal, $hasEnd);

        return $phi;
    }
}
