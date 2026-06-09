--TEST--
stdlib round() precision coercion — JIT (issue #4213)
--FILE--
<?php
echo round(1.5, 0.9), "\n";
echo round(1.5, '1'), "\n";
echo round(1.5, 1.9), "\n";
--EXPECT--
2
1.5
1.5
