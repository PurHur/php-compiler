--TEST--
Language: nullsafe method first-class callable must compile-fatal (#19727, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

class T {
    public function m(int $x): int { return $x * 2; }
}

$n = null;
$g = $n?->m(...);
var_export($g);
echo PHP_EOL;
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Cannot combine nullsafe operator with Closure creation
