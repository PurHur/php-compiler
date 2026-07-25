--TEST--
stdlib extension_loaded('sqlite3') + SQLite3Exception withheld on reference (#22791)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', extension_loaded('sqlite3') ? '1' : '0', "\n";
echo 'SQLite3=', class_exists('SQLite3', false) ? '1' : '0', "\n";
echo 'SQLite3Exception=', class_exists('SQLite3Exception', false) ? '1' : '0', "\n";
--EXPECT--
loaded=0
SQLite3=0
SQLite3Exception=0
