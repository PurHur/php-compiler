--TEST--
ceil / floor Reflection param int|float and return float (#25595, ext/standard/math.stub.php)
--FILE--
<?php
declare(strict_types=1);
foreach (['ceil', 'floor'] as $f) {
    $r = new ReflectionFunction($f);
    $p = $r->getParameters()[0];
    echo $f, ' param=', $p->getName(), ':', (string) $p->getType(),
        ' ret=', (string) $r->getReturnType(), "\n";
}
echo gettype(ceil(1.2)), "\n";
echo gettype(floor(1.2)), "\n";
var_export(ceil(1.2));
echo "\n";
var_export(floor(1.2));
echo "\n";
--EXPECT--
ceil param=num:int|float ret=float
floor param=num:int|float ret=float
double
double
2.0
1.0
