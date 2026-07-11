<?php

declare(strict_types=1);

if (extension_loaded('curl')) {
    echo "skip: curl extension loaded on reference profile\n";
    exit(0);
}

if (function_exists('curl_escape') || function_exists('curl_unescape')) {
    echo "fail: curl_escape/curl_unescape advertised without ext/curl\n";
    exit(1);
}

echo "ok\n";
