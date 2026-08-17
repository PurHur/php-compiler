<?php
// ArrayAccess by-ref offsetGet: ++ and .= work; += / -= / *= TypeError mixed <op> int.
error_reporting(E_ALL);

class A implements ArrayAccess
{
    private $d = [0 => 1];

    public function offsetExists(mixed $k): bool
    {
        return isset($this->d[$k]);
    }

    public function &offsetGet(mixed $k): mixed
    {
        return $this->d[$k];
    }

    public function offsetSet(mixed $k, mixed $v): void
    {
        $this->d[$k] = $v;
    }

    public function offsetUnset(mixed $k): void
    {
        unset($this->d[$k]);
    }
}

$inc = new A();
try {
    $inc[0]++;
    echo 'inc=', $inc[0], "\n";
} catch (Throwable $e) {
    echo 'inc_err=', get_class($e), ': ', $e->getMessage(), "\n";
}

$plus = new A();
try {
    $plus[0] += 2;
    echo 'plus=', $plus[0], "\n";
} catch (Throwable $e) {
    echo 'plus_err=', get_class($e), ': ', $e->getMessage(), "\n";
}

$mul = new A();
try {
    $mul[0] *= 3;
    echo 'mul=', $mul[0], "\n";
} catch (Throwable $e) {
    echo 'mul_err=', get_class($e), ': ', $e->getMessage(), "\n";
}
