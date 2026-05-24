--TEST--
Stdlib: restore_error_handler() restores previous handler (VM, #1379)
--FILE--
<?php
function handler_a($errno, $errstr, $errfile, $errline) {
    echo "a\n";
    return true;
}
function handler_b($errno, $errstr, $errfile, $errline) {
    echo "b\n";
    return true;
}
set_error_handler('handler_a');
set_error_handler('handler_b');
trigger_error('x', 1024);
restore_error_handler();
trigger_error('y', 1024);
echo restore_error_handler() ? "restored\n" : "fail\n";
echo restore_error_handler() ? "again\n" : "empty\n";
--EXPECT--
b
a
restored
empty
