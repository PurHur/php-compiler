--TEST--
DNF parenthesized intersection-only param/return types (#9733)
--FILE--
<?php
declare(strict_types=1);

interface I1 {}
interface I2 {}
class Both implements I1, I2 {}

function accepts((I1&I2) $o): string { return 'ok'; }
function returns(): (I1&I2) { return new Both(); }

echo accepts(new Both()), "\n";
echo returns() instanceof Both ? '1' : '0', "\n";
?>
--EXPECT--
ok
1
