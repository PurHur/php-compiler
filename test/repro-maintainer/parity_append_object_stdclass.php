<?php
try {
    $o = new stdClass();
    $o[] = 1;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
