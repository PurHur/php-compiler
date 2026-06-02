--TEST--
language: class instantiation fatals throw Error not LogicException (#4281, zend_execute.c)
--FILE--
<?php
abstract class A {}
interface I {}

try {
    $c = 'A';
    new $c();
} catch (Error $e) {
    echo 'abstract: ', get_class($e), ': ', $e->getMessage(), "\n";
} catch (LogicException $e) {
    echo 'abstract: LogicException: ', $e->getMessage(), "\n";
}

try {
    new I();
} catch (Error $e) {
    echo 'interface: ', get_class($e), ': ', $e->getMessage(), "\n";
} catch (LogicException $e) {
    echo 'interface: LogicException: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
abstract: Error: Cannot instantiate abstract class A
interface: Error: Cannot instantiate interface I
