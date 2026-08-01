--TEST--
stdlib zlib_encode Reflection level optional default -1 (#25588)
--FILE--
<?php
declare(strict_types=1);

$r = new ReflectionFunction('zlib_encode');
echo 'req=', $r->getNumberOfRequiredParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' opt=', $p->isOptional() ? '1' : '0';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo ' def=', json_encode($p->getDefaultValue());
    }
    echo "\n";
}
$a = zlib_encode(data: 'hi', encoding: ZLIB_ENCODING_RAW);
$b = zlib_encode('hi', ZLIB_ENCODING_RAW);
echo 'named_eq=', ($a === $b) ? '1' : '0', "\n";
--EXPECT--
req=2
data opt=0
encoding opt=0
level opt=1 def=-1
named_eq=1
