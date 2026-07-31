--TEST--
Language: __debugInfo throw → Warning + Fatal must return array (#25748, Zend/zend.c)
--FILE--
<?php
class C {
    public function __debugInfo() { throw new RuntimeException('x'); }
}
try {
    var_dump(new C());
    echo "var_dump_returned\n";
} catch (Throwable $e) {
    echo 'caught:', get_class($e), ':', $e->getMessage(), "\n";
}
echo "after\n";
--EXPECTF--
PHP Warning:  Uncaught RuntimeException: x in %s:%d
Stack trace:
%a
  thrown in %s on line %d
PHP Fatal error:  __debuginfo() must return an array in %s on line %d
--EXPECT_EXIT--
255
