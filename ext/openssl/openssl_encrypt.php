<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_encrypt() — symmetric EVP cipher (php-src ext/openssl/openssl.c; #18594, JIT/AOT #21065, AEAD #21135).
 */
final class openssl_encrypt extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_encrypt');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 8) {
            throw new \ArgumentCountError(
                'openssl_encrypt() expects at least 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        // Z_PARAM_STR $data — soft-null DEP+coerce on forward profile (#21445, reverts #20263;
        // php-src ext/openssl/openssl.c — Zend 8.4 still deprecates null → '').
        $data = VmString::trimFamilyStringArgForFrame($frame, 0, 'openssl_encrypt', 0, 'data');
        // Z_PARAM_STR $cipher_algo — TypeError under caller strict_types (#29956).
        $cipherAlgo = VmString::stringBuiltinArgForFrame($frame, 1, 'openssl_encrypt', 1, 'cipher_algo');
        $passphrase = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'openssl_encrypt', 2, 'passphrase');
        $options = 0;
        if ($argc >= 4) {
            $options = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'openssl_encrypt', 4, 'options');
        }
        $iv = '';
        if ($argc >= 5) {
            $iv = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'openssl_encrypt', 4, 'iv');
        }
        $tagVar = $argc >= 6 ? $frame->calledArgs[5] : null;
        $aad = '';
        if ($argc >= 7) {
            $aad = VmString::coerceStringBuiltinArg($frame->calledArgs[6], 'openssl_encrypt', 6, 'aad');
        }
        $tagLength = 16;
        if ($argc >= 8) {
            $tagLength = VmMath::parseIntBuiltinArgForFrame($frame, 7, 'openssl_encrypt', 8, 'tag_length');
        }

        $encrypted = VmOpenssl::encrypt(
            $data,
            $cipherAlgo,
            $passphrase,
            $options,
            $iv,
            $frame,
            $tagVar,
            $aad,
            $tagLength
        );
        if (false === $encrypted) {
            $frame->returnVar->bool(false);

            return;
        }

        if (0 === ($options & OpensslConstants::OPENSSL_RAW_DATA)) {
            $encrypted = base64_encode($encrypted);
        }
        $frame->returnVar->string($encrypted);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 8) {
            throw new \ArgumentCountError(
                'openssl_encrypt() expects at least 3 arguments, '.$argc.' given'
            );
        }

        return JitOpensslEncrypt::encrypt(
            $context,
            $args[0],
            $args[1],
            $args[2],
            $args[3] ?? null,
            $args[4] ?? null,
            $args[5] ?? null,
            $args[6] ?? null,
            $args[7] ?? null
        );
    }
}
