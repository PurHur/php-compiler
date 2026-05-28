--TEST--
Generator yield from array (issue #167)
--FILE--
<?php
function gen() {
    yield from [1, 2, 3];
}
foreach (gen() as $v) {
    echo $v;
}
echo "\n";
--EXPECT--
123

