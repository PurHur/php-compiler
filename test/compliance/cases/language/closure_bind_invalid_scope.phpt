--TEST--
language: Closure::bind() invalid $newScope throws TypeError (#3673)
--FILE--
<?php
try {
    Closure::bind(function () {}, new stdClass(), 123);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Closure::bind(): Argument #3 ($newScope) must be of type object|string|null, int given
