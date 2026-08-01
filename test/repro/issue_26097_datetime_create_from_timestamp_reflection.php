<?php
declare(strict_types=1);

// #26097 — DateTime/DateTimeImmutable::createFromTimestamp Reflection + named args (PROFILE=8.4)
foreach ([DateTime::class, DateTimeImmutable::class] as $c) {
    $m = new ReflectionMethod($c, 'createFromTimestamp');
    $n = [];
    foreach ($m->getParameters() as $p) {
        $n[] = $p->getName().':'.($p->hasType() ? (string) $p->getType() : '-');
    }
    echo $c, '::createFromTimestamp arity=', $m->getNumberOfParameters(),
        ' [', implode(',', $n), ']', PHP_EOL;
}
echo DateTimeImmutable::createFromTimestamp(timestamp: 0)->getTimestamp(), PHP_EOL;
echo DateTimeImmutable::createFromTimestamp(1)->getTimestamp(), PHP_EOL;
