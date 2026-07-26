<?php
// Repro #23461 — Zend stub named args for unlink/chdir/umask/fnmatch
$path = sys_get_temp_dir() . '/pc-unlink-named-' . getmypid();
file_put_contents($path, 'x');
try {
    var_export(unlink(filename: $path));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $cwd = getcwd();
    var_export(chdir(directory: sys_get_temp_dir()));
    echo "\n";
    chdir($cwd);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $prev = umask(mask: 0022);
    var_export(is_int($prev));
    echo "\n";
    umask($prev);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(fnmatch(pattern: 'a*', filename: 'abc'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
