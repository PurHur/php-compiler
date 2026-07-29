--TEST--
ext/pgsql pg_set_error_context_visibility withheld on PROFILE=8.2 (#22620, re-#20674)
--ENV--
PHP_COMPILER_ENABLE_PGSQL=1
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
declare(strict_types=1);
// Soft-exit: BaseTest ignores --SKIPIF--.
if (!function_exists('pg_connect')) {
    echo "skip\n";
    exit(0);
}
echo 'fn=', function_exists('pg_set_error_context_visibility') ? 'Y' : 'N', "\n";
foreach (['PGSQL_SHOW_CONTEXT_NEVER', 'PGSQL_SHOW_CONTEXT_ERRORS', 'PGSQL_SHOW_CONTEXT_ALWAYS'] as $c) {
    echo $c, '=', defined($c) ? 'Y' : 'N', "\n";
}
?>
--EXPECT--
fn=N
PGSQL_SHOW_CONTEXT_NEVER=N
PGSQL_SHOW_CONTEXT_ERRORS=N
PGSQL_SHOW_CONTEXT_ALWAYS=N
