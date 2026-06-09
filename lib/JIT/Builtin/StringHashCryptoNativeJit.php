<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for hash() / hash_hmac() / hash_pbkdf2() (#7437).
 *
 * Mirrors lib/JIT/Builtin/hash_crypto_jit_runtime.c and ext/standard/VmHashNative.php.
 */
final class StringHashCryptoNativeJit
{
    private const SHA256_DIGEST_SIZE = 32;

    private const SHA1_DIGEST_SIZE = 20;

    private const MD5_DIGEST_SIZE = 16;

    private const ALGO_SHA256 = 1;

    private const ALGO_SHA1 = 2;

    private const ALGO_MD5 = 3;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_hash',
        '__compiler_hash_hmac',
        '__compiler_hash_pbkdf2',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_hash');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLibc($context);
        self::ensureRuntimeHelpers($context);

        self::emitAlgoId($context);
        self::emitDigestLen($context);
        self::emitHexEncode($context);
        self::emitMd5Transform($context);
        self::emitSha256Transform($context);
        self::emitSha1Transform($context);
        self::emitDigest($context);
        self::emitHmac($context);
        self::emitPbkdf2F($context);
        self::emitResultString($context);

        self::implementIfMissing($context, '__compiler_hash', self::emitCompilerHash(...));
        self::implementIfMissing($context, '__compiler_hash_hmac', self::emitCompilerHashHmac(...));
        self::implementIfMissing($context, '__compiler_hash_pbkdf2', self::emitCompilerHashPbkdf2(...));

        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');

        $fn = match ($name) {
            '__compiler_hash' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $i32)
            ),
            '__compiler_hash_hmac' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $i32)
            ),
            '__compiler_hash_pbkdf2' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $i64, $i64, $i32)
            ),
            default => throw new \LogicException('Unknown hash crypto JIT function: '.$name),
        };
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringHashCryptoNativeJit implement');
            }
            $context->registerFunction($name, $fn);
        }
    }

    private static function emitCompilerHash(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('hc_hash_entry');
        $context->builder->positionAtEnd($entry);

        $algo = $fn->getParam(0);
        $data = $fn->getParam(1);
        $raw = $fn->getParam(2);
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $nullStr = $strPtr->constNull();

        $id = self::algoId($context, $algo);
        $bad = $fn->appendBasicBlock('hc_hash_bad');
        $body = $fn->appendBasicBlock('hc_hash_body');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $id, $i32->constInt(0, false)),
            $bad,
            $body
        );

        $context->builder->positionAtEnd($bad);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($body);
        $digest = $context->builder->alloca($i8, self::SHA256_DIGEST_SIZE, 'hc_hash_digest');
        self::callDigest(
            $context,
            $id,
            self::stringData($context, $data),
            self::stringLen($context, $data),
            $digest
        );
        $context->builder->returnValue(self::callResultString($context, $id, $digest, $raw));
    }

    private static function emitCompilerHashHmac(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('hc_hmac_entry');
        $context->builder->positionAtEnd($entry);

        $algo = $fn->getParam(0);
        $data = $fn->getParam(1);
        $key = $fn->getParam(2);
        $raw = $fn->getParam(3);
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $nullStr = $strPtr->constNull();

        $id = self::algoId($context, $algo);
        $bad = $fn->appendBasicBlock('hc_hmac_bad');
        $body = $fn->appendBasicBlock('hc_hmac_body');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $id, $i32->constInt(0, false)),
            $bad,
            $body
        );

        $context->builder->positionAtEnd($bad);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($body);
        $digest = $context->builder->alloca($i8, self::SHA256_DIGEST_SIZE, 'hc_hmac_digest');
        self::callHmac(
            $context,
            $id,
            self::stringData($context, $data),
            self::stringLen($context, $data),
            self::stringData($context, $key),
            self::stringLen($context, $key),
            $digest
        );
        $context->builder->returnValue(self::callResultString($context, $id, $digest, $raw));
    }

    private static function emitCompilerHashPbkdf2(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('hc_pbkdf2_entry');
        $context->builder->positionAtEnd($entry);

        $algo = $fn->getParam(0);
        $password = $fn->getParam(1);
        $salt = $fn->getParam(2);
        $iterations = $fn->getParam(3);
        $length = $fn->getParam(4);
        $raw = $fn->getParam(5);
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $nullStr = $strPtr->constNull();
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $oneI32 = $i32->constInt(1, false);

        $id = self::algoId($context, $algo);
        $bad = $fn->appendBasicBlock('hc_pbkdf2_bad');
        $body = $fn->appendBasicBlock('hc_pbkdf2_body');
        $iterBad = $context->builder->icmp(Builder::INT_SLT, $iterations, $oneI64);
        $idBad = $context->builder->icmp(Builder::INT_EQ, $id, $i32->constInt(0, false));
        $context->builder->branchIf($context->builder->or($idBad, $iterBad), $bad, $body);

        $context->builder->positionAtEnd($bad);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($body);
        $hlen = self::callDigestLen($context, $id);
        $lengthArg = $length;
        $dklen = $length;
        $useDefault = $context->builder->icmp(Builder::INT_EQ, $dklen, $zeroI64);
        $afterLen = $fn->appendBasicBlock('hc_pbkdf2_after_len');
        $context->builder->branchIf($useDefault, $afterLen, $afterLen);
        $context->builder->positionAtEnd($afterLen);
        $dklen = $context->builder->select($useDefault, $hlen, $dklen);

        $blocks = $context->builder->add(
            $context->builder->sub($dklen, $oneI64),
            $context->builder->sub($hlen, $oneI64)
        );
        $blocks = $context->builder->lshr($blocks, $i64->constInt(32, false));
        $blocks = $context->builder->add($blocks, $oneI64);

        $derived = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($dklen, $sizeT)
        );
        $mallocFail = $fn->appendBasicBlock('hc_pbkdf2_malloc_fail');
        $loopHead = $fn->appendBasicBlock('hc_pbkdf2_loop_head');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $derived, $i8p->constNull()),
            $mallocFail,
            $loopHead
        );

        $context->builder->positionAtEnd($mallocFail);
        $context->builder->returnValue($nullStr);

        $blockSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $writtenSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($oneI32, $blockSlot);
        $context->builder->store($zeroI64, $writtenSlot);

        $context->builder->positionAtEnd($loopHead);
        $block = $context->builder->load($blockSlot);
        $doneLoop = $context->builder->icmp(Builder::INT_SGT, $block, $context->builder->truncOrBitCast($blocks, $i32));
        $loopBody = $fn->appendBasicBlock('hc_pbkdf2_loop_body');
        $loopDone = $fn->appendBasicBlock('hc_pbkdf2_loop_done');
        $context->builder->branchIf($doneLoop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $t = $context->builder->alloca($i8, self::SHA256_DIGEST_SIZE, 'hc_pbkdf2_t');
        self::callPbkdf2F(
            $context,
            $id,
            self::stringData($context, $password),
            self::stringLen($context, $password),
            self::stringData($context, $salt),
            self::stringLen($context, $salt),
            $block,
            $iterations,
            $t
        );
        $written = $context->builder->load($writtenSlot);
        $copy = $hlen;
        $wouldOverflow = $context->builder->icmp(
            Builder::INT_SGT,
            $context->builder->add($written, $copy),
            $dklen
        );
        $afterCopy = $fn->appendBasicBlock('hc_pbkdf2_after_copy');
        $context->builder->branchIf($wouldOverflow, $afterCopy, $afterCopy);
        $context->builder->positionAtEnd($afterCopy);
        $copy = $context->builder->select(
            $wouldOverflow,
            $context->builder->sub($dklen, $written),
            $copy
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->inBoundsGep($derived, $written),
            $t,
            $context->builder->truncOrBitCast($copy, $sizeT)
        );
        $context->builder->store($context->builder->add($written, $copy), $writtenSlot);
        $context->builder->store($context->builder->add($block, $oneI32), $blockSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $rawNonZero = $context->builder->icmp(Builder::INT_NE, $raw, $i32->constInt(0, false));
        $rawBb = $fn->appendBasicBlock('hc_pbkdf2_raw');
        $hexBb = $fn->appendBasicBlock('hc_pbkdf2_hex');
        $context->builder->branchIf($rawNonZero, $rawBb, $hexBb);

        $context->builder->positionAtEnd($rawBb);
        $rawResult = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $dklen,
            $context->builder->pointerCast($derived, $context->getTypeFromString('char*'))
        );
        $context->builder->call($context->lookupFunction('free'), $derived);
        $context->builder->returnValue($rawResult);

        $context->builder->positionAtEnd($hexBb);
        $hexFullLen = $context->builder->mul($dklen, $i64->constInt(2, false));
        $hexOutLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $lengthArg, $zeroI64),
            $lengthArg,
            $hexFullLen
        );
        $hex = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast(
                $context->builder->add($hexFullLen, $oneI64),
                $sizeT
            )
        );
        $hexFail = $fn->appendBasicBlock('hc_pbkdf2_hex_fail');
        $hexOk = $fn->appendBasicBlock('hc_pbkdf2_hex_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $hex, $i8p->constNull()),
            $hexFail,
            $hexOk
        );
        $context->builder->positionAtEnd($hexFail);
        $context->builder->call($context->lookupFunction('free'), $derived);
        $context->builder->returnValue($nullStr);
        $context->builder->positionAtEnd($hexOk);
        self::callHexEncode(
            $context,
            $derived,
            $context->builder->truncOrBitCast($dklen, $sizeT),
            $hex
        );
        $hexResult = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $hexOutLen,
            $context->builder->pointerCast($hex, $context->getTypeFromString('char*'))
        );
        $context->builder->call($context->lookupFunction('free'), $hex);
        $context->builder->call($context->lookupFunction('free'), $derived);
        $context->builder->returnValue($hexResult);
    }

    private static function algoId(Context $context, Value $algo): Value
    {
        return $context->builder->call($context->lookupFunction('__phpc_hc_algo_id'), $algo);
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function stringLen(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->load($context->builder->structGep($str, $map['length']));
    }

    private static function cstr(Context $context, string $text): Value
    {
        return $context->builder->pointerCast(
            $context->constantFromString($text),
            $context->getTypeFromString('char*')
        );
    }

    private static function u32(Context $context, Value $v): Value
    {
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->and($context->builder->truncOrBitCast($v, $i32), $i32->constInt(0xFFFFFFFF, false));
    }

    private static function u32Add(Context $context, Value $a, Value $b): Value
    {
        return self::u32($context, $context->builder->add($a, $b));
    }

    private static function u32Not(Context $context, Value $x): Value
    {
        $i32 = $context->getTypeFromString('int32');

        return self::u32($context, $context->builder->xor($x, $i32->constInt(0xFFFFFFFF, false)));
    }

    private static function rotr(Context $context, Value $x, int $n): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $xn = self::u32($context, $x);

        return self::u32($context, $context->builder->or(
            $context->builder->lshr($xn, $i32->constInt($n, false)),
            $context->builder->shl($xn, $i32->constInt(32 - $n, false))
        ));
    }

    private static function rotl(Context $context, Value $x, int $n): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $xn = self::u32($context, $x);

        return self::u32($context, $context->builder->or(
            $context->builder->shl($xn, $i32->constInt($n, false)),
            $context->builder->lshr($xn, $i32->constInt(32 - $n, false))
        ));
    }

    private static function sha256K(Context $context, int $i): Value
    {
        $i32 = $context->getTypeFromString('int32');

        return match ($i) {
            0 => $i32->constInt(0x428A2F98, false),
            1 => $i32->constInt(0x71374491, false),
            2 => $i32->constInt(0xB5C0FBCF, false),
            3 => $i32->constInt(0xE9B5DBA5, false),
            4 => $i32->constInt(0x3956C25B, false),
            5 => $i32->constInt(0x59F111F1, false),
            6 => $i32->constInt(0x923F82A4, false),
            7 => $i32->constInt(0xAB1C5ED5, false),
            8 => $i32->constInt(0xD807AA98, false),
            9 => $i32->constInt(0x12835B01, false),
            10 => $i32->constInt(0x243185BE, false),
            11 => $i32->constInt(0x550C7DC3, false),
            12 => $i32->constInt(0x72BE5D74, false),
            13 => $i32->constInt(0x80DEB1FE, false),
            14 => $i32->constInt(0x9BDC06A7, false),
            15 => $i32->constInt(0xC19BF174, false),
            16 => $i32->constInt(0xE49B69C1, false),
            17 => $i32->constInt(0xEFBE4786, false),
            18 => $i32->constInt(0x0FC19DC6, false),
            19 => $i32->constInt(0x240CA1CC, false),
            20 => $i32->constInt(0x2DE92C6F, false),
            21 => $i32->constInt(0x4A7484AA, false),
            22 => $i32->constInt(0x5CB0A9DC, false),
            23 => $i32->constInt(0x76F988DA, false),
            24 => $i32->constInt(0x983E5152, false),
            25 => $i32->constInt(0xA831C66D, false),
            26 => $i32->constInt(0xB00327C8, false),
            27 => $i32->constInt(0xBF597FC7, false),
            28 => $i32->constInt(0xC6E00BF3, false),
            29 => $i32->constInt(0xD5A79147, false),
            30 => $i32->constInt(0x06CA6351, false),
            31 => $i32->constInt(0x14292967, false),
            32 => $i32->constInt(0x27B70A85, false),
            33 => $i32->constInt(0x2E1B2138, false),
            34 => $i32->constInt(0x4D2C6DFC, false),
            35 => $i32->constInt(0x53380D13, false),
            36 => $i32->constInt(0x650A7354, false),
            37 => $i32->constInt(0x766A0ABB, false),
            38 => $i32->constInt(0x81C2C92E, false),
            39 => $i32->constInt(0x92722C85, false),
            40 => $i32->constInt(0xA2BFE8A1, false),
            41 => $i32->constInt(0xA81A664B, false),
            42 => $i32->constInt(0xC24B8B70, false),
            43 => $i32->constInt(0xC76C51A3, false),
            44 => $i32->constInt(0xD192E819, false),
            45 => $i32->constInt(0xD6990624, false),
            46 => $i32->constInt(0xF40E3585, false),
            47 => $i32->constInt(0x106AA070, false),
            48 => $i32->constInt(0x19A4C116, false),
            49 => $i32->constInt(0x1E376C08, false),
            50 => $i32->constInt(0x2748774C, false),
            51 => $i32->constInt(0x34B0BCB5, false),
            52 => $i32->constInt(0x391C0CB3, false),
            53 => $i32->constInt(0x4ED8AA4A, false),
            54 => $i32->constInt(0x5B9CCA4F, false),
            55 => $i32->constInt(0x682E6FF3, false),
            56 => $i32->constInt(0x748F82EE, false),
            57 => $i32->constInt(0x78A5636F, false),
            58 => $i32->constInt(0x84C87814, false),
            59 => $i32->constInt(0x8CC70208, false),
            60 => $i32->constInt(0x90BEFFFA, false),
            61 => $i32->constInt(0xA4506CEB, false),
            62 => $i32->constInt(0xBEF9A3F7, false),
            63 => $i32->constInt(0xC67178F2, false),
            default => throw new \LogicException('sha256 k index out of range'),
        };
    }

    private static function sha256Ch(Context $context, Value $x, Value $y, Value $z): Value
    {
        return self::u32($context, $context->builder->xor(
            $context->builder->and($x, $y),
            $context->builder->and(self::u32Not($context, $x), $z)
        ));
    }

    private static function sha256Maj(Context $context, Value $x, Value $y, Value $z): Value
    {
        return self::u32($context, $context->builder->xor(
            $context->builder->xor(
                $context->builder->and($x, $y),
                $context->builder->and($x, $z)
            ),
            $context->builder->and($y, $z)
        ));
    }

    private static function sha256Ep0(Context $context, Value $x): Value
    {
        return self::u32($context, $context->builder->xor(
            $context->builder->xor(self::rotr($context, $x, 2), self::rotr($context, $x, 13)),
            self::rotr($context, $x, 22)
        ));
    }

    private static function sha256Ep1(Context $context, Value $x): Value
    {
        return self::u32($context, $context->builder->xor(
            $context->builder->xor(self::rotr($context, $x, 6), self::rotr($context, $x, 11)),
            self::rotr($context, $x, 25)
        ));
    }

    private static function sha256Sig0(Context $context, Value $x): Value
    {
        return self::u32($context, $context->builder->xor(
            $context->builder->xor(self::rotr($context, $x, 7), self::rotr($context, $x, 18)),
            $context->builder->lshr($x, $context->getTypeFromString('int32')->constInt(3, false))
        ));
    }

    private static function sha256Sig1(Context $context, Value $x): Value
    {
        return self::u32($context, $context->builder->xor(
            $context->builder->xor(self::rotr($context, $x, 17), self::rotr($context, $x, 19)),
            $context->builder->lshr($x, $context->getTypeFromString('int32')->constInt(10, false))
        ));
    }

    private static function md5F(Context $context, Value $x, Value $y, Value $z): Value
    {
        return self::u32($context, $context->builder->or(
            $context->builder->and($x, $y),
            $context->builder->and(self::u32Not($context, $x), $z)
        ));
    }

    private static function md5G(Context $context, Value $x, Value $y, Value $z): Value
    {
        return self::u32($context, $context->builder->or(
            $context->builder->and($x, $z),
            $context->builder->and($y, self::u32Not($context, $z))
        ));
    }

    private static function md5H(Context $context, Value $x, Value $y, Value $z): Value
    {
        return self::u32($context, $context->builder->xor(
            $context->builder->xor($x, $y),
            $z
        ));
    }

    private static function md5I(Context $context, Value $x, Value $y, Value $z): Value
    {
        return self::u32($context, $context->builder->xor(
            $y,
            $context->builder->or($x, self::u32Not($context, $z))
        ));
    }

    private static function md5Step(
        Context $context,
        Value $a,
        Value $b,
        Value $c,
        Value $d,
        Value $x,
        int $s,
        int $ac,
        callable $fn
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $sum = self::u32Add($context, $a, self::u32Add($context, $fn($context, $b, $c, $d), self::u32Add($context, $x, $i32->constInt($ac, false))));

        return self::u32Add($context, self::rotl($context, $sum, $s), $b);
    }

    private static function sha1F1(Context $context, Value $b, Value $c, Value $d): Value
    {
        return self::u32($context, $context->builder->or(
            $context->builder->and($b, $c),
            $context->builder->and(self::u32Not($context, $b), $d)
        ));
    }

    private static function sha1F2(Context $context, Value $b, Value $c, Value $d): Value
    {
        return self::u32($context, $context->builder->xor(
            $context->builder->xor($b, $c),
            $d
        ));
    }

    private static function sha1F3(Context $context, Value $b, Value $c, Value $d): Value
    {
        return self::u32($context, $context->builder->or(
            $context->builder->or(
                $context->builder->and($b, $c),
                $context->builder->and($b, $d)
            ),
            $context->builder->and($c, $d)
        ));
    }

    private static function sha1F4(Context $context, Value $b, Value $c, Value $d): Value
    {
        return self::sha1F2($context, $b, $c, $d);
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['malloc', $i8p, [$sizeT]],
            ['free', $voidTy, [$i8p]],
            ['memcpy', $voidTy, [$i8p, $i8p, $sizeT]],
            ['memset', $voidTy, [$i8p, $i32, $sizeT]],
            ['strlen', $sizeT, [$charPtr]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['__string__init', $strPtr, [$i64, $charPtr]],
            ['__phpc_hc_algo_id', $i32, [$strPtr]],
            ['__phpc_hc_digest_len', $i64, [$i32]],
            ['__phpc_hc_hex_encode', $voidTy, [$i8p, $sizeT, $i8p]],
            ['__phpc_hc_md5_transform', $voidTy, [$i32->pointerType(0), $i8p]],
            ['__phpc_hc_sha256_transform', $voidTy, [$i8p, $i8p]],
            ['__phpc_hc_sha1_transform', $voidTy, [$i8p, $i8p]],
            ['__phpc_hc_digest', $voidTy, [$i32, $i8p, $sizeT, $i8p]],
            ['__phpc_hc_hmac', $voidTy, [$i32, $i8p, $sizeT, $i8p, $sizeT, $i8p]],
            ['__phpc_hc_pbkdf2_f', $voidTy, [$i32, $i8p, $sizeT, $i8p, $sizeT, $i32, $i64, $i8p]],
            ['__phpc_hc_result_string', $strPtr, [$i32, $i8p, $i32]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }



    private static function emitMd5Transform(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_hc_md5_transform');
        if (null !== $probe && $probe->countBasicBlocks() > 0) { return; }
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');
        $fn = null !== $probe ? $probe : $context->module->addFunction('__phpc_hc_md5_transform', $context->context->functionType($voidTy, false, $i32->pointerType(0), $i8p));
        $entry = $fn->appendBasicBlock('hc_md5t_entry');
        $context->builder->positionAtEnd($entry);
        $state = $fn->getParam(0);
        $block = $fn->getParam(1);
        $x = $context->builder->alloca($i32, 16, 'md5_x');
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(0, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(1, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(2, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(3, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(0, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(4, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(5, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(6, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(7, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(1, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(8, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(9, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(10, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(11, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(2, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(12, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(13, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(14, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(15, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(3, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(16, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(17, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(18, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(19, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(4, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(20, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(21, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(22, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(23, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(5, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(24, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(25, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(26, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(27, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(6, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(28, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(29, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(30, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(31, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(7, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(32, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(33, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(34, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(35, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(8, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(36, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(37, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(38, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(39, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(9, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(40, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(41, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(42, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(43, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(10, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(44, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(45, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(46, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(47, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(11, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(48, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(49, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(50, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(51, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(12, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(52, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(53, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(54, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(55, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(13, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(56, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(57, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(58, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(59, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(14, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(60, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(61, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(62, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(63, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(15, false)));
        $a = $context->builder->load($context->builder->gep($state, $i64->constInt(0, false)));
        $b = $context->builder->load($context->builder->gep($state, $i64->constInt(1, false)));
        $c = $context->builder->load($context->builder->gep($state, $i64->constInt(2, false)));
        $d = $context->builder->load($context->builder->gep($state, $i64->constInt(3, false)));
        $a = self::md5Step($context, $a, $b, $c, $d, $context->builder->load($context->builder->gep($x, $i64->constInt(0, false))), 7, 0xD76AA478, self::md5F(...));
        $d = self::md5Step($context, $d, $a, $b, $c, $context->builder->load($context->builder->gep($x, $i64->constInt(1, false))), 12, 0xE8C7B756, self::md5F(...));
        $c = self::md5Step($context, $c, $d, $a, $b, $context->builder->load($context->builder->gep($x, $i64->constInt(2, false))), 17, 0x242070DB, self::md5F(...));
        $b = self::md5Step($context, $b, $c, $d, $a, $context->builder->load($context->builder->gep($x, $i64->constInt(3, false))), 22, 0xC1BDCEEE, self::md5F(...));
        $a = self::md5Step($context, $a, $b, $c, $d, $context->builder->load($context->builder->gep($x, $i64->constInt(4, false))), 7, 0xF57C0FAF, self::md5F(...));
        $d = self::md5Step($context, $d, $a, $b, $c, $context->builder->load($context->builder->gep($x, $i64->constInt(5, false))), 12, 0x4787C62A, self::md5F(...));
        $c = self::md5Step($context, $c, $d, $a, $b, $context->builder->load($context->builder->gep($x, $i64->constInt(6, false))), 17, 0xA8304613, self::md5F(...));
        $b = self::md5Step($context, $b, $c, $d, $a, $context->builder->load($context->builder->gep($x, $i64->constInt(7, false))), 22, 0xFD469501, self::md5F(...));
        $a = self::md5Step($context, $a, $b, $c, $d, $context->builder->load($context->builder->gep($x, $i64->constInt(8, false))), 7, 0x698098D8, self::md5F(...));
        $d = self::md5Step($context, $d, $a, $b, $c, $context->builder->load($context->builder->gep($x, $i64->constInt(9, false))), 12, 0x8B44F7AF, self::md5F(...));
        $c = self::md5Step($context, $c, $d, $a, $b, $context->builder->load($context->builder->gep($x, $i64->constInt(10, false))), 17, 0xFFFF5BB1, self::md5F(...));
        $b = self::md5Step($context, $b, $c, $d, $a, $context->builder->load($context->builder->gep($x, $i64->constInt(11, false))), 22, 0x895CD7BE, self::md5F(...));
        $a = self::md5Step($context, $a, $b, $c, $d, $context->builder->load($context->builder->gep($x, $i64->constInt(12, false))), 7, 0x6B901122, self::md5F(...));
        $d = self::md5Step($context, $d, $a, $b, $c, $context->builder->load($context->builder->gep($x, $i64->constInt(13, false))), 12, 0xFD987193, self::md5F(...));
        $c = self::md5Step($context, $c, $d, $a, $b, $context->builder->load($context->builder->gep($x, $i64->constInt(14, false))), 17, 0xA679438E, self::md5F(...));
        $b = self::md5Step($context, $b, $c, $d, $a, $context->builder->load($context->builder->gep($x, $i64->constInt(15, false))), 22, 0x49B40821, self::md5F(...));
        $a = self::md5Step($context, $a, $b, $c, $d, $context->builder->load($context->builder->gep($x, $i64->constInt(1, false))), 5, 0xF61E2562, self::md5G(...));
        $d = self::md5Step($context, $d, $a, $b, $c, $context->builder->load($context->builder->gep($x, $i64->constInt(6, false))), 9, 0xC040B340, self::md5G(...));
        $c = self::md5Step($context, $c, $d, $a, $b, $context->builder->load($context->builder->gep($x, $i64->constInt(11, false))), 14, 0x265E5A51, self::md5G(...));
        $b = self::md5Step($context, $b, $c, $d, $a, $context->builder->load($context->builder->gep($x, $i64->constInt(0, false))), 20, 0xE9B6C7AA, self::md5G(...));
        $a = self::md5Step($context, $a, $b, $c, $d, $context->builder->load($context->builder->gep($x, $i64->constInt(5, false))), 5, 0xD62F105D, self::md5G(...));
        $d = self::md5Step($context, $d, $a, $b, $c, $context->builder->load($context->builder->gep($x, $i64->constInt(10, false))), 9, 0x02441453, self::md5G(...));
        $c = self::md5Step($context, $c, $d, $a, $b, $context->builder->load($context->builder->gep($x, $i64->constInt(15, false))), 14, 0xD8A1E681, self::md5G(...));
        $b = self::md5Step($context, $b, $c, $d, $a, $context->builder->load($context->builder->gep($x, $i64->constInt(4, false))), 20, 0xE7D3FBC8, self::md5G(...));
        $a = self::md5Step($context, $a, $b, $c, $d, $context->builder->load($context->builder->gep($x, $i64->constInt(9, false))), 5, 0x21E1CDE6, self::md5G(...));
        $d = self::md5Step($context, $d, $a, $b, $c, $context->builder->load($context->builder->gep($x, $i64->constInt(14, false))), 9, 0xC33707D6, self::md5G(...));
        $c = self::md5Step($context, $c, $d, $a, $b, $context->builder->load($context->builder->gep($x, $i64->constInt(3, false))), 14, 0xF4D50D87, self::md5G(...));
        $b = self::md5Step($context, $b, $c, $d, $a, $context->builder->load($context->builder->gep($x, $i64->constInt(8, false))), 20, 0x455A14ED, self::md5G(...));
        $a = self::md5Step($context, $a, $b, $c, $d, $context->builder->load($context->builder->gep($x, $i64->constInt(13, false))), 5, 0xA9E3E905, self::md5G(...));
        $d = self::md5Step($context, $d, $a, $b, $c, $context->builder->load($context->builder->gep($x, $i64->constInt(2, false))), 9, 0xFCEFA3F8, self::md5G(...));
        $c = self::md5Step($context, $c, $d, $a, $b, $context->builder->load($context->builder->gep($x, $i64->constInt(7, false))), 14, 0x676F02D9, self::md5G(...));
        $b = self::md5Step($context, $b, $c, $d, $a, $context->builder->load($context->builder->gep($x, $i64->constInt(12, false))), 20, 0x8D2A4C8A, self::md5G(...));
        $a = self::md5Step($context, $a, $b, $c, $d, $context->builder->load($context->builder->gep($x, $i64->constInt(5, false))), 4, 0xFFFA3942, self::md5H(...));
        $d = self::md5Step($context, $d, $a, $b, $c, $context->builder->load($context->builder->gep($x, $i64->constInt(8, false))), 11, 0x8771F681, self::md5H(...));
        $c = self::md5Step($context, $c, $d, $a, $b, $context->builder->load($context->builder->gep($x, $i64->constInt(11, false))), 16, 0x6D9D6122, self::md5H(...));
        $b = self::md5Step($context, $b, $c, $d, $a, $context->builder->load($context->builder->gep($x, $i64->constInt(14, false))), 23, 0xFDE5380C, self::md5H(...));
        $a = self::md5Step($context, $a, $b, $c, $d, $context->builder->load($context->builder->gep($x, $i64->constInt(1, false))), 4, 0xA4BEEA44, self::md5H(...));
        $d = self::md5Step($context, $d, $a, $b, $c, $context->builder->load($context->builder->gep($x, $i64->constInt(4, false))), 11, 0x4BDECFA9, self::md5H(...));
        $c = self::md5Step($context, $c, $d, $a, $b, $context->builder->load($context->builder->gep($x, $i64->constInt(7, false))), 16, 0xF6BB4B60, self::md5H(...));
        $b = self::md5Step($context, $b, $c, $d, $a, $context->builder->load($context->builder->gep($x, $i64->constInt(10, false))), 23, 0xBEBFBC70, self::md5H(...));
        $a = self::md5Step($context, $a, $b, $c, $d, $context->builder->load($context->builder->gep($x, $i64->constInt(13, false))), 4, 0x289B7EC6, self::md5H(...));
        $d = self::md5Step($context, $d, $a, $b, $c, $context->builder->load($context->builder->gep($x, $i64->constInt(0, false))), 11, 0xEAA127FA, self::md5H(...));
        $c = self::md5Step($context, $c, $d, $a, $b, $context->builder->load($context->builder->gep($x, $i64->constInt(3, false))), 16, 0xD4EF3085, self::md5H(...));
        $b = self::md5Step($context, $b, $c, $d, $a, $context->builder->load($context->builder->gep($x, $i64->constInt(6, false))), 23, 0x04881D05, self::md5H(...));
        $a = self::md5Step($context, $a, $b, $c, $d, $context->builder->load($context->builder->gep($x, $i64->constInt(9, false))), 4, 0xD9D4D039, self::md5H(...));
        $d = self::md5Step($context, $d, $a, $b, $c, $context->builder->load($context->builder->gep($x, $i64->constInt(12, false))), 11, 0xE6DB99E5, self::md5H(...));
        $c = self::md5Step($context, $c, $d, $a, $b, $context->builder->load($context->builder->gep($x, $i64->constInt(15, false))), 16, 0x1FA27CF8, self::md5H(...));
        $b = self::md5Step($context, $b, $c, $d, $a, $context->builder->load($context->builder->gep($x, $i64->constInt(2, false))), 23, 0xC4AC5665, self::md5H(...));
        $a = self::md5Step($context, $a, $b, $c, $d, $context->builder->load($context->builder->gep($x, $i64->constInt(0, false))), 6, 0xF4292244, self::md5I(...));
        $d = self::md5Step($context, $d, $a, $b, $c, $context->builder->load($context->builder->gep($x, $i64->constInt(7, false))), 10, 0x432AFF97, self::md5I(...));
        $c = self::md5Step($context, $c, $d, $a, $b, $context->builder->load($context->builder->gep($x, $i64->constInt(14, false))), 15, 0xAB9423A7, self::md5I(...));
        $b = self::md5Step($context, $b, $c, $d, $a, $context->builder->load($context->builder->gep($x, $i64->constInt(5, false))), 21, 0xFC93A039, self::md5I(...));
        $a = self::md5Step($context, $a, $b, $c, $d, $context->builder->load($context->builder->gep($x, $i64->constInt(12, false))), 6, 0x655B59C3, self::md5I(...));
        $d = self::md5Step($context, $d, $a, $b, $c, $context->builder->load($context->builder->gep($x, $i64->constInt(3, false))), 10, 0x8F0CCC92, self::md5I(...));
        $c = self::md5Step($context, $c, $d, $a, $b, $context->builder->load($context->builder->gep($x, $i64->constInt(10, false))), 15, 0xFFEFF47D, self::md5I(...));
        $b = self::md5Step($context, $b, $c, $d, $a, $context->builder->load($context->builder->gep($x, $i64->constInt(1, false))), 21, 0x85845DD1, self::md5I(...));
        $a = self::md5Step($context, $a, $b, $c, $d, $context->builder->load($context->builder->gep($x, $i64->constInt(8, false))), 6, 0x6FA87E4F, self::md5I(...));
        $d = self::md5Step($context, $d, $a, $b, $c, $context->builder->load($context->builder->gep($x, $i64->constInt(15, false))), 10, 0xFE2CE6E0, self::md5I(...));
        $c = self::md5Step($context, $c, $d, $a, $b, $context->builder->load($context->builder->gep($x, $i64->constInt(6, false))), 15, 0xA3014314, self::md5I(...));
        $b = self::md5Step($context, $b, $c, $d, $a, $context->builder->load($context->builder->gep($x, $i64->constInt(13, false))), 21, 0x4E0811A1, self::md5I(...));
        $a = self::md5Step($context, $a, $b, $c, $d, $context->builder->load($context->builder->gep($x, $i64->constInt(4, false))), 6, 0xF7537E82, self::md5I(...));
        $d = self::md5Step($context, $d, $a, $b, $c, $context->builder->load($context->builder->gep($x, $i64->constInt(11, false))), 10, 0xBD3AF235, self::md5I(...));
        $c = self::md5Step($context, $c, $d, $a, $b, $context->builder->load($context->builder->gep($x, $i64->constInt(2, false))), 15, 0x2AD7D2BB, self::md5I(...));
        $b = self::md5Step($context, $b, $c, $d, $a, $context->builder->load($context->builder->gep($x, $i64->constInt(9, false))), 21, 0xEB86D391, self::md5I(...));
        $context->builder->store(self::u32Add($context, $context->builder->load($context->builder->gep($state, $i64->constInt(0, false))), $a), $context->builder->gep($state, $i64->constInt(0, false)));
        $context->builder->store(self::u32Add($context, $context->builder->load($context->builder->gep($state, $i64->constInt(1, false))), $b), $context->builder->gep($state, $i64->constInt(1, false)));
        $context->builder->store(self::u32Add($context, $context->builder->load($context->builder->gep($state, $i64->constInt(2, false))), $c), $context->builder->gep($state, $i64->constInt(2, false)));
        $context->builder->store(self::u32Add($context, $context->builder->load($context->builder->gep($state, $i64->constInt(3, false))), $d), $context->builder->gep($state, $i64->constInt(3, false)));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function emitSha256Transform(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_hc_sha256_transform');
        if (null !== $probe && $probe->countBasicBlocks() > 0) { return; }
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');
        $fn = null !== $probe ? $probe : $context->module->addFunction('__phpc_hc_sha256_transform', $context->context->functionType($voidTy, false, $i32->pointerType(0), $i8p));
        $entry = $fn->appendBasicBlock('hc_sha256t_entry');
        $context->builder->positionAtEnd($entry);
        $state = $fn->getParam(0);
        $block = $fn->getParam(1);
        $m = $context->builder->alloca($i32, 64, 'sha256_m');
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(0, false))), $i32), $i32->constInt(24, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(1, false))), $i32), $i32->constInt(16, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(2, false))), $i32), $i32->constInt(8, false)), $context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(3, false))), $i32))))), $context->builder->gep($m, $i64->constInt(0, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(4, false))), $i32), $i32->constInt(24, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(5, false))), $i32), $i32->constInt(16, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(6, false))), $i32), $i32->constInt(8, false)), $context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(7, false))), $i32))))), $context->builder->gep($m, $i64->constInt(1, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(8, false))), $i32), $i32->constInt(24, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(9, false))), $i32), $i32->constInt(16, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(10, false))), $i32), $i32->constInt(8, false)), $context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(11, false))), $i32))))), $context->builder->gep($m, $i64->constInt(2, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(12, false))), $i32), $i32->constInt(24, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(13, false))), $i32), $i32->constInt(16, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(14, false))), $i32), $i32->constInt(8, false)), $context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(15, false))), $i32))))), $context->builder->gep($m, $i64->constInt(3, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(16, false))), $i32), $i32->constInt(24, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(17, false))), $i32), $i32->constInt(16, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(18, false))), $i32), $i32->constInt(8, false)), $context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(19, false))), $i32))))), $context->builder->gep($m, $i64->constInt(4, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(20, false))), $i32), $i32->constInt(24, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(21, false))), $i32), $i32->constInt(16, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(22, false))), $i32), $i32->constInt(8, false)), $context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(23, false))), $i32))))), $context->builder->gep($m, $i64->constInt(5, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(24, false))), $i32), $i32->constInt(24, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(25, false))), $i32), $i32->constInt(16, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(26, false))), $i32), $i32->constInt(8, false)), $context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(27, false))), $i32))))), $context->builder->gep($m, $i64->constInt(6, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(28, false))), $i32), $i32->constInt(24, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(29, false))), $i32), $i32->constInt(16, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(30, false))), $i32), $i32->constInt(8, false)), $context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(31, false))), $i32))))), $context->builder->gep($m, $i64->constInt(7, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(32, false))), $i32), $i32->constInt(24, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(33, false))), $i32), $i32->constInt(16, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(34, false))), $i32), $i32->constInt(8, false)), $context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(35, false))), $i32))))), $context->builder->gep($m, $i64->constInt(8, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(36, false))), $i32), $i32->constInt(24, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(37, false))), $i32), $i32->constInt(16, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(38, false))), $i32), $i32->constInt(8, false)), $context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(39, false))), $i32))))), $context->builder->gep($m, $i64->constInt(9, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(40, false))), $i32), $i32->constInt(24, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(41, false))), $i32), $i32->constInt(16, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(42, false))), $i32), $i32->constInt(8, false)), $context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(43, false))), $i32))))), $context->builder->gep($m, $i64->constInt(10, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(44, false))), $i32), $i32->constInt(24, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(45, false))), $i32), $i32->constInt(16, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(46, false))), $i32), $i32->constInt(8, false)), $context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(47, false))), $i32))))), $context->builder->gep($m, $i64->constInt(11, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(48, false))), $i32), $i32->constInt(24, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(49, false))), $i32), $i32->constInt(16, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(50, false))), $i32), $i32->constInt(8, false)), $context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(51, false))), $i32))))), $context->builder->gep($m, $i64->constInt(12, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(52, false))), $i32), $i32->constInt(24, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(53, false))), $i32), $i32->constInt(16, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(54, false))), $i32), $i32->constInt(8, false)), $context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(55, false))), $i32))))), $context->builder->gep($m, $i64->constInt(13, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(56, false))), $i32), $i32->constInt(24, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(57, false))), $i32), $i32->constInt(16, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(58, false))), $i32), $i32->constInt(8, false)), $context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(59, false))), $i32))))), $context->builder->gep($m, $i64->constInt(14, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(60, false))), $i32), $i32->constInt(24, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(61, false))), $i32), $i32->constInt(16, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(62, false))), $i32), $i32->constInt(8, false)), $context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(63, false))), $i32))))), $context->builder->gep($m, $i64->constInt(15, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(14, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(9, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(1, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(0, false)))), $context->builder->gep($m, $i64->constInt(16, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(15, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(10, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(2, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(1, false)))), $context->builder->gep($m, $i64->constInt(17, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(16, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(11, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(3, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(2, false)))), $context->builder->gep($m, $i64->constInt(18, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(17, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(12, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(4, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(3, false)))), $context->builder->gep($m, $i64->constInt(19, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(18, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(13, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(5, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(4, false)))), $context->builder->gep($m, $i64->constInt(20, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(19, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(14, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(6, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(5, false)))), $context->builder->gep($m, $i64->constInt(21, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(20, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(15, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(7, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(6, false)))), $context->builder->gep($m, $i64->constInt(22, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(21, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(16, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(8, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(7, false)))), $context->builder->gep($m, $i64->constInt(23, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(22, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(17, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(9, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(8, false)))), $context->builder->gep($m, $i64->constInt(24, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(23, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(18, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(10, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(9, false)))), $context->builder->gep($m, $i64->constInt(25, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(24, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(19, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(11, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(10, false)))), $context->builder->gep($m, $i64->constInt(26, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(25, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(20, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(12, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(11, false)))), $context->builder->gep($m, $i64->constInt(27, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(26, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(21, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(13, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(12, false)))), $context->builder->gep($m, $i64->constInt(28, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(27, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(22, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(14, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(13, false)))), $context->builder->gep($m, $i64->constInt(29, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(28, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(23, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(15, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(14, false)))), $context->builder->gep($m, $i64->constInt(30, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(29, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(24, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(16, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(15, false)))), $context->builder->gep($m, $i64->constInt(31, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(30, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(25, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(17, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(16, false)))), $context->builder->gep($m, $i64->constInt(32, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(31, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(26, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(18, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(17, false)))), $context->builder->gep($m, $i64->constInt(33, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(32, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(27, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(19, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(18, false)))), $context->builder->gep($m, $i64->constInt(34, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(33, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(28, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(20, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(19, false)))), $context->builder->gep($m, $i64->constInt(35, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(34, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(29, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(21, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(20, false)))), $context->builder->gep($m, $i64->constInt(36, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(35, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(30, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(22, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(21, false)))), $context->builder->gep($m, $i64->constInt(37, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(36, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(31, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(23, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(22, false)))), $context->builder->gep($m, $i64->constInt(38, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(37, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(32, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(24, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(23, false)))), $context->builder->gep($m, $i64->constInt(39, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(38, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(33, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(25, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(24, false)))), $context->builder->gep($m, $i64->constInt(40, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(39, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(34, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(26, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(25, false)))), $context->builder->gep($m, $i64->constInt(41, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(40, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(35, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(27, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(26, false)))), $context->builder->gep($m, $i64->constInt(42, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(41, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(36, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(28, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(27, false)))), $context->builder->gep($m, $i64->constInt(43, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(42, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(37, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(29, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(28, false)))), $context->builder->gep($m, $i64->constInt(44, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(43, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(38, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(30, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(29, false)))), $context->builder->gep($m, $i64->constInt(45, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(44, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(39, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(31, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(30, false)))), $context->builder->gep($m, $i64->constInt(46, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(45, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(40, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(32, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(31, false)))), $context->builder->gep($m, $i64->constInt(47, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(46, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(41, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(33, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(32, false)))), $context->builder->gep($m, $i64->constInt(48, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(47, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(42, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(34, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(33, false)))), $context->builder->gep($m, $i64->constInt(49, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(48, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(43, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(35, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(34, false)))), $context->builder->gep($m, $i64->constInt(50, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(49, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(44, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(36, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(35, false)))), $context->builder->gep($m, $i64->constInt(51, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(50, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(45, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(37, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(36, false)))), $context->builder->gep($m, $i64->constInt(52, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(51, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(46, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(38, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(37, false)))), $context->builder->gep($m, $i64->constInt(53, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(52, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(47, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(39, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(38, false)))), $context->builder->gep($m, $i64->constInt(54, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(53, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(48, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(40, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(39, false)))), $context->builder->gep($m, $i64->constInt(55, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(54, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(49, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(41, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(40, false)))), $context->builder->gep($m, $i64->constInt(56, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(55, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(50, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(42, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(41, false)))), $context->builder->gep($m, $i64->constInt(57, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(56, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(51, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(43, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(42, false)))), $context->builder->gep($m, $i64->constInt(58, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(57, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(52, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(44, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(43, false)))), $context->builder->gep($m, $i64->constInt(59, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(58, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(53, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(45, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(44, false)))), $context->builder->gep($m, $i64->constInt(60, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(59, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(54, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(46, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(45, false)))), $context->builder->gep($m, $i64->constInt(61, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(60, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(55, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(47, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(46, false)))), $context->builder->gep($m, $i64->constInt(62, false)));
        $context->builder->store(self::u32Add($context, self::u32Add($context, self::u32Add($context, self::sha256Sig1($context, $context->builder->load($context->builder->gep($m, $i64->constInt(61, false)))), $context->builder->load($context->builder->gep($m, $i64->constInt(56, false)))), self::sha256Sig0($context, $context->builder->load($context->builder->gep($m, $i64->constInt(48, false))))), $context->builder->load($context->builder->gep($m, $i64->constInt(47, false)))), $context->builder->gep($m, $i64->constInt(63, false)));
        $a = $context->builder->load($context->builder->gep($state, $i64->constInt(0, false)));
        $b = $context->builder->load($context->builder->gep($state, $i64->constInt(1, false)));
        $c = $context->builder->load($context->builder->gep($state, $i64->constInt(2, false)));
        $d = $context->builder->load($context->builder->gep($state, $i64->constInt(3, false)));
        $e = $context->builder->load($context->builder->gep($state, $i64->constInt(4, false)));
        $f = $context->builder->load($context->builder->gep($state, $i64->constInt(5, false)));
        $g = $context->builder->load($context->builder->gep($state, $i64->constInt(6, false)));
        $h = $context->builder->load($context->builder->gep($state, $i64->constInt(7, false)));
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 0), $context->builder->load($context->builder->gep($m, $i64->constInt(0, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 1), $context->builder->load($context->builder->gep($m, $i64->constInt(1, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 2), $context->builder->load($context->builder->gep($m, $i64->constInt(2, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 3), $context->builder->load($context->builder->gep($m, $i64->constInt(3, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 4), $context->builder->load($context->builder->gep($m, $i64->constInt(4, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 5), $context->builder->load($context->builder->gep($m, $i64->constInt(5, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 6), $context->builder->load($context->builder->gep($m, $i64->constInt(6, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 7), $context->builder->load($context->builder->gep($m, $i64->constInt(7, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 8), $context->builder->load($context->builder->gep($m, $i64->constInt(8, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 9), $context->builder->load($context->builder->gep($m, $i64->constInt(9, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 10), $context->builder->load($context->builder->gep($m, $i64->constInt(10, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 11), $context->builder->load($context->builder->gep($m, $i64->constInt(11, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 12), $context->builder->load($context->builder->gep($m, $i64->constInt(12, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 13), $context->builder->load($context->builder->gep($m, $i64->constInt(13, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 14), $context->builder->load($context->builder->gep($m, $i64->constInt(14, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 15), $context->builder->load($context->builder->gep($m, $i64->constInt(15, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 16), $context->builder->load($context->builder->gep($m, $i64->constInt(16, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 17), $context->builder->load($context->builder->gep($m, $i64->constInt(17, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 18), $context->builder->load($context->builder->gep($m, $i64->constInt(18, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 19), $context->builder->load($context->builder->gep($m, $i64->constInt(19, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 20), $context->builder->load($context->builder->gep($m, $i64->constInt(20, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 21), $context->builder->load($context->builder->gep($m, $i64->constInt(21, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 22), $context->builder->load($context->builder->gep($m, $i64->constInt(22, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 23), $context->builder->load($context->builder->gep($m, $i64->constInt(23, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 24), $context->builder->load($context->builder->gep($m, $i64->constInt(24, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 25), $context->builder->load($context->builder->gep($m, $i64->constInt(25, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 26), $context->builder->load($context->builder->gep($m, $i64->constInt(26, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 27), $context->builder->load($context->builder->gep($m, $i64->constInt(27, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 28), $context->builder->load($context->builder->gep($m, $i64->constInt(28, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 29), $context->builder->load($context->builder->gep($m, $i64->constInt(29, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 30), $context->builder->load($context->builder->gep($m, $i64->constInt(30, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 31), $context->builder->load($context->builder->gep($m, $i64->constInt(31, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 32), $context->builder->load($context->builder->gep($m, $i64->constInt(32, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 33), $context->builder->load($context->builder->gep($m, $i64->constInt(33, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 34), $context->builder->load($context->builder->gep($m, $i64->constInt(34, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 35), $context->builder->load($context->builder->gep($m, $i64->constInt(35, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 36), $context->builder->load($context->builder->gep($m, $i64->constInt(36, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 37), $context->builder->load($context->builder->gep($m, $i64->constInt(37, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 38), $context->builder->load($context->builder->gep($m, $i64->constInt(38, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 39), $context->builder->load($context->builder->gep($m, $i64->constInt(39, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 40), $context->builder->load($context->builder->gep($m, $i64->constInt(40, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 41), $context->builder->load($context->builder->gep($m, $i64->constInt(41, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 42), $context->builder->load($context->builder->gep($m, $i64->constInt(42, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 43), $context->builder->load($context->builder->gep($m, $i64->constInt(43, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 44), $context->builder->load($context->builder->gep($m, $i64->constInt(44, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 45), $context->builder->load($context->builder->gep($m, $i64->constInt(45, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 46), $context->builder->load($context->builder->gep($m, $i64->constInt(46, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 47), $context->builder->load($context->builder->gep($m, $i64->constInt(47, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 48), $context->builder->load($context->builder->gep($m, $i64->constInt(48, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 49), $context->builder->load($context->builder->gep($m, $i64->constInt(49, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 50), $context->builder->load($context->builder->gep($m, $i64->constInt(50, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 51), $context->builder->load($context->builder->gep($m, $i64->constInt(51, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 52), $context->builder->load($context->builder->gep($m, $i64->constInt(52, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 53), $context->builder->load($context->builder->gep($m, $i64->constInt(53, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 54), $context->builder->load($context->builder->gep($m, $i64->constInt(54, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 55), $context->builder->load($context->builder->gep($m, $i64->constInt(55, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 56), $context->builder->load($context->builder->gep($m, $i64->constInt(56, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 57), $context->builder->load($context->builder->gep($m, $i64->constInt(57, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 58), $context->builder->load($context->builder->gep($m, $i64->constInt(58, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 59), $context->builder->load($context->builder->gep($m, $i64->constInt(59, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 60), $context->builder->load($context->builder->gep($m, $i64->constInt(60, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 61), $context->builder->load($context->builder->gep($m, $i64->constInt(61, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 62), $context->builder->load($context->builder->gep($m, $i64->constInt(62, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $t1 = self::u32Add($context, $h, self::u32Add($context, self::sha256Ep1($context, $e), self::u32Add($context, self::sha256Ch($context, $e, $f, $g), self::u32Add($context, self::sha256K($context, 63), $context->builder->load($context->builder->gep($m, $i64->constInt(63, false)))))));
        $t2 = self::u32Add($context, self::sha256Ep0($context, $a), self::sha256Maj($context, $a, $b, $c));
        $h = $g; $g = $f; $f = $e;
        $e = self::u32Add($context, $d, $t1);
        $d = $c; $c = $b; $b = $a;
        $a = self::u32Add($context, $t1, $t2);
        $context->builder->store(self::u32Add($context, $context->builder->load($context->builder->gep($state, $i64->constInt(0, false))), $a), $context->builder->gep($state, $i64->constInt(0, false)));
        $context->builder->store(self::u32Add($context, $context->builder->load($context->builder->gep($state, $i64->constInt(1, false))), $b), $context->builder->gep($state, $i64->constInt(1, false)));
        $context->builder->store(self::u32Add($context, $context->builder->load($context->builder->gep($state, $i64->constInt(2, false))), $c), $context->builder->gep($state, $i64->constInt(2, false)));
        $context->builder->store(self::u32Add($context, $context->builder->load($context->builder->gep($state, $i64->constInt(3, false))), $d), $context->builder->gep($state, $i64->constInt(3, false)));
        $context->builder->store(self::u32Add($context, $context->builder->load($context->builder->gep($state, $i64->constInt(4, false))), $e), $context->builder->gep($state, $i64->constInt(4, false)));
        $context->builder->store(self::u32Add($context, $context->builder->load($context->builder->gep($state, $i64->constInt(5, false))), $f), $context->builder->gep($state, $i64->constInt(5, false)));
        $context->builder->store(self::u32Add($context, $context->builder->load($context->builder->gep($state, $i64->constInt(6, false))), $g), $context->builder->gep($state, $i64->constInt(6, false)));
        $context->builder->store(self::u32Add($context, $context->builder->load($context->builder->gep($state, $i64->constInt(7, false))), $h), $context->builder->gep($state, $i64->constInt(7, false)));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function emitSha1Transform(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_hc_sha1_transform');
        if (null !== $probe && $probe->countBasicBlocks() > 0) { return; }
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');
        $fn = null !== $probe ? $probe : $context->module->addFunction('__phpc_hc_sha1_transform', $context->context->functionType($voidTy, false, $i32->pointerType(0), $i8p));
        $entry = $fn->appendBasicBlock('hc_sha1t_entry');
        $context->builder->positionAtEnd($entry);
        $state = $fn->getParam(0);
        $block = $fn->getParam(1);
        $x = $context->builder->alloca($i32, 16, 'sha1_x');
        $w = $context->builder->alloca($i32, 80, 'sha1_w');
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(0, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(1, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(2, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(3, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(0, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(4, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(5, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(6, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(7, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(1, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(8, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(9, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(10, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(11, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(2, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(12, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(13, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(14, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(15, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(3, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(16, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(17, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(18, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(19, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(4, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(20, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(21, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(22, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(23, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(5, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(24, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(25, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(26, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(27, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(6, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(28, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(29, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(30, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(31, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(7, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(32, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(33, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(34, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(35, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(8, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(36, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(37, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(38, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(39, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(9, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(40, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(41, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(42, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(43, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(10, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(44, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(45, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(46, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(47, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(11, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(48, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(49, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(50, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(51, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(12, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(52, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(53, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(54, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(55, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(13, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(56, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(57, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(58, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(59, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(14, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(60, false))), $i32), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(61, false))), $i32), $i32->constInt(8, false)), $context->builder->or($context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(62, false))), $i32), $i32->constInt(16, false)), $context->builder->shl($context->builder->zExt($context->builder->load($context->builder->gep($block, $i64->constInt(63, false))), $i32), $i32->constInt(24, false)))))), $context->builder->gep($x, $i64->constInt(15, false)));
        $x0 = $context->builder->load($context->builder->gep($x, $i64->constInt(0, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->and($context->builder->shl($x0, $i32->constInt(24, false)), $i32->constInt(0xFF000000, false)), $context->builder->or($context->builder->and($context->builder->shl($x0, $i32->constInt(8, false)), $i32->constInt(0x00FF0000, false)), $context->builder->or($context->builder->and($context->builder->lshr($x0, $i32->constInt(8, false)), $i32->constInt(0x0000FF00, false)), $context->builder->and($context->builder->lshr($x0, $i32->constInt(24, false)), $i32->constInt(0x000000FF, false)))))), $context->builder->gep($w, $i64->constInt(0, false)));
        $x1 = $context->builder->load($context->builder->gep($x, $i64->constInt(1, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->and($context->builder->shl($x1, $i32->constInt(24, false)), $i32->constInt(0xFF000000, false)), $context->builder->or($context->builder->and($context->builder->shl($x1, $i32->constInt(8, false)), $i32->constInt(0x00FF0000, false)), $context->builder->or($context->builder->and($context->builder->lshr($x1, $i32->constInt(8, false)), $i32->constInt(0x0000FF00, false)), $context->builder->and($context->builder->lshr($x1, $i32->constInt(24, false)), $i32->constInt(0x000000FF, false)))))), $context->builder->gep($w, $i64->constInt(1, false)));
        $x2 = $context->builder->load($context->builder->gep($x, $i64->constInt(2, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->and($context->builder->shl($x2, $i32->constInt(24, false)), $i32->constInt(0xFF000000, false)), $context->builder->or($context->builder->and($context->builder->shl($x2, $i32->constInt(8, false)), $i32->constInt(0x00FF0000, false)), $context->builder->or($context->builder->and($context->builder->lshr($x2, $i32->constInt(8, false)), $i32->constInt(0x0000FF00, false)), $context->builder->and($context->builder->lshr($x2, $i32->constInt(24, false)), $i32->constInt(0x000000FF, false)))))), $context->builder->gep($w, $i64->constInt(2, false)));
        $x3 = $context->builder->load($context->builder->gep($x, $i64->constInt(3, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->and($context->builder->shl($x3, $i32->constInt(24, false)), $i32->constInt(0xFF000000, false)), $context->builder->or($context->builder->and($context->builder->shl($x3, $i32->constInt(8, false)), $i32->constInt(0x00FF0000, false)), $context->builder->or($context->builder->and($context->builder->lshr($x3, $i32->constInt(8, false)), $i32->constInt(0x0000FF00, false)), $context->builder->and($context->builder->lshr($x3, $i32->constInt(24, false)), $i32->constInt(0x000000FF, false)))))), $context->builder->gep($w, $i64->constInt(3, false)));
        $x4 = $context->builder->load($context->builder->gep($x, $i64->constInt(4, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->and($context->builder->shl($x4, $i32->constInt(24, false)), $i32->constInt(0xFF000000, false)), $context->builder->or($context->builder->and($context->builder->shl($x4, $i32->constInt(8, false)), $i32->constInt(0x00FF0000, false)), $context->builder->or($context->builder->and($context->builder->lshr($x4, $i32->constInt(8, false)), $i32->constInt(0x0000FF00, false)), $context->builder->and($context->builder->lshr($x4, $i32->constInt(24, false)), $i32->constInt(0x000000FF, false)))))), $context->builder->gep($w, $i64->constInt(4, false)));
        $x5 = $context->builder->load($context->builder->gep($x, $i64->constInt(5, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->and($context->builder->shl($x5, $i32->constInt(24, false)), $i32->constInt(0xFF000000, false)), $context->builder->or($context->builder->and($context->builder->shl($x5, $i32->constInt(8, false)), $i32->constInt(0x00FF0000, false)), $context->builder->or($context->builder->and($context->builder->lshr($x5, $i32->constInt(8, false)), $i32->constInt(0x0000FF00, false)), $context->builder->and($context->builder->lshr($x5, $i32->constInt(24, false)), $i32->constInt(0x000000FF, false)))))), $context->builder->gep($w, $i64->constInt(5, false)));
        $x6 = $context->builder->load($context->builder->gep($x, $i64->constInt(6, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->and($context->builder->shl($x6, $i32->constInt(24, false)), $i32->constInt(0xFF000000, false)), $context->builder->or($context->builder->and($context->builder->shl($x6, $i32->constInt(8, false)), $i32->constInt(0x00FF0000, false)), $context->builder->or($context->builder->and($context->builder->lshr($x6, $i32->constInt(8, false)), $i32->constInt(0x0000FF00, false)), $context->builder->and($context->builder->lshr($x6, $i32->constInt(24, false)), $i32->constInt(0x000000FF, false)))))), $context->builder->gep($w, $i64->constInt(6, false)));
        $x7 = $context->builder->load($context->builder->gep($x, $i64->constInt(7, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->and($context->builder->shl($x7, $i32->constInt(24, false)), $i32->constInt(0xFF000000, false)), $context->builder->or($context->builder->and($context->builder->shl($x7, $i32->constInt(8, false)), $i32->constInt(0x00FF0000, false)), $context->builder->or($context->builder->and($context->builder->lshr($x7, $i32->constInt(8, false)), $i32->constInt(0x0000FF00, false)), $context->builder->and($context->builder->lshr($x7, $i32->constInt(24, false)), $i32->constInt(0x000000FF, false)))))), $context->builder->gep($w, $i64->constInt(7, false)));
        $x8 = $context->builder->load($context->builder->gep($x, $i64->constInt(8, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->and($context->builder->shl($x8, $i32->constInt(24, false)), $i32->constInt(0xFF000000, false)), $context->builder->or($context->builder->and($context->builder->shl($x8, $i32->constInt(8, false)), $i32->constInt(0x00FF0000, false)), $context->builder->or($context->builder->and($context->builder->lshr($x8, $i32->constInt(8, false)), $i32->constInt(0x0000FF00, false)), $context->builder->and($context->builder->lshr($x8, $i32->constInt(24, false)), $i32->constInt(0x000000FF, false)))))), $context->builder->gep($w, $i64->constInt(8, false)));
        $x9 = $context->builder->load($context->builder->gep($x, $i64->constInt(9, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->and($context->builder->shl($x9, $i32->constInt(24, false)), $i32->constInt(0xFF000000, false)), $context->builder->or($context->builder->and($context->builder->shl($x9, $i32->constInt(8, false)), $i32->constInt(0x00FF0000, false)), $context->builder->or($context->builder->and($context->builder->lshr($x9, $i32->constInt(8, false)), $i32->constInt(0x0000FF00, false)), $context->builder->and($context->builder->lshr($x9, $i32->constInt(24, false)), $i32->constInt(0x000000FF, false)))))), $context->builder->gep($w, $i64->constInt(9, false)));
        $x10 = $context->builder->load($context->builder->gep($x, $i64->constInt(10, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->and($context->builder->shl($x10, $i32->constInt(24, false)), $i32->constInt(0xFF000000, false)), $context->builder->or($context->builder->and($context->builder->shl($x10, $i32->constInt(8, false)), $i32->constInt(0x00FF0000, false)), $context->builder->or($context->builder->and($context->builder->lshr($x10, $i32->constInt(8, false)), $i32->constInt(0x0000FF00, false)), $context->builder->and($context->builder->lshr($x10, $i32->constInt(24, false)), $i32->constInt(0x000000FF, false)))))), $context->builder->gep($w, $i64->constInt(10, false)));
        $x11 = $context->builder->load($context->builder->gep($x, $i64->constInt(11, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->and($context->builder->shl($x11, $i32->constInt(24, false)), $i32->constInt(0xFF000000, false)), $context->builder->or($context->builder->and($context->builder->shl($x11, $i32->constInt(8, false)), $i32->constInt(0x00FF0000, false)), $context->builder->or($context->builder->and($context->builder->lshr($x11, $i32->constInt(8, false)), $i32->constInt(0x0000FF00, false)), $context->builder->and($context->builder->lshr($x11, $i32->constInt(24, false)), $i32->constInt(0x000000FF, false)))))), $context->builder->gep($w, $i64->constInt(11, false)));
        $x12 = $context->builder->load($context->builder->gep($x, $i64->constInt(12, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->and($context->builder->shl($x12, $i32->constInt(24, false)), $i32->constInt(0xFF000000, false)), $context->builder->or($context->builder->and($context->builder->shl($x12, $i32->constInt(8, false)), $i32->constInt(0x00FF0000, false)), $context->builder->or($context->builder->and($context->builder->lshr($x12, $i32->constInt(8, false)), $i32->constInt(0x0000FF00, false)), $context->builder->and($context->builder->lshr($x12, $i32->constInt(24, false)), $i32->constInt(0x000000FF, false)))))), $context->builder->gep($w, $i64->constInt(12, false)));
        $x13 = $context->builder->load($context->builder->gep($x, $i64->constInt(13, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->and($context->builder->shl($x13, $i32->constInt(24, false)), $i32->constInt(0xFF000000, false)), $context->builder->or($context->builder->and($context->builder->shl($x13, $i32->constInt(8, false)), $i32->constInt(0x00FF0000, false)), $context->builder->or($context->builder->and($context->builder->lshr($x13, $i32->constInt(8, false)), $i32->constInt(0x0000FF00, false)), $context->builder->and($context->builder->lshr($x13, $i32->constInt(24, false)), $i32->constInt(0x000000FF, false)))))), $context->builder->gep($w, $i64->constInt(13, false)));
        $x14 = $context->builder->load($context->builder->gep($x, $i64->constInt(14, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->and($context->builder->shl($x14, $i32->constInt(24, false)), $i32->constInt(0xFF000000, false)), $context->builder->or($context->builder->and($context->builder->shl($x14, $i32->constInt(8, false)), $i32->constInt(0x00FF0000, false)), $context->builder->or($context->builder->and($context->builder->lshr($x14, $i32->constInt(8, false)), $i32->constInt(0x0000FF00, false)), $context->builder->and($context->builder->lshr($x14, $i32->constInt(24, false)), $i32->constInt(0x000000FF, false)))))), $context->builder->gep($w, $i64->constInt(14, false)));
        $x15 = $context->builder->load($context->builder->gep($x, $i64->constInt(15, false)));
        $context->builder->store(self::u32($context, $context->builder->or($context->builder->and($context->builder->shl($x15, $i32->constInt(24, false)), $i32->constInt(0xFF000000, false)), $context->builder->or($context->builder->and($context->builder->shl($x15, $i32->constInt(8, false)), $i32->constInt(0x00FF0000, false)), $context->builder->or($context->builder->and($context->builder->lshr($x15, $i32->constInt(8, false)), $i32->constInt(0x0000FF00, false)), $context->builder->and($context->builder->lshr($x15, $i32->constInt(24, false)), $i32->constInt(0x000000FF, false)))))), $context->builder->gep($w, $i64->constInt(15, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(13, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(8, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(2, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(0, false))))), 1), $context->builder->gep($w, $i64->constInt(16, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(14, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(9, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(3, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(1, false))))), 1), $context->builder->gep($w, $i64->constInt(17, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(15, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(10, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(4, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(2, false))))), 1), $context->builder->gep($w, $i64->constInt(18, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(16, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(11, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(5, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(3, false))))), 1), $context->builder->gep($w, $i64->constInt(19, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(17, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(12, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(6, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(4, false))))), 1), $context->builder->gep($w, $i64->constInt(20, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(18, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(13, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(7, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(5, false))))), 1), $context->builder->gep($w, $i64->constInt(21, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(19, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(14, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(8, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(6, false))))), 1), $context->builder->gep($w, $i64->constInt(22, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(20, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(15, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(9, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(7, false))))), 1), $context->builder->gep($w, $i64->constInt(23, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(21, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(16, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(10, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(8, false))))), 1), $context->builder->gep($w, $i64->constInt(24, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(22, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(17, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(11, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(9, false))))), 1), $context->builder->gep($w, $i64->constInt(25, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(23, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(18, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(12, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(10, false))))), 1), $context->builder->gep($w, $i64->constInt(26, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(24, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(19, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(13, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(11, false))))), 1), $context->builder->gep($w, $i64->constInt(27, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(25, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(20, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(14, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(12, false))))), 1), $context->builder->gep($w, $i64->constInt(28, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(26, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(21, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(15, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(13, false))))), 1), $context->builder->gep($w, $i64->constInt(29, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(27, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(22, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(16, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(14, false))))), 1), $context->builder->gep($w, $i64->constInt(30, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(28, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(23, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(17, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(15, false))))), 1), $context->builder->gep($w, $i64->constInt(31, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(29, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(24, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(18, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(16, false))))), 1), $context->builder->gep($w, $i64->constInt(32, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(30, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(25, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(19, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(17, false))))), 1), $context->builder->gep($w, $i64->constInt(33, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(31, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(26, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(20, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(18, false))))), 1), $context->builder->gep($w, $i64->constInt(34, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(32, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(27, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(21, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(19, false))))), 1), $context->builder->gep($w, $i64->constInt(35, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(33, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(28, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(22, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(20, false))))), 1), $context->builder->gep($w, $i64->constInt(36, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(34, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(29, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(23, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(21, false))))), 1), $context->builder->gep($w, $i64->constInt(37, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(35, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(30, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(24, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(22, false))))), 1), $context->builder->gep($w, $i64->constInt(38, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(36, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(31, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(25, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(23, false))))), 1), $context->builder->gep($w, $i64->constInt(39, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(37, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(32, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(26, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(24, false))))), 1), $context->builder->gep($w, $i64->constInt(40, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(38, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(33, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(27, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(25, false))))), 1), $context->builder->gep($w, $i64->constInt(41, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(39, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(34, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(28, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(26, false))))), 1), $context->builder->gep($w, $i64->constInt(42, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(40, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(35, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(29, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(27, false))))), 1), $context->builder->gep($w, $i64->constInt(43, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(41, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(36, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(30, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(28, false))))), 1), $context->builder->gep($w, $i64->constInt(44, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(42, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(37, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(31, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(29, false))))), 1), $context->builder->gep($w, $i64->constInt(45, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(43, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(38, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(32, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(30, false))))), 1), $context->builder->gep($w, $i64->constInt(46, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(44, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(39, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(33, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(31, false))))), 1), $context->builder->gep($w, $i64->constInt(47, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(45, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(40, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(34, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(32, false))))), 1), $context->builder->gep($w, $i64->constInt(48, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(46, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(41, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(35, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(33, false))))), 1), $context->builder->gep($w, $i64->constInt(49, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(47, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(42, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(36, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(34, false))))), 1), $context->builder->gep($w, $i64->constInt(50, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(48, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(43, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(37, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(35, false))))), 1), $context->builder->gep($w, $i64->constInt(51, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(49, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(44, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(38, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(36, false))))), 1), $context->builder->gep($w, $i64->constInt(52, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(50, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(45, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(39, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(37, false))))), 1), $context->builder->gep($w, $i64->constInt(53, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(51, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(46, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(40, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(38, false))))), 1), $context->builder->gep($w, $i64->constInt(54, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(52, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(47, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(41, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(39, false))))), 1), $context->builder->gep($w, $i64->constInt(55, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(53, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(48, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(42, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(40, false))))), 1), $context->builder->gep($w, $i64->constInt(56, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(54, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(49, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(43, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(41, false))))), 1), $context->builder->gep($w, $i64->constInt(57, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(55, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(50, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(44, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(42, false))))), 1), $context->builder->gep($w, $i64->constInt(58, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(56, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(51, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(45, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(43, false))))), 1), $context->builder->gep($w, $i64->constInt(59, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(57, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(52, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(46, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(44, false))))), 1), $context->builder->gep($w, $i64->constInt(60, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(58, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(53, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(47, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(45, false))))), 1), $context->builder->gep($w, $i64->constInt(61, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(59, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(54, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(48, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(46, false))))), 1), $context->builder->gep($w, $i64->constInt(62, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(60, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(55, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(49, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(47, false))))), 1), $context->builder->gep($w, $i64->constInt(63, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(61, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(56, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(50, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(48, false))))), 1), $context->builder->gep($w, $i64->constInt(64, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(62, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(57, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(51, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(49, false))))), 1), $context->builder->gep($w, $i64->constInt(65, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(63, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(58, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(52, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(50, false))))), 1), $context->builder->gep($w, $i64->constInt(66, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(64, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(59, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(53, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(51, false))))), 1), $context->builder->gep($w, $i64->constInt(67, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(65, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(60, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(54, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(52, false))))), 1), $context->builder->gep($w, $i64->constInt(68, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(66, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(61, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(55, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(53, false))))), 1), $context->builder->gep($w, $i64->constInt(69, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(67, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(62, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(56, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(54, false))))), 1), $context->builder->gep($w, $i64->constInt(70, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(68, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(63, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(57, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(55, false))))), 1), $context->builder->gep($w, $i64->constInt(71, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(69, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(64, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(58, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(56, false))))), 1), $context->builder->gep($w, $i64->constInt(72, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(70, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(65, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(59, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(57, false))))), 1), $context->builder->gep($w, $i64->constInt(73, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(71, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(66, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(60, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(58, false))))), 1), $context->builder->gep($w, $i64->constInt(74, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(72, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(67, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(61, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(59, false))))), 1), $context->builder->gep($w, $i64->constInt(75, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(73, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(68, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(62, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(60, false))))), 1), $context->builder->gep($w, $i64->constInt(76, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(74, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(69, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(63, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(61, false))))), 1), $context->builder->gep($w, $i64->constInt(77, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(75, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(70, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(64, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(62, false))))), 1), $context->builder->gep($w, $i64->constInt(78, false)));
        $context->builder->store(self::rotl($context, $context->builder->xor($context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(76, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(71, false)))), $context->builder->xor($context->builder->load($context->builder->gep($w, $i64->constInt(65, false))), $context->builder->load($context->builder->gep($w, $i64->constInt(63, false))))), 1), $context->builder->gep($w, $i64->constInt(79, false)));
        $a = $context->builder->load($context->builder->gep($state, $i64->constInt(0, false)));
        $b = $context->builder->load($context->builder->gep($state, $i64->constInt(1, false)));
        $c = $context->builder->load($context->builder->gep($state, $i64->constInt(2, false)));
        $d = $context->builder->load($context->builder->gep($state, $i64->constInt(3, false)));
        $e = $context->builder->load($context->builder->gep($state, $i64->constInt(4, false)));
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(0, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(1, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(2, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(3, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(4, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(5, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(6, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(7, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(8, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(9, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(10, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(11, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(12, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(13, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(14, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(15, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(16, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(17, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(18, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F1($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(19, false)))), $i32->constInt(0x5A827999, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(20, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(21, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(22, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(23, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(24, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(25, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(26, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(27, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(28, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(29, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(30, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(31, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(32, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(33, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(34, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(35, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(36, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(37, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(38, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F2($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(39, false)))), $i32->constInt(0x6ED9EBA1, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(40, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(41, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(42, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(43, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(44, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(45, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(46, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(47, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(48, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(49, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(50, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(51, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(52, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(53, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(54, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(55, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(56, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(57, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(58, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F3($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(59, false)))), $i32->constInt(0x8F1BBCDC, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(60, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(61, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(62, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(63, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(64, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(65, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(66, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(67, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(68, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(69, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(70, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(71, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(72, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(73, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(74, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(75, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(76, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(77, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(78, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $temp = self::u32Add($context, self::u32Add($context, self::u32Add($context, self::u32Add($context, self::rotl($context, $a, 5), self::sha1F4($context, $b, $c, $d)), $e), $context->builder->load($context->builder->gep($w, $i64->constInt(79, false)))), $i32->constInt(0xCA62C1D6, false));
        $e = $d; $d = $c; $c = self::rotl($context, $b, 30); $b = $a; $a = $temp;
        $context->builder->store(self::u32Add($context, $context->builder->load($context->builder->gep($state, $i64->constInt(0, false))), $a), $context->builder->gep($state, $i64->constInt(0, false)));
        $context->builder->store(self::u32Add($context, $context->builder->load($context->builder->gep($state, $i64->constInt(1, false))), $b), $context->builder->gep($state, $i64->constInt(1, false)));
        $context->builder->store(self::u32Add($context, $context->builder->load($context->builder->gep($state, $i64->constInt(2, false))), $c), $context->builder->gep($state, $i64->constInt(2, false)));
        $context->builder->store(self::u32Add($context, $context->builder->load($context->builder->gep($state, $i64->constInt(3, false))), $d), $context->builder->gep($state, $i64->constInt(3, false)));
        $context->builder->store(self::u32Add($context, $context->builder->load($context->builder->gep($state, $i64->constInt(4, false))), $e), $context->builder->gep($state, $i64->constInt(4, false)));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function emitAlgoId(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_hc_algo_id');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe ? $probe : $context->module->addFunction(
            '__phpc_hc_algo_id',
            $context->context->functionType($i32, false, $strPtr)
        );
        $entry = $fn->appendBasicBlock('hc_algo_id_entry');
        $context->builder->positionAtEnd($entry);
        $algo = $fn->getParam(0);
        $data = self::stringData($context, $algo);
        $len = self::stringLen($context, $algo);
        $r256 = self::matchFixedCi($context, $fn, $data, $len, [0x73, 0x68, 0x61, 0x32, 0x35, 0x36], 6, self::ALGO_SHA256);
        $r1 = self::matchFixedCi($context, $fn, $data, $len, [0x73, 0x68, 0x61, 0x31], 4, self::ALGO_SHA1);
        $rMd5 = self::matchFixedCi($context, $fn, $data, $len, [0x6d, 0x64, 0x35], 3, self::ALGO_MD5);
        $zero = $i32->constInt(0, false);
        $result = $context->builder->select(
            $context->builder->icmp(Builder::INT_NE, $r256, $zero),
            $r256,
            $context->builder->select(
                $context->builder->icmp(Builder::INT_NE, $r1, $zero),
                $r1,
                $rMd5
            )
        );
        $context->builder->returnValue($result);
        $context->builder->clearInsertionPosition();
    }

    private static function emitDigestLen(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_hc_digest_len');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe ? $probe : $context->module->addFunction(
            '__phpc_hc_digest_len',
            $context->context->functionType($i64, false, $i32)
        );
        $entry = $fn->appendBasicBlock('hc_digest_len_entry');
        $context->builder->positionAtEnd($entry);
        $algo = $fn->getParam(0);
        $is256 = $context->builder->icmp(Builder::INT_EQ, $algo, $i32->constInt(self::ALGO_SHA256, false));
        $is1 = $context->builder->icmp(Builder::INT_EQ, $algo, $i32->constInt(self::ALGO_SHA1, false));
        $len = $context->builder->select(
            $is256,
            $i64->constInt(self::SHA256_DIGEST_SIZE, false),
            $context->builder->select(
                $is1,
                $i64->constInt(self::SHA1_DIGEST_SIZE, false),
                $i64->constInt(self::MD5_DIGEST_SIZE, false)
            )
        );
        $context->builder->returnValue($len);
        $context->builder->clearInsertionPosition();
    }

    private static function emitHexEncode(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_hc_hex_encode');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $fn = null !== $probe ? $probe : $context->module->addFunction(
            '__phpc_hc_hex_encode',
            $context->context->functionType($voidTy, false, $i8p, $sizeT, $i8p)
        );
        $entry = $fn->appendBasicBlock('hc_hex_entry');
        $context->builder->positionAtEnd($entry);
        $bin = $fn->getParam(0);
        $binLen = $fn->getParam(1);
        $out = $fn->getParam(2);
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $loopHead = $fn->appendBasicBlock('hc_hex_head');
        $loopBody = $fn->appendBasicBlock('hc_hex_body');
        $loopDone = $fn->appendBasicBlock('hc_hex_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $done = $context->builder->icmp(Builder::INT_UGE, $i, $binLen);
        $context->builder->branchIf($done, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $b = $context->builder->zExt(
            $context->builder->load($context->builder->gep($bin, $context->builder->truncOrBitCast($i, $i64))),
            $i32
        );
        $hi = $context->builder->and($context->builder->lShr($b, $i32->constInt(4, false)), $i32->constInt(0xF, false));
        $lo = $context->builder->and($b, $i32->constInt(0xF, false));
        $outIdx = $context->builder->mul($context->builder->truncOrBitCast($i, $i64), $i64->constInt(2, false));
        foreach ([$hi, $lo] as $j => $nibble) {
            $isDigit = $context->builder->icmp(Builder::INT_SLT, $nibble, $i32->constInt(10, false));
            $ch = $context->builder->select(
                $isDigit,
                $context->builder->add($nibble, $i32->constInt((int) \ord('0'), false)),
                $context->builder->add($nibble, $i32->constInt((int) \ord('a') - 10, false))
            );
            $context->builder->store(
                $context->builder->trunc($ch, $i8),
                $context->builder->gep($out, $context->builder->add($outIdx, $i64->constInt($j, false)))
            );
        }
        $context->builder->store($context->builder->add($i, $sizeT->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $nullPos = $context->builder->mul($context->builder->truncOrBitCast($binLen, $i64), $i64->constInt(2, false));
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($out, $nullPos));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function emitResultString(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_hc_result_string');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = null !== $probe ? $probe : $context->module->addFunction(
            '__phpc_hc_result_string',
            $context->context->functionType($strPtr, false, $i32, $i8p, $i32)
        );
        $entry = $fn->appendBasicBlock('hc_result_entry');
        $context->builder->positionAtEnd($entry);
        $algo = $fn->getParam(0);
        $digest = $fn->getParam(1);
        $raw = $fn->getParam(2);
        $dlen = self::callDigestLen($context, $algo);
        $rawNonZero = $context->builder->icmp(Builder::INT_NE, $raw, $i32->constInt(0, false));
        $rawBb = $fn->appendBasicBlock('hc_result_raw');
        $hexBb = $fn->appendBasicBlock('hc_result_hex');
        $context->builder->branchIf($rawNonZero, $rawBb, $hexBb);

        $context->builder->positionAtEnd($rawBb);
        $context->builder->returnValue($context->builder->call(
            $context->lookupFunction('__string__init'),
            $dlen,
            $context->builder->pointerCast($digest, $charPtr)
        ));

        $context->builder->positionAtEnd($hexBb);
        $hexLen = $context->builder->mul($dlen, $i64->constInt(2, false));
        $hex = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast(
                $context->builder->add($hexLen, $i64->constInt(1, false)),
                $sizeT
            )
        );
        $hexFail = $fn->appendBasicBlock('hc_result_hex_fail');
        $hexOk = $fn->appendBasicBlock('hc_result_hex_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $hex, $i8p->constNull()),
            $hexFail,
            $hexOk
        );
        $context->builder->positionAtEnd($hexFail);
        $context->builder->returnValue($strPtr->constNull());
        $context->builder->positionAtEnd($hexOk);
        self::callHexEncode(
            $context,
            $digest,
            $context->builder->truncOrBitCast($dlen, $sizeT),
            $hex
        );
        $hexResult = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $hexLen,
            $context->builder->pointerCast($hex, $charPtr)
        );
        $context->builder->call($context->lookupFunction('free'), $hex);
        $context->builder->returnValue($hexResult);
        $context->builder->clearInsertionPosition();
    }

    /** @param list<int> $bytes */
    private static function matchFixedCi(Context $context, LlvmFunction $fn, Value $data, Value $len, array $bytes, int $tlen, int $id): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $lenOk = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt($tlen, false));
        $fail = $fn->appendBasicBlock('hc_mfail');
        $head = $fn->appendBasicBlock('hc_mhead');
        $merge = $fn->appendBasicBlock('hc_mmerge');
        $context->builder->branchIf($lenOk, $head, $fail);
        $context->builder->positionAtEnd($fail);
        $context->builder->store($i32->constInt(0, false), $resultSlot);
        $context->builder->branch($merge);
        $iSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->positionAtEnd($head);
        $context->builder->store($i64->constInt(0, false), $iSlot);
        $loopHead = $fn->appendBasicBlock('hc_mloop');
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $done = $context->builder->icmp(Builder::INT_SGE, $i, $i64->constInt($tlen, false));
        $ok = $fn->appendBasicBlock('hc_mok');
        $body = $fn->appendBasicBlock('hc_mbody');
        $context->builder->branchIf($done, $ok, $body);
        $context->builder->positionAtEnd($body);
        $ca = $context->builder->zExt($context->builder->load($context->builder->gep($data, $i)), $i32);
        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ca, $i32->constInt(65, false)),
            $context->builder->icmp(Builder::INT_SLE, $ca, $i32->constInt(90, false))
        );
        $ca = $context->builder->select($isUpper, $context->builder->add($ca, $i32->constInt(32, false)), $ca);
        $idx = $context->builder->truncOrBitCast($i, $i32);
        $exp = $i32->constInt(0, false);
        foreach ($bytes as $bi => $byte) {
            $at = $context->builder->icmp(Builder::INT_EQ, $idx, $i32->constInt($bi, false));
            $exp = $context->builder->select($at, $i32->constInt($byte, false), $exp);
        }
        $neq = $context->builder->icmp(Builder::INT_NE, $ca, $exp);
        $bodyFail = $fn->appendBasicBlock('hc_mbody_fail');
        $cont = $fn->appendBasicBlock('hc_mcont');
        $context->builder->branchIf($neq, $bodyFail, $cont);
        $context->builder->positionAtEnd($bodyFail);
        $context->builder->store($i32->constInt(0, false), $resultSlot);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($cont);
        $context->builder->store($context->builder->addNoSignedWrap($i, $i64->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($ok);
        $context->builder->store($i32->constInt($id, false), $resultSlot);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        return $context->builder->load($resultSlot);
    }


    private static function emitDigest(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_hc_digest');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $fn = null !== $probe ? $probe : $context->module->addFunction(
            '__phpc_hc_digest',
            $context->context->functionType($voidTy, false, $i32, $i8p, $sizeT, $i8p)
        );
        $entry = $fn->appendBasicBlock('hc_digest_entry');
        $context->builder->positionAtEnd($entry);
        $algo = $fn->getParam(0);
        $data = $fn->getParam(1);
        $len = $fn->getParam(2);
        $out = $fn->getParam(3);
        $is256 = $context->builder->icmp(Builder::INT_EQ, $algo, $i32->constInt(self::ALGO_SHA256, false));
        $is1 = $context->builder->icmp(Builder::INT_EQ, $algo, $i32->constInt(self::ALGO_SHA1, false));
        $pick = $fn->appendBasicBlock('hc_digest_pick');
        $bb256 = $fn->appendBasicBlock('hc_digest_sha256');
        $bb1 = $fn->appendBasicBlock('hc_digest_sha1');
        $bbMd5 = $fn->appendBasicBlock('hc_digest_md5');
        $done = $fn->appendBasicBlock('hc_digest_done');
        $context->builder->branchIf($is256, $bb256, $pick);
        $context->builder->positionAtEnd($pick);
        $context->builder->branchIf($is1, $bb1, $bbMd5);
        $context->builder->positionAtEnd($bb256);
        self::emitSha256DigestBody($context, $fn, $data, $len, $out);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($bb1);
        self::emitSha1DigestBody($context, $fn, $data, $len, $out);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($bbMd5);
        self::emitMd5DigestBody($context, $fn, $data, $len, $out);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function emitSha256DigestBody(Context $context, LlvmFunction $fn, Value $data, Value $len, Value $out): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $state = $context->builder->alloca($i32, 8, 'sha256_state');
        $buf = $context->builder->alloca($i8, 64, 'sha256_buf');
        $datalenSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $bitlenSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i32->constInt(0x6A09E667, false), $context->builder->gep($state, $i64->constInt(0, false)));
        $context->builder->store($i32->constInt(0xBB67AE85, false), $context->builder->gep($state, $i64->constInt(1, false)));
        $context->builder->store($i32->constInt(0x3C6EF372, false), $context->builder->gep($state, $i64->constInt(2, false)));
        $context->builder->store($i32->constInt(0xA54FF53A, false), $context->builder->gep($state, $i64->constInt(3, false)));
        $context->builder->store($i32->constInt(0x510E527F, false), $context->builder->gep($state, $i64->constInt(4, false)));
        $context->builder->store($i32->constInt(0x9B05688C, false), $context->builder->gep($state, $i64->constInt(5, false)));
        $context->builder->store($i32->constInt(0x1F83D9AB, false), $context->builder->gep($state, $i64->constInt(6, false)));
        $context->builder->store($i32->constInt(0x5BE0CD19, false), $context->builder->gep($state, $i64->constInt(7, false)));
        $context->builder->store($i32->constInt(0, false), $datalenSlot);
        $context->builder->store($i64->constInt(0, false), $bitlenSlot);
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $updHead = $fn->appendBasicBlock('sha256_upd_head');
        $updDone = $fn->appendBasicBlock('sha256_upd_done');
        $context->builder->branch($updHead);
        $context->builder->positionAtEnd($updHead);
        $i = $context->builder->load($iSlot);
        $updCont = $context->builder->icmp(Builder::INT_SLT, $i, $len);
        $updBody = $fn->appendBasicBlock('sha256_upd_body');
        $context->builder->branchIf($updCont, $updBody, $updDone);
        $context->builder->positionAtEnd($updBody);
        $b = $context->builder->load($context->builder->gep($data, $i));
        $dl = $context->builder->load($datalenSlot);
        $context->builder->store($b, $context->builder->gep($buf, $context->builder->truncOrBitCast($dl, $i64)));
        $dl1 = $context->builder->add($dl, $i32->constInt(1, false));
        $context->builder->store($dl1, $datalenSlot);
        $full = $context->builder->icmp(Builder::INT_EQ, $dl1, $i32->constInt(64, false));
        $afterByte = $fn->appendBasicBlock('sha256_after_byte');
        $transformBb = $fn->appendBasicBlock('sha256_transform');
        $context->builder->branchIf($full, $transformBb, $afterByte);
        $context->builder->positionAtEnd($transformBb);
        $context->builder->call($context->lookupFunction('__phpc_hc_sha256_transform'), $state, $buf);
        $context->builder->store($i32->constInt(0, false), $datalenSlot);
        $context->builder->store($context->builder->add($context->builder->load($bitlenSlot), $i64->constInt(512, false)), $bitlenSlot);
        $context->builder->branch($afterByte);
        $context->builder->positionAtEnd($afterByte);
        $context->builder->store($context->builder->addNoUnsignedWrap($i, $sizeT->constInt(1, false)), $iSlot);
        $context->builder->branch($updHead);
        $context->builder->positionAtEnd($updDone);
        $dlFinal = $context->builder->load($datalenSlot);
        $context->builder->store($context->builder->add($context->builder->load($bitlenSlot), $context->builder->mul($context->builder->zExtOrBitCast($dlFinal, $i64), $i64->constInt(8, false))), $bitlenSlot);
        $lt56 = $context->builder->icmp(Builder::INT_SLT, $dlFinal, $i32->constInt(56, false));
        $padLen = $context->builder->select($lt56, $context->builder->sub($i32->constInt(56, false), $dlFinal), $context->builder->sub($i32->constInt(120, false), $dlFinal));
        $context->builder->store($i8->constInt(0x80, false), $context->builder->gep($buf, $context->builder->truncOrBitCast($dlFinal, $i64)));
        $pSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($i32->constInt(1, false), $pSlot);
        $padHead = $fn->appendBasicBlock('sha256_pad_head');
        $padDone = $fn->appendBasicBlock('sha256_pad_done');
        $context->builder->branch($padHead);
        $context->builder->positionAtEnd($padHead);
        $pi = $context->builder->load($pSlot);
        $padMore = $context->builder->icmp(Builder::INT_SLT, $pi, $padLen);
        $padBody = $fn->appendBasicBlock('sha256_pad_body');
        $context->builder->branchIf($padMore, $padBody, $padDone);
        $context->builder->positionAtEnd($padBody);
        $pdl = $context->builder->load($datalenSlot);
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($buf, $context->builder->truncOrBitCast($pdl, $i64)));
        $pdl1 = $context->builder->add($pdl, $i32->constInt(1, false));
        $context->builder->store($pdl1, $datalenSlot);
        $pf = $context->builder->icmp(Builder::INT_EQ, $pdl1, $i32->constInt(64, false));
        $padAfter = $fn->appendBasicBlock('sha256_pad_after');
        $padXform = $fn->appendBasicBlock('sha256_pad_xform');
        $context->builder->branchIf($pf, $padXform, $padAfter);
        $context->builder->positionAtEnd($padXform);
        $context->builder->call($context->lookupFunction('__phpc_hc_sha256_transform'), $state, $buf);
        $context->builder->store($i32->constInt(0, false), $datalenSlot);
        $context->builder->branch($padAfter);
        $context->builder->positionAtEnd($padAfter);
        $context->builder->store($context->builder->add($pi, $i32->constInt(1, false)), $pSlot);
        $context->builder->branch($padHead);
        $context->builder->positionAtEnd($padDone);
        $bits = $context->builder->alloca($i8, 8, 'sha256_bits');
        $biSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($i32->constInt(0, false), $biSlot);
        $bitsHead = $fn->appendBasicBlock('sha256_bits_head');
        $bitsDone = $fn->appendBasicBlock('sha256_bits_done');
        $context->builder->branch($bitsHead);
        $context->builder->positionAtEnd($bitsHead);
        $bi = $context->builder->load($biSlot);
        $bitsCont = $context->builder->icmp(Builder::INT_SLT, $bi, $i32->constInt(8, false));
        $bitsBody = $fn->appendBasicBlock('sha256_bits_body');
        $context->builder->branchIf($bitsCont, $bitsBody, $bitsDone);
        $context->builder->positionAtEnd($bitsBody);
        $shift = $context->builder->sub($i32->constInt(56, false), $context->builder->mul($bi, $i32->constInt(8, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->truncOrBitCast($context->builder->load($bitlenSlot), $i32), $shift), $i8), $context->builder->gep($bits, $context->builder->truncOrBitCast($bi, $i64)));
        $context->builder->store($context->builder->add($bi, $i32->constInt(1, false)), $biSlot);
        $context->builder->branch($bitsHead);
        $context->builder->positionAtEnd($bitsDone);
        $bSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($i32->constInt(0, false), $bSlot);
        $bitsPadHead = $fn->appendBasicBlock('sha256_bpad_head');
        $bitsPadDone = $fn->appendBasicBlock('sha256_bpad_done');
        $context->builder->branch($bitsPadHead);
        $context->builder->positionAtEnd($bitsPadHead);
        $bpi = $context->builder->load($bSlot);
        $bMore = $context->builder->icmp(Builder::INT_SLT, $bpi, $i32->constInt(8, false));
        $bBody = $fn->appendBasicBlock('sha256_bpad_body');
        $context->builder->branchIf($bMore, $bBody, $bitsPadDone);
        $context->builder->positionAtEnd($bBody);
        $bdl = $context->builder->load($datalenSlot);
        $context->builder->store($context->builder->load($context->builder->gep($bits, $context->builder->truncOrBitCast($bpi, $i64))), $context->builder->gep($buf, $context->builder->truncOrBitCast($bdl, $i64)));
        $bdl1 = $context->builder->add($bdl, $i32->constInt(1, false));
        $context->builder->store($bdl1, $datalenSlot);
        $bf = $context->builder->icmp(Builder::INT_EQ, $bdl1, $i32->constInt(64, false));
        $bAfter = $fn->appendBasicBlock('sha256_bpad_after');
        $bXform = $fn->appendBasicBlock('sha256_bpad_xform');
        $context->builder->branchIf($bf, $bXform, $bAfter);
        $context->builder->positionAtEnd($bXform);
        $context->builder->call($context->lookupFunction('__phpc_hc_sha256_transform'), $state, $buf);
        $context->builder->store($i32->constInt(0, false), $datalenSlot);
        $context->builder->branch($bAfter);
        $context->builder->positionAtEnd($bAfter);
        $context->builder->store($context->builder->add($bpi, $i32->constInt(1, false)), $bSlot);
        $context->builder->branch($bitsPadHead);
        $context->builder->positionAtEnd($bitsPadDone);
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(0, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($out, $i64->constInt(0, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(0, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($out, $i64->constInt(1, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(0, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($out, $i64->constInt(2, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(0, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($out, $i64->constInt(3, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(1, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($out, $i64->constInt(4, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(1, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($out, $i64->constInt(5, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(1, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($out, $i64->constInt(6, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(1, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($out, $i64->constInt(7, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(2, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($out, $i64->constInt(8, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(2, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($out, $i64->constInt(9, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(2, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($out, $i64->constInt(10, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(2, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($out, $i64->constInt(11, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(3, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($out, $i64->constInt(12, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(3, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($out, $i64->constInt(13, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(3, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($out, $i64->constInt(14, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(3, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($out, $i64->constInt(15, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(4, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($out, $i64->constInt(16, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(4, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($out, $i64->constInt(17, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(4, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($out, $i64->constInt(18, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(4, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($out, $i64->constInt(19, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(5, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($out, $i64->constInt(20, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(5, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($out, $i64->constInt(21, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(5, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($out, $i64->constInt(22, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(5, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($out, $i64->constInt(23, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(6, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($out, $i64->constInt(24, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(6, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($out, $i64->constInt(25, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(6, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($out, $i64->constInt(26, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(6, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($out, $i64->constInt(27, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(7, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($out, $i64->constInt(28, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(7, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($out, $i64->constInt(29, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(7, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($out, $i64->constInt(30, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(7, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($out, $i64->constInt(31, false)));    }

    private static function emitHmac(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_hc_hmac');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $fn = null !== $probe ? $probe : $context->module->addFunction(
            '__phpc_hc_hmac',
            $context->context->functionType($voidTy, false, $i32, $i8p, $sizeT, $i8p, $sizeT, $i8p)
        );
        $entry = $fn->appendBasicBlock('hc_hmac_entry');
        $context->builder->positionAtEnd($entry);
        $algo = $fn->getParam(0);
        $data = $fn->getParam(1);
        $dataLen = $fn->getParam(2);
        $key = $fn->getParam(3);
        $keyLen = $fn->getParam(4);
        $out = $fn->getParam(5);
        $kPad = $context->builder->alloca($i8, 64, 'hmac_kpad');
        $tk = $context->builder->alloca($i8, self::SHA256_DIGEST_SIZE, 'hmac_tk');
        $inner = $context->builder->alloca($i8, self::SHA256_DIGEST_SIZE, 'hmac_inner');
        $context->builder->call($context->lookupFunction('memset'), $kPad, $i32->constInt(0, false), $sizeT->constInt(64, false));
        $dlen = self::callDigestLen($context, $algo);
        $keyLong = $context->builder->icmp(Builder::INT_SGT, $keyLen, $sizeT->constInt(64, false));
        $keyBody = $fn->appendBasicBlock('hc_hmac_key_body');
        $keyCopy = $fn->appendBasicBlock('hc_hmac_key_copy');
        $context->builder->branchIf($keyLong, $keyBody, $keyCopy);
        $context->builder->positionAtEnd($keyBody);
        $context->builder->call($context->lookupFunction('__phpc_hc_digest'), $algo, $key, $keyLen, $tk);
        $context->builder->call($context->lookupFunction('memcpy'), $kPad, $tk, $context->builder->truncOrBitCast($dlen, $sizeT));
        $context->builder->branch($keyCopy);
        $context->builder->positionAtEnd($keyCopy);
        $context->builder->call($context->lookupFunction('memcpy'), $kPad, $key, $keyLen);
        $xorHead = $fn->appendBasicBlock('hc_hmac_xor_head');
        $context->builder->branch($xorHead);
        $iSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->positionAtEnd($xorHead);
        $context->builder->store($i64->constInt(0, false), $iSlot);
        $xorLoop = $fn->appendBasicBlock('hc_hmac_xor_loop');
        $context->builder->branch($xorLoop);
        $context->builder->positionAtEnd($xorLoop);
        $i = $context->builder->load($iSlot);
        $xorDone = $context->builder->icmp(Builder::INT_SGE, $i, $i64->constInt(64, false));
        $xorBody = $fn->appendBasicBlock('hc_hmac_xor_body');
        $afterXor = $fn->appendBasicBlock('hc_hmac_after_xor');
        $context->builder->branchIf($xorDone, $afterXor, $xorBody);
        $context->builder->positionAtEnd($xorBody);
        $kp = $context->builder->gep($kPad, $i);
        $context->builder->store($context->builder->xor($context->builder->zExt($context->builder->load($kp), $i32), $i32->constInt(0x36, false)), $kp);
        $context->builder->store($context->builder->addNoSignedWrap($i, $i64->constInt(1, false)), $iSlot);
        $context->builder->branch($xorLoop);
        $context->builder->positionAtEnd($afterXor);
        $innerLen = $context->builder->add($dataLen, $sizeT->constInt(64, false));
        $buf = $context->builder->call($context->lookupFunction('malloc'), $innerLen);
        $context->builder->call($context->lookupFunction('memcpy'), $buf, $kPad, $sizeT->constInt(64, false));
        $context->builder->call($context->lookupFunction('memcpy'), $context->builder->inBoundsGep($buf, $sizeT->constInt(64, false)), $data, $dataLen);
        $context->builder->call($context->lookupFunction('__phpc_hc_digest'), $algo, $buf, $innerLen, $inner);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $xor2Head = $fn->appendBasicBlock('hc_hmac_xor2_head');
        $context->builder->branch($xor2Head);
        $context->builder->positionAtEnd($xor2Head);
        $context->builder->store($i64->constInt(0, false), $iSlot);
        $xor2Loop = $fn->appendBasicBlock('hc_hmac_xor2_loop');
        $context->builder->branch($xor2Loop);
        $context->builder->positionAtEnd($xor2Loop);
        $i2 = $context->builder->load($iSlot);
        $xor2Done = $context->builder->icmp(Builder::INT_SGE, $i2, $i64->constInt(64, false));
        $xor2Body = $fn->appendBasicBlock('hc_hmac_xor2_body');
        $afterXor2 = $fn->appendBasicBlock('hc_hmac_after_xor2');
        $context->builder->branchIf($xor2Done, $afterXor2, $xor2Body);
        $context->builder->positionAtEnd($xor2Body);
        $kp2 = $context->builder->gep($kPad, $i2);
        $context->builder->store($context->builder->xor($context->builder->zExt($context->builder->load($kp2), $i32), $i32->constInt(0x6A, false)), $kp2);
        $context->builder->store($context->builder->addNoSignedWrap($i2, $i64->constInt(1, false)), $iSlot);
        $context->builder->branch($xor2Loop);
        $context->builder->positionAtEnd($afterXor2);
        $outerLen = $context->builder->add($context->builder->truncOrBitCast($dlen, $sizeT), $sizeT->constInt(64, false));
        $buf2 = $context->builder->call($context->lookupFunction('malloc'), $outerLen);
        $context->builder->call($context->lookupFunction('memcpy'), $buf2, $kPad, $sizeT->constInt(64, false));
        $context->builder->call($context->lookupFunction('memcpy'), $context->builder->inBoundsGep($buf2, $sizeT->constInt(64, false)), $inner, $context->builder->truncOrBitCast($dlen, $sizeT));
        $context->builder->call($context->lookupFunction('__phpc_hc_digest'), $algo, $buf2, $outerLen, $out);
        $context->builder->call($context->lookupFunction('free'), $buf2);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function emitSha1DigestBody(Context $context, LlvmFunction $fn, Value $data, Value $len, Value $out): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $state = $context->builder->alloca($i32, 5, 'sha1_state');
        $buf = $context->builder->alloca($i8, 64, 'sha1_buf');
        $count = $context->builder->alloca($i32, 2, 'sha1_count');
        $context->builder->store($i32->constInt(0x67452301, false), $context->builder->gep($state, $i64->constInt(0, false)));
        $context->builder->store($i32->constInt(0xEFCDAB89, false), $context->builder->gep($state, $i64->constInt(1, false)));
        $context->builder->store($i32->constInt(0x98BADCFE, false), $context->builder->gep($state, $i64->constInt(2, false)));
        $context->builder->store($i32->constInt(0x10325476, false), $context->builder->gep($state, $i64->constInt(3, false)));
        $context->builder->store($i32->constInt(0xC3D2E1F0, false), $context->builder->gep($state, $i64->constInt(4, false)));
        $context->builder->store($i32->constInt(0, false), $context->builder->gep($count, $i64->constInt(0, false)));
        $context->builder->store($i32->constInt(0, false), $context->builder->gep($count, $i64->constInt(1, false)));
        $context->builder->call($context->lookupFunction('memset'), $buf, $i32->constInt(0, false), $sizeT->constInt(64, false));
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $updHead = $fn->appendBasicBlock('sha1_upd_head');
        $updDone = $fn->appendBasicBlock('sha1_upd_done');
        $context->builder->branch($updHead);
        $context->builder->positionAtEnd($updHead);
        $i = $context->builder->load($iSlot);
        $updCont = $context->builder->icmp(Builder::INT_SLT, $i, $len);
        $updBody = $fn->appendBasicBlock('sha1_upd_body');
        $context->builder->branchIf($updCont, $updBody, $updDone);
        $context->builder->positionAtEnd($updBody);

        $j = $context->builder->and(
            $context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(0, false))), $i32->constInt(3, false)),
            $i32->constInt(63, false)
        );
        $context->builder->store($context->builder->load($context->builder->gep($data, $i)), $context->builder->gep($buf, $context->builder->truncOrBitCast($j, $i64)));
        $c0 = $context->builder->load($context->builder->gep($count, $i64->constInt(0, false)));
        $c0n = self::u32Add($context, $c0, $i32->constInt(8, false));
        $context->builder->store($c0n, $context->builder->gep($count, $i64->constInt(0, false)));
        $wrap = $context->builder->icmp(Builder::INT_ULT, $c0n, $c0);
        $c1 = $context->builder->load($context->builder->gep($count, $i64->constInt(1, false)));
        $context->builder->store(self::u32Add($context, $c1, $context->builder->zExtOrBitCast($wrap, $i32)), $context->builder->gep($count, $i64->constInt(1, false)));
        $jAfter = $context->builder->add($j, $i32->constInt(1, false));
        $needXform = $context->builder->icmp(Builder::INT_SGT, $jAfter, $i32->constInt(63, false));
        $sha1DataAfter = $fn->appendBasicBlock('sha1Data_upd_after');
        $sha1DataXform = $fn->appendBasicBlock('sha1Data_upd_xform');
        $context->builder->branchIf($needXform, $sha1DataXform, $sha1DataAfter);
        $context->builder->positionAtEnd($sha1DataXform);
        $context->builder->call($context->lookupFunction('__phpc_hc_sha1_transform'), $state, $buf);
        $context->builder->branch($sha1DataAfter);
        $context->builder->positionAtEnd($sha1DataAfter);
        $context->builder->store($context->builder->addNoUnsignedWrap($i, $sizeT->constInt(1, false)), $iSlot);
        $context->builder->branch($updHead);
        $context->builder->positionAtEnd($updDone);
        $finalcount = $context->builder->alloca($i8, 8, 'sha1_finalcount');
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(1, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($finalcount, $i64->constInt(0, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(1, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($finalcount, $i64->constInt(1, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(1, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($finalcount, $i64->constInt(2, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(1, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($finalcount, $i64->constInt(3, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(0, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($finalcount, $i64->constInt(4, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(0, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($finalcount, $i64->constInt(5, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(0, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($finalcount, $i64->constInt(6, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(0, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($finalcount, $i64->constInt(7, false)));

        $j = $context->builder->and(
            $context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(0, false))), $i32->constInt(3, false)),
            $i32->constInt(63, false)
        );
        $context->builder->store($i8->constInt(0x80, false), $context->builder->gep($buf, $context->builder->truncOrBitCast($j, $i64)));
        $c0 = $context->builder->load($context->builder->gep($count, $i64->constInt(0, false)));
        $c0n = self::u32Add($context, $c0, $i32->constInt(8, false));
        $context->builder->store($c0n, $context->builder->gep($count, $i64->constInt(0, false)));
        $wrap = $context->builder->icmp(Builder::INT_ULT, $c0n, $c0);
        $c1 = $context->builder->load($context->builder->gep($count, $i64->constInt(1, false)));
        $context->builder->store(self::u32Add($context, $c1, $context->builder->zExtOrBitCast($wrap, $i32)), $context->builder->gep($count, $i64->constInt(1, false)));
        $jAfter = $context->builder->add($j, $i32->constInt(1, false));
        $needXform = $context->builder->icmp(Builder::INT_SGT, $jAfter, $i32->constInt(63, false));
        $sha1Pad80After = $fn->appendBasicBlock('sha1Pad80_upd_after');
        $sha1Pad80Xform = $fn->appendBasicBlock('sha1Pad80_upd_xform');
        $context->builder->branchIf($needXform, $sha1Pad80Xform, $sha1Pad80After);
        $context->builder->positionAtEnd($sha1Pad80Xform);
        $context->builder->call($context->lookupFunction('__phpc_hc_sha1_transform'), $state, $buf);
        $context->builder->branch($sha1Pad80After);
        $context->builder->positionAtEnd($sha1Pad80After);
        $alignHead = $fn->appendBasicBlock('sha1_align_head');
        $alignDone = $fn->appendBasicBlock('sha1_align_done');
        $context->builder->branch($alignHead);
        $context->builder->positionAtEnd($alignHead);
        $c0a = $context->builder->load($context->builder->gep($count, $i64->constInt(0, false)));
        $aligned = $context->builder->icmp(Builder::INT_EQ, $context->builder->and($c0a, $i32->constInt(504, false)), $i32->constInt(448, false));
        $alignBody = $fn->appendBasicBlock('sha1_align_body');
        $context->builder->branchIf($aligned, $alignDone, $alignBody);
        $context->builder->positionAtEnd($alignBody);

        $j = $context->builder->and(
            $context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(0, false))), $i32->constInt(3, false)),
            $i32->constInt(63, false)
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($buf, $context->builder->truncOrBitCast($j, $i64)));
        $c0 = $context->builder->load($context->builder->gep($count, $i64->constInt(0, false)));
        $c0n = self::u32Add($context, $c0, $i32->constInt(8, false));
        $context->builder->store($c0n, $context->builder->gep($count, $i64->constInt(0, false)));
        $wrap = $context->builder->icmp(Builder::INT_ULT, $c0n, $c0);
        $c1 = $context->builder->load($context->builder->gep($count, $i64->constInt(1, false)));
        $context->builder->store(self::u32Add($context, $c1, $context->builder->zExtOrBitCast($wrap, $i32)), $context->builder->gep($count, $i64->constInt(1, false)));
        $jAfter = $context->builder->add($j, $i32->constInt(1, false));
        $needXform = $context->builder->icmp(Builder::INT_SGT, $jAfter, $i32->constInt(63, false));
        $sha1Pad0After = $fn->appendBasicBlock('sha1Pad0_upd_after');
        $sha1Pad0Xform = $fn->appendBasicBlock('sha1Pad0_upd_xform');
        $context->builder->branchIf($needXform, $sha1Pad0Xform, $sha1Pad0After);
        $context->builder->positionAtEnd($sha1Pad0Xform);
        $context->builder->call($context->lookupFunction('__phpc_hc_sha1_transform'), $state, $buf);
        $context->builder->branch($sha1Pad0After);
        $context->builder->positionAtEnd($sha1Pad0After);
        $context->builder->branch($alignHead);
        $context->builder->positionAtEnd($alignDone);
        $fcSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($i32->constInt(0, false), $fcSlot);
        $fcHead = $fn->appendBasicBlock('sha1_fc_head');
        $fcDone = $fn->appendBasicBlock('sha1_fc_done');
        $context->builder->branch($fcHead);
        $context->builder->positionAtEnd($fcHead);
        $fci = $context->builder->load($fcSlot);
        $fcCont = $context->builder->icmp(Builder::INT_SLT, $fci, $i32->constInt(8, false));
        $fcBody = $fn->appendBasicBlock('sha1_fc_body');
        $context->builder->branchIf($fcCont, $fcBody, $fcDone);
        $context->builder->positionAtEnd($fcBody);
        $fcByte = $context->builder->load($context->builder->gep($finalcount, $context->builder->truncOrBitCast($fci, $i64)));

        $j = $context->builder->and(
            $context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(0, false))), $i32->constInt(3, false)),
            $i32->constInt(63, false)
        );
        $context->builder->store($fcByte, $context->builder->gep($buf, $context->builder->truncOrBitCast($j, $i64)));
        $c0 = $context->builder->load($context->builder->gep($count, $i64->constInt(0, false)));
        $c0n = self::u32Add($context, $c0, $i32->constInt(8, false));
        $context->builder->store($c0n, $context->builder->gep($count, $i64->constInt(0, false)));
        $wrap = $context->builder->icmp(Builder::INT_ULT, $c0n, $c0);
        $c1 = $context->builder->load($context->builder->gep($count, $i64->constInt(1, false)));
        $context->builder->store(self::u32Add($context, $c1, $context->builder->zExtOrBitCast($wrap, $i32)), $context->builder->gep($count, $i64->constInt(1, false)));
        $jAfter = $context->builder->add($j, $i32->constInt(1, false));
        $needXform = $context->builder->icmp(Builder::INT_SGT, $jAfter, $i32->constInt(63, false));
        $sha1FcAfter = $fn->appendBasicBlock('sha1Fc_upd_after');
        $sha1FcXform = $fn->appendBasicBlock('sha1Fc_upd_xform');
        $context->builder->branchIf($needXform, $sha1FcXform, $sha1FcAfter);
        $context->builder->positionAtEnd($sha1FcXform);
        $context->builder->call($context->lookupFunction('__phpc_hc_sha1_transform'), $state, $buf);
        $context->builder->branch($sha1FcAfter);
        $context->builder->positionAtEnd($sha1FcAfter);
        $context->builder->store($context->builder->add($fci, $i32->constInt(1, false)), $fcSlot);
        $context->builder->branch($fcHead);
        $context->builder->positionAtEnd($fcDone);
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(0, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($out, $i64->constInt(0, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(0, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($out, $i64->constInt(1, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(0, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($out, $i64->constInt(2, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(0, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($out, $i64->constInt(3, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(1, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($out, $i64->constInt(4, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(1, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($out, $i64->constInt(5, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(1, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($out, $i64->constInt(6, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(1, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($out, $i64->constInt(7, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(2, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($out, $i64->constInt(8, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(2, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($out, $i64->constInt(9, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(2, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($out, $i64->constInt(10, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(2, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($out, $i64->constInt(11, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(3, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($out, $i64->constInt(12, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(3, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($out, $i64->constInt(13, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(3, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($out, $i64->constInt(14, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(3, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($out, $i64->constInt(15, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(4, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($out, $i64->constInt(16, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(4, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($out, $i64->constInt(17, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(4, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($out, $i64->constInt(18, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(4, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($out, $i64->constInt(19, false)));
    }

    private static function emitMd5DigestBody(Context $context, LlvmFunction $fn, Value $data, Value $len, Value $out): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $state = $context->builder->alloca($i32, 4, 'md5_state');
        $buf = $context->builder->alloca($i8, 64, 'md5_buf');
        $count = $context->builder->alloca($i32, 2, 'md5_count');
        $context->builder->store($i32->constInt(0x67452301, false), $context->builder->gep($state, $i64->constInt(0, false)));
        $context->builder->store($i32->constInt(0xEFCDAB89, false), $context->builder->gep($state, $i64->constInt(1, false)));
        $context->builder->store($i32->constInt(0x98BADCFE, false), $context->builder->gep($state, $i64->constInt(2, false)));
        $context->builder->store($i32->constInt(0x10325476, false), $context->builder->gep($state, $i64->constInt(3, false)));
        $context->builder->store($i32->constInt(0, false), $context->builder->gep($count, $i64->constInt(0, false)));
        $context->builder->store($i32->constInt(0, false), $context->builder->gep($count, $i64->constInt(1, false)));
        $context->builder->call($context->lookupFunction('memset'), $buf, $i32->constInt(0, false), $sizeT->constInt(64, false));
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $updHead = $fn->appendBasicBlock('md5_upd_head');
        $updDone = $fn->appendBasicBlock('md5_upd_done');
        $context->builder->branch($updHead);
        $context->builder->positionAtEnd($updHead);
        $i = $context->builder->load($iSlot);
        $updCont = $context->builder->icmp(Builder::INT_SLT, $i, $len);
        $updBody = $fn->appendBasicBlock('md5_upd_body');
        $context->builder->branchIf($updCont, $updBody, $updDone);
        $context->builder->positionAtEnd($updBody);

        $index = $context->builder->and(
            $context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(0, false))), $i32->constInt(3, false)),
            $i32->constInt(0x3F, false)
        );
        $context->builder->store($context->builder->load($context->builder->gep($data, $i)), $context->builder->gep($buf, $context->builder->truncOrBitCast($index, $i64)));
        $c0 = $context->builder->load($context->builder->gep($count, $i64->constInt(0, false)));
        $c0n = self::u32Add($context, $c0, $i32->constInt(8, false));
        $context->builder->store($c0n, $context->builder->gep($count, $i64->constInt(0, false)));
        $wrap = $context->builder->icmp(Builder::INT_ULT, $c0n, $c0);
        $c1 = $context->builder->load($context->builder->gep($count, $i64->constInt(1, false)));
        $context->builder->store(self::u32Add($context, $c1, $context->builder->zExtOrBitCast($wrap, $i32)), $context->builder->gep($count, $i64->constInt(1, false)));
        $index1 = $context->builder->add($index, $i32->constInt(1, false));
        $needXform = $context->builder->icmp(Builder::INT_EQ, $index1, $i32->constInt(64, false));
        $md5DataAfter = $fn->appendBasicBlock('md5Data_upd_after');
        $md5DataXform = $fn->appendBasicBlock('md5Data_upd_xform');
        $context->builder->branchIf($needXform, $md5DataXform, $md5DataAfter);
        $context->builder->positionAtEnd($md5DataXform);
        $context->builder->call($context->lookupFunction('__phpc_hc_md5_transform'), $state, $buf);
        $context->builder->branch($md5DataAfter);
        $context->builder->positionAtEnd($md5DataAfter);
        $context->builder->store($context->builder->addNoUnsignedWrap($i, $sizeT->constInt(1, false)), $iSlot);
        $context->builder->branch($updHead);
        $context->builder->positionAtEnd($updDone);
        $bits = $context->builder->alloca($i8, 8, 'md5_bits');
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(0, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($bits, $i64->constInt(0, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(0, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($bits, $i64->constInt(1, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(0, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($bits, $i64->constInt(2, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(0, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($bits, $i64->constInt(3, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(1, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($bits, $i64->constInt(4, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(1, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($bits, $i64->constInt(5, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(1, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($bits, $i64->constInt(6, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(1, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($bits, $i64->constInt(7, false)));
        $index = $context->builder->and(
            $context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(0, false))), $i32->constInt(3, false)),
            $i32->constInt(0x3F, false)
        );
        $lt56 = $context->builder->icmp(Builder::INT_SLT, $index, $i32->constInt(56, false));
        $padLen = $context->builder->select($lt56, $context->builder->sub($i32->constInt(56, false), $index), $context->builder->sub($i32->constInt(120, false), $index));

        $index = $context->builder->and(
            $context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(0, false))), $i32->constInt(3, false)),
            $i32->constInt(0x3F, false)
        );
        $context->builder->store($i8->constInt(0x80, false), $context->builder->gep($buf, $context->builder->truncOrBitCast($index, $i64)));
        $c0 = $context->builder->load($context->builder->gep($count, $i64->constInt(0, false)));
        $c0n = self::u32Add($context, $c0, $i32->constInt(8, false));
        $context->builder->store($c0n, $context->builder->gep($count, $i64->constInt(0, false)));
        $wrap = $context->builder->icmp(Builder::INT_ULT, $c0n, $c0);
        $c1 = $context->builder->load($context->builder->gep($count, $i64->constInt(1, false)));
        $context->builder->store(self::u32Add($context, $c1, $context->builder->zExtOrBitCast($wrap, $i32)), $context->builder->gep($count, $i64->constInt(1, false)));
        $index1 = $context->builder->add($index, $i32->constInt(1, false));
        $needXform = $context->builder->icmp(Builder::INT_EQ, $index1, $i32->constInt(64, false));
        $md5Pad80After = $fn->appendBasicBlock('md5Pad80_upd_after');
        $md5Pad80Xform = $fn->appendBasicBlock('md5Pad80_upd_xform');
        $context->builder->branchIf($needXform, $md5Pad80Xform, $md5Pad80After);
        $context->builder->positionAtEnd($md5Pad80Xform);
        $context->builder->call($context->lookupFunction('__phpc_hc_md5_transform'), $state, $buf);
        $context->builder->branch($md5Pad80After);
        $context->builder->positionAtEnd($md5Pad80After);
        $pSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($i32->constInt(1, false), $pSlot);
        $padHead = $fn->appendBasicBlock('md5_pad_head');
        $padDone = $fn->appendBasicBlock('md5_pad_done');
        $context->builder->branch($padHead);
        $context->builder->positionAtEnd($padHead);
        $pi = $context->builder->load($pSlot);
        $padMore = $context->builder->icmp(Builder::INT_SLT, $pi, $padLen);
        $padBody = $fn->appendBasicBlock('md5_pad_body');
        $context->builder->branchIf($padMore, $padBody, $padDone);
        $context->builder->positionAtEnd($padBody);

        $index = $context->builder->and(
            $context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(0, false))), $i32->constInt(3, false)),
            $i32->constInt(0x3F, false)
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($buf, $context->builder->truncOrBitCast($index, $i64)));
        $c0 = $context->builder->load($context->builder->gep($count, $i64->constInt(0, false)));
        $c0n = self::u32Add($context, $c0, $i32->constInt(8, false));
        $context->builder->store($c0n, $context->builder->gep($count, $i64->constInt(0, false)));
        $wrap = $context->builder->icmp(Builder::INT_ULT, $c0n, $c0);
        $c1 = $context->builder->load($context->builder->gep($count, $i64->constInt(1, false)));
        $context->builder->store(self::u32Add($context, $c1, $context->builder->zExtOrBitCast($wrap, $i32)), $context->builder->gep($count, $i64->constInt(1, false)));
        $index1 = $context->builder->add($index, $i32->constInt(1, false));
        $needXform = $context->builder->icmp(Builder::INT_EQ, $index1, $i32->constInt(64, false));
        $md5Pad0After = $fn->appendBasicBlock('md5Pad0_upd_after');
        $md5Pad0Xform = $fn->appendBasicBlock('md5Pad0_upd_xform');
        $context->builder->branchIf($needXform, $md5Pad0Xform, $md5Pad0After);
        $context->builder->positionAtEnd($md5Pad0Xform);
        $context->builder->call($context->lookupFunction('__phpc_hc_md5_transform'), $state, $buf);
        $context->builder->branch($md5Pad0After);
        $context->builder->positionAtEnd($md5Pad0After);
        $context->builder->store($context->builder->add($pi, $i32->constInt(1, false)), $pSlot);
        $context->builder->branch($padHead);
        $context->builder->positionAtEnd($padDone);
        $bSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($i32->constInt(0, false), $bSlot);
        $bitsHead = $fn->appendBasicBlock('md5_bits_head');
        $bitsDone = $fn->appendBasicBlock('md5_bits_done');
        $context->builder->branch($bitsHead);
        $context->builder->positionAtEnd($bitsHead);
        $bi = $context->builder->load($bSlot);
        $bitsCont = $context->builder->icmp(Builder::INT_SLT, $bi, $i32->constInt(8, false));
        $bitsBody = $fn->appendBasicBlock('md5_bits_body');
        $context->builder->branchIf($bitsCont, $bitsBody, $bitsDone);
        $context->builder->positionAtEnd($bitsBody);
        $bitByte = $context->builder->load($context->builder->gep($bits, $context->builder->truncOrBitCast($bi, $i64)));

        $index = $context->builder->and(
            $context->builder->lshr($context->builder->load($context->builder->gep($count, $i64->constInt(0, false))), $i32->constInt(3, false)),
            $i32->constInt(0x3F, false)
        );
        $context->builder->store($bitByte, $context->builder->gep($buf, $context->builder->truncOrBitCast($index, $i64)));
        $c0 = $context->builder->load($context->builder->gep($count, $i64->constInt(0, false)));
        $c0n = self::u32Add($context, $c0, $i32->constInt(8, false));
        $context->builder->store($c0n, $context->builder->gep($count, $i64->constInt(0, false)));
        $wrap = $context->builder->icmp(Builder::INT_ULT, $c0n, $c0);
        $c1 = $context->builder->load($context->builder->gep($count, $i64->constInt(1, false)));
        $context->builder->store(self::u32Add($context, $c1, $context->builder->zExtOrBitCast($wrap, $i32)), $context->builder->gep($count, $i64->constInt(1, false)));
        $index1 = $context->builder->add($index, $i32->constInt(1, false));
        $needXform = $context->builder->icmp(Builder::INT_EQ, $index1, $i32->constInt(64, false));
        $md5BitAfter = $fn->appendBasicBlock('md5Bit_upd_after');
        $md5BitXform = $fn->appendBasicBlock('md5Bit_upd_xform');
        $context->builder->branchIf($needXform, $md5BitXform, $md5BitAfter);
        $context->builder->positionAtEnd($md5BitXform);
        $context->builder->call($context->lookupFunction('__phpc_hc_md5_transform'), $state, $buf);
        $context->builder->branch($md5BitAfter);
        $context->builder->positionAtEnd($md5BitAfter);
        $context->builder->store($context->builder->add($bi, $i32->constInt(1, false)), $bSlot);
        $context->builder->branch($bitsHead);
        $context->builder->positionAtEnd($bitsDone);
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(0, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($out, $i64->constInt(0, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(0, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($out, $i64->constInt(1, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(0, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($out, $i64->constInt(2, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(0, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($out, $i64->constInt(3, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(1, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($out, $i64->constInt(4, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(1, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($out, $i64->constInt(5, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(1, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($out, $i64->constInt(6, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(1, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($out, $i64->constInt(7, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(2, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($out, $i64->constInt(8, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(2, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($out, $i64->constInt(9, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(2, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($out, $i64->constInt(10, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(2, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($out, $i64->constInt(11, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(3, false))), $i32->constInt(0, false)), $i8), $context->builder->gep($out, $i64->constInt(12, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(3, false))), $i32->constInt(8, false)), $i8), $context->builder->gep($out, $i64->constInt(13, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(3, false))), $i32->constInt(16, false)), $i8), $context->builder->gep($out, $i64->constInt(14, false)));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($context->builder->load($context->builder->gep($state, $i64->constInt(3, false))), $i32->constInt(24, false)), $i8), $context->builder->gep($out, $i64->constInt(15, false)));
    }


    private static function emitPbkdf2F(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_hc_pbkdf2_f');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $fn = null !== $probe ? $probe : $context->module->addFunction(
            '__phpc_hc_pbkdf2_f',
            $context->context->functionType($voidTy, false, $i32, $i8p, $sizeT, $i8p, $sizeT, $i32, $i64, $i8p)
        );
        $entry = $fn->appendBasicBlock('hc_pbkdf2f_entry');
        $context->builder->positionAtEnd($entry);
        $algo = $fn->getParam(0);
        $pass = $fn->getParam(1);
        $passLen = $fn->getParam(2);
        $salt = $fn->getParam(3);
        $saltLen = $fn->getParam(4);
        $blockIndex = $fn->getParam(5);
        $iterations = $fn->getParam(6);
        $out = $fn->getParam(7);
        $dlen = self::callDigestLen($context, $algo);
        $sbLen = $context->builder->add($saltLen, $sizeT->constInt(4, false));
        $saltBlock = $context->builder->call($context->lookupFunction('malloc'), $sbLen);
        $context->builder->call($context->lookupFunction('memcpy'), $saltBlock, $salt, $saltLen);
        $bi32 = $blockIndex;
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($bi32, $i32->constInt(24, false)), $i8), $context->builder->inBoundsGep($saltBlock, $saltLen));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($bi32, $i32->constInt(16, false)), $i8), $context->builder->inBoundsGep($saltBlock, $context->builder->addNoUnsignedWrap($saltLen, $sizeT->constInt(1, false))));
        $context->builder->store($context->builder->truncOrBitCast($context->builder->lshr($bi32, $i32->constInt(8, false)), $i8), $context->builder->inBoundsGep($saltBlock, $context->builder->addNoUnsignedWrap($saltLen, $sizeT->constInt(2, false))));
        $context->builder->store($context->builder->truncOrBitCast($bi32, $i8), $context->builder->inBoundsGep($saltBlock, $context->builder->addNoUnsignedWrap($saltLen, $sizeT->constInt(3, false))));
        $context->builder->call($context->lookupFunction('__phpc_hc_hmac'), $algo, $saltBlock, $sbLen, $pass, $passLen, $out);
        $context->builder->call($context->lookupFunction('free'), $saltBlock);
        $u = $context->builder->alloca($i8, self::SHA256_DIGEST_SIZE, 'pbkdf2_u');
        $context->builder->call($context->lookupFunction('memcpy'), $u, $out, $context->builder->truncOrBitCast($dlen, $sizeT));
        $iSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(1, false), $iSlot);
        $loopHead = $fn->appendBasicBlock('hc_pbkdf2f_head');
        $loopDone = $fn->appendBasicBlock('hc_pbkdf2f_done');
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $more = $context->builder->icmp(Builder::INT_SLT, $i, $iterations);
        $loopBody = $fn->appendBasicBlock('hc_pbkdf2f_body');
        $context->builder->branchIf($more, $loopBody, $loopDone);
        $context->builder->positionAtEnd($loopBody);
        $context->builder->call($context->lookupFunction('__phpc_hc_hmac'), $algo, $u, $context->builder->truncOrBitCast($dlen, $sizeT), $pass, $passLen, $u);
        $jSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $jSlot);
        $jHead = $fn->appendBasicBlock('hc_pbkdf2f_jhead');
        $context->builder->branch($jHead);
        $context->builder->positionAtEnd($jHead);
        $j = $context->builder->load($jSlot);
        $jDone = $context->builder->icmp(Builder::INT_SGE, $j, $dlen);
        $jBody = $fn->appendBasicBlock('hc_pbkdf2f_jbody');
        $jCont = $fn->appendBasicBlock('hc_pbkdf2f_jcont');
        $context->builder->branchIf($jDone, $jCont, $jBody);
        $context->builder->positionAtEnd($jBody);
        $oj = $context->builder->gep($out, $j);
        $uj = $context->builder->gep($u, $j);
        $context->builder->store($context->builder->xor($context->builder->zExt($context->builder->load($oj), $i32), $context->builder->zExt($context->builder->load($uj), $i32)), $oj);
        $context->builder->store($context->builder->addNoSignedWrap($j, $i64->constInt(1, false)), $jSlot);
        $context->builder->branch($jHead);
        $context->builder->positionAtEnd($jCont);
        $context->builder->store($context->builder->addNoSignedWrap($i, $i64->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function callDigestLen(Context $context, Value $algo): Value
    {
        return $context->builder->call($context->lookupFunction('__phpc_hc_digest_len'), $algo);
    }

    private static function callHexEncode(Context $context, Value $bin, Value $binLen, Value $out): void
    {
        $context->builder->call($context->lookupFunction('__phpc_hc_hex_encode'), $bin, $binLen, $out);
    }

    private static function callDigest(Context $context, Value $algo, Value $data, Value $len, Value $out): void
    {
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call(
            $context->lookupFunction('__phpc_hc_digest'),
            $algo,
            $data,
            $context->builder->truncOrBitCast($len, $sizeT),
            $out
        );
    }

    private static function callHmac(
        Context $context,
        Value $algo,
        Value $data,
        Value $dataLen,
        Value $key,
        Value $keyLen,
        Value $out
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call(
            $context->lookupFunction('__phpc_hc_hmac'),
            $algo,
            $data,
            $context->builder->truncOrBitCast($dataLen, $sizeT),
            $key,
            $context->builder->truncOrBitCast($keyLen, $sizeT),
            $out
        );
    }

    private static function callPbkdf2F(
        Context $context,
        Value $algo,
        Value $pass,
        Value $passLen,
        Value $salt,
        Value $saltLen,
        Value $blockIndex,
        Value $iterations,
        Value $out
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call(
            $context->lookupFunction('__phpc_hc_pbkdf2_f'),
            $algo,
            $pass,
            $context->builder->truncOrBitCast($passLen, $sizeT),
            $salt,
            $context->builder->truncOrBitCast($saltLen, $sizeT),
            $blockIndex,
            $iterations,
            $out
        );
    }

    private static function callResultString(Context $context, Value $algo, Value $digest, Value $raw): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__phpc_hc_result_string'),
            $algo,
            $digest,
            $raw
        );
    }
}
