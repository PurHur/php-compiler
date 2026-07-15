<?php
try {
    $x = clone null;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
