--TEST--
unset() hook with preceding same-name backing field compiles and clears backing (#18171, zend_compile.c)
--FILE--
<?php
class C {
    private string $x = 'a';
    public string $x {
        get => $this->x;
        unset { unset($this->x); }
    }
}
$c = new C();
unset($c->x);
echo "PASS_PROPERTY_HOOK_UNSET\n";
var_export(isset($c->x));
echo "\n";
--EXPECT--
PASS_PROPERTY_HOOK_UNSET
false
