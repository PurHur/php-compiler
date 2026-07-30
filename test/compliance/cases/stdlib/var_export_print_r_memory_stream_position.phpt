--TEST--
var_export()/print_r() around fread/fgets/stream_get_line on php://memory keep position (#25084)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
fwrite($h, 'abcdef');
rewind($h);
$a = var_export(fread($h, 3), true);
$pos1 = ftell($h);
$b = var_export(fread($h, 10), true);
echo $a, '|', $b, ' pos=', $pos1, ',', ftell($h), "\n";

$h = fopen('php://temp', 'r+');
fwrite($h, 'abcdef');
rewind($h);
$a = print_r(fread($h, 3), true);
$pos1 = ftell($h);
$b = print_r(fread($h, 10), true);
echo $a, '|', $b, ' pos=', $pos1, ',', ftell($h), "\n";

$h = fopen('php://memory', 'r+');
fwrite($h, "ab\ncd\n");
rewind($h);
echo var_export(fgets($h), true), '|', var_export(fgets($h), true), ' pos=', ftell($h), "\n";

$h = fopen('php://memory', 'r+');
fwrite($h, 'abcdefghi');
rewind($h);
echo var_export(stream_get_line($h, 10, 'd'), true), '|';
echo var_export(stream_get_line($h, 10, 'g'), true), '|';
echo var_export(stream_get_line($h, 10, 'z'), true), ' pos=', ftell($h), "\n";
?>
--EXPECT--
'abc'|'def' pos=3,6
abc|def pos=3,6
'ab
'|'cd
' pos=6
'abc'|'ef'|'hi' pos=9
