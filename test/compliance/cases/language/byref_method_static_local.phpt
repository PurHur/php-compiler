--TEST--
By-ref instance method parameters work when callee has function-static locals (issue #6739)
--FILE--
<?php
class C
{
    public function f(int &$x): void
    {
        static $n = 0;
        ++$n;
        $x = $n;
    }
}

$c = new C();
$x = 1;
$c->f($x);
$c->f($x);
echo $x, "\n";
--EXPECT--
2
--CREDITS--
PurHur/php-compiler issue #6739
