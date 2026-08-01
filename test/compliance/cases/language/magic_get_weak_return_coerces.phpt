--TEST--
Language: __get typed return without strict_types coerces string→int (#26431)
--FILE--
<?php
class C {
    public function __get(string $n): int {
        return "42";
    }
}
var_export((new C)->x);
echo PHP_EOL;
--EXPECT--
42
