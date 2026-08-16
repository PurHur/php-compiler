<?php
try {
    fopen(__FILE__, 'r', false, null, 'extra');
} catch (Throwable $e) {
    echo 'excess:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    fopen(__FILE__);
} catch (Throwable $e) {
    echo 'missing:', get_class($e), ':', $e->getMessage(), "\n";
}
