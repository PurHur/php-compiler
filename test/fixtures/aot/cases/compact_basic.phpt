--TEST--
AOT: compact() defined locals — Module verify + snapshot (#30778)
--FILE--
<?php
$a = 1;
$b = 2;
$c = compact('a', 'b');
echo $c['a'], ',', $c['b'], "\n";
echo "ok\n";
--EXPECT--
1,2
ok
