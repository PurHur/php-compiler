<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('curl'), "\n";
echo 'init=', (int) function_exists('curl_init'), "\n";
echo 'CurlHandle=', (int) class_exists('CurlHandle', false), "\n";

$ch = curl_init('https://example.com/');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
echo is_string($body) || $body === true ? $code : ('fail:'.$err);
echo "\n";
