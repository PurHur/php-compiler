--TEST--
Nested new IteratorIterator(new DirectoryIterator) after @expr (#24368, Zend/zend_compile.c)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc_atndi_' . getmypid();
@mkdir($dir);
file_put_contents($dir . '/x.txt', '1');
$it = new IteratorIterator(new DirectoryIterator($dir));
$n = 0;
foreach ($it as $f) { $n++; }
echo "count=$n\n";
unlink($dir . '/x.txt');
rmdir($dir);
// no-@ control
$dir2 = sys_get_temp_dir() . '/phpc_atndi2_' . getmypid();
mkdir($dir2);
file_put_contents($dir2 . '/x.txt', '1');
$it2 = new IteratorIterator(new DirectoryIterator($dir2));
$n2 = 0;
foreach ($it2 as $f) { $n2++; }
echo "count=$n2\n";
unlink($dir2 . '/x.txt');
rmdir($dir2);
// pre-bound inner after @
$dir3 = sys_get_temp_dir() . '/phpc_atndi3_' . getmypid();
@mkdir($dir3);
file_put_contents($dir3 . '/x.txt', '1');
$di = new DirectoryIterator($dir3);
$it3 = new IteratorIterator($di);
$n3 = 0;
foreach ($it3 as $f) { $n3++; }
echo "count=$n3\n";
unlink($dir3 . '/x.txt');
rmdir($dir3);
--EXPECT--
count=3
count=3
count=3
