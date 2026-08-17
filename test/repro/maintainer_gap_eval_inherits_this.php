<?php
/**
 * Maintainer gap: eval() from an instance method inherits $this (#31902).
 * Zend zend_eval_string inherits EG(current_execute_data)->This.
 * VM/JIT previously: Error "Using $this when not in object context".
 */
error_reporting(E_ALL);

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

$c = new C();
echo $c->f(), "\n";
echo $c->g(), "\n";

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

echo S::f(), "\n";

try {
    eval('return $this->x;');
    echo "file=OK\n";
} catch (Throwable $e) {
    echo 'file=', get_class($e), ': ', $e->getMessage(), "\n";
}
