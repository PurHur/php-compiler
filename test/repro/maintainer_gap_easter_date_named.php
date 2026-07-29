<?php

declare(strict_types=1);

$r = new ReflectionFunction('easter_date');
echo 'arity=', $r->getNumberOfParameters(), ' names=';
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ($p->isOptional() ? '=' : ''), ',';
}
echo ' req=', $r->getNumberOfRequiredParameters(), PHP_EOL;

try {
    echo 'year=', easter_date(year: 2024), PHP_EOL;
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
try {
    echo 'mode=', easter_date(mode: 0, year: 2024), PHP_EOL;
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
