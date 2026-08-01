--TEST--
Language: Closures / FCC in constant expressions under PROFILE=8.5 (#26240, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
const C = static fn(int $x): int => $x + 1;
echo 'C=', (C)(2), "\n";
const D = strlen(...);
echo 'D=', (D)('ab'), "\n";
const E = static function (int $x): int {
    return $x + 1;
};
echo 'E=', (E)(2), "\n";
class K {
    public const F = static fn(string $s): int => strlen($s);
}
echo 'F=', (K::F)('xy'), "\n";
--EXPECT--
C=3
D=2
E=3
F=2
