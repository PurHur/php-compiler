--TEST--
stdlib is_writeable() — alias of is_writable() (ext/standard/filestat.c, #14965)
--FILE--
<?php
echo function_exists('is_writable') ? 'w=yes' : 'w=no', "\n";
echo function_exists('is_writeable') ? 'we=yes' : 'we=no', "\n";
$path = __FILE__;
echo is_writeable($path) === is_writable($path) ? 'same' : 'diff', "\n";
?>
--EXPECT--
w=yes
we=yes
same
