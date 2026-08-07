--TEST--
Language: missing RoundingMode::case is catchable Error (zend_execute.c, #28480)
--FILE--
<?php
echo 'class_exists=', class_exists('RoundingMode') ? 'Y' : 'N', PHP_EOL;
try {
    $x = RoundingMode::HalfAwayFromZero;
    echo "unexpected_ok", PHP_EOL;
} catch (Error $e) {
    echo 'caught:', get_class($e), ':', $e->getMessage(), PHP_EOL;
}
echo "after", PHP_EOL;

// Generic missing class const fetch — same Error shape.
try {
    $y = NoSuchClass28480::SOME_CONST;
    echo "unexpected_generic", PHP_EOL;
} catch (Error $e) {
    echo 'generic:', get_class($e), ':', $e->getMessage(), PHP_EOL;
}
echo "after_generic", PHP_EOL;
?>
--EXPECT--
class_exists=N
caught:Error:Class "RoundingMode" not found
after
generic:Error:Class "NoSuchClass28480" not found
after_generic
