<?php
class A
{
    private function priv($x)
    {
        echo "priv:$x\n";
    }

    protected function prot()
    {
        echo "prot\n";
    }
}

class B extends A
{
    public function go()
    {
        call_user_func([$this, 'prot']);
    }
}

$a = new A();
try {
    call_user_func([$a, 'priv'], 9);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
try {
    call_user_func_array([$a, 'priv'], [9]);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
try {
    call_user_func([$a, 'prot']);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
(new B())->go();
