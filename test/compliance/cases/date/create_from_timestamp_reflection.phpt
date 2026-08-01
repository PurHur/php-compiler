--TEST--
stdlib DateTime::createFromTimestamp Reflection + named args (#26097, ext/date/php_date.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

foreach (['DateTime', 'DateTimeImmutable'] as $c) {
    $rf = new ReflectionMethod($c, 'createFromTimestamp');
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
echo DateTimeImmutable::createFromTimestamp(timestamp: 0)->getTimestamp(), "\n";
echo DateTimeImmutable::createFromTimestamp(1)->getTimestamp(), "\n";
echo DateTime::createFromTimestamp(timestamp: 2)->getTimestamp(), "\n";
--EXPECT--
DateTime arity=1 req=1
  params=timestamp:int|float:REQ
DateTimeImmutable arity=1 req=1
  params=timestamp:int|float:REQ
0
1
2
