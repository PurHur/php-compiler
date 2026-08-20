<?php
class C { public string $p = 'hi'; }
$c = new C;
unset($c->p);
var_dump(isset($c->p));
try {
    echo $c->p;
} catch (Error $e) {
    echo 'err';
}
echo "\n";

class T { public int $i = 0; }
$t = new T;
unset($t->i);
var_dump(isset($t->i));
try {
    echo $t->i;
} catch (Throwable $e) {
    echo get_class($e);
}
echo "\n";
