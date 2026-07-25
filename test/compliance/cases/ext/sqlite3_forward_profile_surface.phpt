--TEST--
sqlite3 surface present under PHP_COMPILER_PROFILE=8.4 (#22791)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', extension_loaded('sqlite3') ? '1' : '0', "\n";
echo 'SQLite3=', class_exists('SQLite3', false) ? '1' : '0', "\n";
echo 'SQLite3Exception=', class_exists('SQLite3Exception', false) ? '1' : '0', "\n";
--EXPECT--
loaded=1
SQLite3=1
SQLite3Exception=1
