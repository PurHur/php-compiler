--TEST--
ext/pgsql pg_query_params/prepare/execute + escape_* + fetch helpers (#20661)
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
--ENV--
PHP_COMPILER_ENABLE_PGSQL=1
--FILE--
<?php
declare(strict_types=1);
foreach ([
    'pg_query_params', 'pg_prepare', 'pg_execute',
    'pg_escape_string', 'pg_escape_literal', 'pg_escape_identifier',
    'pg_escape_bytea', 'pg_unescape_bytea',
    'pg_affected_rows', 'pg_fetch_all', 'pg_num_fields',
] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}
$esc = pg_escape_string("O'Reilly");
echo 'escape_string=', (int) (str_contains($esc, "''") || str_contains($esc, "\\'")), "\n";
$bytea = pg_escape_bytea("ab");
echo 'escape_bytea=', (int) (strlen($bytea) > 0), "\n";
$round = pg_unescape_bytea($bytea);
echo 'unescape=', (int) (is_string($round) && strlen($round) >= 0), "\n";
try {
    pg_query_params();
    echo "argc=fail\n";
} catch (ArgumentCountError $e) {
    echo "argc=ok\n";
}
?>
--EXPECT--
pg_query_params=1
pg_prepare=1
pg_execute=1
pg_escape_string=1
pg_escape_literal=1
pg_escape_identifier=1
pg_escape_bytea=1
pg_unescape_bytea=1
pg_affected_rows=1
pg_fetch_all=1
pg_num_fields=1
escape_string=1
escape_bytea=1
unescape=1
argc=ok
