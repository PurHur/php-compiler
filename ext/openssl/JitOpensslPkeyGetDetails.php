<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\OpensslPkeyGetDetailsEmbedBridge;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for openssl_pkey_get_details() (#34030 leftover of #33496).
 *
 * Thin AOT: libcrypto leaves ({@see JitOpensslPkeyGetDetailsKernel}) — no FFI.
 * JIT / helper NestedJIT: {@see OpensslPkeyGetDetailsJitHelper} via EmbedBridge.
 *
 * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_get_details)
 */
final class JitOpensslPkeyGetDetails
{
    private static int $blockSerial = 0;

    public static function details(Context $context, JITVariable $key): Value
    {
        if ($context->isThinStandaloneAotMain()) {
            return self::fromObjectThinAot($context, $key);
        }

        return self::fromObjectNestedJit($context, $key);
    }

    private static function fromObjectThinAot(Context $context, JITVariable $key): Value
    {
        JitOpensslPkeyGetDetailsKernel::ensureLeaves($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_pkey_gd_thin');

        $obj = self::readObject($context, $key);
        $pemStr = self::loadPemProperty($context, $obj);

        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_pkey_gd_thin_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pkey_gd_thin_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkey_gd_thin_done_'.$id);

        $i64 = $context->getTypeFromString('int64');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $htPtrTy = $context->getTypeFromString('__hashtable__*');

        $bits = $context->builder->call(
            $context->lookupFunction(JitOpensslPkeyGetDetailsKernel::DETAILS_BITS),
            $pemStr
        );
        $type = $context->builder->call(
            $context->lookupFunction(JitOpensslPkeyGetDetailsKernel::DETAILS_TYPE),
            $pemStr
        );
        $pub = $context->builder->call(
            $context->lookupFunction(JitOpensslPkeyGetDetailsKernel::DETAILS_PUB),
            $pemStr
        );

        $bitsBad = $context->builder->icmp(Builder::INT_SLT, $bits, $i64->constInt(0, false));
        $typeBad = $context->builder->icmp(Builder::INT_SLT, $type, $i64->constInt(0, false));
        $pubBad = $context->builder->icmp(Builder::INT_EQ, $pub, $strPtrTy->constNull());
        $failed = $context->builder->or($bitsBad, $context->builder->or($typeBad, $pubBad));
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $bitsKey = $context->builder->load($context->constantStringFromString('bits'));
        $typeKey = $context->builder->load($context->constantStringFromString('type'));
        $keyKey = $context->builder->load($context->constantStringFromString('key'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $bitsKey,
            $bits
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $typeKey,
            $type
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyKey,
            $pub
        );
        unset($htPtrTy);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function fromObjectNestedJit(Context $context, JITVariable $key): Value
    {
        OpensslPkeyGetDetailsEmbedBridge::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_pkey_get_details');

        $obj = self::readObject($context, $key);
        $pemStr = self::loadPemProperty($context, $obj);

        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            OpensslPkeyGetDetailsEmbedBridge::fromPemHelper($context),
            [$pemStr]
        );
        $failed = JitNestedHelperCoerce::isHelperResultNull($context, $htRaw);

        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_pkey_gd_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pkey_gd_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkey_gd_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function readObject(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
    }

    private static function loadPemProperty(Context $context, Value $obj): Value
    {
        $strVar = $context->type->object->propertyFetch(
            $obj,
            OpensslPkeyNewJitSupport::CLASS_NAME,
            OpensslPkeyNewJitSupport::PROP_PEM
        );

        return $context->helper->loadValue($strVar);
    }
}
