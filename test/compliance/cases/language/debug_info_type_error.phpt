--TEST--
Language: __debugInfo() — TypeError when return is not array (#4683, Zend/zend.c)
--FILE--
<?php
class C {
    public function __debugInfo() {
        return 'not-array';
    }
}
try {
    var_dump(new C());
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo "ok\n";
--EXPECT--
TypeError:C::__debugInfo(): Return value must be of type array, string returned
ok
