--TEST--
stdlib chmod Reflection/named permissions (#23346, ext/standard/filestat.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('chmod');
echo 'names=';
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ',';
}
echo "\n";
$f = sys_get_temp_dir() . '/phpc-chmod-named-' . getmypid();
file_put_contents($f, 'x');
try {
    $ok = chmod(filename: $f, permissions: 0600);
    echo 'permissions=', $ok ? 'ok' : 'bad', "\n";
} catch (Throwable $e) {
    echo 'permissions ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $ok = chmod(filename: $f, mode: 0600);
    echo 'mode=', $ok ? 'ok' : 'bad', "\n";
} catch (Throwable $e) {
    echo 'mode ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
$pos = chmod($f, 0644);
echo 'positional=', $pos ? 'ok' : 'bad', "\n";
@unlink($f);
?>
--EXPECT--
names=filename,permissions,
permissions=ok
mode ERR=Error: Unknown named parameter $mode
positional=ok
