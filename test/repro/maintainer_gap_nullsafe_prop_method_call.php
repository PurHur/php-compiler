<?php
// Zend parity: nullsafe method call on property receiver (re-#5308 / Zend zend_compile.c).
// Expect: 42 / 42 / 6 / NULL — VM today returns the Recv object for property?->method().
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
