<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * openssl_decrypt() — symmetric EVP cipher (php-src ext/openssl/openssl.c; #18594, JIT/AOT #21065, AEAD #21135).
 */
final class openssl_decrypt extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_decrypt');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        // php-src openssl.stub.php: 7 params (no tag_length on decrypt).
        if ($argc < 3 || $argc > 7) {
            throw new \ArgumentCountError(
                'openssl_decrypt() expects at least 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        // Z_PARAM_STR $data — soft-null DEP+coerce on forward profile (#21445, reverts #20263;
        // php-src ext/openssl/openssl.c — Zend 8.4 still deprecates null → '').
        $data = VmString::trimFamilyStringArgForFrame($frame, 0, 'openssl_decrypt', 0, 'data');
        // Z_PARAM_STR $cipher_algo — TypeError under caller strict_types (#29956).
        $cipherAlgo = VmString::stringBuiltinArgForFrame($frame, 1, 'openssl_decrypt', 1, 'cipher_algo');
        $passphrase = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'openssl_decrypt', 2, 'passphrase');
        $options = 0;
        if ($argc >= 4) {
            $options = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'openssl_decrypt', 4, 'options');
        }
        $iv = '';
        if ($argc >= 5) {
            $iv = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'openssl_decrypt', 4, 'iv');
        }
        $tag = '';
        if ($argc >= 6) {
            $tagVar = $frame->calledArgs[5]->resolveIndirect();
            if (Variable::TYPE_NULL !== $tagVar->type) {
                $tag = VmString::coerceStringBuiltinArg($frame->calledArgs[5], 'openssl_decrypt', 5, 'tag');
            }
        }
        $aad = '';
        if ($argc >= 7) {
            $aadVar = $frame->calledArgs[6]->resolveIndirect();
            if (Variable::TYPE_NULL !== $aadVar->type) {
                $aad = VmString::coerceStringBuiltinArg($frame->calledArgs[6], 'openssl_decrypt', 6, 'aad');
            }
        }

        $payload = $data;
        if (0 === ($options & OpensslConstants::OPENSSL_RAW_DATA)) {
            $decoded = base64_decode($data, true);
            if (false === $decoded) {
                VmOpenssl::userWarningForFrame('openssl_decrypt(): Input is not valid base64', $frame);
                $frame->returnVar->bool(false);

                return;
            }
            $payload = $decoded;
        }

        $plain = VmOpenssl::decrypt($payload, $cipherAlgo, $passphrase, $options, $iv, $frame, $tag, $aad);
        if (false === $plain) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($plain);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 7) {
            throw new \ArgumentCountError(
                'openssl_decrypt() expects at least 3 arguments, '.$argc.' given'
            );
        }

        return JitOpensslEncrypt::decrypt(
            $context,
            $args[0],
            $args[1],
            $args[2],
            $args[3] ?? null,
            $args[4] ?? null,
            $args[5] ?? null,
            $args[6] ?? null
        );
    }
}
