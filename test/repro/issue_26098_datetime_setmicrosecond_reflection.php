<?php
declare(strict_types=1);

// #26098 — DateTime/DateTimeImmutable::setMicrosecond Reflection + named args (PROFILE=8.4)
$d = new DateTimeImmutable('2020-01-01 00:00:00.000000');
foreach ([DateTime::class, DateTimeImmutable::class] as $c) {
    $m = new ReflectionMethod($c, 'setMicrosecond');
    $n = [];
    foreach ($m->getParameters() as $p) {
        $n[] = $p->getName().':'.($p->hasType() ? (string) $p->getType() : '-');
    }
    echo $c, '::setMicrosecond arity=', $m->getNumberOfParameters(),
        ' [', implode(',', $n), ']', PHP_EOL;
}
echo $d->setMicrosecond(microsecond: 123456)->format('u'), PHP_EOL;
echo $d->setMicrosecond(654321)->format('u'), PHP_EOL;
$m = new DateTime('2020-01-01 00:00:00.000000');
$m->setMicrosecond(microsecond: 111111);
echo $m->format('u'), PHP_EOL;
