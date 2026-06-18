--TEST--
Language: (string) cast on Stringable inline call arg invokes __toString (#9504, Zend/zend_operators.c)
--FILE--
<?php
class C implements Stringable {
    public function __toString(): string {
        return 'x';
    }
}
var_export((string) new C());
echo "\n";
var_export(strval(new C()));
echo "\n";
--EXPECT--
'x'
'x'
