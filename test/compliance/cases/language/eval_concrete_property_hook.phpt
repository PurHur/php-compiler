--TEST--
eval() concrete property hooks compile and dispatch (#7031, zend_property_hooks.c)
--FILE--
<?php
$ok = eval('class Evaled {
    public string $name {
        get => strtoupper($this->name ?? "");
        set => $this->name = strtolower($value);
    }
    function __construct() { $this->name = "x"; }
}');
if ($ok === false) {
    echo "eval-failed\n";
    exit(1);
}
$o = new Evaled();
$o->name = 'AbC';
echo $o->name, "\n";
?>
--EXPECT--
ABC
