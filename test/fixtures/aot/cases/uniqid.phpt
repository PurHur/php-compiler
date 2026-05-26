--TEST--
AOT uniqid() prefix and entropy (issue #2219)
--FILE--
<?php
$a = uniqid();
$b = uniqid();
echo strlen($a) === 13 ? "len13\n" : "bad\n";
echo strlen($b) === 13 ? "two\n" : "bad\n";
$p = uniqid('aot_');
echo strpos($p, 'aot_') === 0 ? "prefix\n" : "bad\n";
$e = uniqid('', true);
echo strlen($e) > 21 && strpos($e, ".") !== false ? "entropy\n" : "bad\n";
--EXPECT--
len13
two
prefix
entropy
