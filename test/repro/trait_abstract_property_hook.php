<?php
trait T {
    public string $x { get; set; }
}
class C {
    use T;
}
$c = new C();
$c->x = 'a';
echo $c->x, "\n";
