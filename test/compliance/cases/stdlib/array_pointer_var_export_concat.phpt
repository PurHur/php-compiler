--TEST--
stdlib array pointer builtins in var_export concat (#13829, ext/standard/array.c)
--FILE--
<?php
$a = [1, 2, 3];
next($a);
echo 'key=' . var_export(key($a), true), "\n";
echo 'current=' . var_export(current($a), true), "\n";
echo 'end=' . var_export(end($a), true), "\n";
--EXPECT--
key=1
current=2
end=3
