<?php
declare(strict_types=1);

// #25400 — DateTime/DateTimeImmutable::setTime microsecond Reflection + named args
foreach ([DateTime::class, DateTimeImmutable::class] as $c) {
    $rf = new ReflectionMethod($c, 'setTime');
    echo $c, ' arity=', $rf->getNumberOfParameters(),
        ' req=', $rf->getNumberOfRequiredParameters(), "\n";
    foreach ($rf->getParameters() as $p) {
        echo '  ', $p->getName(),
            ' ', ($p->isOptional() ? 'OPT' : 'REQ'),
            ' type=', ($p->hasType() ? (string) $p->getType() : '-');
        if ($p->isDefaultValueAvailable()) {
            echo ' def=', var_export($p->getDefaultValue(), true);
        }
        echo "\n";
    }
}
$d = new DateTimeImmutable('2020-01-01');
echo $d->setTime(hour: 1, minute: 2, second: 3, microsecond: 4)->format('H:i:s.u'), "\n";
