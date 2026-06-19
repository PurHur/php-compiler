--TEST--
Language: property hook + same-name private backing field merges (#9831, zend_compile.c)
--FILE--
<?php
class C {
    public int $x {
        get => $this->x;
        set => $this->x = $value;
    }
    public string $y = 'a';
    private int $x = 1;
}
$c = new C();
echo 'x=' . $c->x . ' y=' . $c->y . "\n";
--EXPECT--
x=1 y=a
