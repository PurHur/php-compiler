--TEST--
fileinfo finfo_file/finfo_buffer Reflection names + named args (#24390, ext/fileinfo/fileinfo.stub.php)
--FILE--
<?php
foreach (['finfo_file', 'finfo_buffer'] as $f) {
    $rf = new ReflectionFunction($f);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $f, ':', implode(',', $names), "\n";
}
$fi = finfo_open(FILEINFO_MIME_TYPE);
$tmp = sys_get_temp_dir() . '/finfo_named_test.txt';
file_put_contents($tmp, 'hello');
try {
    $mime = finfo_file(finfo: $fi, filename: $tmp);
    echo str_contains((string) $mime, 'plain') ? "file_named_ok\n" : "file_named_fail\n";
} catch (Throwable $e) {
    echo 'file_named_fail:', $e->getMessage(), "\n";
}
@unlink($tmp);
try {
    $buf = finfo_buffer(finfo: $fi, string: 'hello');
    echo str_contains((string) $buf, 'plain') ? "buffer_named_ok\n" : "buffer_named_fail\n";
} catch (Throwable $e) {
    echo 'buffer_named_fail:', $e->getMessage(), "\n";
}
?>
--EXPECT--
finfo_file:finfo,filename,flags,context
finfo_buffer:finfo,string,flags,context
file_named_ok
buffer_named_ok
