--TEST--
stdlib array_map() inline closure and arrow callbacks (#10651)
--FILE--
<?php
$closure = array_map(function (int $x): int {
    return $x * 2;
}, [1, 2, 3]);
$arrow = array_map(fn (int $x): int => $x * 2, [1, 2, 3]);
echo $closure[0], $closure[1], $closure[2], "\n";
echo $arrow[0], $arrow[1], $arrow[2], "\n";
--EXPECT--
246
246
