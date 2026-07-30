--TEST--
stdlib date_create Reflection DateTime|false + defaults (#25392, ext/date/php_date.stub.php)
--FILE--
<?php
declare(strict_types=1);

foreach (['date_create', 'date_create_immutable'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, ' return=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'none',
        ' arity=', $rf->getNumberOfParameters(),
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
$d = date_create(datetime: '2020-01-02 03:04:05');
echo $d->format('Y-m-d H:i:s'), "\n";
--EXPECT--
date_create return=DateTime|false arity=2 req=0
  params=datetime:string:OPT:"now",timezone:?DateTimeZone:OPT:null
date_create_immutable return=DateTimeImmutable|false arity=2 req=0
  params=datetime:string:OPT:"now",timezone:?DateTimeZone:OPT:null
2020-01-02 03:04:05
