--TEST--
posix_times Reflection return array|false (VM, issue #28783, posix.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('posix_times');
echo 'posix_times=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
$t = posix_times();
echo 'posix_times_runtime=', (false === $t || (is_array($t)
    && isset($t['ticks'], $t['utime'], $t['stime'], $t['cutime'], $t['cstime']))) ? 'ok' : gettype($t), "\n";
?>
--EXPECT--
posix_times=array|false
posix_times_runtime=ok
