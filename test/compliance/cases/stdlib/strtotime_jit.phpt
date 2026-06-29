--TEST--
stdlib strtotime() JIT/AOT path (#10742)
--FILE--
<?php
declare(strict_types=1);
echo strtotime('2024-06-21'), "\n";
echo strtotime('2024-01-31 +1 month'), "\n";
--EXPECT--
1718928000
1709337600
