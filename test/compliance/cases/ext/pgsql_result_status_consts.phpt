--TEST--
ext/pgsql ExecStatusType / SEEK_* / LIBPQ_VERSION* constants (#24129)
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
$ints = [
    'PGSQL_EMPTY_QUERY' => 0,
    'PGSQL_COMMAND_OK' => 1,
    'PGSQL_TUPLES_OK' => 2,
    'PGSQL_COPY_OUT' => 3,
    'PGSQL_COPY_IN' => 4,
    'PGSQL_BAD_RESPONSE' => 5,
    'PGSQL_NONFATAL_ERROR' => 6,
    'PGSQL_FATAL_ERROR' => 7,
    'PGSQL_SEEK_SET' => 0,
    'PGSQL_SEEK_CUR' => 1,
    'PGSQL_SEEK_END' => 2,
];
foreach ($ints as $name => $want) {
    echo $name, '=', defined($name) ? (int) constant($name) : 'UNDEF', "\n";
    if (!defined($name) || (int) constant($name) !== $want) {
        echo 'MISMATCH_', $name, "\n";
    }
}
foreach (['PGSQL_LIBPQ_VERSION', 'PGSQL_LIBPQ_VERSION_STR'] as $name) {
    if (!defined($name)) {
        echo $name, "=UNDEF\n";
        continue;
    }
    $v = constant($name);
    echo $name, '_defined=1 type=', gettype($v), ' nonempty=', (int) (is_string($v) && '' !== $v), "\n";
}
if (defined('PGSQL_LIBPQ_VERSION') && defined('PGSQL_LIBPQ_VERSION_STR')) {
    echo 'LIBPQ_PAIR_EQUAL=', (int) (constant('PGSQL_LIBPQ_VERSION') === constant('PGSQL_LIBPQ_VERSION_STR')), "\n";
}
?>
--EXPECT--
PGSQL_EMPTY_QUERY=0
PGSQL_COMMAND_OK=1
PGSQL_TUPLES_OK=2
PGSQL_COPY_OUT=3
PGSQL_COPY_IN=4
PGSQL_BAD_RESPONSE=5
PGSQL_NONFATAL_ERROR=6
PGSQL_FATAL_ERROR=7
PGSQL_SEEK_SET=0
PGSQL_SEEK_CUR=1
PGSQL_SEEK_END=2
PGSQL_LIBPQ_VERSION_defined=1 type=string nonempty=1
PGSQL_LIBPQ_VERSION_STR_defined=1 type=string nonempty=1
LIBPQ_PAIR_EQUAL=1
