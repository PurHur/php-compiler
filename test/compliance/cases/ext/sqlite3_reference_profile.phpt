--TEST--
ext/sqlite3 extension_loaded + SQLite3Exception on reference profile (#19047, ext/sqlite3/php_sqlite3.c)
--FILE--
<?php
echo extension_loaded('sqlite3') ? "ext-loaded\n" : "ext-missing\n";
echo class_exists('SQLite3Exception', false) ? "class-exists\n" : "class-missing\n";
echo class_exists('SQLite3', false) ? "sqlite3-class\n" : "sqlite3-missing\n";
--EXPECT--
ext-loaded
class-exists
sqlite3-missing
