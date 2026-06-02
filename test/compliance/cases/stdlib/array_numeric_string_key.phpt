--TEST--
stdlib array numeric-string keys — "1" matches int key 1 (Zend zend_hash.c parity)
--FILE--
<?php
$a = [1 => 'v'];
echo array_key_exists('1', $a) ? 'y' : 'n', "\n";
echo isset($a['1']) ? 'y' : 'n', "\n";
echo $a['1'] ?? 'missing', "\n";

$b = ['01' => 'leading'];
echo array_key_exists('01', $b) ? 'y' : 'n', "\n";
echo array_key_exists('1', $b) ? 'y' : 'n', "\n";
echo isset($b['01']) ? 'y' : 'n', "\n";
--EXPECT--
y
y
v
y
n
y
