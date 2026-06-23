--TEST--
stdlib sprintf() %F conversion specifier JIT/AOT (#9043)
--FILE--
<?php
echo sprintf('%F', 1.2), "\n";
echo sprintf('%.2F', 3.5), "\n";
--EXPECT--
1.200000
3.50
