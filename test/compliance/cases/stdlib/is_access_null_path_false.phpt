--TEST--
is_link()/is_readable()/is_writable()/is_executable() null path returns false (#13661, ext/standard/filestat.c)
--FILE--
<?php
$checks = [
    is_link(null),
    is_readable(null),
    is_writable(null),
    is_executable(null),
];
foreach ($checks as $i => $v) {
    echo $i, '=', var_export($v, true), "\n";
}
--EXPECT--
0=false
1=false
2=false
3=false
