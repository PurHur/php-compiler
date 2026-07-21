--TEST--
stdlib basename()/dirname()/pathinfo() null — coerce on 8.2 reference profile (#20099 guard)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo var_export(basename(null), true), "\n";
echo var_export(dirname(null), true), "\n";
echo is_array(pathinfo(null)) ? "pathinfo: array\n" : "pathinfo: not-array\n";
?>
--EXPECT--
''
''
pathinfo: array
