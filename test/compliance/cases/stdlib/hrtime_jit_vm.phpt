--TEST--
stdlib hrtime() VM/JIT parity — monotonic ns and [sec, nsec] pair (#9182)
--FILE--
<?php
$a = hrtime(true);
$b = hrtime(true);
echo ($a > 0) ? "pos\n" : "bad\n";
echo $b >= $a ? "mono\n" : "bad\n";
$pair = hrtime();
echo count($pair) === 2 ? "pair\n" : "bad\n";
--EXPECT--
pos
mono
pair
