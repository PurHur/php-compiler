--TEST--
stdlib DIRECTORY_SEPARATOR and PATH_SEPARATOR constants (issues #11444, #15833)
--FILE--
<?php
echo defined('DIRECTORY_SEPARATOR') ? '1' : '0', "\n";
echo defined('PATH_SEPARATOR') ? '1' : '0', "\n";
echo DIRECTORY_SEPARATOR, "\n";
echo PATH_SEPARATOR, "\n";
if ('/' !== DIRECTORY_SEPARATOR || ':' !== PATH_SEPARATOR) {
    echo "guard_fail\n";
    exit(1);
}
$parts = explode(PATH_SEPARATOR, get_include_path());
echo is_array($parts) && count($parts) >= 1 ? '1' : '0', "\n";
--EXPECT--
1
1
/
:
1
