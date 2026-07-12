--TEST--
stdlib func_get_args() global scope throws Error (issue #17916)
--FILE--
<?php
try {
    func_get_args();
    echo "no_error\n";
} catch (Error $e) {
    echo str_contains($e->getMessage(), 'global scope') ? "error\n" : "bad_msg\n";
} catch (LogicException $e) {
    echo "logic\n";
}
--EXPECT--
error
