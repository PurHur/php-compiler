--TEST--
Language: property hook + sibling property + detached same-name backing compiles (#16936, zend_compile.c)
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
echo 'compile-ok x=' . $c->x . ' y=' . $c->y . "\n";
--EXPECT--
compile-ok x=1 y=a
