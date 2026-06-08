--TEST--
AOT: min()/max() numeric-string variadic coercion (#4347)
--FILE--
<?php
declare(strict_types=1);

echo max(1, '2', 3.5), "\n";
echo min('3', 2), "\n";
echo min(3, 1, 2), "\n";
--EXPECT--
3.5
2
1
