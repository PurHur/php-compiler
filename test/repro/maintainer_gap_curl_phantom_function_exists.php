<?php

declare(strict_types=1);

/**
 * curl_init phantom function_exists without ext/curl — Zend parity (#18470, #11627).
 */

$fail = 0;

$loaded = extension_loaded('curl');
$initExists = function_exists('curl_init');
$execExists = function_exists('curl_exec');

echo 'loaded=', (int) $loaded, "\n";
echo 'init=', (int) $initExists, "\n";
echo 'exec=', (int) $execExists, "\n";
echo 'handle=', (int) class_exists('CurlHandle', false), "\n";

if (!$loaded && $initExists) {
    fwrite(STDERR, "FAIL: function_exists('curl_init') true but extension_loaded('curl') false\n");
    ++$fail;
}
if (!$loaded && class_exists('CurlHandle', false)) {
    fwrite(STDERR, "FAIL: CurlHandle visible but extension_loaded('curl') false\n");
    ++$fail;
}

exit($fail === 0 ? 0 : 1);
