--TEST--
PHP 8.1 first-class callable invoke syntax (($f)(...)) parity (issue #4437)
--FILE--
<?php
function add(int $a, int $b): int { return $a + $b; }

$f = add(...);
echo ($f)(2, 3), "\n";

$c = strlen(...);
echo $c('abc'), "\n";

class C {
    public static function s(int $x): int { return $x + 1; }
    public function i(int $x): int { return $x + 2; }
}

$cs = C::s(...);
echo $cs(9), "\n";

$ci = (new C())->i(...);
echo $ci(9), "\n";
--EXPECT--
5
3
10
11

