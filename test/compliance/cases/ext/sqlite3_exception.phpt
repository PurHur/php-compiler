--TEST--
ext/sqlite3 SQLite3Exception on reference profile (#7269, #19047, ext/sqlite3/sqlite3.stub.php)
--FILE--
<?php
echo class_exists('SQLite3Exception', false) ? "exists\n" : "missing\n";
echo is_subclass_of('SQLite3Exception', 'Exception') ? "subclass\n" : "not-subclass\n";
echo extension_loaded('sqlite3') ? "ext-loaded\n" : "ext-missing\n";
try {
    throw new SQLite3Exception('db error');
} catch (SQLite3Exception $e) {
    echo 'caught: ', $e->getMessage(), "\n";
}
--EXPECT--
exists
subclass
ext-loaded
caught: db error
