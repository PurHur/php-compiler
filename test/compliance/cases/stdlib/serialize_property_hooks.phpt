--TEST--
stdlib serialize()/unserialize() on objects with property hooks (#6474, ext/standard/var.c)
--FILE--
<?php
class C {
    private string $x = 'secret';
    public string $y { get => $this->x; set => $this->x = $value; }
}
$c = new C();
$s = serialize($c);
var_export($s);
echo "\n";
$u = unserialize($s);
var_export($u instanceof C);
echo "\n";
var_export($u->y);
echo "\n";
--EXPECT--
'O:1:"C":1:{s:1:"y";s:6:"secret";}'
true
'secret'
