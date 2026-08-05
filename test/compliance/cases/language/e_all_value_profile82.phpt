--TEST--
language E_ALL — Zend ≤8.3 value 32767 under PROFILE=8.2 (#27824)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo 'E_ALL=', E_ALL, "\n";
echo 'has_STRICT=', ((E_ALL & E_STRICT) === E_STRICT) ? 'Y' : 'N', "\n";
echo 'error_reporting=', error_reporting(), "\n";
echo 'eq=', (error_reporting() === E_ALL) ? 'Y' : 'N', "\n";
--EXPECT--
E_ALL=32767
has_STRICT=Y
error_reporting=22527
eq=N
