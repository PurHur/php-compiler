--TEST--
curl_init/setopt/exec/getinfo HTTP client via libcurl (#3325, ext/curl/interface.c)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('curl'), "\n";
echo 'init=', (int) function_exists('curl_init'), "\n";
echo 'CurlHandle=', (int) class_exists('CurlHandle', false), "\n";

$fixture = tempnam(sys_get_temp_dir(), 'curl_body_');
file_put_contents($fixture, "hello-curl\n");
$url = 'file://' . $fixture;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$body = curl_exec($ch);
echo 'body=', is_string($body) ? trim($body) : 'fail', "\n";
echo 'errno=', curl_errno($ch), "\n";
echo 'error=', curl_error($ch) === '' ? 'empty' : curl_error($ch), "\n";
$info = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
echo 'effective=', is_string($info) && str_contains($info, 'file://') ? 'file' : 'other', "\n";
curl_close($ch);
@unlink($fixture);

$ch2 = curl_init('https://example.com/');
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_NOBODY, true);
$body2 = curl_exec($ch2);
$code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);
echo 'http=', is_int($code) ? $code : 'fail', "\n";
echo 'nobody_body=', ($body2 === '' || $body2 === true || (is_string($body2) && strlen($body2) < 5000)) ? 'ok' : 'big', "\n";
?>
--EXPECTF--
loaded=1
init=1
CurlHandle=1
body=hello-curl
errno=0
error=empty
effective=file
http=200
nobody_body=ok
