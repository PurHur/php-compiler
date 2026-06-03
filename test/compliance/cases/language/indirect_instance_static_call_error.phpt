--TEST--
Language: $obj::{$name}() — undefined method throws catchable Error (#4797, zend_execute.c)
--FILE--
<?php
declare(strict_types=1);
$obj = new stdClass();
try {
    $obj::{'strlen'}('hi');
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
} catch (LogicException $e) {
    echo 'LogicException: ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Call to undefined method stdClass::strlen()
