--TEST--
empty() on property hooks checks separate backing without get hook (#8901, zend_property_hooks.c)
--FILE--
<?php
class C {
    public string $x {
        get { throw new Exception('get must not run for empty()'); }
        set => $this->backing = $value;
    }
    private string $backing = 'a';
}
$c = new C();
var_dump(empty($c->x));
echo "ok\n";

class NullBacking {
    public ?string $x {
        get { throw new Exception('get must not run'); }
        set => $this->backing = $value;
    }
    private ?string $backing = null;
}
$n = new NullBacking();
var_dump(empty($n->x));

class WriteOnly {
    public string $x {
        set => $this->x = strtoupper($value);
    }
}
$w = new WriteOnly();
$w->x = 'hi';
try {
    var_dump(empty($w->x));
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
bool(false)
ok
bool(true)
Error: Cannot read property WriteOnly::$x without get hook
