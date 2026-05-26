--TEST--
stdlib uniqid() JIT/AOT path (#2219)
--FILE--
<?php
$a = uniqid();
$b = uniqid();
echo strlen($a) === 13 ? "len13\n" : "bad\n";
echo strlen($b) === 13 ? "two\n" : "bad\n";
$p = uniqid('jit_');
echo strpos($p, 'jit_') === 0 ? "prefix\n" : "bad\n";
$e = uniqid('', true);
echo strlen($e) > 21 && strpos($e, ".") !== false ? "entropy\n" : "bad\n";
--EXPECT--
len13
two
prefix
entropy
