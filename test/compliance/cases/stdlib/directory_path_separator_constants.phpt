--TEST--
stdlib DIRECTORY_SEPARATOR and PATH_SEPARATOR constants (issue #11444)
--FILE--
<?php
echo defined('DIRECTORY_SEPARATOR') ? '1' : '0', "\n";
echo defined('PATH_SEPARATOR') ? '1' : '0', "\n";
echo DIRECTORY_SEPARATOR, "\n";
echo PATH_SEPARATOR, "\n";
$parts = explode(PATH_SEPARATOR, get_include_path());
echo is_array($parts) && count($parts) >= 1 ? '1' : '0', "\n";
--EXPECT--
1
1
/
:
1
