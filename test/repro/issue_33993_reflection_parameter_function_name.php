<?php
// Repro #33993 — AOT ReflectionParameter / ReflectionFunction $name after construct.
function f($x) {}
$rp = new ReflectionParameter('f', 'x');
echo $rp->name, '|', $rp->getName(), PHP_EOL;
$rf = new ReflectionFunction('f');
echo $rf->name, '|', $rf->getName(), PHP_EOL;
