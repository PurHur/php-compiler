--TEST--
Language: __isset typed return without strict_types coerces int→bool (#26428)
--FILE--
<?php
class C {
    public function __isset(string $n): bool {
        return 1;
    }
}
var_export(isset((new C)->foo));
echo PHP_EOL;
--EXPECT--
true
