--TEST--
Pre/post decrement on loop counter (JIT)
--FILE--
<?php
for ($i = 0; $i < 3; $i++) {
    echo $i;
}
echo "\n";
$n = 2;
echo --$n, $n;
echo "\n";
--EXPECT--
012
11
