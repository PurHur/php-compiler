--TEST--
language: array_map with closure callback (issue #72)
--FILE--
<?php
$out = array_map(function ($x) {
    return $x * 2;
}, [1, 2, 3]);
echo $out[0], $out[1], $out[2], "\n";
--EXPECT--
246
