--TEST--
stdlib DateTime::setMicrosecond Reflection + named args (#26098, ext/date/php_date.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$d = new DateTimeImmutable('2020-01-01 00:00:00.000000');
foreach (['DateTime', 'DateTimeImmutable'] as $c) {
    $rf = new ReflectionMethod($c, 'setMicrosecond');
    echo $c, ' arity=', $rf->getNumberOfParameters(),
        ' req=', $rf->getNumberOfRequiredParameters(), "\n";
    $parts = [];
    foreach ($rf->getParameters() as $p) {
        $parts[] = $p->getName()
            .':'
            .($p->hasType() ? (string) $p->getType() : '-')
            .':'.($p->isOptional() ? 'OPT' : 'REQ');
    }
    echo '  params=', implode(',', $parts), "\n";
}
echo $d->setMicrosecond(microsecond: 123456)->format('u'), "\n";
echo $d->setMicrosecond(654321)->format('u'), "\n";
$m = new DateTime('2020-01-01 00:00:00.000000');
$m->setMicrosecond(microsecond: 111111);
echo $m->format('u'), "\n";
--EXPECT--
DateTime arity=1 req=1
  params=microsecond:int:REQ
DateTimeImmutable arity=1 req=1
  params=microsecond:int:REQ
123456
654321
111111
