<?php
/**
 * setcookie/setrawcookie excess argc → Zend ArgumentCountError wording (#30713).
 * php-src: ext/standard/head.c PHP_FUNCTION(setcookie) / setrawcookie
 */
try {
    setcookie('n', 'v', 0, '', '', false, false, false);
    echo "pos:OK\n";
} catch (ArgumentCountError $e) {
    echo 'pos:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'pos:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    setrawcookie('n', 'v', 0, '', '', false, false, false);
    echo "raw:OK\n";
} catch (ArgumentCountError $e) {
    echo 'raw:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'raw:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    setcookie('n', 'v', ['expires' => 0], 1);
    echo "opts:OK\n";
} catch (ArgumentCountError $e) {
    echo 'opts:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'opts:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    setrawcookie('n', 'v', ['expires' => 0], 1);
    echo "rawopts:OK\n";
} catch (ArgumentCountError $e) {
    echo 'rawopts:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'rawopts:', get_class($e), ':', $e->getMessage(), "\n";
}
