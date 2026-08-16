<?php
error_reporting(E_ALL);
$s = new SplObjectStorage();
try {
    $s->offsetSet(null, 1);
} catch (Throwable $e) {
    echo 'off:', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $s[null] = 1;
} catch (Throwable $e) {
    echo 'dim:', get_class($e), ': ', $e->getMessage(), "\n";
}
