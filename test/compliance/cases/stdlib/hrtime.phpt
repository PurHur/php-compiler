--TEST--
stdlib hrtime() nanoseconds and [sec, nsec] pair (issue #3195)
--FILE--
<?php
$a = hrtime(true);
$b = hrtime(true);
echo $b >= $a ? "mono\n" : "bad\n";
$pair = hrtime();
echo count($pair) === 2 ? "pair\n" : "bad\n";
--EXPECT--
mono
pair
