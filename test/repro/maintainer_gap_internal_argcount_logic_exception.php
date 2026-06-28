<?php

declare(strict_types=1);

/**
 * Repro for #12920 — Internal builtins must throw ArgumentCountError, not LogicException.
 *
 * php-src: Zend/zend_execute.c — too few/many arguments → ArgumentCountError
 */

$cases = [
    ['strlen', static fn () => strlen()],
    ['flush', static fn () => flush(fopen('php://memory', 'r+'))],
    ['ob_flush', static fn () => ob_flush('extra')],
    ['register_shutdown_function', static fn () => register_shutdown_function()],
    ['set_error_handler', static fn () => set_error_handler()],
];

foreach ($cases as [$name, $fn]) {
    try {
        $fn();
        echo "fail: {$name} expected ArgumentCountError\n";
        exit(1);
    } catch (ArgumentCountError $e) {
        echo $name, ': ArgumentCountError', "\n";
    } catch (LogicException $e) {
        echo "fail: {$name} LogicException: ", $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
