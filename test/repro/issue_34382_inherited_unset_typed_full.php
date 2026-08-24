<?php
class A { public string $p = 'hi'; }
class B extends A {}
$b = new B;
unset($b->p);
var_dump(isset($b->p));
try {
    echo $b->p;
} catch (Error $e) {
    echo get_class($e);
}
echo "\n";

class A2 { public string $p = 'hi'; }
$a = new A2;
unset($a->p);
try {
    echo $a->p;
} catch (Error $e) {
    echo get_class($e);
}
echo "\n";
