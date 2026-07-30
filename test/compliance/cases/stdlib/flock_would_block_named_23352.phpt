--TEST--
stdlib flock Reflection/named would_block (#23352, ext/standard/file.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('flock');
echo 'names=';
foreach ($r->getParameters() as $p) {
    echo $p->getName();
    if ($p->isPassedByReference()) {
        echo '&';
    }
    if ($p->isOptional()) {
        echo '=';
    }
    echo ',';
}
echo "\n";
$tmp = tempnam(sys_get_temp_dir(), 'fl');
$f = fopen($tmp, 'c+');
$wb = null;
try {
    $ok = flock(stream: $f, operation: LOCK_SH, would_block: $wb);
    echo 'would_block=', var_export($ok, true), ' wb=', var_export($wb, true), "\n";
} catch (Throwable $e) {
    echo 'would_block ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    flock(stream: $f, operation: LOCK_SH, wouldblock: $wb);
    echo "wouldblock_ok\n";
} catch (Throwable $e) {
    echo 'wouldblock ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
fclose($f);
unlink($tmp);
?>
--EXPECT--
names=stream,operation,would_block&=,
would_block=true wb=0
wouldblock ERR=Error: Unknown named parameter $wouldblock
