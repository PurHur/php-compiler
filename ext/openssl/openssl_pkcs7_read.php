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
 * openssl_pkcs7_read() — extract cert PEMs from PKCS#7 PEM content
 * (php-src ext/openssl/openssl.c; #20305 VM, JIT/AOT #33458).
 */
final class openssl_pkcs7_read extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_pkcs7_read');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'openssl_pkcs7_read() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_pkcs7_read', 0, 'data');
        $certs = VmOpenssl::pkcs7Read($data, $frame);
        if (false === $certs) {
            $frame->returnVar->bool(false);

            return;
        }

        $ht = new HashTable();
        foreach ($certs as $pem) {
            $var = new Variable();
            $var->string($pem);
            $ht->append($var);
        }
        // ZEND_SEND_REF writeback — same as openssl_cms_read() / openssl_pkcs12_read() (#20305).
        $frame->calledArgs[1]->resolveIndirect()->array($ht);
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(
                'openssl_pkcs7_read() expects exactly 2 arguments, '.\count($args).' given'
            );
        }

        return JitOpensslX509::pkcs7Read($context, $args[0], $args[1]);
    }
}
