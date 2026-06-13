<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\VM\Context;

/**
 * Register openssl builtin object classes (php-src ext/openssl/openssl.stub.php; issue #7268).
 *
 * PHP 8.4: X.509 certs, asymmetric keys, and CSRs are final internal objects — not resources.
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmOpensslObjects::registerClasses($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }
}
