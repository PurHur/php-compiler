--TEST--
Language: property hook unset block with prior backing field compiles (#18171, zend_compile.c)
--FILE--
<?php
class C {
    private string $x = 'a';
    public string $x {
        get => $this->x;
        unset { unset($this->x); }
    }
}
$c = new C;
unset($c->x);
var_export(isset($c->x));
echo "\n";
--EXPECT--
false
