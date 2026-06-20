--TEST--
Backed enum enum+enum arithmetic in try/catch reports TypeError not Method call on non-object (#9887)
--FILE--
<?php
declare(strict_types=1);
enum E: int { case A = 1; case B = 2; }
try {
    $x = E::A + E::A;
    var_export($x);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage();
}
echo "\n";
--EXPECT--
TypeError: Unsupported operand types: E + E
