--TEST--
Language: AOT string assignment shares refcount (copy-on-write) — not eager memcpy (#36192)
--FILE--
<?php
$s = str_repeat('x', 64);
$u = $s;
$v = $s;
echo strlen($u), '|', strlen($v), "\n";

$a = 'hello';
$b = $a;
$a = 'bye';
echo $b, "\n";
--EXPECT--
64|64
hello
