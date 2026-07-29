--TEST--
Language: AOT static arrow/closure must not SIGSEGV (storeStaticClosureFlag load-on-i1, #24836)
--FILE--
<?php
$f = static fn (string $p): bool => $p !== '.';
$g = static function ($p) { return $p; };
var_export($f('.'));
echo "\n";
echo $g(1), "\n";
?>
--EXPECT--
false
1
