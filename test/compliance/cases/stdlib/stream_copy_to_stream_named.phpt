--TEST--
stdlib stream_copy_to_stream() from:/to:/length: named parameters (#11079, ext/standard/streamsfuncs.c)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

$src = fopen('php://memory', 'r+');
$dst = fopen('php://memory', 'w+');
fwrite($src, 'hello');
rewind($src);
$n = stream_copy_to_stream(from: $src, to: $dst, length: 3);
fclose($src);
fclose($dst);
echo (3 === $n) ? "OK\n" : "FAIL\n";
?>
--EXPECT--
OK
