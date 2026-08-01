--TEST--
Language: __isset(): bool compiles; isset uses magic (#26463)
--FILE--
<?php
class C {
    public function __isset(string $n): bool {
        return $n === 'x';
    }
}
var_export(isset((new C)->x));
echo PHP_EOL;
var_export(isset((new C)->y));
echo PHP_EOL;
--EXPECT--
true
false
