--TEST--
AOT: compact() — undefined names omitted, warning on stderr (issue #3750)
--FILE--
<?php
$a = 1;
$c = compact('a', 'b');
echo count($c), "\n";
echo $c['a'], "\n";
echo isset($c['b']) ? 'has_b' : 'no_b', "\n";
--EXPECT--
1
1
no_b
