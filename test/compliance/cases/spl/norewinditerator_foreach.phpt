--TEST--
NoRewindIterator foreach visits once (#27583)
--FILE--
<?php
$it = new NoRewindIterator(new ArrayIterator([1, 2, 3]));
foreach ($it as $v) {
    echo "a$v";
}
foreach ($it as $v) {
    echo "b$v";
}
echo "\n";
--EXPECT--
a1a2a3
