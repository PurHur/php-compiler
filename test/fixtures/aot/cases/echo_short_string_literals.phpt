--TEST--
AOT: echo short string literals "0"/"1" with helper-runtime cache (#15889, #16075)
--FILE--
<?php
echo "0", "\n";
echo "1", "\n";
echo count([1, 2, 3]) === 3 ? '1' : '0', "\n";
--EXPECT--
0
1
1
