--TEST--
ext/pgsql pg_set_error_context_visibility + PGSQL_SHOW_CONTEXT_* (#20674 / #22620)
--ENV--
PHP_COMPILER_ENABLE_PGSQL=1
PHP_COMPILER_PROFILE=8.3
--SKIPIF--
<?php
// Host Zend often lacks ext/pgsql; in-tree path uses PHP_COMPILER_ENABLE_PGSQL (#24994).
if (!extension_loaded('pgsql')) {
    $en = getenv('PHP_COMPILER_ENABLE_PGSQL');
    if (!is_string($en) || '' === trim($en) || in_array(strtolower(trim($en)), ['0', 'false', 'off', 'no'], true)) {
        die('skip pgsql withheld');
    }
}
?>
--FILE--
<?php
declare(strict_types=1);
echo 'fn=', (int) function_exists('pg_set_error_context_visibility'), "\n";
foreach (['PGSQL_SHOW_CONTEXT_NEVER', 'PGSQL_SHOW_CONTEXT_ERRORS', 'PGSQL_SHOW_CONTEXT_ALWAYS'] as $c) {
    echo $c, '=', (int) defined($c), "\n";
}
echo 'NEVER=', (int) constant('PGSQL_SHOW_CONTEXT_NEVER'), "\n";
echo 'ERRORS=', (int) constant('PGSQL_SHOW_CONTEXT_ERRORS'), "\n";
echo 'ALWAYS=', (int) constant('PGSQL_SHOW_CONTEXT_ALWAYS'), "\n";
try {
    pg_set_error_context_visibility();
    echo "argc=fail\n";
} catch (ArgumentCountError $e) {
    echo "argc=ok\n";
}
?>
--EXPECT--
fn=1
PGSQL_SHOW_CONTEXT_NEVER=1
PGSQL_SHOW_CONTEXT_ERRORS=1
PGSQL_SHOW_CONTEXT_ALWAYS=1
NEVER=0
ERRORS=1
ALWAYS=2
argc=ok
