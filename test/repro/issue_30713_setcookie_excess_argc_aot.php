<?php
/**
 * AOT: setcookie/setrawcookie excess argc ArgumentCountError wording (#30713).
 *
 * Direct calls (no variable-function). Catch Throwable only — peer #30783.
 */
try {
    setcookie('n', 'v', 0, '', '', false, false, false);
    echo "pos:OK\n";
} catch (Throwable $e) {
    echo 'pos:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    setrawcookie('n', 'v', 0, '', '', false, false, false);
    echo "raw:OK\n";
} catch (Throwable $e) {
    echo 'raw:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    setcookie('n', 'v', ['expires' => 0], 1);
    echo "opts:OK\n";
} catch (Throwable $e) {
    echo 'opts:', get_class($e), ':', $e->getMessage(), "\n";
}
