--TEST--
foreach on associative array with keys (VM)
--FILE--
<?php
foreach (['a' => 1, 'b' => 2] as $k => $v) {
    echo $k, $v;
}
echo "\n";
--EXPECT--
a1b2
