--TEST--
stdlib Internal builtins throw ArgumentCountError on wrong arity (#12920)
--FILE--
<?php
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
        echo $name, ": no_exception\n";
    } catch (ArgumentCountError $e) {
        echo $name, ": ArgumentCountError\n";
    } catch (LogicException $e) {
        echo $name, ": LogicException\n";
    }
}
--EXPECT--
strlen: ArgumentCountError
flush: ArgumentCountError
ob_flush: ArgumentCountError
register_shutdown_function: ArgumentCountError
set_error_handler: ArgumentCountError
