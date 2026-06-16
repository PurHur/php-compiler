--TEST--
stdlib sprintf() dynamic width via %* and static padding (#9069, ext/standard/formatted_print.c)
--FILE--
<?php
declare(strict_types=1);

echo sprintf('%*d', 5, 1), "\n";
echo sprintf('%0*d', 5, 1), "\n";
echo sprintf('%05d', 1), "\n";
echo sprintf('%-5s', 'x'), "\n";
echo sprintf('%.*f', 2, 1.234), "\n";
--EXPECT--
    1
00001
00001
x    
1.23
