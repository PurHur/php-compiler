--TEST--
ReflectionProperty::setRawValue()/getRawValue() — bypass property hooks (#6451)
--FILE--
<?php
class Box6451 {
    private string $label = 'init';
    public string $name {
        get => strtoupper($this->label);
        set => $this->label = strtolower($value);
    }
}

$o = new Box6451();
$rp = new ReflectionProperty(Box6451::class, 'name');

var_export(method_exists($rp, 'setRawValue'));
echo "\n";
var_export(method_exists($rp, 'getRawValue'));
echo "\n";

$rp->setRawValue($o, 'RAW');
echo $o->name, "\n";
echo $rp->getRawValue($o), "\n";
--EXPECT--
true
true
RAW
RAW
