--TEST--
stdlib mongodb Manager class_exists + construct (#6575, PECL mongodb)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsMongodb()) {
    die('skip mongodb withheld on reference profile (#6575)');
}
--FILE--
<?php
declare(strict_types=1);

echo class_exists('MongoDB\\Driver\\Manager', false) ? '1' : '0';
echo class_exists('MongoDB\\Driver\\BulkWrite', false) ? '1' : '0';
echo class_exists('MongoDB\\Driver\\Query', false) ? '1' : '0';
echo class_exists('MongoDB\\Driver\\Cursor', false) ? '1' : '0';
echo extension_loaded('mongodb') ? '1' : '0';
echo "\n";

$m = new MongoDB\Driver\Manager('mongodb://127.0.0.1');
echo get_class($m), "\n";

$bulk = new MongoDB\Driver\BulkWrite();
$query = new MongoDB\Driver\Query([]);
echo get_class($bulk), "\n";
echo get_class($query), "\n";

try {
    $m->executeBulkWrite('db.coll', $bulk);
    echo "unexpected_ok\n";
} catch (RuntimeException $e) {
    echo "ex_bulk\n";
}
try {
    $m->executeQuery('db.coll', $query);
    echo "unexpected_ok\n";
} catch (RuntimeException $e) {
    echo "ex_query\n";
}
?>
--EXPECT--
11111
MongoDB\Driver\Manager
MongoDB\Driver\BulkWrite
MongoDB\Driver\Query
ex_bulk
ex_query
