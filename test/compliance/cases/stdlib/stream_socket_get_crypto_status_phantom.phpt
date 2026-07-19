--TEST--
stdlib stream_socket_get_crypto_status() — phantom on ≤8.5 profiles (#21021)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
$prev = getenv('PHP_COMPILER_PROFILE');
putenv('PHP_COMPILER_PROFILE');
if (PHPCompiler\CompilerVersion::supportsStreamSocketGetCryptoStatus()) {
    if (is_string($prev) && '' !== $prev) {
        putenv('PHP_COMPILER_PROFILE='.$prev);
    }
    die('skip phantom test not applicable when crypto status API is enabled');
}
?>
--FILE--
<?php
echo function_exists('stream_socket_get_crypto_status') ? "fn-fail\n" : "fn-ok\n";
try {
    stream_socket_get_crypto_status(STDIN);
    echo "no-exception\n";
} catch (Throwable $e) {
    echo $e instanceof Error ? 'Error' : get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
fn-ok
Error: Call to undefined function stream_socket_get_crypto_status()
