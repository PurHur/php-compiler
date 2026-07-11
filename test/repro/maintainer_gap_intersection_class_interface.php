<?php
interface IntersectionIface {}
class IntersectionBase {}
class IntersectionConcrete extends IntersectionBase implements IntersectionIface {}

function accepts_intersection(IntersectionBase&IntersectionIface $value): void {}

function returns_intersection(): IntersectionBase&IntersectionIface {
    return new IntersectionConcrete();
}

class Holder {
    public IntersectionBase&IntersectionIface $prop;

    public function __construct() {
        $this->prop = new IntersectionConcrete();
    }
}

$c = new IntersectionConcrete();
accepts_intersection($c);
echo get_class(returns_intersection()), "\n";
$h = new Holder();
echo get_class($h->prop), "\n";
echo "ok\n";
