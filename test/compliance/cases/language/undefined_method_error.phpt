--TEST--
Language: undefined instance method throws catchable Error (#4851, zend_execute.c)
--FILE--
<?php
enum E: int { case A = 1; }
class C {}
try {
    E::A->nope();
} catch (Error $e) {
    echo 'enum:', $e->getMessage(), "\n";
}
try {
    (new C())->nope();
} catch (Error $e) {
    echo 'user:', $e->getMessage(), "\n";
}
--EXPECT--
enum:Call to undefined method E::nope()
user:Call to undefined method C::nope()
