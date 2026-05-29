--TEST--
Generator yield with explicit keys (issue #167)
--FILE--
<?php
function gen() {
    yield 'a' => 1;
    yield 'b' => 2;
}
foreach (gen() as $k => $v) {
    echo $k, $v;
}
echo "\n";
--EXPECT--
a1b2
