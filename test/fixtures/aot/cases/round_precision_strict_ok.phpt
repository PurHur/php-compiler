--TEST--
AOT round() precision — strict_types int accepted (#9482)
--FILE--
<?php
declare(strict_types=1);
echo round(1.5, 2), "\n";
echo round(1.5, 0), "\n";
--EXPECT--
1.5
2
--EXPECT_EXIT--
0
