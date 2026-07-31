--TEST--
Language: __debugInfo() non-array return → Fatal must return an array (#25748, re-#4683, Zend/zend.c)
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
--EXPECTF--
PHP Fatal error:  __debuginfo() must return an array in %s on line %d
--EXPECT_EXIT--
255
