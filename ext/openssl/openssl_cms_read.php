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
 * openssl_cms_read() — extract cert PEMs from CMS PEM content
 * (php-src ext/openssl/openssl.c; #6592 VM, JIT/AOT #33460).
 *
 * php-src names the first parameter `$cms_data` (PEM *content*, not a path).
 */
final class openssl_cms_read extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_cms_read');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'openssl_cms_read() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $cms = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_cms_read', 0, 'cms_data');
        $certs = VmOpenssl::cmsRead($cms, $frame);
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
        // ZEND_SEND_REF writeback — same as openssl_pkcs7_read() / openssl_pkcs12_read() (#6592).
        $frame->calledArgs[1]->resolveIndirect()->array($ht);
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(
                'openssl_cms_read() expects exactly 2 arguments, '.\count($args).' given'
            );
        }

        return JitOpensslX509::cmsRead($context, $args[0], $args[1]);
    }
}
