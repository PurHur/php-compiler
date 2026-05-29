--TEST--
language: closure stored in array and called (issue #72)
--FILE--
<?php
$fs = [
    function ($x) {
        return $x + 1;
    },
];
echo $fs[0](2), "\n";
--EXPECT--
3
