<?php

declare(strict_types=1);

/**
 * Issue #21137 — CURLOPT_TIMEOUT and friends must be defined (VM).
 * Avoid constant() — AOT path uses bare names.
 */
echo 'CURLOPT_TIMEOUT=', defined('CURLOPT_TIMEOUT') ? (string) CURLOPT_TIMEOUT : 'UNDEF', PHP_EOL;
echo 'CURLOPT_CONNECTTIMEOUT=', defined('CURLOPT_CONNECTTIMEOUT') ? (string) CURLOPT_CONNECTTIMEOUT : 'UNDEF', PHP_EOL;
echo 'CURLOPT_FOLLOWLOCATION=', defined('CURLOPT_FOLLOWLOCATION') ? (string) CURLOPT_FOLLOWLOCATION : 'UNDEF', PHP_EOL;
echo 'CURLOPT_POSTFIELDS=', defined('CURLOPT_POSTFIELDS') ? (string) CURLOPT_POSTFIELDS : 'UNDEF', PHP_EOL;
echo 'CURLOPT_USERAGENT=', defined('CURLOPT_USERAGENT') ? (string) CURLOPT_USERAGENT : 'UNDEF', PHP_EOL;
echo 'CURLOPT_SSL_VERIFYPEER=', defined('CURLOPT_SSL_VERIFYPEER') ? (string) CURLOPT_SSL_VERIFYPEER : 'UNDEF', PHP_EOL;

$ch = curl_init();
var_export(curl_setopt($ch, CURLOPT_TIMEOUT, 5));
echo PHP_EOL;
curl_close($ch);
