--TEST--
Language: JIT echo object without __toString throws Error (#4964, zend_operators.c)
--FILE--
<?php
class EchoObjectErrorJitBootstrap {
    public function __toString(): string { return ''; }
}
class C {}
try {
    echo new C();
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Object of class C could not be converted to string
