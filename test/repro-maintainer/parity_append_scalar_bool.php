<?php
try {
    $x = true;
    $x[] = 1;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
