<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * Internal OpenSSLAsymmetricKey property names for JIT/AOT (#34015 leftover of #33530).
 *
 * PEM lives on the object so thin AOT (no VM side-store) can round-trip via
 * {@see VmOpensslObjects::keyPem()}.
 */
final class OpensslAsymmetricKeyJitSupport
{
    public const CLASS_NAME = 'OpenSSLAsymmetricKey';

    /** Private-key PEM material (mirrors VmOpensslObjects::$keyStore). */
    public const PROP_PEM = '__osslPem';
}
