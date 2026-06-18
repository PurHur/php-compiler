--TEST--
stdlib hrtime() named as_number flag (issue #9751, ext/standard/hrtime.c)
--FILE--
<?php
$a = hrtime(as_number: true);
$b = hrtime(as_number: true);
echo $b >= $a ? "mono\n" : "bad\n";
$pair = hrtime(as_number: false);
echo count($pair) === 2 ? "pair\n" : "bad\n";
--EXPECT--
mono
pair
