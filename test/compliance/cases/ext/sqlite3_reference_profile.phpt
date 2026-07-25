--TEST--
ext/sqlite3 withheld on reference profile without host ext (#22791, re-#19047, php_sqlite3.c)
--FILE--
<?php
echo extension_loaded('sqlite3') ? "ext-loaded\n" : "ext-missing\n";
echo class_exists('SQLite3Exception', false) ? "class-exists\n" : "class-missing\n";
echo class_exists('SQLite3', false) ? "sqlite3-class\n" : "sqlite3-missing\n";
--EXPECT--
ext-missing
class-missing
sqlite3-missing
