--TEST--
curl_close()/curl_share_close() emit E_DEPRECATED under PROFILE=8.5 (#28133, ext/curl/curl.stub.php)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsCurlCloseDeprecation()) {
    die('skip requires PHP 8.5+ curl_close/curl_share_close deprecation');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $errno, string $msg) use (&$seen): bool {
    if (E_DEPRECATED === $errno) {
        $seen[] = $msg;
    }
    return true;
});

curl_close(curl_init());
$sh = curl_share_init();
curl_share_close($sh);

$closeOk = isset($seen[0])
    && str_contains($seen[0], 'Function curl_close() is deprecated since 8.5')
    && str_contains($seen[0], 'as it has no effect since PHP 8.0');
$shareOk = isset($seen[1])
    && str_contains($seen[1], 'Function curl_share_close() is deprecated since 8.5')
    && str_contains($seen[1], 'as it has no effect since PHP 8.0');
echo 'count=', count($seen), "\n";
echo $closeOk ? "close_ok\n" : "close_bad\n";
echo $shareOk ? "share_ok\n" : "share_bad\n";
--EXPECT--
count=2
close_ok
share_ok
