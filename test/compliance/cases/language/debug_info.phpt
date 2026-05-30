--TEST--
Language: __debugInfo() magic method — var_dump redaction (VM, #3259)
--FILE--
<?php
class C {
    private int $secret = 1;
    public function __debugInfo(): array {
        return ['redacted' => true];
    }
}
$c = new C();
var_dump($c);
--EXPECTF--
object(C)#%d (1) {
["redacted"]=>
bool(true)
}
