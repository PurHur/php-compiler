--TEST--
stdlib mktime/gmmktime Reflection hour required + ?int null defaults (#25147, ext/date/php_date.stub.php)
--FILE--
<?php
declare(strict_types=1);

foreach (['mktime', 'gmmktime'] as $fn) {
    $parts = [];
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        $parts[] = $p->getName()
            .':opt='.($p->isOptional() ? '1' : '0')
            .':'.($p->hasType() ? (string) $p->getType() : '-')
            .':'.($p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '-');
    }
    echo $fn, ' params=', implode(',', $parts), "\n";
}
try {
    mktime();
    echo "zero_ok\n";
} catch (ArgumentCountError $e) {
    echo "zero_ace\n";
}
--EXPECT--
mktime params=hour:opt=0:int:-,minute:opt=1:?int:NULL,second:opt=1:?int:NULL,month:opt=1:?int:NULL,day:opt=1:?int:NULL,year:opt=1:?int:NULL
gmmktime params=hour:opt=0:int:-,minute:opt=1:?int:NULL,second:opt=1:?int:NULL,month:opt=1:?int:NULL,day:opt=1:?int:NULL,year:opt=1:?int:NULL
zero_ace
