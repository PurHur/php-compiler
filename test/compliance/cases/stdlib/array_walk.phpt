--TEST--
stdlib array_walk() string callback
--FILE--
<?php
$items = ['a', 'b', 'c'];
$ok = array_walk($items, 'strtoupper');
echo $ok ? 'ok' : 'fail', "\n";
echo $items[0], '|', $items[1], '|', $items[2], "\n";
--EXPECT--
ok
A|B|C
