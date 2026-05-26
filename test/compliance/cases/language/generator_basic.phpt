--TEST--
Basic generator yield and foreach (issue #167)
--FILE--
<?php
function gen() {
    yield 1;
    yield 2;
    yield 3;
}
foreach (gen() as $v) {
    echo $v;
}
echo "\n";
--EXPECT--
123
