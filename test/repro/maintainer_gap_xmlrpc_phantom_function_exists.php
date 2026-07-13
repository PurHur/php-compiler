<?php

declare(strict_types=1);

/**
 * xmlrpc_encode/xmlrpc_decode phantom function_exists without ext/xmlrpc — Zend parity (#18503).
 */

$fail = 0;

$loaded = extension_loaded('xmlrpc');
$encodeExists = function_exists('xmlrpc_encode');
$decodeExists = function_exists('xmlrpc_decode');

echo 'loaded=', (int) $loaded, "\n";
echo 'encode=', (int) $encodeExists, "\n";
echo 'decode=', (int) $decodeExists, "\n";

if (!$loaded && $encodeExists) {
    fwrite(STDERR, "FAIL: function_exists('xmlrpc_encode') true but extension_loaded('xmlrpc') false\n");
    ++$fail;
}
if (!$loaded && $decodeExists) {
    fwrite(STDERR, "FAIL: function_exists('xmlrpc_decode') true but extension_loaded('xmlrpc') false\n");
    ++$fail;
}

exit($fail === 0 ? 0 : 1);
