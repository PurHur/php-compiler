--TEST--
stdlib intval/floatval/strval/boolval Reflection/named params (#23334, basic_functions.stub.php)
--FILE--
<?php
$ri = new ReflectionFunction('intval');
echo 'intval=';
foreach ($ri->getParameters() as $p) {
    echo $p->getName(), ',';
}
echo "\n";
echo intval(value: 'ff', base: 16), "\n";
try {
    intval(var: 'ff', base: 16);
    echo "legacy intval ok\n";
} catch (Throwable $e) {
    echo 'legacy intval ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}

$rf = new ReflectionFunction('floatval');
echo 'floatval=';
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), ',';
}
echo "\n";
var_export(floatval(value: '1.5'));
echo "\n";

$rs = new ReflectionFunction('strval');
echo 'strval=';
foreach ($rs->getParameters() as $p) {
    echo $p->getName(), ',';
}
echo "\n";
var_export(strval(value: 42));
echo "\n";

$rb = new ReflectionFunction('boolval');
echo 'boolval=';
foreach ($rb->getParameters() as $p) {
    echo $p->getName(), ',';
}
echo "\n";
var_export(boolval(value: 1));
echo "\n";
?>
--EXPECT--
intval=value,base,
255
legacy intval ERR=Error: Unknown named parameter $var
floatval=value,
1.5
strval=value,
'42'
boolval=value,
true
