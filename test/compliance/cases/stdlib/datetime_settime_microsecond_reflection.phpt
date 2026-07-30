--TEST--
stdlib DateTime::setTime Reflection microsecond + Immutable second optional (#25400, ext/date/php_date.stub.php)
--FILE--
<?php
declare(strict_types=1);

foreach (['DateTime', 'DateTimeImmutable'] as $c) {
    $rf = new ReflectionMethod($c, 'setTime');
    echo $c, ' arity=', $rf->getNumberOfParameters(),
        ' req=', $rf->getNumberOfRequiredParameters(), "\n";
    $parts = [];
    foreach ($rf->getParameters() as $p) {
        $parts[] = $p->getName()
            .':'
            .($p->hasType() ? (string) $p->getType() : '-')
            .':'.($p->isOptional() ? 'OPT' : 'REQ')
            .':'.($p->isDefaultValueAvailable() ? json_encode($p->getDefaultValue()) : '-');
    }
    echo '  params=', implode(',', $parts), "\n";
}
$d = new DateTimeImmutable('2020-01-01');
echo $d->setTime(hour: 1, minute: 2, second: 3, microsecond: 4)->format('H:i:s.u'), "\n";
$m = new DateTime('2020-01-01');
$m->setTime(hour: 5, minute: 6, second: 7, microsecond: 8);
echo $m->format('H:i:s.u'), "\n";
--EXPECT--
DateTime arity=4 req=2
  params=hour:int:REQ:-,minute:int:REQ:-,second:int:OPT:0,microsecond:int:OPT:0
DateTimeImmutable arity=4 req=2
  params=hour:int:REQ:-,minute:int:REQ:-,second:int:OPT:0,microsecond:int:OPT:0
01:02:03.000004
05:06:07.000008
