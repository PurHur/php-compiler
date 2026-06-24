--TEST--
stdlib fread() length: named parameter (#11111, ext/standard/streams.c)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
fwrite($fp, 'hello');
rewind($fp);
echo fread($fp, length: 3), "\n";
fclose($fp);
?>
--EXPECT--
hel
