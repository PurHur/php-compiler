--TEST--
stdlib array_walk() JIT string callback (#1209)
--FILE--
<?php
$items = array();
$items[] = ' a ';
$items[] = ' b ';
$ok = array_walk($items, 'trim');
echo $ok ? 'ok' : 'fail', "\n";
echo $items[0], '|', $items[1], "\n";
--EXPECT--
ok
 a | b 
