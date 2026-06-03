<?php

declare(strict_types=1);

/** Issue #4722 — TypeError for non-array second argument (ext/standard/array.c). */
class C
{
}

try {
    array_key_exists('key', new C());
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
