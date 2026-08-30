<?php
// #35874 — AOT global-scope instance property assign must match Zend (leftover of #35863).
class S {
    public $p = 'b';
}
class I {
    public $n = 1;
}

$s = new S;
$s->p = 'c';
echo 'str=', $s->p, "\n";

$i = new I;
$i->n = 2;
echo 'int=', $i->n, "\n";

unset($s->p);
$s->p = 'd';
echo 'unset=', $s->p, "\n";

// #23514 regression guard: ?: false arm must not overwrite a live property.
$o = new S;
$o->p = 'keep';
$x = false ? $o->p : 'null';
echo 'ternary=', $x, '|prop=', $o->p, "\n";
