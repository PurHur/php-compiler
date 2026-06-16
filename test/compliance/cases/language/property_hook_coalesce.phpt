--TEST--
Property hook ?? — null-check backing without get hook (#8902, zend_property_hooks.c)
--FILE--
<?php
class C {
    public string $x {
        get { throw new Exception('get must not run for ??'); }
        set => $this->backing = $value;
    }
    private string $backing = 'a';
}
$c = new C();
var_dump($c->x ?? 'default');
echo "ok\n";

class U {
    public string $x {
        get { throw new Exception('get must not run for unset ??'); }
        set => $this->backing = $value;
    }
    private string $backing;
}
$u = new U();
unset($u->x);
var_dump($u->x ?? 'default');
echo "unset ok\n";
--EXPECT--
string(1) "a"
ok
string(7) "default"
unset ok
