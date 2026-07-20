<?php
/**
 * #6575 — MongoDB\Driver\Manager class_exists + construct (PROFILE=8.4).
 */
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
    $m->executeQuery('db.coll', $query);
    echo "unexpected_ok\n";
} catch (RuntimeException $e) {
    echo "ex\n";
}
