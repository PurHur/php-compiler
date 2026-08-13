--TEST--
JIT: readfile() optional use_include_path/context + excess argc (#30582)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$path = sys_get_temp_dir().'/phpc_30582_rf_jit_'.getmypid().'.txt';
file_put_contents($path, "hello-30582\n");
try {
    ob_start();
    $n = readfile($path, false);
    $out = ob_get_clean();
    echo 'ok2 n=', $n, ' body=', trim($out), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    ob_start();
    $n = readfile($path, false, null);
    $out = ob_get_clean();
    echo 'ok3 n=', $n, ' body=', trim($out), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    readfile($path, false, null, 'extra');
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    readfile($path, false, 1);
    echo "CTX_NO_THROW\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
@unlink($path);
?>
--EXPECT--
ok2 n=12 body=hello-30582
ok3 n=12 body=hello-30582
readfile() expects at most 3 arguments, 4 given
readfile(): Argument #3 ($context) must be of type resource or null, int given
