--TEST--
empty() on property hooks with explicit same-name backing must invoke get hook (#16935)
--FILE--
<?php
class C {
    public int $x {
        get { echo "GET\n"; return $this->x; }
        set => $this->x = $value;
    }
    private int $x = 0;
}
$c = new C();
ob_start();
var_dump(empty($c->x));
$hookOutput = ob_get_clean();
echo $hookOutput;

class D {
    public string $y {
        get { echo "GET2\n"; return $this->y; }
        set => $this->y = $value;
    }
    private string $y = 'hi';
}
$d = new D();
ob_start();
var_dump(empty($d->y));
$hookOutput2 = ob_get_clean();
echo $hookOutput2;
--EXPECT--
GET
bool(true)
GET2
bool(false)

