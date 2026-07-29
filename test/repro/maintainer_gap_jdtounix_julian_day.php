<?php

declare(strict_types=1);

$r = new ReflectionFunction('jdtounix');
echo 'Zend: arity=', $r->getNumberOfParameters(), ' names=';
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ',';
}
echo PHP_EOL;

$jd = gregoriantojd(1, 1, 1970);
try {
    echo 'named=', jdtounix(julian_day: $jd), PHP_EOL;
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
try {
    jdtounix(jday: $jd);
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
