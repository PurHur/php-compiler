--TEST--
Language: new Abstract/Interface/Trait Error getLine() at new expression (#31993, zend_API.c)
--FILE--
<?php
abstract class A {}
try {
    new A();
} catch (Throwable $e) {
    echo 'abstract=', $e->getLine(), ' ', $e->getMessage(), "\n";
}

interface I {}
try {
    new I();
} catch (Throwable $e) {
    echo 'interface=', $e->getLine(), ' ', $e->getMessage(), "\n";
}

trait T {}
try {
    new T();
} catch (Throwable $e) {
    echo 'trait=', $e->getLine(), ' ', $e->getMessage(), "\n";
}
--EXPECT--
abstract=4 Cannot instantiate abstract class A
interface=11 Cannot instantiate interface I
trait=18 Cannot instantiate trait T
