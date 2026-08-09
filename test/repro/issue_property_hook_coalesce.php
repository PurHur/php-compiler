<?php
class C {
    public string $x {
        get { echo "GET\n"; return $this->backing; }
        set => $this->backing = $value;
    }
    private string $backing = 'a';
}
$c = new C();
var_dump($c->x ?? 'default');
echo "ok\n";

class V {
    public string $hello {
        get => 'hello';
    }
}
$v = new V();
var_dump($v->hello ?? 'default');

class N {
    public ?string $y {
        get => null;
    }
}
$n = new N();
var_dump($n->y ?? 'default');
