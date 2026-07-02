--TEST--
stdlib sprintf() %h/%H JIT/AOT (#9991)
--FILE--
<?php
declare(strict_types=1);

echo sprintf('%h', 1.2), "\n";
echo sprintf('%H', 1234567.0), "\n";
--EXPECT--
1.2
1.23457E+6
