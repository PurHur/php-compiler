--TEST--
isset() on property hooks probes backing without get hook (#8917, zend_property_hooks.c)
--FILE--
<?php
class C {
    public ?string $x {
        get { throw new Exception('get must not run for isset'); }
    }
}
$c = new C();
var_dump(isset($c->x));
echo "ok\n";

class Backed {
    public string $x {
        get { throw new Exception('get must not run'); }
        set => $this->backing = $value;
    }
    private string $backing = 'a';
}
$b = new Backed();
var_dump(isset($b->x));
echo "backed ok\n";
--EXPECT--
bool(false)
ok
bool(true)
backed ok
