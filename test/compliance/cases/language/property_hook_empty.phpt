--TEST--
empty() on property hooks — get hook runs when present; set-only probes backing (#10680, #9832)
--FILE--
<?php
class VirtualGetOnly {
    public ?string $x {
        get { echo "get runs for empty()\n"; return null; }
    }
}
$v = new VirtualGetOnly();
var_dump(empty($v->x));
echo "virtual ok\n";

class C {
    public string $x {
        get { echo "get runs for empty()\n"; return $this->backing; }
        set => $this->backing = $value;
    }
    private string $backing = 'a';
}
$c = new C();
var_dump(empty($c->x));
echo "ok\n";

class NullBacking {
    public ?string $x {
        get => null;
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
var_dump(empty($w->x));
--EXPECT--
get runs for empty()
bool(true)
virtual ok
get runs for empty()
bool(false)
ok
bool(true)
bool(false)
