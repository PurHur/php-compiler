<?php

declare(strict_types=1);

$m = new ReflectionMethod('DateTime', 'format');
if (!method_exists($m, 'getTentativeReturnType')) {
    echo "fail: getTentativeReturnType missing\n";
    exit(1);
}
$name = $m->getTentativeReturnType()?->getName();
if ('string' !== $name) {
    echo 'fail: name=', var_export($name, true), "\n";
    exit(1);
}
echo "ok name={$name}\n";
