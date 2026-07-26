--TEST--
fclose/feof/fgetc/ftell/rewind/fflush/fseek stream named args (VM, issue #23241)
--FILE--
<?php
$fp = fopen('php://memory', 'r+');
fwrite($fp, 'xy');
var_export(rewind(stream: $fp));
echo PHP_EOL;
var_export(feof(stream: $fp));
echo PHP_EOL;
echo fgetc(stream: $fp), PHP_EOL;
echo ftell(stream: $fp), PHP_EOL;
var_export(0 === fseek(stream: $fp, offset: 0));
echo PHP_EOL;
var_export(fflush(stream: $fp));
echo PHP_EOL;
var_export(fclose(stream: $fp));
echo PHP_EOL;
foreach (['fclose', 'feof', 'fgetc', 'ftell', 'rewind', 'fflush', 'fseek'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), PHP_EOL;
}
--EXPECT--
true
false
x
1
true
true
true
fclose:stream
feof:stream
fgetc:stream
ftell:stream
rewind:stream
fflush:stream
fseek:stream,offset,whence
