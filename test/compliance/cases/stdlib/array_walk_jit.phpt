--TEST--
stdlib array_walk() JIT string callback (#1209)
--FILE--
<?php
$items = array();
$items[] = 'a';
$items[] = 'b';
$items[] = 'c';
$ok = array_walk($items, 'strtoupper');
echo $ok ? 'ok' : 'fail', "\n";
echo $items[0], '|', $items[1], '|', $items[2], "\n";
--EXPECT--
ok
A|B|C
