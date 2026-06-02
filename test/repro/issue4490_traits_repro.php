<?php

trait TA { public function f() { return "A"; } }
trait TB { public function f() { return "B"; } public function g() { return "Bg"; } }

class C1 { use TA; }

class C2 {
    use TA, TB {
        TA::f insteadof TB;
        TB::f as fb;
    }
}

echo (new C1)->f(), "\n";
$c = new C2();
echo $c->f(), "\n";
echo $c->fb(), "\n";
echo $c->g(), "\n";

