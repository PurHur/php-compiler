--TEST--
Language: enum case undeclared property write throws Error (#26588, zend_object_handlers.c / zend_enum.c)
--FILE--
<?php
error_reporting(E_ALL);

enum E {
    case A;
    public function f(): void
    {
        try {
            $this->x = 1;
            echo "assign_survived\n";
        } catch (Throwable $e) {
            echo 'assign:', get_class($e), ':', $e->getMessage(), "\n";
        }
        try {
            unset($this->x);
            echo "unset_survived\n";
        } catch (Throwable $e) {
            echo 'unset:', get_class($e), ':', $e->getMessage(), "\n";
        }
        try {
            $this->name = 'B';
            echo "name_survived\n";
        } catch (Throwable $e) {
            echo 'name:', get_class($e), ':', $e->getMessage(), "\n";
        }
        echo 'name=', $this->name, "\n";
    }
}

enum B: int {
    case A = 1;
    public function g(): void
    {
        try {
            $this->value = 2;
            echo "value_survived\n";
        } catch (Throwable $e) {
            echo 'value:', get_class($e), ':', $e->getMessage(), "\n";
        }
        try {
            $this->y = 3;
            echo "backed_dyn_survived\n";
        } catch (Throwable $e) {
            echo 'backed_dyn:', get_class($e), ':', $e->getMessage(), "\n";
        }
        echo 'name=', $this->name, ' value=', $this->value, "\n";
    }
}

E::A->f();
B::A->g();
--EXPECT--
assign:Error:Cannot create dynamic property E::$x
unset_survived
name:Error:Cannot modify readonly property E::$name
name=A
value:Error:Cannot modify readonly property B::$value
backed_dyn:Error:Cannot create dynamic property B::$y
name=A value=1
