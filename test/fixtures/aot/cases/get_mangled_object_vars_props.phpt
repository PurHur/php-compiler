--TEST--
AOT: get_mangled_object_vars() private/protected/public keys (#26797, ext/standard/var.c)
--FILE--
<?php
class A {
    private $x = 1;
    protected $y = 2;
    public $z = 3;
}
$m = get_mangled_object_vars(new A());
echo 'count=', count($m), "\n";
foreach ($m as $k => $v) {
    echo 'hex=', bin2hex($k), ' val=', $v, "\n";
}
--EXPECT--
count=3
hex=00410078 val=1
hex=002a0079 val=2
hex=7a val=3
