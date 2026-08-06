<?php
/**
 * #28285 — htmlspecialchars() wrong argc → ArgumentCountError (Zend), not LogicException.
 */
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
