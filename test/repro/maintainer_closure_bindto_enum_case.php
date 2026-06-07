<?php

enum E: string
{
    case A = 'x';
}

$c = function () {
    return $this;
};
$r = $c->bindTo(E::A);
var_export($r instanceof Closure);
echo "\n";
var_export($r() === E::A);
echo "\n";
