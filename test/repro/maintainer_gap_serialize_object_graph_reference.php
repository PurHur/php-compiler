<?php

declare(strict_types=1);

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
