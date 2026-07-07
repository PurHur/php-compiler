--TEST--
ext/sqlite3 withheld on reference profile — extension_loaded/class_exists (#17106, ext/sqlite3/php_sqlite3.c)
--FILE--
<?php
echo extension_loaded('sqlite3') ? "ext-loaded\n" : "ext-missing\n";
echo class_exists('SQLite3Exception', false) ? "class-exists\n" : "class-missing\n";
--EXPECT--
ext-missing
class-missing
