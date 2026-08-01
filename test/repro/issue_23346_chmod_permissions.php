<?php
/**
 * Repro #23346 — chmod Reflection/named permissions (php-src filestat.stub.php).
 *
 * VM:  php bin/vm.php test/repro/issue_23346_chmod_permissions.php
 * JIT: php bin/jit.php test/repro/issue_23346_chmod_permissions.php
 * AOT named success path (unknown mode: is compile-time Error in NamedArgs — VM-only):
 *   php bin/compile.php -o /tmp/chmod23346 -r 'var_export(chmod(filename:"/tmp", permissions:0755)); echo "\n";'
 */
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
    echo 'permissions=', var_export($ok, true), "\n";
} catch (Throwable $e) {
    echo 'permissions ERR=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $ok = chmod(filename: $f, mode: 0600);
    echo 'mode=', var_export($ok, true), "\n";
} catch (Throwable $e) {
    echo 'mode ERR=', get_class($e), ':', $e->getMessage(), "\n";
}
echo 'positional=', var_export(chmod($f, 0644), true), "\n";
@unlink($f);
