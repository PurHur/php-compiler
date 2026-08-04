--TEST--
AOT: ReflectionProperty::getRawValue/setRawValue plain + hooked (#27598, #6451)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class T {
    public string $x = "a";
}
$rp = new ReflectionProperty(T::class, "x");
$o = new T();
echo $rp->getRawValue($o), "\n";
$rp->setRawValue($o, "b");
echo $o->x, "\n";

class Box {
    private string $label = "init";
    public string $name {
        get => strtoupper($this->label);
        set => $this->label = strtolower($value);
    }
}
$b = new Box();
$rn = new ReflectionProperty(Box::class, "name");
$rn->setRawValue($b, "RAW");
echo $b->name, "\n";
echo $rn->getRawValue($b), "\n";
--EXPECT--
a
b
RAW
RAW
