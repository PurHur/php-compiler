<?php
declare(strict_types=1);

try {
    str_repeat('a', 1.5);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    substr('hello', 1.5, 2.5);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    str_pad('a', 5.5);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    array_slice([1, 2, 3], 1.5, 1.5);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    sleep(1.5);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    usleep(1.5);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
