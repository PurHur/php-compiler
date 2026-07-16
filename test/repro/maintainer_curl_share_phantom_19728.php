<?php
declare(strict_types=1);

// #19728 — share APIs must not outlive curl_init when ext/curl is unloaded.
echo 'curl_init=', function_exists('curl_init') ? '1' : '0', PHP_EOL;
echo 'curl_share_init=', function_exists('curl_share_init') ? '1' : '0', PHP_EOL;
echo 'CurlHandle=', class_exists('CurlHandle', false) ? '1' : '0', PHP_EOL;
echo 'CurlShareHandle=', class_exists('CurlShareHandle', false) ? '1' : '0', PHP_EOL;
