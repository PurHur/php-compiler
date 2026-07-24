--TEST--
Language: nullsafe ?-> method call on property receiver invokes method (#22753, re-#5308, Zend/zend_compile.c)
--FILE--
<?php
class Holder
{
    public ?Recv $b = null;
}

class Recv
{
    public function f(): int
    {
        return 42;
    }

    public function g(int $n): int
    {
        return $n * 2;
    }
}

$h = new Holder();
$h->b = new Recv();
$direct = new Recv();

echo 'direct=', var_export($direct?->f(), true), "\n";
echo 'prop=', var_export($h->b?->f(), true), "\n";
echo 'chain=', var_export($h?->b?->f(), true), "\n";
echo 'arg=', var_export($h->b?->g(3), true), "\n";
$h->b = null;
echo 'short=', var_export($h->b?->f(), true), "\n";
?>
--EXPECT--
direct=42
prop=42
chain=42
arg=6
short=NULL
