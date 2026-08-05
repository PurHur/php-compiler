--TEST--
language E_ALL — Zend 8.4 value 30719; error_reporting() === E_ALL (#27824)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'E_ALL=', E_ALL, "\n";
echo 'has_STRICT=', ((E_ALL & E_STRICT) === E_STRICT) ? 'Y' : 'N', "\n";
echo 'error_reporting=', error_reporting(), "\n";
echo 'eq=', (error_reporting() === E_ALL) ? 'Y' : 'N', "\n";
--EXPECT--
E_ALL=30719
has_STRICT=N
error_reporting=30719
eq=Y
