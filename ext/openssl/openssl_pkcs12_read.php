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
 * openssl_pkcs12_read() — parse PKCS#12 keystore (php-src ext/openssl/pkcs12.c; #6420 VM, JIT/AOT #33444).
 */
final class openssl_pkcs12_read extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_pkcs12_read');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'openssl_pkcs12_read() expects exactly 3 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $pkcs12 = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_pkcs12_read', 0, 'pkcs12');
        $passphrase = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'openssl_pkcs12_read', 2, 'passphrase');
        $parsed = VmOpenssl::pkcs12Read($pkcs12, $passphrase, $frame);
        if (false === $parsed) {
            $frame->returnVar->bool(false);

            return;
        }

        $outArg = $frame->calledArgs[1];
        $ht = new HashTable();
        foreach ($parsed as $key => $pem) {
            $var = new Variable();
            $var->string($pem);
            $ht->update($key, $var);
        }
        $replacement = new Variable(Variable::TYPE_ARRAY);
        $replacement->array($ht);
        $outArg->copyFrom($replacement);
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \ArgumentCountError(
                'openssl_pkcs12_read() expects exactly 3 arguments, '.\count($args).' given'
            );
        }

        return JitOpensslX509::pkcs12Read($context, $args[0], $args[1], $args[2]);
    }
}
