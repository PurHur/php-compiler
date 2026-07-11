--TEST--
stdlib serialize() object graph r:/R: markers (#12082, ext/standard/var.c)
--FILE--
<?php
class Node
{
    public $self;
}

$n = new Node();
$n->self = $n;
echo serialize($n), "\n";

$o = new stdClass();
$a = [$o, $o];
echo serialize($a), "\n";

$u = unserialize(serialize($a));
var_export($u[0] === $u[1]);
echo "\n";
--EXPECT--
O:4:"Node":1:{s:4:"self";r:1;}
a:2:{i:0;O:8:"stdClass":0:{}i:1;r:2;}
true
