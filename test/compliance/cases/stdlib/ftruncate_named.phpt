--TEST--
ftruncate Reflection stream/size + named stream: (issue #24534, php-src file.stub.php)
--FILE--
<?php
$rf = new ReflectionFunction('ftruncate');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
$path = sys_get_temp_dir() . '/phpc_ftruncate_named_' . getmypid() . '.txt';
$fp = fopen($path, 'w');
fwrite($fp, 'abcdef');
try {
    ftruncate(stream: $fp, size: 3);
    echo "stream_named_ok\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
fclose($fp);
echo filesize($path), "\n";
@unlink($path);
try {
    $path2 = sys_get_temp_dir() . '/phpc_ftruncate_fp_' . getmypid() . '.txt';
    $fp2 = fopen($path2, 'w');
    fwrite($fp2, 'abcdef');
    ftruncate(fp: $fp2, size: 3);
    echo "fp accepted\n";
    fclose($fp2);
    @unlink($path2);
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
stream,size
stream_named_ok
3
Unknown named parameter $fp
