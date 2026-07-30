--TEST--
stdlib DateTime::createFromFormat Reflection ?DateTimeZone=null (#25166, ext/date/php_date.stub.php)
--FILE--
<?php
declare(strict_types=1);

foreach (['DateTime', 'DateTimeImmutable'] as $c) {
    $rf = new ReflectionMethod($c, 'createFromFormat');
    $parts = [];
    foreach ($rf->getParameters() as $p) {
        $parts[] = $p->getName()
            .':'
            .($p->hasType() ? (string) $p->getType() : '-')
            .':null='.(($p->hasType() && $p->getType()->allowsNull()) ? '1' : '0')
            .':'.($p->isDefaultValueAvailable() ? json_encode($p->getDefaultValue()) : '-');
    }
    echo $c, ' params=', implode(',', $parts), "\n";
}
--EXPECT--
DateTime params=format:string:null=0:-,datetime:string:null=0:-,timezone:?DateTimeZone:null=1:null
DateTimeImmutable params=format:string:null=0:-,datetime:string:null=0:-,timezone:?DateTimeZone:null=1:null
