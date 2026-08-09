--TEST--
Language: by-ref method return of $this->prop binds live property (#29456)
--FILE--
<?php
class C {
    public string $x = "a";
    private string $p = "priv";
    public array $a = [1, 2, 3];

    public function &get() {
        return $this->x;
    }

    public function &getPriv() {
        return $this->p;
    }

    public function &item(int $i) {
        return $this->a[$i];
    }
}

function takesRef(&$v) {
    $v = "z";
}

$c = new C;
$r =& $c->get();
$r = "b";
echo $c->x, "\n";

$c2 = new C;
takesRef($c2->get());
echo $c2->x, "\n";

$c3 = new C;
$r3 =& $c3->getPriv();
$r3 = "q";
echo $c3->getPriv(), "\n";

$c4 = new C;
$r4 =& $c4->item(1);
$r4 = 99;
echo $c4->a[1], "\n";
?>
--EXPECT--
b
z
q
99
