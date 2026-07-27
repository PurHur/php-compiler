--TEST--
AOT: min()/max() with boxed call-arg operands (#23779)
--FILE--
<?php
declare(strict_types=1);

function g(int $v): int {
    return $v * 2;
}

echo max(g(1), g(3)), "\n";
echo max('xy', 'zw'), "\n";
--EXPECT--
6
zw
