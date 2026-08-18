<?php
// Repro #27918 — posix_sysconf/pathconf/fpathconf Reflection + named args (posix.stub.php)
foreach (['posix_sysconf', 'posix_pathconf', 'posix_fpathconf'] as $f) {
    if (!function_exists($f)) {
        echo $f, " MISSING\n";
        continue;
    }
    $r = new ReflectionFunction($f);
    $parts = [];
    foreach ($r->getParameters() as $p) {
        $parts[] = ($p->getType() ? (string) $p->getType() : '?').' $'.$p->getName();
    }
    echo $f, '(', implode(', ', $parts), '):', $r->getReturnType() ? (string) $r->getReturnType() : '?', "\n";
    echo $f, '_argc=', $r->getNumberOfParameters(), ' req=', $r->getNumberOfRequiredParameters(), "\n";
}
if (function_exists('posix_sysconf')) {
    $n = posix_sysconf(conf_id: POSIX_SC_PAGESIZE);
    echo 'sysconf_named=', is_int($n) && $n > 0 ? 'ok' : 'bad', "\n";
}
if (function_exists('posix_pathconf')) {
    $pm = posix_pathconf(path: '/', name: POSIX_PC_PATH_MAX);
    echo 'pathconf_named=', (false !== $pm && is_int($pm) && $pm > 0) ? 'ok' : 'bad', "\n";
}
if (function_exists('posix_fpathconf')) {
    $fm = posix_fpathconf(file_descriptor: 0, name: POSIX_PC_PATH_MAX);
    echo 'fpathconf_named=', (false !== $fm && is_int($fm) && $fm > 0) ? 'ok' : 'bad', "\n";
}
