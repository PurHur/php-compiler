--TEST--
Reflection: ReflectionClass(null) — ReflectionException like Zend (#21770, ext/reflection/php_reflection.c)
--FILE--
<?php
try {
    new ReflectionClass(null);
} catch (ReflectionException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ReflectionException: Class "" does not exist
