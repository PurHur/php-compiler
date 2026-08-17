<?php

class C
{
    public $x = 7;

    public function f()
    {
        return eval('return $this->x;');
    }

    public function g()
    {
        eval('$this->x = 9;');

        return $this->x;
    }
}

class S
{
    public static function f()
    {
        try {
            return eval('return $this->x;');
        } catch (Throwable $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }
    }
}

$c = new C();
echo $c->f(), "\n";
echo $c->g(), "\n";
echo S::f(), "\n";
try {
    eval('return $this->x;');
    echo "file=OK\n";
} catch (Throwable $e) {
    echo 'file=', get_class($e), ': ', $e->getMessage(), "\n";
}
