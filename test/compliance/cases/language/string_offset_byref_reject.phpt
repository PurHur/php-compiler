--TEST--
Language: cannot create references to/from string offsets (#21910 / #29523, Zend/zend_execute.c)
--FILE--
<?php
$s = 'abc';
$x = 'z';
try {
    $r =& $s[1];
    echo "rhs no throw\n";
} catch (Error $e) {
    echo 'rhs: ', $e->getMessage(), "\n";
}
try {
    $s[1] =& $x;
    echo "lhs no throw\n";
} catch (Error $e) {
    echo 'lhs: ', $e->getMessage(), "\n";
}
try {
    $a = [&$s[1]];
    echo "arr no throw\n";
} catch (Error $e) {
    echo 'arr: ', $e->getMessage(), "\n";
}
try {
    [&$r2] = $s;
    echo "list no throw\n";
} catch (Error $e) {
    echo 'list: ', $e->getMessage(), "\n";
}
function byref_sink(&$v) {
    $v = 'Z';
}
try {
    byref_sink($s[0]);
    echo "call no throw s=$s\n";
} catch (Error $e) {
    echo 'call: ', $e->getMessage(), "\n";
}
echo $s, "\n";
--EXPECT--
rhs: Cannot create references to/from string offsets
lhs: Cannot create references to/from string offsets
arr: Cannot create references to/from string offsets
list: Cannot create references to/from string offsets
call: Cannot create references to/from string offsets
abc
