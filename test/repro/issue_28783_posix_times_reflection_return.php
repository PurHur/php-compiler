<?php
/**
 * #28783 — posix_times Reflection return array|false (ext/posix/posix.stub.php).
 */
$r = new ReflectionFunction('posix_times');
echo 'posix_times=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
$t = posix_times();
echo 'posix_times_runtime=', (false === $t || (is_array($t)
    && isset($t['ticks'], $t['utime'], $t['stime'], $t['cutime'], $t['cstime']))) ? 'ok' : gettype($t), "\n";
