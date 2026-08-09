--TEST--
Language: child-scope read of other-instance parent private throws Error (#29494, zend_object_handlers.c)
--FILE--
<?php
error_reporting(E_ALL);

class A
{
    private string $p = 'hid';

    private function secret(): string
    {
        return 'secret';
    }
}

class B extends A
{
    public function leakOther(): string
    {
        $a = new A();
        try {
            return 'val=' . var_export($a->p, true);
        } catch (Throwable $e) {
            return get_class($e) . ':' . $e->getMessage();
        }
    }

    public function leakThis(): string
    {
        try {
            return 'val=' . var_export($this->p, true);
        } catch (Throwable $e) {
            return get_class($e) . ':' . $e->getMessage();
        }
    }

    public function writeOther(): string
    {
        $a = new A();
        try {
            $a->p = 'x';
            return 'wrote';
        } catch (Throwable $e) {
            return get_class($e) . ':' . $e->getMessage();
        }
    }

    public function callOtherSecret(): string
    {
        $a = new A();
        try {
            return $a->secret();
        } catch (Throwable $e) {
            return get_class($e) . ':' . $e->getMessage();
        }
    }

    public function unsetOther(): string
    {
        $a = new A();
        try {
            unset($a->p);
            return 'unset-ok';
        } catch (Throwable $e) {
            return get_class($e) . ':' . $e->getMessage();
        }
    }

    public function issetOther(): string
    {
        $a = new A();
        return 'isset=' . var_export(isset($a->p), true);
    }
}

echo (new B())->leakOther(), "\n";
echo (new B())->leakThis(), "\n";
echo (new B())->writeOther(), "\n";
echo (new B())->callOtherSecret(), "\n";
echo (new B())->unsetOther(), "\n";
echo (new B())->issetOther(), "\n";
?>
--EXPECT--
Error:Cannot access private property A::$p
val=NULL
Error:Cannot access private property A::$p
Error:Call to private method A::secret() from scope B
Error:Cannot access private property A::$p
isset=false
