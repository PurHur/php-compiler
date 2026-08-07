<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * openssl_seal() — public-key envelope encryption (php-src ext/openssl/openssl.c; #6523).
 *
 * Reflection / named args: Zend stub `data`, `&$sealed_data`, `&$encrypted_keys`, `array $public_key`,
 * `string $cipher_algo`, `&$iv = null`: `int|false` (InternalArgInfo still says sealdata/ekeys/pubkeys/method
 * — see BuiltinParamNames / BuiltinInternalArgInfo; #28754).
 */
final class openssl_seal extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_seal');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 5 || $argc > 6) {
            throw new \ArgumentCountError(
                'openssl_seal() expects at least 5 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_seal', 0, 'data');
        $cipherAlgo = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'openssl_seal', 4, 'cipher_algo');
        $assignIv = $argc >= 6;

        $publicKeys = VmOpenssl::coercePublicKeyPemList($frame->calledArgs[3], 'openssl_seal', 3, 'public_key', $frame);
        if (false === $publicKeys) {
            $frame->returnVar->bool(false);

            return;
        }

        $result = VmOpenssl::seal($data, $publicKeys, $cipherAlgo, $assignIv, $frame);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }

        $sealedOut = $frame->calledArgs[1]->resolveIndirect();
        $sealedOut->string($result['sealed']);

        $ekeysOut = $frame->calledArgs[2]->resolveIndirect();
        $ekeysHt = new HashTable();
        foreach ($result['encrypted_keys'] as $encryptedKey) {
            $var = new Variable();
            $var->string($encryptedKey);
            $ekeysHt->append($var);
        }
        $ekeysOut->array($ekeysHt);

        if ($assignIv) {
            $ivOut = $frame->calledArgs[5]->resolveIndirect();
            $ivOut->string($result['iv']);
        }

        $frame->returnVar->int($result['length']);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_seal() is not implemented for JIT in this compiler build (issue #6523)'
        );
    }
}
