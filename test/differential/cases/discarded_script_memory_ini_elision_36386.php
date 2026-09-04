<?php
// Discarded getmyinode/getlastmod/get_current_user/memory_get_*/php_ini_*/gc_enabled
// must match Zend (#36386). Side-effect-free statements only — results unused
// except shape checks on live builtins that compile/run cleanly under AOT.
// Live php_ini_* omitted: NestedJIT IniIntrospection segfaults compile host.
// Live gc_enabled omitted when paired with script-identity (blank AOT stdout).
// php-src: ext/standard/basic_functions.c, Zend/zend_alloc.c, ext/standard/php_gc.c
// @differential-repeat: 3
function work(bool $real, int $loops): int
{
    $c = 0;
    for ($k = 0; $k < $loops; ++$k) {
        getmyinode();
        getlastmod();
        get_current_user();
        memory_get_usage();
        memory_get_usage($real);
        memory_get_peak_usage();
        memory_get_peak_usage($real);
        php_ini_loaded_file();
        php_ini_scanned_files();
        gc_enabled();
        $c += $k;
    }

    $inode = getmyinode();
    $mtime = getlastmod();
    $user = get_current_user();
    $mem = memory_get_usage($real);
    $peak = memory_get_peak_usage();

    return $c
        + (is_int($inode) || false === $inode ? 1 : 0)
        + (is_int($mtime) || false === $mtime ? 1 : 0)
        + (is_string($user) ? 1 : 0)
        + (is_int($mem) && $mem >= 0 ? 1 : 0)
        + (is_int($peak) && $peak >= 0 ? 1 : 0);
}
echo work(false, 5), "\n";
echo work(true, 3), "\n";
echo work(false, 2), "\n";
