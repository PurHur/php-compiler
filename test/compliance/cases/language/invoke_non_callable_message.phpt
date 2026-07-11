--TEST--
language invoke non-callable Error messages (#17745, Zend/zend_execute.c)
--FILE--
<?php
function invokeError(string $label, callable $runner): void {
    try {
        $runner();
        echo $label, ": no error\n";
    } catch (Error $e) {
        echo $label, ': ', $e->getMessage(), "\n";
    }
}

invokeError('int', static function (): void {
    $x = 1;
    $x();
});

invokeError('array', static function (): void {
    $x = [1];
    $x();
});

invokeError('object', static function (): void {
    $x = new stdClass();
    $x();
});
--EXPECT--
int: Value of type int is not callable
array: Array callback must have exactly two elements
object: Object of type stdClass is not callable
