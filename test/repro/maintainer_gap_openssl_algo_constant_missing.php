<?php

declare(strict_types=1);

echo 'OPENSSL_ALGO_SHA256=', defined('OPENSSL_ALGO_SHA256') ? 'yes' : 'no', "\n";
echo 'OPENSSL_RAW_DATA=', defined('OPENSSL_RAW_DATA') ? 'yes' : 'no', "\n";
echo 'ext_openssl=', extension_loaded('openssl') ? 'yes' : 'no', "\n";
if (defined('OPENSSL_ALGO_SHA256')) {
    echo 'algo_value=', OPENSSL_ALGO_SHA256, "\n";
}
