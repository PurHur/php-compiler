--TEST--
Language: throw non-Throwable raises TypeError (#5223, Zend/zend_exceptions.c)
--FILE--
<?php
function check($value, $label) {
    try {
        throw $value;
    } catch (TypeError $e) {
        echo $label, ': TypeError: ', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

check('x', 'string');
check(1, 'int');
check([], 'array');
check(new stdClass(), 'object');
--EXPECT--
string: TypeError: Cannot throw objects that do not implement Throwable
int: TypeError: Cannot throw objects that do not implement Throwable
array: TypeError: Cannot throw objects that do not implement Throwable
object: TypeError: Cannot throw objects that do not implement Throwable
