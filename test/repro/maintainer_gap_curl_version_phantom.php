<?php

declare(strict_types=1);

/**
 * curl_version phantom function_exists without ext/curl — Zend parity (#18554, re-#18470).
 */

$fail = 0;

$loaded = extension_loaded('curl');
$versionExists = function_exists('curl_version');

echo 'loaded=', (int) $loaded, "\n";
echo 'version=', (int) $versionExists, "\n";

if (!$loaded && $versionExists) {
    fwrite(STDERR, "FAIL: function_exists('curl_version') true but extension_loaded('curl') false\n");
    ++$fail;
}

exit($fail === 0 ? 0 : 1);
