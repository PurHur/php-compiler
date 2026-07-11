<?php
/**
 * Issue #17745 — invoke non-callable Error messages (Zend/zend_execute.c).
 */
function invokeError(string $label, callable $runner): void
{
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
