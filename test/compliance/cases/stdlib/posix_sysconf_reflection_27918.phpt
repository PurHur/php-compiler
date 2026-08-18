--TEST--
posix_sysconf/pathconf/fpathconf Reflection + named args (VM, issue #27918, posix.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
declare(strict_types=1);

foreach (['posix_sysconf', 'posix_pathconf', 'posix_fpathconf'] as $f) {
    $r = new ReflectionFunction($f);
    $parts = [];
    foreach ($r->getParameters() as $p) {
        $parts[] = ($p->getType() ? (string) $p->getType() : '?').' $'.$p->getName();
    }
    echo $f, '(', implode(', ', $parts), '):', $r->getReturnType() ? (string) $r->getReturnType() : '?', "\n";
}

$n = posix_sysconf(conf_id: POSIX_SC_PAGESIZE);
echo 'sysconf_named ', is_int($n) && $n > 0 ? 'ok' : 'bad', "\n";
$pm = posix_pathconf(path: '/', name: POSIX_PC_PATH_MAX);
echo 'pathconf_named ', (false !== $pm && is_int($pm) && $pm > 0) ? 'ok' : 'bad', "\n";
$fm = posix_fpathconf(file_descriptor: 0, name: POSIX_PC_PATH_MAX);
echo 'fpathconf_named ', (false !== $fm && is_int($fm) && $fm > 0) ? 'ok' : 'bad', "\n";
?>
--EXPECT--
posix_sysconf(int $conf_id):int
posix_pathconf(string $path, int $name):int|false
posix_fpathconf(? $file_descriptor, int $name):int|false
sysconf_named ok
pathconf_named ok
fpathconf_named ok
