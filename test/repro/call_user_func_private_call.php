<?php
/**
 * call_user_func* on inaccessible private with __call → magic (#25710).
 * Without __call → TypeError (#25709).
 */
class A
{
    private function p()
    {
        echo "priv\n";
    }

    public function __call($n, $a)
    {
        echo "call:$n\n";
    }
}

$a = new A();
echo 'direct: ';
$a->p();
echo 'cuf: ';
call_user_func([$a, 'p']);
echo 'cufa: ';
call_user_func_array([$a, 'p'], []);
echo 'is_callable: ';
var_export(is_callable([$a, 'p']));
echo "\n";

class B
{
    private function p()
    {
        echo "priv\n";
    }
}

$b = new B();
echo 'no_magic cuf: ';
try {
    call_user_func([$b, 'p']);
    echo "ran\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
