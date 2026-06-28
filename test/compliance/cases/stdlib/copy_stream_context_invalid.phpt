--TEST--
stdlib copy() invalid stream context TypeError (#13248)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc_copy_ctx_' . getmypid();
@mkdir($dir);
$from = $dir . '/a.txt';
$to = $dir . '/b.txt';
file_put_contents($from, 'x');
try {
    copy($from, $to, 123);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
@unlink($to);
@unlink($from);
@rmdir($dir);
?>
--EXPECT--
copy(): Argument #3 ($context) must be of type resource or null, int given
