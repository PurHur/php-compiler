<?php

declare(strict_types=1);

$cl = function (Iterator&Countable $x) {
};
$r = new ReflectionFunction($cl);
$t = $r->getParameters()[0]->getType();
echo $t instanceof ReflectionIntersectionType ? "intersection-ok\n" : "intersection-bad\n";
$types = $t->getTypes();
echo count($types), "\n";
echo $types[0]->getName(), '&', $types[1]->getName(), "\n";
