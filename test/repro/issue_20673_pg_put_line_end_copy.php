<?php
/**
 * Repro #20673 — pg_put_line() / pg_end_copy() after #20637.
 */
echo 'pg_put_line=', function_exists('pg_put_line') ? '1' : '0', "\n";
echo 'pg_end_copy=', function_exists('pg_end_copy') ? '1' : '0', "\n";
try {
    pg_put_line();
    echo "put_line_argc=fail\n";
} catch (ArgumentCountError $e) {
    echo "put_line_argc=ok\n";
}
try {
    pg_end_copy(1, 2);
    echo "end_copy_argc=fail\n";
} catch (ArgumentCountError $e) {
    echo "end_copy_argc=ok\n";
}
