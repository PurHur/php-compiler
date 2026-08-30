--TEST--
AOT: instance $obj->prop = value in global scope (leftover of #23514 / #35863) (#35874)
--FILE--
<?php
class S { public $p = 'b'; }
$s = new S;
$s->p = 'c';
echo $s->p, '|';

class I { public $n = 1; }
$i = new I;
$i->n = 2;
echo $i->n, '|';

class T { public string $p = 'b'; }
$t = new T;
$t->p = 'c';
echo $t->p, '|';

$u = new S;
unset($u->p);
$u->p = 'd';
echo $u->p, '|';

$q = new S;
$x = false ? $q->p : 'null';
echo $q->p, '|', $x, "\n";
--EXPECT--
c|2|c|d|b|null
--EXPECT_EXIT--
0
