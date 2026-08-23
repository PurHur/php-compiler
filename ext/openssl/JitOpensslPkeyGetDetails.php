<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\OpensslPkeyGetDetailsEmbedBridge;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for openssl_pkey_get_details() (#34030 leftover of #33496 / #20240).
 *
 * - Compile-time PEM bake via {@see VmOpensslPkeyNative::getDetails} + HashTableHelper
 * - JIT: NestedJIT {@see OpensslPkeyGetDetailsJitHelper}
 * - Thin AOT: runtime libcrypto leaf {@see JitOpensslPkeyKernel::EVP_PKEY_DETAILS}
 *
 * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_get_details)
 */
final class JitOpensslPkeyGetDetails
{
    private static int $blockSerial = 0;

    public static function invoke(Context $context, JITVariable $key): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_pkey_get_details');

        // Bake when __osslPem is a compile-time string constant (peer export #32705).
        $baked = self::tryBakeFromCompileTimePem($context, $key);
        if (null !== $baked) {
            return $baked;
        }

        if ($context->isThinStandaloneAotMain()) {
            return self::viaThinAotLeaf($context, $key);
        }

        return self::viaNestedJit($context, $key);
    }

    /**
     * Compile-time bake: host FFI {@see VmOpensslPkeyNative::getDetails} → constant HT.
     *
     * Used when a PEM string is known at compile time (peer openssl_pkey_export #32705;
     * future openssl_pkey_get_private literal bake).
     */
    public static function bakeFromPemLiteral(Context $context, string $pem): Value
    {
        if (!VmOpensslPkeyNative::available()) {
            return self::boxedFalse($context);
        }

        $details = VmOpensslPkeyNative::getDetails($pem);
        if (false === $details) {
            return self::boxedFalse($context);
        }

        $vmVar = VmOpensslObjects::variableFromPhpValue($details);
        if (VmVariable::TYPE_ARRAY !== $vmVar->type) {
            return self::boxedFalse($context);
        }

        $htVar = HashTableHelper::variableFromVmHashTable($context, $vmVar->toArray());

        return $htVar->value;
    }

    /**
     * Runtime OpenSSLAsymmetricKey from openssl_pkey_new() cannot fold PEM (#34015 runtime keygen).
     * Compile-time bake is {@see bakeFromPemLiteral}.
     */
    private static function tryBakeFromCompileTimePem(Context $context, JITVariable $key): ?Value
    {
        unset($context, $key);

        return null;
    }

    private static function viaNestedJit(Context $context, JITVariable $key): Value
    {
        OpensslPkeyGetDetailsEmbedBridge::ensureLinked($context);

        $pemStr = self::loadPemFromKey($context, $key);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            OpensslPkeyGetDetailsEmbedBridge::fromPemHelper($context),
            [$pemStr]
        );
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $raw);

        return self::boxHashtableOrFalse($context, $ht);
    }

    private static function viaThinAotLeaf(Context $context, JITVariable $key): Value
    {
        JitOpensslPkeyKernel::ensureDetailsLeaf($context);

        $pemStr = self::loadPemFromKey($context, $key);
        $ht = $context->builder->call(
            $context->lookupFunction(JitOpensslPkeyKernel::EVP_PKEY_DETAILS),
            $pemStr
        );

        return self::boxHashtableOrFalse($context, $ht);
    }

    private static function loadPemFromKey(Context $context, JITVariable $key): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $key);
        $objectType = $context->type->object;
        $className = OpensslPkeyNewJitSupport::CLASS_NAME;
        $prop = OpensslPkeyNewJitSupport::PROP_PEM;
        $classId = $objectType->lookup($className);
        if (!$objectType->hasProperty($classId, $prop)) {
            $objectType->defineProperty($classId, $prop, JITVariable::TYPE_STRING);
        }
        $pemVar = $objectType->propertyFetch($obj, $className, $prop);

        return $context->helper->loadValue($pemVar);
    }

    private static function boxHashtableOrFalse(Context $context, Value $ht): Value
    {
        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $failBlock = BasicBlockHelper::append($context, 'ossl_pkey_details_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pkey_details_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkey_details_done_'.$id);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        // Empty HT from NestedJIT failure also → false.
        $emptyCheck = BasicBlockHelper::append($context, 'ossl_pkey_details_empty_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $emptyCheck);

        $context->builder->positionAtEnd($emptyCheck);
        $n = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $i64 = $context->getTypeFromString('int64');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $n, $i64->constInt(0, false));
        $context->builder->branchIf($isEmpty, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function boxedFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }
}
