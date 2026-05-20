--TEST--
declare(strict_types=1) accepts only exact int for int parameter (issue #156)
--FILE--
<?php
declare(strict_types=1);
function f(int $x) {
    return $x;
}
echo f(1), "\n";
--EXPECT--
1
