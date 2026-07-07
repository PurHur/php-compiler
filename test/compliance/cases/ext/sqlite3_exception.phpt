--TEST--
ext/sqlite3 SQLite3Exception withheld on reference profile (issue #7269, #17106, ext/sqlite3/sqlite3.stub.php)
--FILE--
<?php
echo class_exists('SQLite3Exception', false) ? "exists\n" : "missing\n";
echo extension_loaded('sqlite3') ? "ext-loaded\n" : "ext-missing\n";
--EXPECT--
missing
ext-missing
