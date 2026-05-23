--TEST--
stdlib urlencode() bootstrap AOT parity (concat path + non-empty check)
--FILE--
<?php
declare(strict_types=1);
$p = 'lib/' . 'JIT.php';
echo urlencode($p) !== '' ? '1' : '0';
echo "\n";
--EXPECT--
1
