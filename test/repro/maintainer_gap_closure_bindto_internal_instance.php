<?php

declare(strict_types=1);

$c = function (): int {
    return 42;
};

$std = $c->bindTo(new stdClass());
if (!$std instanceof Closure) {
    echo 'fail: bindTo(new stdClass()) expected Closure, got NULL' . PHP_EOL;
    exit(1);
}

$ao = $c->bindTo(new ArrayObject());
if (!$ao instanceof Closure) {
    echo 'fail: bindTo(new ArrayObject()) expected Closure, got NULL' . PHP_EOL;
    exit(1);
}

$explicit = $c->bindTo(new stdClass(), 'stdClass');
if (null !== $explicit) {
    echo 'fail: bindTo(new stdClass(), stdClass) expected null, got Closure' . PHP_EOL;
    exit(1);
}

echo "ok\n";
