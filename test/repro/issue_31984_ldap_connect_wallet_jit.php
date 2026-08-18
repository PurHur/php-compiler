<?php

declare(strict_types=1);

/**
 * Issue #31984 — ldap_connect_wallet() JIT lowering exists; withheld without Oracle ABI.
 *
 * php-src: ext/ldap/ldap.c PHP_FUNCTION(ldap_connect_wallet) (HAVE_ORALDAP)
 */
echo 'ldap=', extension_loaded('ldap') ? '1' : '0', PHP_EOL;
echo 'wallet=', function_exists('ldap_connect_wallet') ? '1' : '0', PHP_EOL;

if (function_exists('ldap_connect_wallet')) {
    $link = @ldap_connect_wallet('ldap://127.0.0.1', '/tmp/wallet', 'secret', GSLC_SSL_NO_AUTH);
    echo 'connect=', false === $link ? 'false' : 'obj', PHP_EOL;
}
echo "ok\n";
