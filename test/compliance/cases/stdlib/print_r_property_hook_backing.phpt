--TEST--
print_r()/var_dump() hide separate property-hook backing storage (#8854, zend_property_hooks.c)
--FILE--
<?php
class Hooked {
    public string $x {
        get => $this->backing;
        set => $this->backing = $value;
    }
    private string $backing = 'secret';
}
$o = new Hooked();
ob_start();
print_r($o);
$pr = ob_get_clean();
echo str_contains($pr, 'backing') ? "LEAK\n" : "OK\n";
ob_start();
var_dump($o);
$vd = ob_get_clean();
echo str_contains($vd, 'backing') ? "LEAK\n" : "OK\n";
echo var_export($o, true), "\n";
--EXPECT--
OK
OK
Hooked::__set_state(array (
  'x' => 'secret',
))
