--TEST--
curl_share_setopt rejects CurlSharePersistentHandle (#20530, PHP 8.5)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::advertisesCurlShareInitPersistent()) {
    die('skip curl_share_init_persistent requires PHP_COMPILER_PROFILE=8.5');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
declare(strict_types=1);

$sh = curl_share_init_persistent([CURL_LOCK_DATA_DNS]);
try {
    curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
    echo "setopt-type-fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    new CurlSharePersistentHandle();
    echo "construct-fail\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
curl_share_setopt(): Argument #1 ($share_handle) must be of type CurlShareHandle, CurlSharePersistentHandle given
Cannot directly construct CurlSharePersistentHandle, use curl_share_init_persistent() instead
