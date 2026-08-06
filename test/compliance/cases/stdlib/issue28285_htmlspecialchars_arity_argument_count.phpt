--TEST--
stdlib htmlspecialchars() wrong argc — ArgumentCountError not LogicException (#28285, Zend html.stub.php)
--FILE--
<?php
try {
    htmlspecialchars();
    echo "too_few_0 ok\n";
} catch (Throwable $e) {
    echo 'too_few_0 ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo 'ok_1 ok=', var_export(htmlspecialchars('a'), true), "\n";
} catch (Throwable $e) {
    echo 'ok_1 ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo 'ok_4 ok=', var_export(htmlspecialchars('a', ENT_QUOTES, 'UTF-8', true), true), "\n";
} catch (Throwable $e) {
    echo 'ok_4 ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    htmlspecialchars('a', ENT_QUOTES, 'UTF-8', true, 'extra');
    echo "too_many_5 ok\n";
} catch (Throwable $e) {
    echo 'too_many_5 ', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
too_few_0 ArgumentCountError: htmlspecialchars() expects at least 1 argument, 0 given
ok_1 ok='a'
ok_4 ok='a'
too_many_5 ArgumentCountError: htmlspecialchars() expects at most 4 arguments, 5 given
