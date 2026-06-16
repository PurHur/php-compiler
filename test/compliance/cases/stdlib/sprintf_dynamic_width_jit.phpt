--TEST--
stdlib sprintf() dynamic width JIT/AOT (#9069)
--FILE--
<?php
declare(strict_types=1);

echo sprintf('%*d', 5, 1), "\n";
echo sprintf('%0*d', 5, 1), "\n";
--EXPECT--
    1
00001
