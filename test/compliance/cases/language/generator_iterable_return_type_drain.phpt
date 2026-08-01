--TEST--
Language: generator with iterable return type drains without body return (#26468)
--FILE--
<?php
function gen(): iterable {
    yield 1;
}
foreach (gen() as $v) {
    echo "y:$v\n";
}
echo "ok\n";
--EXPECT--
y:1
ok
