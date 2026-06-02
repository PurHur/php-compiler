--TEST--
AOT: compact() — non-string var_names warn-and-continue (issue #4487)
--FILE--
<?php
$a = 1;
$c = compact(['a', 123]);
echo count($c), "\n";
echo $c['a'], "\n";
--EXPECT--
1
1
