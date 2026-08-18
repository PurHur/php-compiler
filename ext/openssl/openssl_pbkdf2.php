<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\OpensslPbkdf2Runtime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * openssl_pbkdf2() — PKCS#5 v2 PBKDF2 (php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_pbkdf2);
 * VM #6488. JIT/AOT leftover #32410: HMAC over {@see __compiler_hash} (not HashCrypto HMAC).
 *
 * Compile-time bake in {@see JitOpensslPbkdf2} (#32429) only handles literals; this path
 * lowers runtime password/salt/key_length/iterations/digest_algo as well.
 */
final class openssl_pbkdf2 extends Internal
{
    private const KEY_LENGTH_ERROR = 'openssl_pbkdf2(): Argument #3 ($key_length) must be greater than 0';

    private static int $blockSerial = 0;

    public function __construct()
    {
        parent::__construct('openssl_pbkdf2');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 5) {
            throw new \ArgumentCountError(
                'openssl_pbkdf2() expects 4 or 5 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $password = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_pbkdf2', 0, 'password');
        $salt = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'openssl_pbkdf2', 1, 'salt');
        $keyLength = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'openssl_pbkdf2', 3, 'key_length');
        $iterations = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'openssl_pbkdf2', 4, 'iterations');
        $digestAlgo = 'sha1';
        if (5 === $argc) {
            $digestAlgo = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[4],
                'openssl_pbkdf2',
                4,
                'digest_algo'
            );
        }

        $derived = VmOpenssl::pbkdf2($password, $salt, $keyLength, $iterations, $digestAlgo, $frame);
        if (false === $derived) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($derived);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 4 || $argc > 5) {
            throw new \ArgumentCountError(
                'openssl_pbkdf2() expects 4 or 5 arguments, '.$argc.' given'
            );
        }

        $passwordVal = JitStringBuiltinArg::lower($context, $args[0], 'openssl_pbkdf2', 0, 'password');
        $saltVal = JitStringBuiltinArg::lower($context, $args[1], 'openssl_pbkdf2', 1, 'salt');
        $keyLenVal = JitLongArg::lower($context, $args[2], 'openssl_pbkdf2() key_length');
        $iterVal = JitLongArg::lower($context, $args[3], 'openssl_pbkdf2() iterations');
        $methodVal = isset($args[4])
            ? JitStringBuiltinArg::lower($context, $args[4], 'openssl_pbkdf2', 4, 'digest_algo')
            : $context->builder->load($context->constantStringFromString('sha1'));

        $compileKeyLen = $args[2]->compileTimeLong;
        if (null !== $compileKeyLen && (int) $compileKeyLen <= 0) {
            ExceptionBridge::emitValueErrorAndAbort($context, self::KEY_LENGTH_ERROR);

            return self::boxedFalse($context);
        }
        self::emitKeyLengthGuard($context, $keyLenVal);

        OpensslPbkdf2Runtime::ensureLinked($context);
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_openssl_pbkdf2'),
            $passwordVal,
            $saltVal,
            $keyLenVal,
            $iterVal,
            $methodVal
        );

        return self::stringOrFalse($context, $raw);
    }

    private static function emitKeyLengthGuard(Context $context, Value $keyLenVal): void
    {
        $tag = (string) (++self::$blockSerial);
        $i64 = $context->getTypeFromString('int64');
        $leZero = $context->builder->icmp(
            Builder::INT_SLE,
            $keyLenVal,
            $i64->constInt(0, false)
        );
        $ok = BasicBlockHelper::append($context, 'ossl_pbkdf2_keylen_ok_'.$tag);
        $err = BasicBlockHelper::append($context, 'ossl_pbkdf2_keylen_err_'.$tag);
        $context->builder->branchIf($leZero, $err, $ok);
        $context->builder->positionAtEnd($err);
        ExceptionBridge::emitValueErrorAndAbort($context, self::KEY_LENGTH_ERROR);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_pbkdf2_keylen_dead_'.$tag);
        $context->builder->positionAtEnd($ok);
    }

    private static function stringOrFalse(Context $context, Value $raw): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $raw, $strPtr->constNull());

        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_pbkdf2_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pbkdf2_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pbkdf2_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $raw);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
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
