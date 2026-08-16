--TEST--
md5_file / sha1_file Reflection return string|false (VM, issue #28347, basic_functions.stub.php)
--FILE--
<?php
foreach (['md5_file', 'sha1_file'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, '=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
}
$path = tempnam(sys_get_temp_dir(), 'm28347');
file_put_contents($path, 'x');
$m = md5_file($path);
echo 'md5_file_runtime=', (false === $m || is_string($m)) ? 'ok' : gettype($m), "\n";
$s = sha1_file($path);
echo 'sha1_file_runtime=', (false === $s || is_string($s)) ? 'ok' : gettype($s), "\n";
@unlink($path);
$missing = @md5_file('/no/such/file/28347');
echo 'md5_file_missing=', (false === $missing) ? 'false' : gettype($missing), "\n";
?>
--EXPECT--
md5_file=string|false
sha1_file=string|false
md5_file_runtime=ok
sha1_file_runtime=ok
md5_file_missing=false
