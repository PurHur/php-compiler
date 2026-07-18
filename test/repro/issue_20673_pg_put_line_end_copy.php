<?php
/**
 * Repro #20673 — pg_put_line()/pg_end_copy() after #20637.
 */
foreach (['pg_put_line', 'pg_end_copy'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}
try {
    pg_put_line();
    echo "put_argc=fail\n";
} catch (ArgumentCountError $e) {
    echo "put_argc=ok\n";
}
try {
    pg_end_copy(1, 2);
    echo "end_argc=fail\n";
} catch (ArgumentCountError $e) {
    echo "end_argc=ok\n";
}
