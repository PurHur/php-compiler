<?php
abstract class A {
    public string $p {
        get;
        set;
    }
}
class B extends A {
    public string $p {
        get => $this->p;
        set => $this->p = $value;
    }
}
$b = new B();
$b->p = 'hi';
echo $b->p, "\n";
