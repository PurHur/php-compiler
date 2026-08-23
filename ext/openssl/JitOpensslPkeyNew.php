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
        // Thin standalone AOT cannot NestedJIT FFI libcrypto. Runtime EVP keygen leaf
        // (not compile-time PEM bake) so keys differ across process runs (#34015 Done-when).
        if ($context->isThinStandaloneAotMain()) {
            return self::generateThinAotRuntime($context, $bits, $type, $curve);
        }

        return self::generateViaNestedJit($context, $bits, $type, $curve);
    }

    /**
     * Thin AOT: call {@see JitOpensslPkeyKernel} at process runtime (RSA default).
     * Non-RSA types return false until dedicated leaves exist — never bake PEM constants.
     */
    private static function generateThinAotRuntime(
        Context $context,
        int $bits,
        int $type,
        string $curve
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_pkey_new_aot');
        if (OpensslConstants::OPENSSL_KEYTYPE_RSA !== $type || '' !== $curve) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return JitValueBox::pointer($context, $slot);
        }

        JitOpensslPkeyKernel::ensureKeygenLeaf($context);
        $i64 = $context->getTypeFromString('int64');
        $pemStr = $context->builder->call(
            $context->lookupFunction(JitOpensslPkeyKernel::EVP_RSA_KEYGEN),
            $i64->constInt($bits, false)
        );

        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_aot_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_aot_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkey_new_aot_done_'.$id);

        $strPtrTy = $context->getTypeFromString('__string__*');
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
        int $bits,
        int $type,
        string $curve
    ): Value {
        OpensslPkeyNewEmbedBridge::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_pkey_new');

        $i64 = $context->getTypeFromString('int64');
        $bitsVal = $i64->constInt($bits, false);
        $typeVal = $i64->constInt($type, false);
        $curveVal = $context->builder->load($context->constantStringFromString($curve));

        $pemRaw = JitNestedHelperCoerce::callHelper(
            $context,
            OpensslPkeyNewEmbedBridge::generateHelper($context),
            [$bitsVal, $typeVal, $curveVal]
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
