--TEST--
stdlib session_module_name() JIT get and set (#5749)
--FILE--
<?php
$m0 = session_module_name();
$m1 = session_module_name('files');
$m2 = session_module_name();
echo $m0, "\n", $m1, "\n", $m2, "\n";
--EXPECT--
files
files
files
