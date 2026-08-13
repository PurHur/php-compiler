<?php
$path = sys_get_temp_dir().'/phpc_30582_readfile_'.getmypid().'.txt';
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
