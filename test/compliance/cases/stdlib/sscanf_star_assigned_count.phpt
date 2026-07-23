--TEST--
sscanf()/fscanf() %* successful suppressions count in by-ref return (#22713, re-#9560)
--FILE--
<?php
$a = $b = null;
echo sscanf('1 2 3', '%d%*d%d', $a, $b), " a=$a b=$b\n";

$num = null;
echo sscanf('123abc', '%d%*s', $num), " num=$num\n";

$f = fopen('php://memory', 'r+');
fwrite($f, "1 2 3\n");
rewind($f);
$a = $b = null;
echo fscanf($f, '%d%*d%d', $a, $b), " a=$a b=$b\n";
fclose($f);

// Array-return form must stay Zend-shaped (suppressions omitted from list).
var_export(sscanf('123 456', '%*d %d'));
echo "\n";
--EXPECT--
3 a=1 b=3
2 num=123
3 a=1 b=3
array (
  0 => 456,
)
