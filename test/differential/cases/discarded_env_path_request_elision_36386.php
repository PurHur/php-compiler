<?php
// Discarded sys_get_temp_dir/getcwd/get_include_path/ob_get_level/
// connection_*/session_status/localeconv/gc_status must match Zend (#36386).
// Side-effect-free statements only — results unused except shape checks on
// live builtins that compile/run cleanly under AOT.
// Live getcwd omitted: AOT segfaults on used result (pre-existing).
// Live connection_aborted omitted: AOT blank stdout when result used.
// Live session_status omitted: NestedJIT link fails (phpc_base_convert).
// Live localeconv/gc_status omitted: NestedJIT HT materialize / size.
// php-src: ext/standard/file.c, dir.c, basic_functions.c, output.c,
// locale.c; ext/session/session.c; Zend/zend_builtin_functions.c
// @differential-repeat: 3
function work(int $loops): int
{
    $c = 0;
    for ($k = 0; $k < $loops; ++$k) {
        sys_get_temp_dir();
        getcwd();
        get_include_path();
        ob_get_level();
        connection_status();
        connection_aborted();
        session_status();
        localeconv();
        gc_status();
        $c += $k;
    }

    $tmp = sys_get_temp_dir();
    $inc = get_include_path();
    $ob = ob_get_level();
    $cs = connection_status();

    return $c
        + (is_string($tmp) && $tmp !== '' ? 1 : 0)
        + (is_string($inc) ? 1 : 0)
        + (is_int($ob) && $ob >= 0 ? 1 : 0)
        + (is_int($cs) ? 1 : 0);
}
echo work(5), "\n";
echo work(3), "\n";
echo work(2), "\n";
