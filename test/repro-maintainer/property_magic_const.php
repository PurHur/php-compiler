<?php
class C {
    public string $p {
        get => __PROPERTY__;
    }
}
$c = new C();
echo $c->p, "\n";
