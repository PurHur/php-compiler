<?php

declare(strict_types=1);

$cl = function (string|int $x) {
};
$r = new ReflectionFunction($cl);
$t = $r->getParameters()[0]->getType();
echo $t instanceof ReflectionUnionType ? "union-ok\n" : "union-bad\n";
echo $t->getTypes()[0]->getName(), '|', $t->getTypes()[1]->getName(), "\n";