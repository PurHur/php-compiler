--TEST--
stdlib fgets() length: named parameter + N-1 byte cap (#10318, #10340, ext/standard/file.c)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

$h = fopen('php://memory', 'r+');
fwrite($h, "hello\n");
rewind($h);
$named = fgets($h, length: 3);
rewind($h);
$pos = fgets($h, 3);
var_export($named === $pos);
echo "\n";
var_export($named);
echo "\n";
fclose($h);
?>
--EXPECT--
true
'he'
