--TEST--
JIT: file_put_contents() null $data writes empty file (#17024, ext/standard/file.c)
--FILE--
<?php
$tmp = tempnam(sys_get_temp_dir(), 'phpc-fpc-null-jit-');
$n = file_put_contents($tmp, null);
$body = file_get_contents($tmp);
@unlink($tmp);
var_export($n);
echo "\n";
var_export($body);
echo "\n";
?>
--EXPECT--
0
''
