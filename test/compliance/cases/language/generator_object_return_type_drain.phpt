--TEST--
Language: generator with object return type drains without body return (#26468)
--FILE--
<?php
function gen(): object {
    yield 1;
}
foreach (gen() as $v) {
    echo "y:$v\n";
}
echo "ok\n";
--EXPECT--
y:1
ok
