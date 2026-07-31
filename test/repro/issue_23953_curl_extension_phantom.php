<?php

declare(strict_types=1);

// #23953 — curl advertisement must match host Zend, not libcurl FFI presence
echo 'loaded=', (int) extension_loaded('curl'), "\n";
echo 'init=', (int) function_exists('curl_init'), "\n";
echo 'version=', (int) function_exists('curl_version'), "\n";
echo 'CurlHandle=', (int) class_exists('CurlHandle', false), "\n";
