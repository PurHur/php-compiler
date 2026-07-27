--TEST--
convert_uuencode/convert_uudecode Reflection/named params match php-src stubs (#23784, ext/standard/basic_functions.stub.php)
--FILE--
<?php
foreach (['convert_uuencode', 'convert_uudecode'] as $fn) {
    $rf = new ReflectionFunction($fn);
    foreach ($rf->getParameters() as $p) {
        echo $fn, '_param:', $p->getName(), PHP_EOL;
    }
}
$enc = convert_uuencode(string: 'hi');
echo convert_uudecode(string: $enc), PHP_EOL;
try {
    convert_uuencode(data: 'hi');
    echo "data accepted\n";
} catch (Throwable $e) {
    echo 'data:', $e->getMessage(), PHP_EOL;
}
--EXPECT--
convert_uuencode_param:string
convert_uudecode_param:string
hi
data:Unknown named parameter $data
