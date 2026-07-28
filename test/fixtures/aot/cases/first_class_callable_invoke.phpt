--TEST--
AOT: first-class callable invoke — function, builtin, static, instance (#24166)
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
