<?php
declare(strict_types=1);
// AOT compile-only (#19040): ord() must lower strict_types float TypeError guard (ext/standard/string.c).
try {
    ord(65.9);
    echo "ord_float: ok\n";
} catch (Throwable $e) {
    echo 'ord_float:', get_class($e), ':', $e->getMessage(), "\n";
}
