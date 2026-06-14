<?php
declare(strict_types=1);
try {
    str_rot13([]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    str_rot13(new stdClass());
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo str_rot13('hello'), "\n";
