<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * openssl_open() — decrypt openssl_seal() output (php-src ext/openssl/openssl.c; #6523).
 *
 * Reflection / named args: Zend stub `data`, `&$output`, `string $encrypted_key`, `$private_key`,
 * `string $cipher_algo`, `?string $iv = null`: `bool` (InternalArgInfo still says opendata/ekey/privkey/method
 * — see BuiltinParamNames / BuiltinInternalArgInfo; #28754).
 */
final class openssl_open extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_open');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 5 || $argc > 6) {
            throw new \ArgumentCountError(
                'openssl_open() expects at least 5 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $sealedData = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_open', 0, 'data');
        $encryptedKey = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'openssl_open', 2, 'encrypted_key');
        $privateKeyPem = VmOpenssl::coercePkeyPem($frame->calledArgs[3], 'openssl_open', 3, 'private_key');
        $cipherAlgo = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'openssl_open', 4, 'cipher_algo');

        $iv = null;
        if (6 === $argc) {
            $ivVar = $frame->calledArgs[5]->resolveIndirect();
            if (Variable::TYPE_NULL !== $ivVar->type) {
                $iv = VmString::coerceStringBuiltinArg($ivVar, 'openssl_open', 5, 'iv');
            }
        }

        $plain = VmOpenssl::open($sealedData, $encryptedKey, $privateKeyPem, $cipherAlgo, $iv, $frame);
        if (false === $plain) {
            $frame->returnVar->bool(false);

            return;
        }

        $output = $frame->calledArgs[1]->resolveIndirect();
        $output->string($plain);
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_open() is not implemented for JIT in this compiler build (issue #6523)'
        );
    }
}
