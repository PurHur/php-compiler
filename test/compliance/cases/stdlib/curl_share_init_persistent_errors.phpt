--TEST--
curl_share_init_persistent() ValueError guards (#20530, PHP 8.5, ext/curl/share.c)
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

try {
    curl_share_init_persistent([]);
    echo "empty-fail\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}

try {
    curl_share_init_persistent([CURL_LOCK_DATA_COOKIE]);
    echo "cookie-fail\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}

try {
    curl_share_init_persistent([CURL_LOCK_DATA_DNS, 30]);
    echo "unknown-fail\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
curl_share_init_persistent(): Argument #1 ($share_options) must not be empty
curl_share_init_persistent(): Argument #1 ($share_options) must not contain CURL_LOCK_DATA_COOKIE because sharing cookies across PHP requests is unsafe
curl_share_init_persistent(): Argument #1 ($share_options) must contain only CURL_LOCK_DATA_* constants
